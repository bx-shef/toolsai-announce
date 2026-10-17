<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Integration\Rest;

use Bitrix\Rest\RestException;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\McpTools;
use Shef\ToolsAiAnnounce\Announce\OwnStore;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * REST-методы оповещений для MCP-сервера toolsai-mcp (1.5.0) — область
 * shef_announce, подписка rest:OnRestServiceBuildDescription (installEvents).
 * Что отдаёт манифест и как разбираются аргументы — Announce\McpTools.
 *
 * Вызов идёт токеном сотрудника: $APPLICATION->GetGroupRight() — его право
 * на модуль, как на странице списка (Controller\Announcement). Право
 * проверяется в каждом методе, не только в манифесте: манифест кешируется
 * сервером на 5 минут.
 *
 * Ошибки — RestException: «не найдено» — STATUS_NOT_FOUND с описанием
 * «Not found» (так сервер MCP покажет модели «Не найдено»).
 */
class Service
{
	/** Обработчик rest:OnRestServiceBuildDescription. */
	public static function onRestServiceBuildDescription(): array
	{
		return [
			McpTools::SCOPE => [
				McpTools::METHOD_MANIFEST => [static::class, 'tools'],
				McpTools::METHOD_LIST => [static::class, 'list'],
				McpTools::METHOD_CREATE => [static::class, 'create'],
				McpTools::METHOD_UPDATE => [static::class, 'update'],
				McpTools::METHOD_DISABLE => [static::class, 'disable'],
			],
		];
	}

	public static function tools(array $query, $nav = null, $server = null): array
	{
		return McpTools::manifest(static::right());
	}

	public static function list(array $query, $nav = null, $server = null): array
	{
		static::need('R');

		return ['items' => array_map([McpTools::class, 'row'], Collector::collectWithConflicts()['items'])];
	}

	public static function create(array $query, $nav = null, $server = null): array
	{
		static::need('W');
		$fields = McpTools::fields($query, null);
		$fields['id'] = 'n-'.date('Ymd').'-'.bin2hex(random_bytes(4));

		return static::store($fields, null);
	}

	public static function update(array $query, $nav = null, $server = null): array
	{
		static::need('W');
		$id = static::id($query);
		$old = null;
		foreach(OwnStore::load() as $item)
		{
			if($item['id'] === $id)
			{
				$old = $item;
			}
		}
		if($old === null)
		{
			if(static::find($id) !== null)
			{
				throw new RestException('Оповещение задано модулем: менять можно только созданные на портале (source own). Выключить — announce_disable.', 'ACCESS_DENIED', \CRestServer::STATUS_FORBIDDEN);
			}
			throw new RestException('Not found', 'NOT_FOUND', \CRestServer::STATUS_NOT_FOUND);
		}

		return static::store(McpTools::fields($query, $old), $old);
	}

	public static function disable(array $query, $nav = null, $server = null): array
	{
		static::need('W');
		$old = static::find(static::id($query));
		if($old === null)
		{
			throw new RestException('Not found', 'NOT_FOUND', \CRestServer::STATUS_NOT_FOUND);
		}
		$item = Announcements::stored($old);
		$item['active'] = false;

		return static::store($item, $old);
	}

	/** Проверить, привести и записать в свой список; ответ — id, включено ли и ссылка. */
	private static function store(array $fields, ?array $old): array
	{
		$errors = Announcements::validate($fields);
		if($errors !== [])
		{
			throw new RestException(implode(' ', $errors), 'INVALID_ARG_VALUE', \CRestServer::STATUS_WRONG_REQUEST);
		}
		$fields['published'] = Announcements::publishedOnSave($fields, $old, time());
		$item = Announcements::normalize($fields);
		$list = $item !== null ? Announcements::upsert(OwnStore::load(), $item) : null;
		if($list === null)
		{
			throw new RestException('Не сохранено: в своём списке не больше '.Announcements::MAX_ITEMS.' оповещений.', 'LIMIT', \CRestServer::STATUS_WRONG_REQUEST);
		}
		OwnStore::save($list);

		return McpTools::row($item) + ['listUrl' => Constants::listUrl()];
	}

	private static function id(array $query): string
	{
		$id = strtolower(trim(is_scalar($query['id'] ?? null) ? (string)$query['id'] : ''));
		if(!preg_match(Announcements::ID_PATTERN, $id))
		{
			throw new RestException('Нужен id из announce_list', 'INVALID_ARG_VALUE', \CRestServer::STATUS_WRONG_REQUEST);
		}

		return $id;
	}

	/** Объявление из итогового списка (своё или модульное). */
	private static function find(string $id): ?array
	{
		foreach(Collector::collectWithConflicts()['items'] as $item)
		{
			if($item['id'] === $id)
			{
				return $item;
			}
		}

		return null;
	}

	private static function need(string $right): void
	{
		if(static::right() < $right)
		{
			throw new RestException($right === 'W' ? 'Менять оповещения может только тот, у кого право «запись» на модуль.' : 'Нет доступа к оповещениям.', 'ACCESS_DENIED', \CRestServer::STATUS_FORBIDDEN);
		}
	}

	/** Право текущего сотрудника на модуль (D, R, W). */
	protected static function right(): string
	{
		global $APPLICATION;

		return is_object($APPLICATION) ? (string)$APPLICATION->GetGroupRight(Constants::MODULE_ID) : 'D';
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

/**
 * Объявления из ИИ-клиента через MCP-сервер toolsai-mcp (1.6.0): манифест
 * инструментов и разбор их аргументов. REST-методы — Integration\Rest\Service.
 *
 * Сервер MCP зовёт манифест токеном сотрудника и показывает модели
 * инструменты (docs MODULE_TOOLS.md в toolsai-mcp):
 *
 * | инструмент | метод | право модуля |
 * |---|---|---|
 * | announce_list | shef.announce.list | R |
 * | announce_create | shef.announce.create | W |
 * | announce_update | shef.announce.update | W, только своё объявление |
 * | announce_disable | shef.announce.disable | W |
 *
 * Аудиторию при создании модель не выбирает сама: поле обязательное, а в
 * описании — спросить человека «черновик на тестовых или сразу сотрудникам».
 * Картинку из MCP не задать — только файл со страницы списка.
 *
 * Чистый класс — tests/mcp_test.php.
 */
final class McpTools
{
	public const SCOPE = 'shef_announce';
	public const METHOD_MANIFEST = 'shef.announce.mcp.tools';
	public const METHOD_LIST = 'shef.announce.list';
	public const METHOD_CREATE = 'shef.announce.create';
	public const METHOD_UPDATE = 'shef.announce.update';
	public const METHOD_DISABLE = 'shef.announce.disable';

	/** Поля, которые правит ИИ; остальные (картинка, published, sort) — со страницы списка. */
	public const FIELDS = ['title', 'text', 'url', 'linkText', 'layout', 'audience', 'users', 'departments', 'places', 'dateFrom', 'dateTo', 'repeat', 'repeatDays', 'buttonColor', 'video', 'showToNew', 'art'];

	/**
	 * Манифест для сотрудника с правом модуля $right (D, R, W): без R — пусто,
	 * запись — только с W.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function manifest(string $right): array
	{
		if($right < 'R')
		{
			return [];
		}
		$tools = [[
			'name' => 'announce_list',
			'title' => 'Оповещения сотрудникам: список',
			'description' => 'Список оповещений для сотрудников портала (окно с объявлением в Битрикс24): id, заголовок, вид, кому, включено ли, период, где показывать, источник (own — создано на портале, иначе id модуля). Править можно только own.',
			'method' => self::METHOD_LIST,
			'kind' => 'read',
			'input' => ['type' => 'object', 'properties' => new \stdClass()],
		]];
		if($right < 'W')
		{
			return $tools;
		}
		$fields = static::fieldSchema();
		$tools[] = [
			'name' => 'announce_create',
			'title' => 'Оповещения сотрудникам: создать',
			'description' => 'Создаёт оповещение — окно, которое сотрудники увидят на портале. ПЕРЕД вызовом обязательно спросите человека, как запускать, и не выбирайте сами: '
				.'1) черновик — audience "test", окно увидят только тестовые пользователи модуля, на всех включают потом; '
				.'2) сразу сотрудникам — audience "all" (все) или "custom" (users и/или departments). '
				.'Возвращает id и ссылку на страницу списка.',
			'method' => self::METHOD_CREATE,
			'kind' => 'write',
			'input' => ['type' => 'object', 'properties' => $fields, 'required' => ['title', 'audience']],
		];
		$tools[] = [
			'name' => 'announce_update',
			'title' => 'Оповещения сотрудникам: изменить',
			'description' => 'Меняет оповещение, созданное на портале (source own в announce_list), по id. Передавайте только поля, которые меняются. Смену audience с "test" на "all" или "custom" (запуск на сотрудников) — только после подтверждения человека.',
			'method' => self::METHOD_UPDATE,
			'kind' => 'write',
			'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'id из announce_list']] + $fields, 'required' => ['id']],
		];
		$tools[] = [
			'name' => 'announce_disable',
			'title' => 'Оповещения сотрудникам: выключить',
			'description' => 'Выключает оповещение по id — сотрудники перестают его видеть, само оповещение остаётся в списке (включить снова — на странице списка). Оповещение модуля выключается своей копией.',
			'method' => self::METHOD_DISABLE,
			'kind' => 'write',
			'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'id из announce_list']], 'required' => ['id']],
		];

		return $tools;
	}

	/** @return array<string, array<string, mixed>> */
	private static function fieldSchema(): array
	{
		$ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 100];

		return [
			'title' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Заголовок окна'],
			'text' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Текст. Строка, начатая с «• », — пункт списка с галочкой'],
			'url' => ['type' => 'string', 'description' => 'Ссылка на подробности, только https://'],
			'linkText' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Текст кнопки-ссылки'],
			'layout' => ['type' => 'string', 'enum' => array_keys(Announcements::LAYOUTS), 'description' => static::labels(Announcements::LAYOUTS)],
			'audience' => ['type' => 'string', 'enum' => array_keys(Announcements::AUDIENCES), 'description' => static::labels(Announcements::AUDIENCES)],
			'users' => $ids + ['description' => 'ID сотрудников для audience "custom"'],
			'departments' => $ids + ['description' => 'ID отделов для audience "custom" (без подотделов)'],
			'places' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => Places::MAX_PER_ITEM, 'description' => 'Где показывать: адреса портала от «/», например /crm/deal/. Не задано — /crm/'],
			'dateFrom' => ['type' => 'string', 'description' => 'Показывать с, ГГГГ-ММ-ДД'],
			'dateTo' => ['type' => 'string', 'description' => 'Показывать по, ГГГГ-ММ-ДД'],
			'repeat' => ['type' => 'string', 'enum' => array_keys(Announcements::REPEATS), 'description' => static::labels(Announcements::REPEATS)],
			'repeatDays' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Для repeat "days"'],
			'buttonColor' => ['type' => 'string', 'enum' => array_keys(Announcements::BUTTON_COLORS)],
			'video' => ['type' => 'string', 'description' => 'Для layout "video": https-ссылка YouTube, Rutube, VK Видео или /upload/….mp4'],
			'art' => ['type' => 'string', 'enum' => array_keys(Art::ARTS), 'description' => 'Картинка окна: '.static::labels(Art::ARTS).'. Пусто — по виду окна; build, meeting, library — с анимацией'],
			'showToNew' => ['type' => 'boolean', 'description' => 'Показывать сотрудникам, пришедшим после публикации'],
		];
	}

	/** «код — название; …» для описания перечисления. */
	private static function labels(array $map): string
	{
		return implode('; ', array_map(static fn(string $k, string $v): string => $k.' — '.$v, array_keys($map), $map));
	}

	/**
	 * Поля формы из аргументов инструмента поверх прежнего объявления
	 * (создание — $old null). Чужие ключи отбрасываются.
	 *
	 * @param array<string, mixed> $args
	 * @param array<string, mixed>|null $old
	 * @return array<string, mixed>
	 */
	public static function fields(array $args, ?array $old): array
	{
		$fields = $old !== null ? Announcements::stored($old) : ['active' => true];
		foreach(self::FIELDS as $key)
		{
			if(array_key_exists($key, $args))
			{
				$fields[$key] = $args[$key];
			}
		}

		return $fields;
	}

	/**
	 * Строка ответа announce_list — без HTML и служебных полей.
	 *
	 * @return array<string, mixed>
	 */
	public static function row(array $item): array
	{
		$source = (string)($item['source'] ?? '');

		return [
			'id' => $item['id'],
			'title' => $item['title'],
			'text' => $item['text'] ?? '',
			'layout' => $item['layout'] ?? Announcements::LAYOUT_COSMOS,
			'audience' => $item['audience'] ?? Announcements::AUDIENCE_TEST,
			'active' => !empty($item['active']),
			'dateFrom' => $item['dateFrom'] ?? '',
			'dateTo' => $item['dateTo'] ?? '',
			'places' => $item['places'] ?? [],
			'repeat' => $item['repeat'] ?? Announcements::REPEAT_ONCE,
			'source' => $source === Announcements::SOURCE_SETTINGS ? 'own' : $source,
		];
	}
}

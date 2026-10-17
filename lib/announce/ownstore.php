<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Свой список объявлений — настройка ANNOUNCE_list (JSON). Запись сбрасывает
 * кеш итогового списка (Collector::clearCache()).
 *
 * Правка — через update(): список перечитывается и пишется под блокировкой
 * БД (1.6.0), иначе два одновременных вызова (ИИ шлёт инструменты пачкой,
 * страница списка и MCP) молча затирают друг друга.
 */
final class OwnStore
{
	/** Имя блокировки БД на правку списка. */
	private const LOCK = 'shef_toolsaiannounce_list';

	/** @return list<array> */
	public static function load(): array
	{
		return Announcements::parse((string)Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, ''));
	}

	/** @param list<array> $items */
	public static function save(array $items): void
	{
		Option::set(Constants::MODULE_ID, Constants::OPTION_LIST, (string)json_encode(array_values(array_map([Announcements::class, 'stored'], $items)), JSON_UNESCAPED_UNICODE));
		Collector::clearCache();
	}

	/**
	 * Прочитать, изменить и записать под блокировкой. $change получает свежий
	 * список — прямо из БД (Option::getRealValue(): Option::get() отдаёт копию,
	 * прочитанную в этом хите раньше) — и возвращает новый или null — тогда
	 * ничего не пишется. Блокировку не взять за 5 секунд — \RuntimeException,
	 * без записи.
	 *
	 * @param callable(list<array>): (list<array>|null) $change
	 * @return list<array>|null записанный список
	 */
	public static function update(callable $change): ?array
	{
		$connection = class_exists(Application::class) ? Application::getConnection() : null;
		if($connection !== null && !$connection->lock(self::LOCK, 5))
		{
			throw new \RuntimeException('Список объявлений занят другой правкой.');
		}
		try
		{
			$list = $change(Announcements::parse(Option::getRealValue(Constants::MODULE_ID, Constants::OPTION_LIST)));
			if($list !== null)
			{
				static::save($list);
			}

			return $list;
		}
		finally
		{
			$connection?->unlock(self::LOCK);
		}
	}
}

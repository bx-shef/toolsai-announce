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
	 * список — запросом к b_option (fresh()): Option::get() отдаёт копию,
	 * прочитанную в этом хите раньше, а getRealValue() ищет значение сайта, а
	 * не общее, — пустой список стёр бы все свои объявления. $change
	 * возвращает новый список или null — тогда ничего не пишется. Блокировку не взять за 5 секунд — \RuntimeException,
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
			$list = $change(Announcements::parse(static::fresh($connection)));
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

	/**
	 * Общее (не сайта) значение ANNOUNCE_list прямо из таблицы b_option, мимо
	 * кеша хита. Без соединения (тесты без БД) или строки не нашлось —
	 * Option::get(): пустым список не считаем никогда — запись пустого стёрла
	 * бы все свои объявления.
	 */
	private static function fresh(?object $connection): string
	{
		if($connection === null)
		{
			return (string)Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, '');
		}
		$sql = $connection->getSqlHelper();
		$row = $connection->query(
			"SELECT VALUE FROM b_option WHERE MODULE_ID = '".$sql->forSql(Constants::MODULE_ID)."'"
			." AND NAME = '".$sql->forSql(Constants::OPTION_LIST)."' AND (SITE_ID IS NULL OR SITE_ID IN ('', '-'))"
		)->fetch();

		return is_array($row) && (string)$row['VALUE'] !== '' ? (string)$row['VALUE'] : (string)Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, '');
	}
}

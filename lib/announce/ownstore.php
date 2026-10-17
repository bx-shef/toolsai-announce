<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Свой список объявлений — настройка ANNOUNCE_list (JSON). Запись сбрасывает
 * кеш итогового списка (Collector::clearCache()).
 */
final class OwnStore
{
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
}

<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Отметки «видел»: личная настройка пользователя (CUserOptions,
 * категория shef.toolsaiannounce, имя announce_seen) — список id.
 *
 * Отметки shef.toolsai (категория shef.toolsai, то же имя) читаются вместе
 * со своими, пока они есть: установщик переносит их один раз, а
 * shef.toolsai до своей версии без новинок может дописать новые.
 */
final class SeenStore
{
	/**
	 * Какие объявления пользователь видел.
	 *
	 * @return list<string>
	 */
	public static function get(int $userId): array
	{
		$own = \CUserOptions::GetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, [], $userId);
		$own = is_array($own) ? array_values(array_map('strval', array_filter($own, 'is_scalar'))) : [];
		$legacy = \CUserOptions::GetOption(Constants::LEGACY_MODULE_ID, Constants::SEEN_OPTION, [], $userId);

		return is_array($legacy) && $legacy !== [] ? Announcements::mergeSeen($own, $legacy) : $own;
	}

	/** Отметить: объявление показано, второй раз не показывать. */
	public static function mark(int $userId, string $id): void
	{
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, Announcements::markSeen(static::get($userId), $id), false, $userId);
	}

	/**
	 * Снять отметку («Показать мне снова»). Снимается и в shef.toolsai — иначе
	 * она вернулась бы при следующем чтении.
	 */
	public static function forget(int $userId, string $id): void
	{
		$legacy = \CUserOptions::GetOption(Constants::LEGACY_MODULE_ID, Constants::SEEN_OPTION, [], $userId);
		if(is_array($legacy) && in_array($id, $legacy, true))
		{
			\CUserOptions::SetOption(Constants::LEGACY_MODULE_ID, Constants::SEEN_OPTION, array_values(array_filter($legacy, static fn(mixed $v): bool => $v !== $id)), false, $userId);
		}
		$rest = array_values(array_filter(static::get($userId), static fn(string $v): bool => $v !== $id));
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, $rest, false, $userId);
	}
}

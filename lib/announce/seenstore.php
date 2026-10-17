<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Отметки «видел»: личная настройка пользователя (CUserOptions,
 * категория shef.toolsaiannounce, имя announce_seen) — список id.
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

		return is_array($own) ? array_values(array_map('strval', array_filter($own, 'is_scalar'))) : [];
	}

	/** Отметить: объявление показано, второй раз не показывать. */
	public static function mark(int $userId, string $id): void
	{
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, Announcements::markSeen(static::get($userId), $id), false, $userId);
	}

	/** Снять отметку («Показать мне снова»). */
	public static function forget(int $userId, string $id): void
	{
		$rest = array_values(array_filter(static::get($userId), static fn(string $v): bool => $v !== $id));
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, $rest, false, $userId);
	}
}

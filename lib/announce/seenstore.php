<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Отметки показа — личные настройки пользователя (CUserOptions, категория
 * shef.toolsaiannounce):
 *
 *  * announce_seen — список id «видел» (как до 1.4.0, повтор «один раз»);
 *  * announce_seen_at — id => {t: время показа, ok: нажал «Понятно»} для
 *    повторов «пока не нажмёт» и «раз в N дней» (с 1.4.0).
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

	/**
	 * Время показа и «Понятно» по id.
	 *
	 * @return array<string, array{t: int, ok: bool}>
	 */
	public static function stamps(int $userId): array
	{
		$own = \CUserOptions::GetOption(Constants::MODULE_ID, Constants::SEEN_AT_OPTION, [], $userId);
		$result = [];
		foreach(is_array($own) ? $own : [] as $id => $row)
		{
			if(is_array($row))
			{
				$result[(string)$id] = ['t' => (int)($row['t'] ?? 0), 'ok' => !empty($row['ok'])];
			}
		}

		return $result;
	}

	/**
	 * Отметить показ; $ok — нажал главную кнопку или «Понятно».
	 */
	public static function mark(int $userId, string $id, bool $ok = false, ?int $now = null): void
	{
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, Announcements::markSeen(static::get($userId), $id), false, $userId);
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_AT_OPTION, Announcements::markStamp(static::stamps($userId), $id, $now ?? time(), $ok), false, $userId);
	}

	/** Снять отметку («Показать мне снова») — обе. */
	public static function forget(int $userId, string $id): void
	{
		$rest = array_values(array_filter(static::get($userId), static fn(string $v): bool => $v !== $id));
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, $rest, false, $userId);
		$stamps = static::stamps($userId);
		unset($stamps[$id]);
		\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_AT_OPTION, $stamps, false, $userId);
	}
}

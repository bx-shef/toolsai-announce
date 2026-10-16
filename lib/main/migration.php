<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Main;

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAnnounce\Announce\Announcements;

/**
 * Перенос из shef.toolsai при установке: настройки ANNOUNCE_list и
 * ANNOUNCE_testusers (если свои пусты) и отметки «видел» всех
 * пользователей (CUserOptions shef.toolsai / announce_seen → своя категория,
 * объединение без повторов).
 *
 * Повторный запуск ничего не портит: свою непустую настройку не трогает,
 * отметки объединяет. Данные shef.toolsai не удаляет — их снимет сам
 * shef.toolsai при своём обновлении или удалении.
 */
final class Migration
{
	/**
	 * Выполнить перенос.
	 *
	 * @return array{options: list<string>, users: int} что перенесено: коды настроек и число пользователей
	 */
	public static function run(): array
	{
		$report = ['options' => [], 'users' => 0];
		foreach([Constants::OPTION_LIST, Constants::OPTION_TEST_USERS] as $name)
		{
			$value = Announcements::migrateOption(
				static::raw(Constants::MODULE_ID, $name),
				static::raw(Constants::LEGACY_MODULE_ID, $name)
			);
			if($value !== null)
			{
				Option::set(Constants::MODULE_ID, $name, $value);
				$report['options'][] = $name;
			}
		}

		$rows = \CUserOptions::GetList(['ID' => 'ASC'], [
			'CATEGORY' => Constants::LEGACY_MODULE_ID,
			'NAME' => Constants::SEEN_OPTION,
			'COMMON' => 'N',
		]);
		while($row = $rows->Fetch())
		{
			$userId = (int)$row['USER_ID'];
			$legacy = @unserialize((string)$row['VALUE'], ['allowed_classes' => false]);
			if($userId <= 0 || !is_array($legacy) || $legacy === [])
			{
				continue;
			}
			$own = \CUserOptions::GetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, [], $userId);
			$merged = Announcements::mergeSeen($own, $legacy);
			if($merged !== $own)
			{
				\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, $merged, false, $userId);
				$report['users']++;
			}
		}

		return $report;
	}

	/** Сохранённое значение без умолчаний default_option.php; не сохраняли — null. */
	private static function raw(string $moduleId, string $name): ?string
	{
		$value = Option::getRealValue($moduleId, $name);

		return $value === null ? null : (string)$value;
	}
}

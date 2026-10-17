<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\UserTable;
use Shef\Options\Main\Options\Department;
use Shef\ToolsAiAnnounce\Announce\Announcements;

/**
 * Выбор тестовых пользователей на странице настроек.
 *
 * Штатный Department из shef.options понимает только JSON. Старое значение
 * «ID через запятую» (сохранено до 1.1.0, деплой не запускает установщик)
 * переводится в JSON при показе страницы — выбор показывает тех, кому
 * реально идут тестовые объявления; при сохранении в базу ложится уже JSON.
 */
class TestUsersOption extends Department
{
	/**
	 * Значение для поля выбора: JSON, старое — переведённое, с именами.
	 */
	protected function getInputValue(string $moduleId): string
	{
		$value = (string)Option::get($moduleId, $this->getComplexCode(), $this->getDefValue());
		if(!str_starts_with(trim($value), '['))
		{
			$titles = [];
			$ids = Announcements::testUsers($value);
			if($ids !== [])
			{
				$rows = UserTable::getList([
					'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
					'filter' => ['@ID' => $ids],
				]);
				while($row = $rows->fetch())
				{
					$name = trim($row['NAME'].' '.$row['LAST_NAME']);
					$titles[(int)$row['ID']] = $name !== '' ? $name : (string)$row['LOGIN'];
				}
			}
			$value = Announcements::testUsersSelectorValue($value, $titles);
		}

		return htmlspecialcharsbx($value);
	}
}

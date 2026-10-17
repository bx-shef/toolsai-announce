<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Internals\Integration\Main\EventHandler;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UserTable;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\Places;
use Shef\ToolsAiAnnounce\Announce\SeenStore;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Обработчик main:OnEpilog: кладёт окно объявления, если адрес страницы
 * подходит к «Где показывать» объявления (Places::filter(), по умолчанию —
 * /crm/, как до 1.4.0) и объявление пора показать этому сотруднику
 * (Announcements::plan(): период, повтор, аудитория, новые сотрудники).
 *
 * Адрес — как в адресной строке (getRequestUri: путь и параметры). Только
 * основное окно: не в слайдере (IFRAME=Y) и не на ajax-запросах.
 *
 * Подписку ставит установщик (installEvents в .settings.php), снимает
 * удаление.
 */
final class OnEpilogEventHandler
{
	/** Положить скрипт окна, если есть что показать. Ничего не бросает. */
	public static function handle(): void
	{
		try
		{
			global $USER;
			$request = Context::getCurrent()->getRequest();
			if(
				!is_object($USER) || !$USER->IsAuthorized()
				|| $request->isAjaxRequest()
				|| $request->get('IFRAME') === 'Y'
				|| str_starts_with((string)$request->getRequestedPage(), '/bitrix/')
			)
			{
				return;
			}
			$items = Places::filter(Collector::collect(), (string)$request->getRequestUri());
			if($items === [])
			{
				return;
			}
			$userId = (int)$USER->GetID();
			$plan = Announcements::plan(
				$items,
				SeenStore::get($userId),
				$userId,
				Announcements::testUsers((string)Option::get(Constants::MODULE_ID, Constants::OPTION_TEST_USERS, '1')),
				['now' => time(), 'stamps' => SeenStore::stamps($userId)] + static::userContext($userId)
			);
			if($plan !== null)
			{
				foreach($plan['items'] as $i => $item)
				{
					$plan['items'][$i]['imageSrc'] = static::imageSrc((int)($item['image'] ?? 0));
				}
				Asset::getInstance()->addString('<script>'.Announcements::script($plan).'</script>');
			}
		}
		catch(\Throwable)
		{
			// Объявление — не повод ломать страницу
		}
	}

	/**
	 * Отделы и дата регистрации сотрудника (b_user: UF_DEPARTMENT, DATE_REGISTER).
	 *
	 * @return array{departments?: list<int>, registered?: int}
	 */
	public static function userContext(int $userId): array
	{
		try
		{
			if(!class_exists(UserTable::class))
			{
				return [];
			}
			$row = UserTable::getList([
				'select' => ['ID', 'DATE_REGISTER', 'UF_DEPARTMENT'],
				'filter' => ['=ID' => $userId],
				'limit' => 1,
			])->fetch();
			if(!is_array($row))
			{
				return [];
			}
			$date = $row['DATE_REGISTER'] ?? null;

			return [
				'departments' => Announcements::ids((array)($row['UF_DEPARTMENT'] ?? [])),
				'registered' => is_object($date) && method_exists($date, 'getTimestamp') ? (int)$date->getTimestamp() : 0,
			];
		}
		catch(\Throwable)
		{
			return [];
		}
	}

	/** Путь своей картинки (CFile) или пусто. */
	public static function imageSrc(int $fileId): string
	{
		if($fileId <= 0 || !class_exists('CFile'))
		{
			return '';
		}

		return (string)\CFile::GetPath($fileId);
	}
}

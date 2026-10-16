<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Internals\Integration\Main\EventHandler;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Page\Asset;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\SeenStore;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Обработчик main:OnEpilog: на страницах /crm/ кладёт окно первой
 * непросмотренной новинки (Announcements::plan()). Отметку «видел» ставит
 * действие shef:toolsaiannounce.banner.seen при показе — закрыл крестиком
 * или кнопкой, второй раз не покажем.
 *
 * Подписку ставит установщик (installEvents в .settings.php), снимает
 * удаление. Поведение — как у Shef\ToolsAi\Announce\Events::onEpilog() до
 * выноса.
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
				!str_starts_with((string)$request->getRequestedPage(), '/crm/')
				|| !is_object($USER) || !$USER->IsAuthorized()
				|| $request->isAjaxRequest()
				|| $request->get('IFRAME') === 'Y'
			)
			{
				return;
			}
			$userId = (int)$USER->GetID();
			$plan = Announcements::plan(
				Collector::collect(),
				SeenStore::get($userId),
				$userId,
				Announcements::testUsers((string)Option::get(Constants::MODULE_ID, Constants::OPTION_TEST_USERS, '1'))
			);
			if($plan !== null)
			{
				Asset::getInstance()->addString('<script>'.Announcements::script($plan).'</script>');
			}
		}
		catch(\Throwable)
		{
			// Объявление — не повод ломать CRM
		}
	}
}

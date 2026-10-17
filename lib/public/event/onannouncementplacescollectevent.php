<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Public\Event;

use Bitrix\Main\Event;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Событие shef.toolsaiannounce:OnAnnouncementPlacesCollect — модули отдают
 * готовые места для поля «Где показывать» (с 1.4.0).
 *
 * Параметров нет. Ответ обработчика:
 *
 *   new EventResult(EventResult::SUCCESS, ['places' => [
 *       ['path' => '/shef/toolsai/view/', 'title' => 'Вид «ИИ»'],
 *   ]], 'vendor.module');
 *
 * path — адрес от корня сайта, можно с параметрами (`/a/?type=2`); title —
 * название в окне выбора, до 80 символов. Третий аргумент EventResult —
 * модуль-источник, без него ответ не принимается. Адрес уже есть в списке —
 * остаётся первое название (Announce\Places::catalog()).
 *
 * Как и OnAnnouncementsCollect: модули зовутся по одному (фильтр по
 * TO_MODULE_ID), подписка — registerEventHandler() в установщике модуля-
 * источника, аргумент обработчика — \Bitrix\Main\Event.
 */
final class OnAnnouncementPlacesCollectEvent extends Event
{
	/** Имя события. */
	public const TYPE = 'OnAnnouncementPlacesCollect';

	/**
	 * @param string|null $moduleFilter только обработчики этого модуля (TO_MODULE_ID)
	 */
	public function __construct(?string $moduleFilter = null)
	{
		parent::__construct(Constants::MODULE_ID, self::TYPE, [], $moduleFilter);
	}
}

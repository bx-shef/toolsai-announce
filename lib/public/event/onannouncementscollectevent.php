<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Public\Event;

use Bitrix\Main\Event;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Событие shef.toolsaiannounce:OnAnnouncementsCollect — модули отдают свои
 * объявления «Новинок для сотрудников».
 *
 * Параметров нет. Ответ обработчика:
 *
 *   new EventResult(EventResult::SUCCESS, ['announcements' => [
 *       ['id' => 'calls-ai-2026-10', 'title' => '…', 'text' => '…', 'url' => 'https://…',
 *        'linkText' => '…', 'audience' => 'test'|'all'|'custom', 'active' => true,
 *        'layout' => 'cosmos'|'important'|'danger'|'congrats'|'video'|'slides'],
 *   ]], 'vendor.module');
 *
 * Поля 1.4.0 (все необязательные, без них — поведение до 1.4.0): places
 * (где показывать, по умолчанию ['/crm/']), users/departments (для custom),
 * video, dateFrom/dateTo, sort, repeat/repeatDays, buttonColor, published,
 * showToNew. Полная таблица с пределами — docs/guide/02-event.md. Поле image
 * модулю не задаётся: своя картинка загружается только на странице списка
 * (сохранить можно лишь файл этого модуля).
 *
 * Третий аргумент EventResult — идентификатор модуля-источника, без него
 * ответ не принимается. id — `^[a-z0-9][a-z0-9-]{0,39}$`. Объявление с тем же
 * id в списке настроек модуля заменяет модульное; совпали id у двух модулей —
 * побеждает первый ответ, остальное — в лог (Announce\Collector).
 *
 * Обработчик в другом модуле типизирует аргумент как \Bitrix\Main\Event:
 * класс события есть только там, где стоит этот модуль. Подписка —
 * registerEventHandler() в установщике модуля-источника; модуля нет —
 * событие просто никто не шлёт.
 */
final class OnAnnouncementsCollectEvent extends Event
{
	/** Имя события. */
	public const TYPE = 'OnAnnouncementsCollect';

	/**
	 * Новое событие; на каждую отправку — новый объект.
	 *
	 * @param string|null $moduleFilter только обработчики этого модуля (TO_MODULE_ID) —
	 *                                  так Collector зовёт модули по одному, и сбой
	 *                                  одного не гасит остальные
	 */
	public function __construct(?string $moduleFilter = null)
	{
		parent::__construct(Constants::MODULE_ID, self::TYPE, [], $moduleFilter);
	}
}

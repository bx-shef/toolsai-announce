<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Обработчик shef.toolsai:OnViewSettingsMenuBuild (shef.toolsai 1.53.0, у нас
 * 1.4.1) — пункт «Оповещения для сотрудников» в меню шестерёнки вида «ИИ»:
 * ссылка на страницу списка объявлений, только при праве на модуль от R (как
 * у admin/menu.php и страницы списка).
 *
 * Контракт — shef.toolsai Public\Event\ViewSettingsMenuBuildEvent: ответ
 * EventResult SUCCESS с ['items' => [['text', 'url']]]. Права проверяет
 * обработчик: вид показывает всё, что вернули. Типизирован Main\Event, классы
 * shef.toolsai не нужны: без shef.toolsai 1.53 событие просто не придёт.
 */
final class OnViewSettingsMenuBuildEventHandler
{
	public const TEXT = 'Оповещения для сотрудников';

	public static function handle(Event $event): EventResult
	{
		try
		{
			global $APPLICATION;
			$items = static::items(is_object($APPLICATION) ? (string)$APPLICATION->GetGroupRight(Constants::MODULE_ID) : 'D');
		}
		catch(\Throwable)
		{
			// Сбой не ломает меню вида: просто без пункта
			$items = [];
		}

		return new EventResult(EventResult::SUCCESS, ['items' => $items], Constants::MODULE_ID);
	}

	/**
	 * Пункт по праву на модуль. Чистая часть.
	 *
	 * @return list<array{text: string, url: string}>
	 */
	public static function items(string $moduleRight): array
	{
		return $moduleRight >= 'R' ? [['text' => self::TEXT, 'url' => Constants::listUrl()]] : [];
	}
}

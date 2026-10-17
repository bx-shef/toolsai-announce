<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Main;

/**
 * Постоянные модуля: идентификатор, коды настроек, адреса.
 *
 * Коды настроек и личной настройки «видел» совпадают с теми, что были в
 * shef.toolsai до выноса (1.31.0–1.49.x): на них лежат данные портала.
 */
final class Constants
{
	/** Название страницы, пункта меню и блока настроек (префикс [SH] — модули линейки). */
	public const TITLE = '[SH] Оповещения для сотрудников';

	/** Идентификатор модуля. */
	public const MODULE_ID = 'shef.toolsaiannounce';

	/** Свой список объявлений, JSON. */
	public const OPTION_LIST = 'ANNOUNCE_list';

	/** Тестовые пользователи: JSON выбора сотрудников (старое — ID через запятую). */
	public const OPTION_TEST_USERS = 'ANNOUNCE_testusers';

	/** Отпечаток последних записанных в лог конфликтов id (чтобы не писать одно и то же на каждой странице). */
	public const OPTION_CONFLICTS = 'SYS_conflicts';

	/** Личная настройка (CUserOptions): какие объявления пользователь видел. */
	public const SEEN_OPTION = 'announce_seen';

	/** Личная настройка (CUserOptions): время показа и «Понятно» по id (повторы, с 1.4.0). */
	public const SEEN_AT_OPTION = 'announce_seen_at';

	/** Журнал первого появления объявлений без даты публикации: JSON id => время (с 1.4.0). */
	public const OPTION_FIRST_SEEN = 'SYS_firstseen';

	/** Сущность окна выбора мест (ui.entity-selector, свой провайдер). */
	public const PLACE_ENTITY = 'shef-announce-place';

	/** Действия страницы списка для BX.ajax.runAction (Controller\Announcement). */
	public const ACTION_PREFIX = 'shef:toolsaiannounce.announcement.';

	/** Страница списка в административной части (заглушку пишет AdminPage). */
	public const ADMIN_LIST_FILE = 'shef_toolsaiannounce_list.php';

	/** Действие отметки «видел» для BX.ajax.runAction. */
	public const SEEN_ACTION = 'shef:toolsaiannounce.banner.seen';

	/** Адрес страницы списка от корня сайта. */
	public static function listUrl(): string
	{
		return '/bitrix/admin/'.self::ADMIN_LIST_FILE.'?lang='.(defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');
	}
}

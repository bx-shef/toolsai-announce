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

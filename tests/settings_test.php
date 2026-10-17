<?php declare(strict_types=1);

/**
 * Обвязка модуля: зависимости, подписки, контроллер, коды настроек.
 *
 * * Зависимости — строго shef.options и shef.problems: от shef.toolsai модуль
 *   не зависит (связь — событием), и ни установщик, ни autoload.php не
 *   должны его требовать.
 * * Подписка main:OnEpilog ведёт на существующий класс и метод: опечатка в
 *   строке класса видна только на портале — окно молча не покажется.
 * * Установщик регистрирует и снимает подписки по одному списку.
 * * Коды настроек в default_option.php, Constants и options_conf.php — одни и
 *   те же: на них держится перенос из shef.toolsai.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Main\Constants;

$settings = require $root.'/.settings.php';
$value = static fn(string $key): mixed => $settings[$key]['value'] ?? null;

Check::group('зависимости');
Check::same('requireModules', $value('requireModules'), ['shef.options', 'shef.problems']);
$installer = (string)file_get_contents($root.'/install/index.php');
Check::same('установщик требует только shef.options и shef.problems', preg_match_all("/'(shef\.[a-z]+)' => '/", $installer, $m) ? $m[1] : [], ['shef.options', 'shef.problems']);
Check::same('NEED_MODULES в установщике нет', str_contains($installer, 'NEED_MODULES ='), false);
// Код без комментариев: в докблоках shef.toolsai упоминается — откуда перенесено.
$code = '';
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/lib', FilesystemIterator::SKIP_DOTS)) as $file)
{
	foreach(token_get_all((string)file_get_contents((string)$file)) as $token)
	{
		$code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
	}
}
Check::same('lib/ не зовёт классы shef.toolsai', preg_match('/Shef\\\\ToolsAi\\\\/', $code), 0);
Check::same('lib/ не подключает shef.toolsai', str_contains($code, "includeModule('shef.toolsai')"), false);

Check::group('подписки');
$events = $value('installEvents');
Check::same('две подписки', count($events), 2);
[$event, $menuEvent] = $events;
Check::same('shef.toolsai:OnViewSettingsMenuBuild', [$menuEvent['from']['module'], $menuEvent['from']['event'], $menuEvent['to']['module'], $menuEvent['isCompatible']], ['shef.toolsai', 'OnViewSettingsMenuBuild', Constants::MODULE_ID, false]);
Check::same('класс обработчика меню есть, метод статический', class_exists(ltrim($menuEvent['to']['class'], '\\')) && (new ReflectionMethod(ltrim($menuEvent['to']['class'], '\\'), $menuEvent['to']['function']))->isStatic(), true);
Check::same('main:OnEpilog', [$event['from']['module'], $event['from']['event'], $event['to']['module']], ['main', 'OnEpilog', Constants::MODULE_ID]);
Check::same('класс обработчика есть', class_exists(ltrim($event['to']['class'], '\\')), true);
Check::same('метод обработчика статический', (new ReflectionMethod(ltrim($event['to']['class'], '\\'), $event['to']['function']))->isStatic(), true);
Check::same('установщик регистрирует по installEvents', substr_count($installer, "get('installEvents')"), 1);
Check::same('и снимает по тому же списку', substr_count($installer, '$this->getEventsList()'), 2);

Check::group('установщик на заглушках');
\Bitrix\Main\Config\Configuration::$settings[Constants::MODULE_ID] = array_map(static fn(array $v): mixed => $v['value'], $settings);
$GLOBALS['APPLICATION'] = null;
require $root.'/install/index.php';
$module = new shef_toolsaiannounce();
Check::same('MODULE_ID', $module->MODULE_ID, Constants::MODULE_ID);
Check::same('версия из version.php', $module->MODULE_VERSION, '1.4.1');
$module->InstallEvents();
$handler = ['main', 'OnEpilog', Constants::MODULE_ID, $event['to']['class'], 'handle'];
$menuHandler = ['shef.toolsai', 'OnViewSettingsMenuBuild', Constants::MODULE_ID, $menuEvent['to']['class'], 'handle'];
Check::same('InstallEvents регистрирует обе', \Bitrix\Main\EventManager::$registered, [$handler, $menuHandler]);
\Bitrix\Main\EventManager::$unregistered = [];
$module->UnInstallEvents();
Check::same('UnInstallEvents снимает (с «\\» и без)', \Bitrix\Main\EventManager::$unregistered, [$handler, ['main', 'OnEpilog', Constants::MODULE_ID, ltrim($event['to']['class'], '\\'), 'handle'], $menuHandler, ['shef.toolsai', 'OnViewSettingsMenuBuild', Constants::MODULE_ID, ltrim($menuEvent['to']['class'], '\\'), 'handle']]);

Check::group('удаление');
$GLOBALS['CACHE_MANAGER'] = new class { public function CleanAll(): void {} };
\Bitrix\Main\Config\Option::set(Constants::MODULE_ID, Constants::OPTION_LIST, '[]');
\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, ['a'], false, 5);
$module->UnInstallDB(['savedata' => 'Y']);
Check::same('savedata — настройки и отметки остаются', [\Bitrix\Main\Config\Option::get(Constants::MODULE_ID, Constants::OPTION_LIST), \CUserOptions::GetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, [], 5)], ['[]', ['a']]);
$module->UnInstallDB();
Check::same('без savedata — удалены', [\Bitrix\Main\Config\Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, 'нет'), \CUserOptions::GetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, 'нет', 5)], ['нет', 'нет']);
Check::same('модуль снят', $GLOBALS['UNREGISTERED_MODULES'], [Constants::MODULE_ID, Constants::MODULE_ID]);

Check::group('контроллер');
Check::same('defaultNamespace', $value('controllers')['defaultNamespace'], '\\Shef\\ToolsAiAnnounce\\Controller');
Check::same('действие отметки', Constants::SEEN_ACTION, 'shef:toolsaiannounce.banner.seen');
Check::same('файл контроллера на месте', is_file($root.'/lib/controller/banner.php'), true);
Check::same('метод seenAction', str_contains((string)file_get_contents($root.'/lib/controller/banner.php'), 'public function seenAction(string $id'), true);

Check::group('коды настроек');
$shef_toolsaiannounce_default_option = null;
require $root.'/default_option.php';
Check::same('default_option', array_keys($shef_toolsaiannounce_default_option), [Constants::OPTION_LIST, Constants::OPTION_TEST_USERS]);
Check::same('тестовые по умолчанию — администратор (JSON Department)', \Shef\ToolsAiAnnounce\Announce\Announcements::testUsers($shef_toolsaiannounce_default_option[Constants::OPTION_TEST_USERS]), [1]);
$conf = (string)file_get_contents($root.'/options_conf.php');
Check::same('options_conf: вкладка ANNOUNCE + выбор сотрудников testusers = ANNOUNCE_testusers', str_contains($conf, "new Options\\Tab('ANNOUNCE')") && str_contains($conf, "new TestUsersOption('testusers')") && str_contains((string)file_get_contents($root.'/lib/main/testusersoption.php'), 'extends Department') && str_contains($conf, '->setOnlyUsers(true)'), true);
Check::same('MODULE_SORT = 1004 (место в списке решений)', str_contains((string)file_get_contents(__DIR__.'/../install/index.php'), "(int)'1004'"), true);
Check::same('коды те же, что в shef.toolsai', [Constants::OPTION_LIST, Constants::OPTION_TEST_USERS, Constants::SEEN_OPTION], ['ANNOUNCE_list', 'ANNOUNCE_testusers', 'announce_seen']);
Check::same('options.php — копия из shef.options (страница от $mid)', str_contains((string)file_get_contents($root.'/options.php'), "require __DIR__.'/options_conf.php'"), true);

Check::finish();

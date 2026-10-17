<?php declare(strict_types=1);

/**
 * Пункт «Оповещения для сотрудников» в меню шестерёнки вида «ИИ» shef.toolsai
 * (OnViewSettingsMenuBuild, 1.4.1) и самовосстановление подписок после
 * обновления копированием (Main\Maintenance).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAnnounce\Internals\Integration\ToolsAi\EventHandler\OnViewSettingsMenuBuildEventHandler as Handler;
use Shef\ToolsAiAnnounce\Main\Constants;
use Shef\ToolsAiAnnounce\Main\Maintenance;

Check::group('пункт меню');
$item = ['text' => 'Оповещения для сотрудников', 'url' => '/bitrix/admin/shef_toolsaiannounce_list.php?lang=ru'];
Check::same('право R — пункт', Handler::items('R'), [$item]);
Check::same('право W — пункт', Handler::items('W'), [$item]);
Check::same('право D — пусто', Handler::items('D'), []);
Check::same('годен для вида: путь портала, текст до 60', [(bool)preg_match('~^/(?![/\\\\])[^\s"\'<>\\\\]*$~', $item['url']), mb_strlen($item['text']) <= 60], [true, true]);

$GLOBALS['APPLICATION'] = new class {
	public function GetGroupRight(string $module): string { return $module === 'shef.toolsaiannounce' ? 'R' : 'D'; }
};
$result = Handler::handle(new Event('shef.toolsai', 'OnViewSettingsMenuBuild', ['entityTypeId' => 2, 'categoryId' => 0]));
Check::same('handle: SUCCESS от модуля с пунктом', [$result->getType(), $result->getModuleId(), $result->getParameters()['items']], [EventResult::SUCCESS, Constants::MODULE_ID, [$item]]);
$GLOBALS['APPLICATION'] = null;
Check::same('нет $APPLICATION — пусто', Handler::handle(new Event('shef.toolsai', 'OnViewSettingsMenuBuild', []))->getParameters()['items'], []);
$GLOBALS['APPLICATION'] = new class {
	public function GetGroupRight(string $module): string { throw new \RuntimeException('сбой'); }
};
Check::same('сбой — пусто', Handler::handle(new Event('shef.toolsai', 'OnViewSettingsMenuBuild', []))->getParameters()['items'], []);

Check::group('самовосстановление подписок');
Check::same('версия отстала — нужно', Maintenance::isNeeded('1.4.0', '1.4.1'), true);
Check::same('совпала — не нужно', Maintenance::isNeeded('1.4.1', '1.4.1'), false);
Check::same('версии нет — не нужно', Maintenance::isNeeded('', ''), false);
Check::same('версия из version.php', Maintenance::moduleVersion($root), (string)(static function() use ($root): string { $arModuleVersion = []; include $root.'/install/version.php'; return $arModuleVersion['VERSION']; })());

$settings = require $root.'/.settings.php';
Configuration::$settings[Constants::MODULE_ID] = array_map(static fn(array $v): mixed => $v['value'], $settings);
EventManager::$registered = [];
Option::set(Constants::MODULE_ID, Maintenance::OPTION_VERSION, '1.4.0');
Maintenance::reset();
Maintenance::ensureOnce();
Check::same('после обновления — обе подписки', array_map(static fn(array $r): string => $r[0].':'.$r[1], EventManager::$registered), ['main:OnEpilog', 'shef.toolsai:OnViewSettingsMenuBuild']);
Check::same('версия записана', Option::get(Constants::MODULE_ID, Maintenance::OPTION_VERSION), Maintenance::moduleVersion($root));
EventManager::$registered = [];
Maintenance::reset();
Maintenance::ensureOnce();
Check::same('повторно — ничего', EventManager::$registered, []);

Check::finish();

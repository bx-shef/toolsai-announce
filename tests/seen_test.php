<?php declare(strict_types=1);

/**
 * Отметки «видел» (SeenStore) вместе с отметками shef.toolsai, действие
 * shef:toolsaiannounce.banner.seen (Controller\Banner) и подписки установщика
 * при повторной установке и удалении — на заглушках ядра.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\EventManager;
use Shef\ToolsAiAnnounce\Announce\SeenStore;
use Shef\ToolsAiAnnounce\Controller\Banner;
use Shef\ToolsAiAnnounce\Main\Constants;

Check::group('SeenStore');
\CUserOptions::$values = [];
SeenStore::mark(3, 'a');
SeenStore::mark(3, 'b');
SeenStore::mark(3, 'a');
Check::same('mark: без повторов, свежее в конце', SeenStore::get(3), ['b', 'a']);
SeenStore::forget(3, 'b');
Check::same('forget снимает', SeenStore::get(3), ['a']);
Check::same('другой пользователь не затронут', SeenStore::get(4), []);
\CUserOptions::SetOption(Constants::LEGACY_MODULE_ID, Constants::SEEN_OPTION, ['old', 'a'], false, 3);
Check::same('отметки shef.toolsai читаются вместе со своими', SeenStore::get(3), ['old', 'a']);
SeenStore::forget(3, 'old');
Check::same('forget снимает и в shef.toolsai', [SeenStore::get(3), \CUserOptions::GetOption(Constants::LEGACY_MODULE_ID, Constants::SEEN_OPTION, [], 3)], [['a'], ['a']]);
\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, 'мусор', false, 5);
Check::same('мусор в настройке — пусто', SeenStore::get(5), []);

Check::group('Banner::seenAction');
\CUserOptions::$values = [];
$call = static function(?int $userId, string $id): array
{
	Controller::$userId = $userId;
	$controller = new Banner();

	return [$controller->seenAction($id), count($controller->errors)];
};
Check::same('гость — ошибка', $call(null, 'a'), [null, 1]);
Check::same('userId 0 — ошибка', $call(0, 'a'), [null, 1]);
Check::same('плохой id — ошибка', $call(2, '<x>'), [null, 1]);
Check::same('ничего не записано', \CUserOptions::$values, []);
Check::same('успех', $call(2, 'calls-ai-2026-10'), [['ok' => true], 0]);
Check::same('отметка — только себе', SeenStore::get(2), ['calls-ai-2026-10']);
$filters = (new Banner())->configureActions()['seen']['prefilters'];
Check::same('фильтры: авторизация, POST, CSRF', array_map('get_class', $filters), [
	'Bitrix\\Main\\Engine\\ActionFilter\\Authentication',
	'Bitrix\\Main\\Engine\\ActionFilter\\HttpMethod',
	'Bitrix\\Main\\Engine\\ActionFilter\\Csrf',
]);
Check::same('только POST', $filters[1]->methods, ['POST']);

Check::group('подписки установщика');
$settings = require $root.'/.settings.php';
\Bitrix\Main\Config\Configuration::$settings[Constants::MODULE_ID] = array_map(static fn(array $v): mixed => $v['value'], $settings);
$GLOBALS['APPLICATION'] = null;
require_once $root.'/install/index.php';
$module = new shef_toolsaiannounce();
EventManager::$registered = [];
$module->InstallEvents();
$module->InstallEvents();
Check::same('повторная установка — одна регистрация', count(EventManager::$registered), 1);
Check::same('регистрируется OnEpilog модуля', array_slice(EventManager::$registered[0], 0, 3), ['main', 'OnEpilog', Constants::MODULE_ID]);
$module->UnInstallEvents();
Check::same('удаление снимает всё', EventManager::$registered, []);

Check::finish();

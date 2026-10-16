<?php declare(strict_types=1);

/**
 * Событие OnAnnouncementsCollect: свой список + объявления модулей
 * (Announcements::merge), сбор ответов (Collector) и показ на странице CRM
 * (OnEpilogEventHandler) — на заглушках ядра.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Main\Page\Asset;
use Shef\ToolsAiAnnounce\Announce\Announcements as A;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Internals\Integration\Main\EventHandler\OnEpilogEventHandler;
use Shef\ToolsAiAnnounce\Main\Constants;
use Shef\ToolsAiAnnounce\Public\Event\OnAnnouncementsCollectEvent;

$ann = static fn(string $id, string $title = 'T', array $extra = []): array => array_merge(['id' => $id, 'title' => $title, 'active' => true, 'audience' => 'all'], $extra);

Check::group('merge');
$own = A::parse((string)json_encode([$ann('calls-ai', 'своё', ['active' => false]), $ann('mine')]));
$m = A::merge($own, [
	['moduleId' => 'shef.toolsai', 'announcements' => [$ann('calls-ai', 'модуль'), $ann('tai-2')]],
	['moduleId' => 'shef.other', 'announcements' => [$ann('tai-2', 'чужое'), $ann('other-1')]],
	['moduleId' => '', 'announcements' => [$ann('anon')]],
	['moduleId' => 'shef.broken', 'announcements' => 'x'],
]);
Check::same('порядок: свой список, потом модули по ответам', array_column($m['items'], 'id'), ['calls-ai', 'mine', 'tai-2', 'other-1']);
Check::same('свой список заменяет модульное с тем же id', [$m['items'][0]['title'], $m['items'][0]['active'], $m['items'][0]['overrides']], ['своё', false, 'shef.toolsai']);
Check::same('источник', array_column($m['items'], 'source'), ['', '', 'shef.toolsai', 'shef.other']);
Check::same('у своего без пары — нет overrides', isset($m['items'][1]['overrides']), false);
Check::same('конфликт модулей — побеждает первый', $m['items'][2]['title'], 'T');
Check::same('конфликты — в отчёт', count($m['conflicts']), 3);
Check::same('конфликт называет id и модули', str_contains($m['conflicts'][0], 'tai-2') && str_contains($m['conflicts'][0], 'shef.other'), true);
Check::same('без moduleId — не принят', in_array('anon', array_column($m['items'], 'id'), true), false);
Check::same('объявления модуля нормализуются', A::merge([], [['moduleId' => 'm', 'announcements' => [['id' => 'BAD id', 'title' => 'x'], ['id' => 'ok', 'title' => 'y', 'url' => 'http://insecure']]]])['items'][0]['url'], '');
Check::same('пусто — пусто', A::merge([], []), ['items' => [], 'conflicts' => []]);

Check::group('Collector: событие');
$event = new OnAnnouncementsCollectEvent();
Check::same('модуль и имя события', [$event->getModuleId(), $event->getEventType()], ['shef.toolsaiannounce', 'OnAnnouncementsCollect']);
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'] = [
	'shef.toolsai' => static fn() => new EventResult(EventResult::SUCCESS, ['announcements' => [$ann('calls-ai', 'модуль', ['audience' => 'test'])]], 'shef.toolsai'),
	'shef.err' => static fn() => new EventResult(EventResult::ERROR, ['announcements' => [$ann('err')]], 'shef.err'),
	'shef.undef' => static fn() => ['announcements' => [$ann('undef')]],
];
Option::$values = [];
Cache::$store = [];
$items = Collector::collect();
Check::same('только SUCCESS', array_column($items, 'id'), ['calls-ai']);
Check::same('конфликтов нет — лог не трогаем', Option::get(Constants::MODULE_ID, Constants::OPTION_CONFLICTS, 'нет'), 'нет');
Option::set(Constants::MODULE_ID, Constants::OPTION_LIST, (string)json_encode([$ann('calls-ai', 'своё')]));
Check::same('кеш: до сброса — прежний список', Collector::collect()[0]['title'], 'модуль');
Collector::clearCache();
Check::same('после сброса — настройки побеждают', Collector::collect()[0]['title'], 'своё');
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'] = [
	'shef.bad' => static fn() => throw new RuntimeException('упал'),
	'shef.good' => static fn() => new EventResult(EventResult::SUCCESS, ['announcements' => [$ann('good-1')]], 'shef.good'),
];
$r = Collector::collectWithConflicts();
Check::same('сбой одного обработчика — ответы остальных остаются', array_column($r['items'], 'id'), ['calls-ai', 'good-1']);
Check::same('сбой обработчика — в конфликты с модулем', str_contains($r['conflicts'][0], 'shef.bad') && str_contains($r['conflicts'][0], 'упал'), true);
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'][0] = static fn() => new EventResult(EventResult::SUCCESS, ['announcements' => [$ann('anon')]], 'x');
Check::same('обработчик без модуля назначения не зовётся', in_array('anon', array_column(Collector::collectWithConflicts()['items'], 'id'), true), false);
unset(EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'][0]);
Collector::clearCache();
Collector::collect();
Check::same('конфликты есть — отпечаток записан', strlen((string)Option::get(Constants::MODULE_ID, Constants::OPTION_CONFLICTS, '')), 32);
EventManager::$handlers = [];

Check::group('показ на странице CRM');
$GLOBALS['USER'] = new class
{
	public int $id = 1;

	public function IsAuthorized(): bool
	{
		return $this->id > 0;
	}

	public function GetID(): int
	{
		return $this->id;
	}
};
Option::$values = [];
Option::set(Constants::MODULE_ID, Constants::OPTION_TEST_USERS, '1');
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'] = [
	'shef.toolsai' => static fn() => new EventResult(EventResult::SUCCESS, ['announcements' => [$ann('calls-ai', 'ИИ в звонках', ['audience' => 'test'])]], 'shef.toolsai'),
];
Cache::$store = [];
$show = static function(string $page, bool $ajax = false, array $params = []): int
{
	Context::$page = $page;
	Context::$ajax = $ajax;
	Context::$params = $params;
	Asset::$strings = [];
	OnEpilogEventHandler::handle();

	return count(Asset::$strings);
};
Check::same('страница CRM, тестовый — окно', $show('/crm/deal/'), 1);
Check::same('окно с объявлением модуля', str_contains(Asset::$strings[0], 'ИИ в звонках'), true);
Check::same('не CRM — ничего', $show('/company/'), 0);
Check::same('ajax — ничего', $show('/crm/deal/', true), 0);
Check::same('слайдер — ничего', $show('/crm/deal/', false, ['IFRAME' => 'Y']), 0);
$GLOBALS['USER']->id = 7;
Check::same('не тестовому — тестовое не показываем', $show('/crm/deal/'), 0);
$GLOBALS['USER']->id = 1;
\CUserOptions::SetOption(Constants::MODULE_ID, Constants::SEEN_OPTION, ['calls-ai'], false, 1);
Check::same('видел — не показываем', $show('/crm/deal/'), 0);
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'] = ['shef.x' => static fn() => throw new RuntimeException('x')];
\CUserOptions::$values = [];
Cache::$store = [];
Check::same('сбой — CRM не ломается', $show('/crm/deal/'), 0);

Check::finish();

<?php declare(strict_types=1);

/**
 * Оповещения из ИИ-клиента через MCP (1.5.0): манифест по праву модуля,
 * аудитория при создании — обязательная и «спросить человека», REST-методы
 * на заглушках ядра: создать, изменить только своё, выключить модульное
 * своей копией, право в каждом методе.
 */

namespace Bitrix\Rest
{
	class RestException extends \Exception
	{
		public function __construct(string $message = '', public readonly string $restCode = '', public readonly string $status = "")
		{
			parent::__construct($message);
		}
	}
}

namespace Bitrix\Main
{
	/** Соединение на заглушке: журнал блокировок. */
	class Application
	{
		public static array $locks = [];
		public static bool $busy = false;
		public static array $queries = [];
		/** Значение в «БД», если отличается от кеша хита. */
		public static ?string $db = null;

		public static function getConnection(): object
		{
			return new class {
				public function lock(string $name, int $timeout = 0): bool { Application::$locks[] = 'lock:'.$name; return !Application::$busy; }
				public function unlock(string $name): bool { Application::$locks[] = 'unlock:'.$name; return true; }
				public function getSqlHelper(): object { return new class { public function forSql(string $v): string { return addslashes($v); } }; }
				/** b_option: общее значение — из «БД» (Option::$db), не из кеша хита (Option::$values). */
				public function query(string $sql): object
				{
					Application::$queries[] = $sql;
					$v = Application::$db ?? (\Bitrix\Main\Config\Option::$values['shef.toolsaiannounce']['ANNOUNCE_list'] ?? null);
					return new class($v) { public function __construct(private $v) {} public function fetch() { return $this->v === null ? false : ['VALUE' => $this->v]; } };
				}
			};
		}
	}
}

namespace
{
	class CRestServer
	{
		public const STATUS_WRONG_REQUEST = '400 Bad Request';
		public const STATUS_FORBIDDEN = '403 Forbidden';
		public const STATUS_NOT_FOUND = '404 Not Found';
	}
}

namespace
{
$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Rest\RestException;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\McpTools;
use Shef\ToolsAiAnnounce\Announce\OwnStore;
use Shef\ToolsAiAnnounce\Integration\Rest\Service;
use Shef\ToolsAiAnnounce\Main\Constants;

/** Service с подставным правом модуля. */
class RestAs extends Service
{
	public static string $right = 'W';

	protected static function right(): string
	{
		return static::$right;
	}
}

$call = static function(string $method, array $query): array
{
	try
	{
		return ['ok' => RestAs::$method($query)];
	}
	catch(RestException $e)
	{
		return ['error' => $e->restCode, 'message' => $e->getMessage()];
	}
};

Check::group('манифест');
Check::same('без права — пусто', McpTools::manifest('D'), []);
Check::same('чтение — только список', array_column(McpTools::manifest('R'), 'name'), ['announce_list']);
$tools = array_column(McpTools::manifest('W'), null, 'name');
Check::same('запись — четыре инструмента', array_keys($tools), ['announce_list', 'announce_create', 'announce_update', 'announce_disable']);
Check::same('виды', array_column($tools, 'kind'), ['read', 'write', 'write', 'write']);
Check::same('методы своего вендора', array_values(array_unique(array_map(static fn(array $t): string => explode('.', $t['method'])[0], $tools))), ['shef']);
Check::same('аудитория при создании обязательна', $tools['announce_create']['input']['required'], ['title', 'audience']);
Check::same('и модель спрашивает человека', str_contains($tools['announce_create']['description'], 'спросите человека'), true);
Check::same('в схеме нет картинки', isset($tools['announce_create']['input']['properties']['image']), false);
foreach($tools as $t)
{
	Check::same($t['name'].': имя по правилам сервера', (bool)preg_match('/^[a-z0-9_]{2,48}$/', $t['name']), true);
	Check::same($t['name'].': описание до 1000', mb_strlen($t['description']) <= 1000, true);
	Check::same($t['name'].': схема до 8000', strlen((string)json_encode($t['input'], JSON_UNESCAPED_UNICODE)) <= 8000, true);
}
Check::same('пустые properties — объект в JSON', json_encode($tools['announce_list']['input']), '{"type":"object","properties":{}}');
Check::same('регистрация методов', array_keys(Service::onRestServiceBuildDescription()[McpTools::SCOPE]), [McpTools::METHOD_MANIFEST, McpTools::METHOD_LIST, McpTools::METHOD_CREATE, McpTools::METHOD_UPDATE, McpTools::METHOD_DISABLE]);
foreach(Service::onRestServiceBuildDescription()[McpTools::SCOPE] as $cb)
{
	Check::same($cb[1].' вызываем', is_callable($cb), true);
}

Check::group('аргументы');
Check::same('чужие ключи — мимо', array_keys(McpTools::fields(['title' => 'T', 'image' => 5, 'published' => 1, 'id' => 'x'], null)), ['active', 'title']);
Check::same('правка — поверх старого', McpTools::fields(['text' => 'новый'], ['id' => 'a', 'title' => 'T', 'text' => 'старый', 'source' => '']), ['id' => 'a', 'title' => 'T', 'text' => 'новый']);

Check::group('REST');
Option::$values = [];
Cache::$store = [];
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'] = [
	'shef.toolsai' => static fn() => new EventResult(EventResult::SUCCESS, ['announcements' => [['id' => 'mod-1', 'title' => 'Модульное', 'active' => true, 'audience' => 'all']]], 'shef.toolsai'),
];
RestAs::$right = 'R';
Check::same('манифест для R', array_column(RestAs::tools([]), 'name'), ['announce_list']);
Check::same('создать с R — отказ', $call('create', ['title' => 'T', 'audience' => 'test'])['error'] ?? '', 'ACCESS_DENIED');
Check::same('список с R — есть модульное', array_column($call('list', [])['ok']['items'], 'source', 'id'), ['mod-1' => 'shef.toolsai']);
RestAs::$right = 'D';
Check::same('список без права — отказ', $call('list', [])['error'] ?? '', 'ACCESS_DENIED');

RestAs::$right = 'W';
Check::same('плохая ссылка — ошибка проверки', $call('create', ['title' => 'T', 'audience' => 'test', 'url' => 'http://x'])['error'] ?? '', 'INVALID_ARG_VALUE');
$r = $call('create', ['title' => 'Черновик', 'audience' => 'test', 'text' => "Вступление\n• пункт", 'places' => ['/crm/deal/']]);
$id = $r['ok']['id'] ?? '';
Check::same('создано черновиком', [$r['ok']['audience'] ?? null, $r['ok']['active'] ?? null, $r['ok']['source'] ?? null, $r['ok']['places'] ?? null], ['test', true, 'own', ['/crm/deal/']]);
Check::same('ссылка на страницу списка', str_contains($r['ok']['listUrl'] ?? '', 'shef_toolsaiannounce_list.php'), true);
Check::same('запись — под блокировкой БД', array_slice(\Bitrix\Main\Application::$locks, -2), ['lock:shef_toolsaiannounce_list', 'unlock:shef_toolsaiannounce_list']);
Check::same('в своём списке, с датой публикации', [count(OwnStore::load()), OwnStore::load()[0]['published'] > 0], [1, true]);
$r = $call('update', ['id' => $id, 'audience' => 'all']);
Check::same('изменено: всем, заголовок прежний', [$r['ok']['audience'] ?? null, $r['ok']['title'] ?? null], ['all', 'Черновик']);
Check::same('модульное не меняется', $call('update', ['id' => 'mod-1', 'title' => 'X'])['error'] ?? '', 'ACCESS_DENIED');
Check::same('нет такого — Not found', $call('update', ['id' => 'nope', 'title' => 'X'])['message'] ?? '', 'Not found');
Check::same('плохой id — ошибка', $call('disable', ['id' => 'Bad id!'])['error'] ?? '', 'INVALID_ARG_VALUE');
$r = $call('disable', ['id' => 'mod-1']);
Check::same('модульное выключено своей копией', [$r['ok']['active'] ?? null, $r['ok']['source'] ?? null, count(OwnStore::load())], [false, 'own', 2]);
Check::same('без аудитории (вызов мимо схемы MCP) — черновик на тестовых', $call('create', ['title' => 'Без аудитории'])['ok']['audience'] ?? null, 'test');
Check::same('выключить своё', $call('disable', ['id' => $id])['ok']['active'] ?? null, false);
RestAs::$right = 'R';
Check::same('право — в каждом методе, не только в манифесте', $call('disable', ['id' => $id])['error'] ?? '', 'ACCESS_DENIED');
RestAs::$right = 'W';
\Bitrix\Main\Application::$busy = true;
$before = OwnStore::load();
Check::same('блокировка занята — BUSY, без записи', [$call('create', ['title' => 'T', 'audience' => 'test'])['error'] ?? '', OwnStore::load()], ['BUSY', $before]);
\Bitrix\Main\Application::$busy = false;

// старое своё без даты публикации: дата — из журнала первого появления, не «сейчас»
Option::set(Constants::MODULE_ID, Constants::OPTION_LIST, (string)json_encode([['id' => 'old-1', 'title' => 'Старое', 'active' => true, 'audience' => 'all']]));
Option::set(Constants::MODULE_ID, Constants::OPTION_FIRST_SEEN, (string)json_encode(['old-1' => 1000]));
Cache::$store = [];
$call('update', ['id' => 'old-1', 'title' => 'Старое, поправлено']);
Check::same('правка старого — дата из журнала', OwnStore::load()[0]['published'], 1000);

// объявление, которое не проходит проверку формы, всё равно выключается
Option::set(Constants::MODULE_ID, Constants::OPTION_LIST, (string)json_encode([['id' => 'vid-1', 'title' => 'Видео без ссылки', 'active' => true, 'layout' => 'video']]));
Cache::$store = [];
Check::same('выключение — без проверки формы', $call('disable', ['id' => 'vid-1'])['ok']['active'] ?? null, false);
Check::same('правка его же — с проверкой', $call('update', ['id' => 'vid-1', 'title' => 'X'])['error'] ?? '', 'INVALID_ARG_VALUE');

// правка поверх списка в БД, а не прочитанного раньше
$call('update', ['id' => 'vid-1', 'video' => 'https://youtu.be/dQw4w9WgXcQ']);
Check::same('правка собрана внутри блокировки', OwnStore::load()[0]['video'], 'https://youtu.be/dQw4w9WgXcQ');

// свежее из БД, а не из кеша хита: другой процесс уже дописал своё
$cached = OwnStore::load();
\Bitrix\Main\Application::$db = (string)json_encode(array_merge($cached, [['id' => 'other-proc', 'title' => 'Из другого процесса']]));
$call('create', ['title' => 'Параллельное', 'audience' => 'test']);
\Bitrix\Main\Application::$db = null;
Check::same('под блокировкой — список из b_option, чужая запись не потеряна', in_array('other-proc', array_column(OwnStore::load(), 'id'), true), true);
Check::same('читается общее значение, не сайта', str_contains((string)end(\Bitrix\Main\Application::$queries), "SITE_ID IS NULL OR SITE_ID IN ('', '-')"), true);

// своё удалили, пока шёл вызов: выключение не воскрешает его из кеша
$snapshot = OwnStore::load();
Cache::$store = [];
Collector::collectWithConflicts();
\Bitrix\Main\Application::$db = (string)json_encode(array_values(array_filter($snapshot, static fn(array $i): bool => $i['id'] !== 'vid-1')));
Check::same('удалённое своё — Not found', $call('disable', ['id' => 'vid-1'])['message'] ?? '', 'Not found');
\Bitrix\Main\Application::$db = null;

Check::same('showToNew «false» формой — не true', McpTools::fields(['showToNew' => 'false'], null)['showToNew'], false);

RestAs::$right = 'R';
Check::same('изменить с R — отказ', $call('update', ['id' => 'vid-1', 'title' => 'X'])['error'] ?? '', 'ACCESS_DENIED');

Check::finish();
}

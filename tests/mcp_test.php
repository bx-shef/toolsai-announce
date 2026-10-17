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
use Shef\ToolsAiAnnounce\Announce\McpTools;
use Shef\ToolsAiAnnounce\Announce\OwnStore;
use Shef\ToolsAiAnnounce\Integration\Rest\Service;

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
Check::same('в своём списке, с датой публикации', [count(OwnStore::load()), OwnStore::load()[0]['published'] > 0], [1, true]);
$r = $call('update', ['id' => $id, 'audience' => 'all']);
Check::same('изменено: всем, заголовок прежний', [$r['ok']['audience'] ?? null, $r['ok']['title'] ?? null], ['all', 'Черновик']);
Check::same('модульное не меняется', $call('update', ['id' => 'mod-1', 'title' => 'X'])['error'] ?? '', 'ACCESS_DENIED');
Check::same('нет такого — Not found', $call('update', ['id' => 'nope', 'title' => 'X'])['message'] ?? '', 'Not found');
Check::same('плохой id — ошибка', $call('disable', ['id' => 'Bad id!'])['error'] ?? '', 'INVALID_ARG_VALUE');
$r = $call('disable', ['id' => 'mod-1']);
Check::same('модульное выключено своей копией', [$r['ok']['active'] ?? null, count(OwnStore::load())], [false, 2]);
Check::same('выключить своё', $call('disable', ['id' => $id])['ok']['active'] ?? null, false);
RestAs::$right = 'R';
Check::same('право — в каждом методе, не только в манифесте', $call('disable', ['id' => $id])['error'] ?? '', 'ACCESS_DENIED');

Check::finish();
}

<?php declare(strict_types=1);

/**
 * «Где показывать» (Announce\Places) без ядра: разбор адреса, совпадение
 * адреса страницы с местом, готовые места и поиск в окне выбора.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Announce\Announcements as A;
use Shef\ToolsAiAnnounce\Announce\Places as P;
use Shef\ToolsAiAnnounce\Announce\PlaceCollector;

Check::group('адрес места');
Check::same('путь как есть', P::normalize(' /crm/deal/ '), '/crm/deal/');
Check::same('часть после # отбрасывается', P::normalize('/crm/#x'), '/crm/');
Check::same('пустой «?» отбрасывается', P::normalize('/crm/?'), '/crm/');
Check::same('не от корня — нет', P::normalize('crm/'), null);
Check::same('чужой сайт //host — нет', P::normalize('//evil.by/'), null);
Check::same('https — нет (только адрес портала)', P::normalize('https://evil.by/'), null);
Check::same('кавычки и скобки — нет', [P::normalize('/a"b'), P::normalize('/<script>'), P::normalize("/a b")], [null, null, null]);
Check::same('не строка — нет', P::normalize(['/crm/']), null);
Check::same('слишком длинный — нет', P::normalize('/'.str_repeat('a', 300)), null);

Check::group('список мест объявления');
Check::same('пусто — /crm/ (как до 1.4.0)', P::normalizeList(null), ['/crm/']);
Check::same('мусор — /crm/', P::normalizeList(['x', '']), ['/crm/']);
Check::same('строка — одно место', P::normalizeList('/crm/deal/'), ['/crm/deal/']);
Check::same('повтор с другим порядком параметров — одно место', P::normalizeList(['/a/?x=1&y=2', '/a/?y=2&x=1']), ['/a/?x=1&y=2']);
Check::same('не больше MAX_PER_ITEM', count(P::normalizeList(array_map(static fn(int $i): string => '/p'.$i.'/', range(1, 20)))), P::MAX_PER_ITEM);
Check::same('неправильные — для ошибки', P::invalid(['/ok/', 'bad', '//x']), ['bad', '//x']);

Check::group('совпадение адреса страницы');
Check::same('/crm/ — вся CRM', [P::matches('/crm/', '/crm/deal/details/5/'), P::matches('/crm/', '/crm'), P::matches('/crm/', '/company/')], [true, true, false]);
Check::same('/crm/deal — подкаталог, но не /crm/dealer/', [P::matches('/crm/deal', '/crm/deal/'), P::matches('/crm/deal', '/crm/deal'), P::matches('/crm/deal', '/crm/dealer/')], [true, true, false]);
$ai = '/shef/toolsai/view/?type=2&category=0';
Check::same('параметры места должны быть в адресе', P::matches($ai, '/shef/toolsai/view/?type=2&category=0'), true);
Check::same('порядок параметров не важен, лишние не мешают', P::matches($ai, '/shef/toolsai/view/?category=0&x=1&type=2'), true);
Check::same('другое значение — нет', P::matches($ai, '/shef/toolsai/view/?type=3&category=0'), false);
Check::same('нет параметра — нет', P::matches($ai, '/shef/toolsai/view/?type=2'), false);
Check::same('без параметров у места — любые', P::matches('/shef/toolsai/view/', '/shef/toolsai/view/?type=5'), true);
Check::same('# в адресе страницы не мешает', P::matches('/crm/', '/crm/deal/#x'), true);
Check::same('плохое место — нет', P::matches('crm', '/crm/'), false);
Check::same('хотя бы одно место', [P::matchesAny(['/a/', '/crm/'], '/crm/x/'), P::matchesAny([], '/crm/')], [true, false]);
$items = [
	['id' => 'old'],
	['id' => 'ai', 'places' => [$ai]],
	['id' => 'both', 'places' => ['/crm/lead/', '/shef/toolsai/view/']],
];
Check::same('filter: без мест — только CRM', array_column(P::filter($items, '/crm/lead/'), 'id'), ['old', 'both']);
Check::same('filter: вид ИИ с параметрами', array_column(P::filter($items, '/shef/toolsai/view/?type=2&category=0'), 'id'), ['ai', 'both']);
Check::same('filter: чужая страница — пусто', P::filter($items, '/company/'), []);
Check::same('объявление без мест получает /crm/', A::normalize(['id' => 'a', 'title' => 'A'])['places'], ['/crm/']);
Check::same('места объявления модуля проходят нормализацию', A::merge([], [['moduleId' => 'm', 'announcements' => [['id' => 'a', 'title' => 'A', 'places' => ['/x/', 'bad']]]]])['items'][0]['places'], ['/x/']);

Check::group('готовые места');
$cat = P::catalog([
	['moduleId' => 'acme', 'places' => [['path' => '/acme/', 'title' => 'Акме'], ['path' => '/crm/', 'title' => 'чужое'], ['path' => 'bad'], ['path' => '/no-title/']]],
	['moduleId' => '', 'places' => [['path' => '/anon/']]],
	['moduleId' => 'broken', 'places' => 'x'],
]);
$paths = array_column($cat, 'path');
Check::same('свои первыми', array_slice($paths, 0, count(P::BUILTIN)), array_keys(P::BUILTIN));
Check::same('место модуля добавлено, повтор и мусор — нет', array_slice($paths, count(P::BUILTIN)), ['/acme/', '/no-title/']);
Check::same('повтор — первое название', P::title('/crm/', $cat), 'Вся CRM');
Check::same('без названия — адрес', P::title('/no-title/', $cat), '/no-title/');
Check::same('свой адрес — сам адрес', P::title('/my/?a=1', $cat), '/my/?a=1');
Check::same('без модуля — не принят', in_array('/anon/', $paths, true), false);

Check::group('поиск в окне выбора');
Check::same('по названию', array_column(P::search('сдел', $cat), 'path'), ['/crm/deal/']);
$own = P::search('/shef/toolsai/view/?type=2', $cat);
Check::same('адрес — «свой адрес» первым', [$own[0]['path'], $own[0]['custom']], ['/shef/toolsai/view/?type=2', true]);
Check::same('адрес из готовых — не дублируется', array_column(P::search('/crm/deal/', $cat), 'custom'), [false]);
Check::same('не адрес — без своего', array_column(P::search('лид', $cat), 'custom'), [false]);

Check::group('сбор мест: событие');
\Bitrix\Main\EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementPlacesCollect'] = [
	'acme' => static fn() => new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, ['places' => [['path' => '/acme/', 'title' => 'Акме']]], 'acme'),
	'bad' => static fn() => throw new RuntimeException('упал'),
];
$collected = PlaceCollector::collect();
Check::same('место модуля из события, сбой другого не мешает', end($collected)['path'], '/acme/');
\Bitrix\Main\EventManager::$handlers = [];

Check::finish();

<?php declare(strict_types=1);

/**
 * «Оповещения для сотрудников» — без ядра: разбор списка, выбор объявления для
 * пользователя, отметка «видел», окно без подстановки HTML. Перенесено из
 * shef.toolsai (tests/announce_test.php) — поведение для сотрудника то же.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Announce\Announcements as A;

Check::group('список');
Check::same('пусто — пусто (объявления по умолчанию теперь от модулей)', A::parse(''), []);
Check::same('null — пусто', A::parse(null), []);
Check::same('битый JSON — пусто', A::parse('{'), []);
Check::same('не список — пусто', A::parse('"x"'), []);
$list = A::parse((string)json_encode([
	['id' => 'a', 'title' => 'A', 'audience' => 'all', 'active' => true, 'url' => 'javascript:alert(1)'],
	['id' => 'a', 'title' => 'повтор'],
	['id' => 'Bad id!', 'title' => 'x'],
	['id' => 'b', 'title' => ''],
	['id' => ['x'], 'title' => 'массив вместо id'],
	['id' => 'c', 'title' => 'C', 'active' => false],
]));
Check::same('повторы, плохой id и пустой заголовок — мимо', array_column($list, 'id'), ['a', 'c']);
Check::same('ссылка только https', $list[0]['url'], '');
Check::same('кнопка по умолчанию', $list[0]['linkText'], 'Подробнее');
Check::same('id приводится к строчным', A::normalize(['id' => 'NEW-1', 'title' => 'x'])['id'], 'new-1');
Check::same('не больше MAX_ITEMS', count(A::normalizeList(array_map(static fn(int $i): array => ['id' => 'n'.$i, 'title' => 'T'], range(1, 30)))), A::MAX_ITEMS);

Check::group('кому показать');
Check::same('тестовые пользователи', A::testUsers('1, 15 ;x,0'), [1, 15]);
Check::same('тестовые: старое «1» с портала', A::testUsers('1'), [1]);
Check::same('тестовые: JSON выбора сотрудников', A::testUsers('[{"id":1,"entityId":"user","title":"A"},{"id":"15","entityId":"user"},{"id":3,"entityId":"department"},{"id":"all-users","entityId":"meta-user"},{"id":1,"entityId":"user"}]'), [1, 15]);
Check::same('тестовые: экранированный JSON', A::testUsers('[{&quot;id&quot;:7,&quot;entityId&quot;:&quot;user&quot;}]'), [7]);
Check::same('тестовые: пустой JSON', A::testUsers('[]'), []);
Check::same('тестовые: битый JSON — никого', A::testUsers('[{"id":'), []);
Check::same('тестовые: пусто', A::testUsers(''), []);
Check::same('выбор: старое «1,15» → JSON Department', json_decode(A::testUsersSelectorValue('1,15', [1 => 'Админ']), true), [A::testUserRow(1, 'Админ'), A::testUserRow(15)]);
Check::same('выбор: после перевода разбор тот же', A::testUsers(A::testUsersSelectorValue('1, 15')), [1, 15]);
Check::same('выбор: JSON как есть', A::testUsersSelectorValue('[{"id":3,"entityId":"user"}]'), '[{"id":3,"entityId":"user"}]');
Check::same('выбор: пусто → []', A::testUsersSelectorValue(''), '[]');
require_once __DIR__.'/../default_option.php';
Check::same('по умолчанию — администратор в JSON Department', A::testUsers($shef_toolsaiannounce_default_option['ANNOUNCE_testusers']), [1]);
Check::same('по умолчанию — запись выбора', json_decode($shef_toolsaiannounce_default_option['ANNOUNCE_testusers'], true), [A::testUserRow(1)]);
$items = [
	['id' => 't', 'title' => 'T', 'audience' => 'test', 'active' => true],
	['id' => 'off', 'title' => 'O', 'audience' => 'all', 'active' => false],
	['id' => 'all', 'title' => 'All', 'audience' => 'all', 'active' => true],
];
Check::same('тестовому — тестовое первым', A::pick($items, [], 1, [1])['id'], 't');
Check::same('не тестовому — общее', A::pick($items, [], 7, [1])['id'], 'all');
Check::same('видел — следующее', A::pick($items, ['t'], 1, [1])['id'], 'all');
Check::same('всё видел — ничего', A::pick($items, ['t', 'all'], 1, [1]), null);
Check::same('гость — ничего', A::pick($items, [], 0, [1]), null);

Check::group('вид окна');
Check::same('по умолчанию — нарядное', A::normalize(['id' => 'a', 'title' => 'A'])['layout'], A::LAYOUT_COSMOS);
Check::same('слайды сохраняются', A::normalize(['id' => 'a', 'title' => 'A', 'layout' => 'slides'])['layout'], A::LAYOUT_SLIDES);
Check::same('мусор — нарядное', A::normalize(['id' => 'a', 'title' => 'A', 'layout' => 'x'])['layout'], A::LAYOUT_COSMOS);
$mix = [
	A::normalize(['id' => 's1', 'title' => 'S1', 'audience' => 'all', 'active' => true, 'layout' => 'slides']),
	A::normalize(['id' => 'c1', 'title' => 'C1', 'audience' => 'all', 'active' => true]),
	A::normalize(['id' => 's2', 'title' => 'S2', 'audience' => 'all', 'active' => true, 'layout' => 'slides']),
];
$plan = A::plan($mix, [], 5, []);
Check::same('слайды первыми — все слайды разом', [$plan['layout'], array_column($plan['items'], 'id')], ['slides', ['s1', 's2']]);
$plan = A::plan($mix, ['s1', 's2'], 5, []);
Check::same('нарядное — одно', [$plan['layout'], array_column($plan['items'], 'id')], ['cosmos', ['c1']]);
Check::same('всё видел — ничего', A::plan($mix, ['s1', 's2', 'c1'], 5, []), null);

Check::group('отметка «видел»');
Check::same('без повторов, новое в конце', A::markSeen(['a', 'b'], 'a'), ['b', 'a']);
Check::same('не больше лимита', count(A::markSeen(array_map('strval', range(1, A::MAX_SEEN)), 'x')), A::MAX_SEEN);

Check::group('окно');
$one = ['id' => 'x', 'title' => '<b>оп</b>', 'text' => "a\n• b", 'url' => 'https://e.by/', 'linkText' => 'Подробнее'];
$js = A::script(['layout' => A::LAYOUT_COSMOS, 'items' => [$one]]);
Check::same('через очередь баннеров', str_contains($js, 'ui.banner-dispatcher'), true);
Check::same('слайды — штатное «Что нового»', str_contains(A::script(['layout' => A::LAYOUT_SLIDES, 'items' => [$one]]), 'ui.dialogs.whats-new'), true);
Check::same('HTML из настроек не попадает как есть', str_contains($js, '<b>'), false);
Check::same('своя картинка в данных окна (теги экранированы), без файлов ядра', [str_contains($js, 'sa-news'), str_contains($js, '<svg'), str_contains($js, '/bitrix/js/')], [true, false, false]);
Check::same('отметка при показе — своим действием', str_contains($js, "BX.ajax.runAction(\"shef:toolsaiannounce.banner.seen\""), true);
Check::same('старого действия shef.toolsai нет', str_contains($js, 'toolsai.kb.seen'), false);
$slides = A::script(['layout' => A::LAYOUT_SLIDES, 'items' => [$one]]);
Check::same('слайды: заголовок экранируется (ядро вставляет его через innerHTML)', str_contains($slides, 'title: esc(a.title)'), true);
Check::same('слайды: сырого заголовка нет', str_contains($slides, 'title: a.title'), false);
Check::same('без </script> внутри', stripos($js, '</script') === false, true);
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if($node !== '')
{
	$file = tempnam(sys_get_temp_dir(), 'ann').'.js';
	file_put_contents($file, $js);
	exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $out, $code);
	unlink($file);
	Check::same('node --check', $code, 0);
}

Check::finish();

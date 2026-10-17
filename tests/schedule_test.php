<?php declare(strict_types=1);

/**
 * Что показать (Announcements::plan/isDue/isFor) с полями 1.4.0: период,
 * повтор, порядок, аудитория «выбранные сотрудники и отделы», новые
 * сотрудники и дата публикации; виды окна; совместимость старых объявлений.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Announce\Announcements as A;

$now = (int)strtotime('2026-10-17 12:00:00');
$day = 86400;
$n = static fn(array $f): array => A::normalize($f + ['id' => 'a', 'title' => 'A', 'active' => true, 'audience' => 'all']);

Check::group('старые объявления');
$old = A::normalize(['id' => 'old', 'title' => 'Старое', 'audience' => 'all', 'active' => true, 'layout' => 'cosmos']);
Check::same('новые поля — по умолчанию', [$old['places'], $old['repeat'], $old['dateFrom'], $old['dateTo'], $old['sort'], $old['image'], $old['video'], $old['published'], $old['showToNew'], $old['buttonColor']],
	[['/crm/'], 'once', '', '', 500, 0, '', 0, false, 'violet']);
Check::same('старое показывается как раньше', A::plan([$old], [], 5, [], ['now' => $now])['items'][0]['id'], 'old');
Check::same('видел — нет', A::plan([$old], ['old'], 5, [], ['now' => $now]), null);

Check::group('период');
Check::same('до начала — нет', A::isDue($n(['dateFrom' => '2026-10-18']), false, ['now' => $now]), false);
Check::same('в день начала — да', A::isDue($n(['dateFrom' => '2026-10-17']), false, ['now' => $now]), true);
Check::same('в день окончания — да (до конца дня)', A::isDue($n(['dateTo' => '2026-10-17']), false, ['now' => $now]), true);
Check::same('после окончания — нет', A::isDue($n(['dateTo' => '2026-10-16']), false, ['now' => $now]), false);
Check::same('кривая дата — без периода', $n(['dateFrom' => '2026-13-45'])['dateFrom'], '');
Check::same('выключено — нет', A::isDue($n(['active' => false]), false, ['now' => $now]), false);

Check::group('повтор');
$until = $n(['repeat' => 'until']);
Check::same('пока не нажмёт: показали, не нажал — снова', A::isDue($until, true, ['now' => $now, 'stamps' => ['a' => ['t' => $now - 10, 'ok' => false]]]), true);
Check::same('пока не нажмёт: нажал — нет', A::isDue($until, true, ['now' => $now, 'stamps' => ['a' => ['t' => $now - 10, 'ok' => true]]]), false);
$days = $n(['repeat' => 'days', 'repeatDays' => 3]);
Check::same('раз в 3 дня: не показывали — да', A::isDue($days, false, ['now' => $now]), true);
Check::same('раз в 3 дня: 2 дня назад — нет', A::isDue($days, true, ['now' => $now, 'stamps' => ['a' => ['t' => $now - 2 * $day, 'ok' => true]]]), false);
Check::same('раз в 3 дня: 3 дня назад — да', A::isDue($days, true, ['now' => $now, 'stamps' => ['a' => ['t' => $now - 3 * $day, 'ok' => true]]]), true);
Check::same('дней — от 1 до 365', [$n(['repeatDays' => 0])['repeatDays'], $n(['repeatDays' => 9999])['repeatDays']], [1, 365]);
Check::same('мусор — один раз', $n(['repeat' => 'x'])['repeat'], 'once');
$st = A::markStamp([], 'a', 100, false);
$st = A::markStamp($st, 'a', 200, true);
Check::same('отметка: время и «Понятно»', $st, ['a' => ['t' => 200, 'ok' => true]]);
Check::same('«Понятно» не снимается следующим показом', A::markStamp($st, 'a', 300, false), ['a' => ['t' => 300, 'ok' => true]]);
Check::same('отметок не больше MAX_SEEN', count(array_reduce(range(1, A::MAX_SEEN + 5), static fn(array $s, int $i): array => A::markStamp($s, 'x'.$i, $i, false), [])), A::MAX_SEEN);

Check::group('порядок');
$list = [$n(['id' => 'late', 'sort' => 900]), $n(['id' => 'first', 'sort' => 10]), $n(['id' => 'mid'])];
Check::same('по sort, при равном — как в списке', array_column(A::sorted($list), 'id'), ['first', 'mid', 'late']);
Check::same('первым показывается меньший sort', A::pick($list, [], 5, [], ['now' => $now])['id'], 'first');

Check::group('аудитория');
$custom = $n(['audience' => 'custom', 'users' => [7, '8', 'x', -1], 'departments' => [3]]);
Check::same('выбранные: ID чистятся', [$custom['users'], $custom['departments']], [[7, 8], [3]]);
Check::same('выбранный сотрудник — да', A::isFor($custom, 7, [], []), true);
Check::same('сотрудник отдела — да', A::isFor($custom, 9, [], ['departments' => [5, 3]]), true);
Check::same('остальные — нет', A::isFor($custom, 9, [], ['departments' => [5]]), false);
Check::same('тестовые — только тестовым', [A::isFor($n(['audience' => 'test']), 1, [1]), A::isFor($n(['audience' => 'test']), 2, [1])], [true, false]);

Check::group('новые сотрудники');
$pub = $n(['published' => $now - 10 * $day]);
Check::same('пришёл до публикации — видит', A::isFor($pub, 5, [], ['registered' => $now - 20 * $day]), true);
Check::same('пришёл после — не видит', A::isFor($pub, 5, [], ['registered' => $now - $day]), false);
Check::same('флажок «и новым» — видит', A::isFor($n(['published' => $now - 10 * $day, 'showToNew' => true]), 5, [], ['registered' => $now - $day]), true);
Check::same('нет даты регистрации — видит', A::isFor($pub, 5, [], []), true);
Check::same('видео — «и новым» по умолчанию', [$n(['layout' => 'video'])['showToNew'], $n(['layout' => 'video', 'showToNew' => false])['showToNew']], [true, false]);
Check::same('дата публикации строкой из события', $n(['published' => '2026-10-01'])['published'], (int)strtotime('2026-10-01'));
$f = A::fillPublished([$old, $pub, $n(['id' => 'off', 'active' => false])], ['old' => $now - 5 * $day], $now);
Check::same('без даты — из журнала первого появления', $f['items'][0]['published'], $now - 5 * $day);
Check::same('своя дата не трогается, выключенное ждёт включения', [$f['items'][1]['published'], $f['items'][2]['published'], $f['changed']], [$now - 10 * $day, 0, false]);
$g = A::fillPublished([$n(['id' => 'new-one'])], [], $now);
Check::same('впервые увидели — сейчас, в журнал', [$g['items'][0]['published'], $g['firstSeen'], $g['changed']], [$now, ['new-one' => $now], true]);
Check::same('старое после обновления видят все, кто уже работал', A::isFor($g['items'][0], 5, [], ['registered' => $now - 400 * $day]), true);
Check::same('публикация при сохранении: включено — сейчас, выключено — нет, была — та же',
	[A::publishedOnSave(['active' => true], null, $now), A::publishedOnSave(['active' => false], null, $now), A::publishedOnSave(['active' => true], ['published' => 5], $now)], [$now, 0, 5]);

Check::group('виды окна');
foreach(['important', 'danger', 'congrats', 'video'] as $layout)
{
	$item = $n(['id' => 'v', 'layout' => $layout, 'video' => 'https://youtu.be/dQw4w9WgXcQ']);
	$plan = A::plan([$item], [], 5, [], ['now' => $now]);
	Check::same($layout.': одно окно своего вида', [$plan['layout'], count($plan['items'])], [$layout, 1]);
}
$js = A::script(['layout' => 'important', 'items' => [$n(['id' => 'i'])]]);
Check::same('важное: без крестика и Esc', [str_contains($js, 'strict: true'), str_contains($js, 'closeByEsc: !k.strict')], [true, true]);
$video = A::script(['layout' => 'video', 'items' => [$n(['id' => 'v', 'layout' => 'video', 'video' => 'https://youtu.be/dQw4w9WgXcQ'])]]);
Check::same('видео: плеер из белого списка', str_contains($video, 'youtube-nocookie.com\/embed\/dQw4w9WgXcQ'), true);
Check::same('видео: чужая ссылка не сохраняется', $n(['layout' => 'video', 'video' => 'https://evil.by/x'])['video'], '');
$img = A::script(['layout' => 'cosmos', 'items' => [$n(['id' => 'c']) + ['imageSrc' => '/upload/a.png']]]);
Check::same('своя картинка — путь портала', str_contains($img, '\/upload\/a.png'), true);
Check::same('картинка с javascript: — не попадает', A::imageSrc('javascript:alert(1)'), '');
Check::same('цвет кнопки — из списка', $n(['buttonColor' => 'red;x'])['buttonColor'], 'violet');
Check::same('нажатие кнопки ставит «Понятно»', str_contains($js, "ok: 'Y'"), true);

Check::group('проверка формы');
Check::same('всё хорошо — пусто', A::validate(['title' => 'A', 'places' => ['/crm/'], 'url' => 'https://a.by/']), []);
$errors = A::validate(['title' => '', 'url' => 'http://a', 'places' => ['crm'], 'dateFrom' => '2026-10-10', 'dateTo' => '2026-10-01', 'layout' => 'video', 'audience' => 'custom']);
Check::same('ошибки по-русски, все сразу', count($errors), 6);
Check::same('ошибка места называет адрес', (bool)array_filter($errors, static fn(string $e): bool => str_contains($e, 'crm')), true);
Check::same('кривая дата', A::validate(['title' => 'A', 'dateFrom' => '17.10.2026']) !== [], true);
Check::same('видео не из списка', A::validate(['title' => 'A', 'video' => 'https://evil.by/v']) !== [], true);

Check::group('свой список');
$own = [['id' => 'a'], ['id' => 'b']];
Check::same('upsert: замена на месте', array_column(A::upsert($own, ['id' => 'a', 'x' => 1]), 'x'), [1]);
Check::same('upsert: новое — в конец', array_column(A::upsert($own, ['id' => 'c']), 'id'), ['a', 'b', 'c']);
Check::same('upsert: больше MAX_ITEMS — null', A::upsert(array_map(static fn(int $i): array => ['id' => 'n'.$i], range(1, A::MAX_ITEMS)), ['id' => 'x']), null);
Check::same('remove', array_column(A::remove($own, 'a'), 'id'), ['b']);
Check::same('stored: без source и overrides', A::stored(['id' => 'a', 'source' => 'm', 'overrides' => 'm']), ['id' => 'a']);

Check::finish();

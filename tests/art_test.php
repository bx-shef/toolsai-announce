<?php declare(strict_types=1);

/**
 * Картинки окна (1.5.0): у каждого кода своя картинка, выбор по виду окна,
 * поле art у объявления, в скрипт окна попадает SVG и стили анимаций.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Announce\Announcements as A;
use Shef\ToolsAiAnnounce\Announce\Art;

Check::group('картинки');
foreach(array_keys(Art::ARTS) as $code)
{
	if($code === '')
	{
		continue;
	}
	$svg = Art::svg($code);
	Check::same($code.': своя картинка', str_contains($svg, 'sa-'.$code.'"'), true);
	Check::same($code.': SVG разбирается', (new DOMDocument())->loadXML($svg) !== false, true);
	Check::same($code.': без скриптов и ссылок наружу', preg_match('/<script|on[a-z]+=|href|xlink|url\((?!#)/i', $svg), 0);
}
Check::same('неизвестная — новинка', Art::svg('nope'), Art::svg('news'));
foreach(['build', 'meeting', 'library'] as $code)
{
	Check::same($code.': анимирована', preg_match('/class="sa-(dozer|bar|page)/', Art::svg($code)) === 1, true);
}
Check::same('анимации стоят при «уменьшить движение»', str_contains(Art::css(), 'prefers-reduced-motion:reduce'), true);

Check::group('выбор');
Check::same('по виду окна', array_map(static fn(string $l): string => Art::pick(['layout' => $l]), ['cosmos', 'important', 'danger', 'congrats', 'video']), ['news', 'bell', 'alert', 'congrats', 'news']);
Check::same('своя важнее вида', Art::pick(['layout' => 'danger', 'art' => 'library']), 'library');
Check::same('чужой код — по виду', Art::pick(['layout' => 'danger', 'art' => 'x']), 'alert');
Check::same('поле art: допустимое сохраняется', A::normalize(['id' => 'a', 'title' => 'T', 'art' => 'build'])['art'], 'build');
Check::same('поле art: чужое — пусто', A::normalize(['id' => 'a', 'title' => 'T', 'art' => '<svg>'])['art'], '');
Check::same('старое объявление — пусто', A::normalize(['id' => 'a', 'title' => 'T'])['art'], '');

Check::group('скрипт окна');
$item = A::normalize(['id' => 'a', 'title' => 'T', 'art' => 'meeting']);
$js = A::script(['layout' => 'cosmos', 'items' => [$item]]);
Check::same('картинка в данных, теги экранированы', str_contains($js, 'sa-meeting') && !str_contains($js, '<svg'), true);
Check::same('стили анимаций подключены', str_contains($js, 'CSS + P.css') && str_contains($js, 'saDrive'), true);

Check::finish();

<?php declare(strict_types=1);

/**
 * Страница списка без ядра: строка таблицы (ListView::row) — бейджи «Откуда»,
 * «Кому», «Показ», набор кнопок; название страницы и место пункта меню.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Announce\Announcements as A;
use Shef\ToolsAiAnnounce\Announce\ListView as V;
use Shef\ToolsAiAnnounce\Main\Constants;

$base = ['id' => 'a', 'title' => 'Заголовок', 'audience' => A::AUDIENCE_TEST, 'layout' => A::LAYOUT_COSMOS, 'active' => true, 'source' => A::SOURCE_SETTINGS];

Check::group('откуда');
$own = V::row($base, true);
Check::same('своё — «настройки», основной цвет', $own['source'], ['text' => 'настройки', 'class' => V::LABEL_SETTINGS]);
$over = V::row($base + ['overrides' => 'shef.toolsai'], true);
Check::same('копия модульного — «вместо модуля»', $over['source'], ['text' => 'настройки, вместо shef.toolsai', 'class' => V::LABEL_OVERRIDE]);
$mod = V::row(['source' => 'shef.toolsai'] + $base, true);
Check::same('модульное — имя модуля', $mod['source'], ['text' => 'модуль shef.toolsai', 'class' => V::LABEL_MODULE]);

Check::group('кому и показ');
Check::same('тестовые', $own['audience'], ['text' => 'тестовые пользователи', 'class' => V::LABEL_AUDIENCE_TEST]);
Check::same('все', V::row(['audience' => A::AUDIENCE_ALL] + $base, true)['audience'], ['text' => 'все сотрудники', 'class' => V::LABEL_AUDIENCE_ALL]);
Check::same('вид окна', V::row(['layout' => A::LAYOUT_SLIDES] + $base, true)['layout']['text'], 'слайды «Что нового»');
Check::same('включён — зелёный', $own['active'], ['text' => 'включён', 'class' => V::LABEL_ON]);
Check::same('выключен — серый', V::row(['active' => false] + $base, true)['active'], ['text' => 'выключен', 'class' => V::LABEL_OFF]);
Check::same('неизвестный код — как есть', V::row(['audience' => 'x'] + $base, true)['audience']['text'], 'x');

Check::group('кнопки');
Check::same('своё — «Удалить» с подтверждением', $own['delete']['button'], 'Удалить');
Check::same('подтверждение не пустое', $own['delete']['confirm'] !== '', true);
Check::same('копия — «Вернуть как в модуле»', $over['delete']['button'], 'Вернуть как в модуле');
Check::same('модульное удалить нельзя', $mod['delete'], null);
$ro = V::row($base, false);
Check::same('без права записи — ни правки, ни удаления', [$ro['canEdit'], $ro['delete']], [false, null]);
Check::same('id и заголовок — строки как есть (экранирует страница)', [V::row(['title' => '<b>'] + $base, true)['title'], $own['id']], ['<b>', 'a']);

Check::group('меню «…» и места (1.4.0)');
Check::same('своё: одинаковый набор, одно место', array_column($own['menu'], 'action'), ['again', 'toggle', 'delete']);
Check::same('модульное: без удаления', array_column($mod['menu'], 'action'), ['again', 'toggle']);
Check::same('без права записи — только «Показать мне снова»', array_column($ro['menu'], 'action'), ['again']);
Check::same('включено — пункт «Выключить»', $own['menu'][1]['text'], 'Выключить');
Check::same('удаление — с подтверждением', $own['menu'][2]['confirm'], $own['delete']['confirm']);
Check::same('места — названиями', V::row(['places' => ['/crm/deal/', '/my/']] + $base, true, [['path' => '/crm/deal/', 'title' => 'CRM: сделки']])['places'], ['CRM: сделки', '/my/']);
Check::same('без мест — /crm/', $own['places'], ['/crm/']);
Check::same('подробности: период, повтор, новые', V::row(['dateFrom' => '2026-10-01', 'repeat' => 'days', 'repeatDays' => 3, 'showToNew' => true] + $base, true)['details'], ['с 2026-10-01', 'раз в 3 дн.', 'и новым сотрудникам']);
Check::same('форма — без служебных ключей', array_key_exists('source', $own['form']), false);
Check::same('картинка — путь через переданную функцию', V::rows([['image' => 5] + $base], true, [], static fn(int $id): string => '/upload/'.$id.'.png')[0]['image'], '/upload/5.png');
Check::same('тестовые — по именам', V::testUsersText([1, 15], [1 => 'Иван Петров']), 'Иван Петров, #15');
Check::same('тестовых нет — «никого»', V::testUsersText([], []), 'никого');

Check::group('название и меню');
Check::same('префикс [SH]', Constants::TITLE, '[SH] Оповещения для сотрудников');
$menu = (string)file_get_contents($root.'/admin/menu.php');
Check::same('пункт меню — сразу под «Избранным» (50) и до «Пользователей» (100)', (bool)preg_match("/'sort' => 60,/", $menu), true);
Check::same('в меню — название с [SH]', str_contains($menu, "'[SH] Оповещения для сотрудников'"), true);
$lang = (string)file_get_contents($root.'/lang/ru/options.php');
Check::same('заголовок вкладки настроек — с [SH]', str_contains($lang, "_TAB_ANNOUNCE_TITLE'] = '[SH] Оповещения для сотрудников'"), true);
Check::same('страница списка берёт заголовок из Constants::TITLE', str_contains((string)file_get_contents($root.'/admin/list.php'), 'SetTitle(Constants::TITLE)'), true);

Check::finish();

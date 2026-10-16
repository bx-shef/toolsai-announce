<?php declare(strict_types=1);

/**
 * Перенос из shef.toolsai при установке (Main\Migration): настройки — только
 * если свои пусты; отметки «видел» — объединением, повтор ничего не портит.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAnnounce\Announce\Announcements as A;
use Shef\ToolsAiAnnounce\Main\Migration;

Check::group('решение по настройке');
Check::same('своё пусто, старое есть — берём', A::migrateOption(null, '5, 7'), '5, 7');
Check::same('своё пустая строка — берём', A::migrateOption(' ', '[]'), '[]');
Check::same('своё есть — не трогаем', A::migrateOption('1', '5'), null);
Check::same('старого нет — не трогаем', A::migrateOption(null, null), null);
Check::same('старое пусто — не трогаем', A::migrateOption(null, ''), null);

Check::group('отметки');
Check::same('объединение, свои последними', A::mergeSeen(['b', 'c'], ['a', 'b']), ['a', 'b', 'c']);
Check::same('мусор отброшен', A::mergeSeen(['ok', ['x'], 'Bad!'], 'не массив'), ['ok']);
Check::same('не больше лимита', count(A::mergeSeen(array_map(static fn(int $i): string => 'n'.$i, range(1, 150)), array_map(static fn(int $i): string => 'o'.$i, range(1, 150)))), A::MAX_SEEN);

Check::group('перенос целиком');
Option::$values = [
	'shef.toolsai' => ['ANNOUNCE_list' => '[{"id":"x","title":"X"}]', 'ANNOUNCE_testusers' => '1, 15'],
	'shef.toolsaiannounce' => ['ANNOUNCE_testusers' => '3'],
];
\CUserOptions::$values = [
	'shef.toolsai' => ['announce_seen' => [1 => ['calls-ai-2026-10'], 2 => ['a', 'b'], 3 => serialize([]), 4 => 'O:8:"stdClass":0:{}']],
	'shef.toolsaiannounce' => ['announce_seen' => [2 => ['b', 'c']]],
];
$report = Migration::run();
Check::same('настройки: только пустые свои', $report['options'], ['ANNOUNCE_list']);
Check::same('список перенесён как есть', Option::get('shef.toolsaiannounce', 'ANNOUNCE_list'), '[{"id":"x","title":"X"}]');
Check::same('свои тестовые не тронуты', Option::get('shef.toolsaiannounce', 'ANNOUNCE_testusers'), '3');
Check::same('отметки пользователя 1', \CUserOptions::GetOption('shef.toolsaiannounce', 'announce_seen', [], 1), ['calls-ai-2026-10']);
Check::same('отметки пользователя 2 объединены', \CUserOptions::GetOption('shef.toolsaiannounce', 'announce_seen', [], 2), ['a', 'b', 'c']);
Check::same('пустые и объекты не переносятся', [\CUserOptions::GetOption('shef.toolsaiannounce', 'announce_seen', 'нет', 3), \CUserOptions::GetOption('shef.toolsaiannounce', 'announce_seen', 'нет', 4)], ['нет', 'нет']);
Check::same('отчёт: пользователей', $report['users'], 2);
Check::same('старое не удалено', \CUserOptions::GetOption('shef.toolsai', 'announce_seen', [], 1), ['calls-ai-2026-10']);
$again = Migration::run();
Check::same('повтор — ничего нового', $again, ['options' => [], 'users' => 0]);

Check::finish();

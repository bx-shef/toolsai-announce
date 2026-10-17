<?php declare(strict_types=1);

/**
 * Контроллер страницы списка (Controller\Announcement) на заглушках ядра:
 * права модуля, проверка на сервере, своя копия модульного объявления,
 * включить/выключить, удалить, «Показать мне снова»; фильтры и запрет run().
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAnnounce\Announce\OwnStore;
use Shef\ToolsAiAnnounce\Announce\SeenStore;
use Shef\ToolsAiAnnounce\Controller\Announcement;
use Shef\ToolsAiAnnounce\Main\Constants;

$make = static function(string $right): Announcement
{
	Controller::$userId = 3;

	return new class($right) extends Announcement
	{
		public function __construct(private readonly string $r) {}

		protected function right(): string
		{
			return $this->r;
		}
	};
};
$messages = static fn(Announcement $c): array => array_map(static fn($e): string => $e->getMessage(), $c->errors);
Option::$values = [];
Cache::$store = [];
EventManager::$handlers['shef.toolsaiannounce']['OnAnnouncementsCollect'] = [
	'shef.toolsai' => static fn() => new EventResult(EventResult::SUCCESS, ['announcements' => [['id' => 'mod-1', 'title' => 'Модульное', 'active' => true, 'audience' => 'all']]], 'shef.toolsai'),
];

Check::group('права');
$c = $make('R');
Check::same('R: список можно', array_column($c->listAction()['rows'], 'id'), ['mod-1']);
Check::same('R: строки без правки', $c->listAction()['rows'][0]['canEdit'], false);
$c = $make('R');
Check::same('R: сохранить нельзя', [$c->saveAction(['title' => 'x']), count($c->errors)], [null, 1]);
Check::same('R: удалить и переключить нельзя', [$make('R')->deleteAction('mod-1'), $make('R')->toggleAction('mod-1')], [null, null]);
$c = $make('D');
Check::same('D: даже список нельзя', [$c->listAction(), $messages($c)], [null, ['Нет доступа.']]);

Check::group('сохранение');
$c = $make('W');
Check::same('ошибки проверки — в окно', [$c->saveAction(['title' => '', 'url' => 'http://x']), count($c->errors)], [null, 2]);
$c = $make('W');
$r = $c->saveAction(['title' => 'Новое', 'places' => ['/crm/deal/'], 'active' => 'Y', 'audience' => 'all']);
Check::same('новое — в свой список, ответ — свежие строки', [count($r['rows']), $c->errors], [2, []]);
$new = OwnStore::load()[0];
Check::same('новое: id n-…, место, опубликовано', [str_starts_with($new['id'], 'n-'), $new['places'], $new['published'] > 0], [true, ['/crm/deal/'], true]);
$c = $make('W');
$c->saveAction(['id' => $new['id'], 'title' => 'Новое 2', 'active' => 'Y']);
Check::same('правка: тот же id, дата публикации та же', [count(OwnStore::load()), OwnStore::load()[0]['title'], OwnStore::load()[0]['published']], [1, 'Новое 2', $new['published']]);
$c = $make('W');
Check::same('чужой файл картинки — без CFile не принимается', [$c->saveAction(['title' => 'X', 'image' => 77]), count($c->errors)], [null, 1]);

Check::group('модульное: копия, переключение, возврат');
$c = $make('W');
$rows = $c->toggleAction('mod-1')['rows'];
$mod = array_values(array_filter($rows, static fn(array $r): bool => $r['id'] === 'mod-1'))[0];
Check::same('выключить модульное — своя копия «вместо»', [$mod['active']['text'], $mod['source']['text']], ['выключен', 'настройки, вместо shef.toolsai']);
Check::same('в копии нет служебных ключей', array_key_exists('source', OwnStore::load()[1]), false);
Check::same('меню копии — «Вернуть как в модуле»', array_column($mod['menu'], 'action'), ['again', 'toggle', 'delete']);
$c = $make('W');
$rows = $c->deleteAction('mod-1')['rows'];
$mod = array_values(array_filter($rows, static fn(array $r): bool => $r['id'] === 'mod-1'))[0];
Check::same('вернуть — снова модульное и включено', [$mod['source']['text'], $mod['active']['text']], ['модуль shef.toolsai', 'включён']);
$c = $make('W');
Check::same('нет такого — ошибка', [$c->toggleAction('nope'), $messages($c)], [null, ['Объявление не найдено. Обновите страницу.']]);
$c = $make('W');
Check::same('плохой id — ошибка', [$c->deleteAction('<x>'), count($c->errors)], [null, 1]);

Check::group('показать мне снова');
\CUserOptions::$values = [];
SeenStore::mark(3, 'mod-1', true);
Check::same('снимает обе отметки у себя', [$make('R')->againAction('mod-1'), SeenStore::get(3), SeenStore::stamps(3)], [['ok' => true], [], []]);

Check::group('фильтры и устройство');
$filters = (new class extends Announcement { public function f(): array { return $this->getDefaultPreFilters(); } })->f();
Check::same('авторизация, POST, CSRF', array_map('get_class', $filters), [
	'Bitrix\\Main\\Engine\\ActionFilter\\Authentication',
	'Bitrix\\Main\\Engine\\ActionFilter\\HttpMethod',
	'Bitrix\\Main\\Engine\\ActionFilter\\Csrf',
]);
Check::same('только POST', $filters[1]->methods, ['POST']);
$own = static fn(string $class): array => array_values(array_map(static fn(ReflectionMethod $m): string => $m->getName(), array_filter((new ReflectionClass($class))->getMethods(), static fn(ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class)));
Check::same('run() не объявлен (в ядре он final)', [in_array('run', $own(Announcement::class), true), in_array('run', $own(\Shef\ToolsAiAnnounce\Controller\Banner::class), true)], [false, false]);
Check::same('действия', array_values(array_filter($own(Announcement::class), static fn(string $m): bool => str_ends_with($m, 'Action'))), ['listAction', 'againAction', 'saveAction', 'toggleAction', 'deleteAction', 'uploadImageAction']);
Check::same('префикс действий', Constants::ACTION_PREFIX, 'shef:toolsaiannounce.announcement.');
$settings = require $root.'/.settings.php';
$entity = $settings['ui.entity-selector']['value']['entities'][0];
Check::same('своя сущность окна выбора мест', [$entity['entityId'], $entity['provider']['moduleId']], [Constants::PLACE_ENTITY, Constants::MODULE_ID]);
$provider = (string)file_get_contents($root.'/lib/integration/ui/entityselector/placeprovider.php');
Check::same('класс провайдера лежит по соглашению', str_contains($provider, 'class PlaceProvider extends BaseProvider') && ltrim($entity['provider']['className'], '\\') === 'Shef\\ToolsAiAnnounce\\Integration\\Ui\\EntitySelector\\PlaceProvider', true);
EventManager::$handlers = [];

Check::finish();

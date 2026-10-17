# Событие OnAnnouncementsCollect

> Последняя сверка: 2026-10-17

Как модулю прислать свои объявления «Новинок для сотрудников». Событие D7
(`Bitrix\Main\Event`), шлёт его модуль `shef.toolsaiannounce` при сборе
списка для страниц портала (итог в кеше) и при открытии страницы списка.

* Модуль: `shef.toolsaiannounce`, событие: `OnAnnouncementsCollect`.
* Класс события: `Shef\ToolsAiAnnounce\Public\Event\OnAnnouncementsCollectEvent`
  (без параметров).
* Ответ: `EventResult::SUCCESS`, параметры — `['announcements' => [...]]`,
  **третий аргумент — id модуля-источника** (без него ответ не принимается).
  Ответы `ERROR` и не-`EventResult` пропускаются.
* Модули зовутся **по одному** (фильтр события по модулю назначения,
  `TO_MODULE_ID`): исключение в обработчике одного модуля уходит в лог, ответы
  остальных остаются. Поэтому подписка — только постоянная, с модулем
  назначения (`registerEventHandler`); обработчик, добавленный
  `addEventHandler` без модуля, не зовётся.
* Итог кешируется на 10 минут (см. [01](01-announcements.md#кеш)).

## Объявление

| поле | что | по умолчанию |
|---|---|---|
| `id` | `^[a-z0-9][a-z0-9-]{0,39}$`, приводится к строчным; новый id — новый показ всем | обязательно |
| `title` | заголовок, до 120 символов | обязательно |
| `text` | текст до 2000 символов; строка с «• » — пункт списка | пусто |
| `url` | ссылка, только `https://` | без кнопки |
| `linkText` | текст кнопки, до 60 | «Подробнее» |
| `audience` | `test` — тестовым пользователям, `all` — всем, `custom` — сотрудникам `users` и отделам `departments` (списки ID) | `test` |
| `active` | показывать | `false` |
| `layout` | `cosmos` — обычное окно, `important` — важное, `danger` — предупреждение, `congrats` — поздравление, `video` — обучающее видео, `slides` — слайды «Что нового» | `cosmos` |
| `places` | где показывать: список адресов от корня сайта, можно с параметрами (`/shef/toolsai/view/?type=2`), до 10 | `['/crm/']` |
| `video` | для `video`: https-ссылка YouTube, Rutube, VK Видео или `.mp4` с портала (`/upload/…`) | пусто |
| `dateFrom`, `dateTo` | период показа, `ГГГГ-ММ-ДД` включительно | без периода |
| `sort` | порядок, 0–9999, меньше — раньше | 500 |
| `repeat`, `repeatDays` | `once`, `until` (пока не нажмёт «Понятно»), `days` (раз в `repeatDays` дней, 1–365) | `once`, 7 |
| `buttonColor` | `violet`, `blue`, `green`, `orange`, `red` | `violet` |
| `published` | дата публикации: метка времени или `ГГГГ-ММ-ДД` | первое появление в списке |
| `showToNew` | показывать сотрудникам, пришедшим после `published` | `false` (у `video` — `true`) |

Своей картинки у объявления модуля нет — она загружается только на странице
списка (поле `image` — id файла этого модуля).

## Правила слияния

1. Сначала — свой список из настроек модуля (`ANNOUNCE_list`), потом
   объявления модулей в порядке ответов.
2. Объявление модуля с тем же id, что в своём списке, заменяется своим.
3. Совпали id у двух модулей — побеждает первый ответ, остальное — в лог.

## Пример: модуль-источник

Подписка — в установщике модуля-источника, парное снятие — при удалении
(правило линейки: `registerEventHandler` / `unRegisterEventHandler`). Модуля
`shef.toolsaiannounce` нет — событие просто никто не шлёт.

```php
// install/index.php модуля acme.demo
\Bitrix\Main\EventManager::getInstance()->registerEventHandler(
	'shef.toolsaiannounce',
	'OnAnnouncementsCollect',
	'acme.demo',
	\Acme\Demo\Internals\Integration\Shef\ToolsAiAnnounce\EventHandler\OnAnnouncementsCollectEventHandler::class,
	'handle',
);
```

```php
namespace Acme\Demo\Internals\Integration\Shef\ToolsAiAnnounce\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class OnAnnouncementsCollectEventHandler
{
	// Аргумент — Bitrix\Main\Event: класс события есть только там, где стоит shef.toolsaiannounce.
	public static function handle(Event $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, ['announcements' => [[
			'id' => 'demo-2026-10',
			'title' => 'Новое в демо',
			'text' => "Коротко, что появилось.\n• первое\n• второе",
			'url' => 'https://example.com/demo',
			'linkText' => 'Подробнее',
			'audience' => 'test',
			'active' => true,
			'layout' => 'cosmos',
		]]], 'acme.demo');
	}
}
```

Обработчик зовётся при сборе списка (итог кешируется на 10 минут) — держите
его дешёвым: без запросов к базе и внешних вызовов.

## Событие OnAnnouncementPlacesCollect

Готовые места для поля «Где показывать» (с 1.4.0). Зовётся только на странице
списка и в окне выбора мест.

* Модуль: `shef.toolsaiannounce`, событие: `OnAnnouncementPlacesCollect`,
  класс — `Shef\ToolsAiAnnounce\Public\Event\OnAnnouncementPlacesCollectEvent`.
* Ответ: `EventResult::SUCCESS`, `['places' => [['path' => '/acme/', 'title' => 'Акме']]]`,
  третий аргумент — id модуля. `path` — адрес от корня сайта (с «/», без
  пробелов и кавычек), `title` — до 80 символов. Повтор адреса — остаётся
  первое название. Порядок приоритета: встроенные места модуля → ответы
  модулей на событие → вид «ИИ» shef.toolsai (если стоит и его адрес ещё не
  занят).
* Подписка и вызов — как у `OnAnnouncementsCollect`: постоянная, модули по
  одному.

```php
\Bitrix\Main\EventManager::getInstance()->registerEventHandler(
	'shef.toolsaiannounce', 'OnAnnouncementPlacesCollect', 'acme.demo',
	\Acme\Demo\Internals\Integration\Shef\ToolsAiAnnounce\EventHandler\OnAnnouncementPlacesCollectEventHandler::class, 'handle',
);

// handle(Event $event): EventResult
return new EventResult(EventResult::SUCCESS, ['places' => [
	['path' => '/acme/board/', 'title' => 'Акме: доска'],
]], 'acme.demo');
```

## Подписка модуля на OnViewSettingsMenuBuild

Сам модуль отвечает на событие вида «ИИ» `shef.toolsai:OnViewSettingsMenuBuild`
(shef.toolsai 1.53.0, у нас 1.4.1): пункт «Оповещения для сотрудников» в меню
шестерёнки ведёт на страницу списка, только при праве на модуль от R. Как
ставится подписка (в том числе при обновлении копированием) — в
[установке, п. 4](03-install.md).

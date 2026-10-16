# Событие OnAnnouncementsCollect

> Последняя сверка: 2026-10-16

Как модулю прислать свои объявления «Новинок для сотрудников». Событие D7
(`Bitrix\Main\Event`), шлёт его модуль `shef.toolsaiannounce` при каждом
показе страницы CRM и при открытии страницы списка.

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
| `audience` | `test` — тестовым пользователям, `all` — всем | `test` |
| `active` | показывать | `false` |
| `layout` | `cosmos` — нарядное окно, `slides` — слайды «Что нового» | `cosmos` |

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

Обработчик зовётся на каждой странице CRM — держите его дешёвым: без запросов
к базе и внешних вызовов.

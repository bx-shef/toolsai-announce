# Карта модуля shef.toolsaiannounce

> Последняя сверка: 2026-10-17

## Цель

«Оповещения для сотрудников» отдельным модулем: любой модуль присылает свои
объявления событием, администратор правит их на одной странице, сотрудник
видит каждое один раз.

## Состояние

| что | версия | статус |
|---|---|---|
| Вынос из shef.toolsai: окно, аудитория, отметки | 1.0.0 | 🧪 тесты; на портале не проверено |
| Событие `OnAnnouncementsCollect`, слияние, лог конфликтов | 1.0.0 | 🧪 |
| Страница списка, права R/W, пункт меню | 1.0.0 | 🧪 |
| Оформление страницы списка штатными UI, пункт меню под «Избранным», префикс [SH] | 1.3.0 | 🧪 тесты; на портале не проверено |
| Страница списка без перезагрузки (окно, меню «…», контроллер), «Где показывать» (ui.entity-selector), виды окна, картинка, период, повтор, аудитория по сотрудникам и отделам, новые сотрудники | 1.4.0 | 🧪 тесты; на портале не проверено |
| Пункт «Оповещения для сотрудников» в шестерёнке вида «ИИ» shef.toolsai (событие `OnViewSettingsMenuBuild`) | 1.4.1 | 🧪 тесты; на портале stuttgart стоит с выкладкой 3.72 |
| Перенос настроек и отметок из shef.toolsai | 1.0.0–1.1.0 | ✅ выполнен на обоих порталах, убран в 1.2.0 |

## Что в работе

* Модуль стоит на обоих порталах; на stuttgart — 1.4.1 (выкладка 3.72).
  Дальнейшая выкладка — по слову владельца.

## Линейка ИИ: зависимости и связь событиями

С 1.50.0 shef.toolsai разделён на четыре модуля. Зачем: баннеры, база знаний
и чат ставятся по желанию и выпускаются отдельно, а основной модуль от них
не зависит. Связь — только событиями D7 (`Bitrix\Main\Event`).

Сверено с кодом 2026-10-17; версии — на портале stuttgart (выкладка 3.72).

| модуль | репозиторий | версия | обязательно нужен | по желанию |
|---|---|---|---|---|
| `shef.toolsai` | [bx-shef/toolsai](https://github.com/bx-shef/toolsai) | 1.58.0 | `shef.options`, `shef.problems`; модули ядра `ai`, `crm` — проверка в установщике | — |
| `shef.toolsaiassistant` | [bx-shef/toolsai-assistant](https://github.com/bx-shef/toolsai-assistant) | 1.6.0 | `shef.options`, `shef.problems`, `shef.toolsai` (модель, квота, журнал расхода, реестр промптов, история записи) | `shef.toolsaikb` — поиск статей для ответа |
| `shef.toolsaiannounce` | [bx-shef/toolsai-announce](https://github.com/bx-shef/toolsai-announce) | 1.4.1 | `shef.options`, `shef.problems` | — |
| `shef.toolsaikb` | [bx-shef/toolsai-kb](https://github.com/bx-shef/toolsai-kb) | 1.2.0 | `shef.options`, `shef.problems`; модуль ядра `note` 26.250.0+ — проверка в установщике | — |

| событие | кто шлёт | кто отвечает | что передаёт |
|---|---|---|---|
| `shef.toolsai:OnPromptRegistryBuild` | toolsai | assistant | роли чата в реестр промптов |
| `shef.toolsai:OnPromptChanged` | toolsai | — (для своих модулей) | код изменённого промпта |
| `shef.toolsai:OnPromptPlacesCollect` | toolsai | — (для своих модулей) | места «Где доступен» промпта |
| `shef.toolsai:OnViewRowActionsBuild` | toolsai | assistant | пункт строки в виде «ИИ» |
| `shef.toolsai:OnViewToolbarBuild` | toolsai | assistant | кнопка в шапке вида «ИИ» |
| `shef.toolsai:OnViewSettingsMenuBuild` | toolsai | assistant, announce, kb | пункты шестерёнки вида «ИИ» |
| `shef.toolsaiannounce:OnAnnouncementsCollect` | announce | toolsai | объявления по умолчанию |
| `shef.toolsaiannounce:OnAnnouncementPlacesCollect` | announce | — (для своих модулей) | места показа объявлений |
| `shef.toolsaikb:OnKbSourcesCollect` | kb | toolsai | пакет статей справки (`shef.toolsai:guide`) |
| `shef.toolsaiassistant:OnChatPlacesCollect` | assistant | toolsai | места «Где показывать чат» |

Подробно: публичный API и события toolsai —
[guide/32](https://github.com/bx-shef/toolsai/blob/main/docs/guide/32-public-api.md),
реестр промптов —
[guide/31](https://github.com/bx-shef/toolsai/blob/main/docs/guide/31-prompt-registry.md).

## Открытые вопросы

* Отделы в аудитории — без подотделов. Нужны ли подотделы?
* `shef.toolsai` может сам отдавать свои места событием
  `OnAnnouncementPlacesCollect` — тогда строку вида «ИИ» в `PlaceCollector`
  можно убрать.
* Страница списка — в административной части. Нужна ли публичная (как
  настройки `shef.toolsai` в CRM → Настройки)?
* Удаление объявления модуля сейчас — только выключение своей копией.

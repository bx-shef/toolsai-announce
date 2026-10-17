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
| Новые картинки окна: по виду окна и три с анимацией (стройка, собрание, библиотека), поле `art` | 1.5.0 | 🧪 тесты, снимки на заглушке; на портале не проверено |
| Инструменты MCP: манифест `shef.announce.mcp.tools`, REST `shef.announce.*` (list/create/update/disable), блокировка своего списка | 1.6.0 | 🧪 тесты; на стенде не проверено (событие `rest`, область `shef_announce`, чтение `b_option`) |
| «Включить/выключить» на странице списка — под блокировкой по списку в БД | 1.6.1 | 🧪 тесты; на портале не проверено |
| Перенос настроек и отметок из shef.toolsai | 1.0.0–1.1.0 | ✅ выполнен на обоих порталах, убран в 1.2.0 |

## Что в работе

* Модуль стоит на обоих порталах; на stuttgart — 1.4.1 (выкладка 3.72).
  1.5.0–1.6.1 в `main`, на порталы не выложены — по слову владельца.
* Перед выкладкой 1.6.x — стенд: отвечает ли `/rest/shef.announce.mcp.tools`,
  видна ли область `shef_announce` в правах приложения MCP, читается ли
  список из `b_option`. Подключение: область приложению, манифест в
  «Модули портала» на `/admin` сервера MCP, сотрудникам войти заново.
* Теги `v1.5.0`, `v1.6.0` не поставлены: прокси сессии агента не пускает
  пуш тегов — ставит владелец.
* MCP-сервер показывает модели текст отказа модуля с
  [toolsai-mcp#47](https://github.com/bx-shef/toolsai-mcp/pull/47).

## Линейка ИИ: зависимости и связь событиями

С 1.50.0 shef.toolsai разделён на четыре модуля. Зачем: баннеры, база знаний
и чат ставятся по желанию и выпускаются отдельно, а основной модуль от них
не зависит. Связь — только событиями D7 (`Bitrix\Main\Event`).

Сверено с кодом 2026-10-17; версии — на портале stuttgart (выкладка 3.72).

| модуль | репозиторий | версия | обязательно нужен | по желанию |
|---|---|---|---|---|
| `shef.toolsai` | [bx-shef/toolsai](https://github.com/bx-shef/toolsai) | 1.58.0 | `shef.options`, `shef.problems`; модули ядра `ai`, `crm` — проверка в установщике | — |
| `shef.toolsaiassistant` | [bx-shef/toolsai-assistant](https://github.com/bx-shef/toolsai-assistant) | 1.6.0 | `shef.options`, `shef.problems`, `shef.toolsai` (модель, квота, журнал расхода, реестр промптов, история записи) | `shef.toolsaikb` — поиск статей для ответа |
| `shef.toolsaiannounce` | [bx-shef/toolsai-announce](https://github.com/bx-shef/toolsai-announce) | 1.4.1 (в `main` — 1.6.1) | `shef.options`, `shef.problems` | — |
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
* «Сохранить» в форме читает объявление до блокировки (для даты публикации и
  картинки) и пишет форму целиком: одновременная правка из MCP проигрывает
  форме. Нужна ли защита (версия объявления)?
* Поиск объявления и право модуля повторены в REST и контроллере — общий
  класс?

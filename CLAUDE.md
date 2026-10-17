# Памятка агенту: shef.toolsaiannounce

> Последняя сверка: 2026-10-17

Модуль коробочного Битрикс24 «Оповещения для сотрудников»: окно с объявлением
один раз при открытии CRM. Вынесен из `shef.toolsai` (1.31.0–1.49.x) без
изменения поведения. Опирается на `shef.options` и `shef.problems` и сделан по
канону линейки — сборка, тесты, раскладка. Чего здесь не хватает — смотрите
[CLAUDE.md shef.toolsai](https://github.com/bx-shef/toolsai/blob/main/CLAUDE.md)
и [shef.options](https://github.com/bx-shef/options/blob/main/CLAUDE.md).

Проверка перед сдачей — всегда `./build.sh --check`, ровно это же гоняет CI.

**Процесс** — [docs/private/agent-rules.md](docs/private/agent-rules.md): только
через PR, ревью навыком `review-panel`, мерж — `merge-pr`. Документация
правится **в том же PR**, что и код.

## Опорные точки

| что | где |
|---|---|
| чистая логика: разбор, выбор, слияние | `lib/announce/announcements.php` |
| событие для модулей | `lib/public/event/onannouncementscollectevent.php`, сбор — `lib/announce/collector.php` |
| показ на странице CRM (main:OnEpilog) | `lib/internals/integration/main/eventhandler/onepilogeventhandler.php` |
| отметка «видел» (ajax) | `lib/controller/banner.php`, хранилище — `lib/announce/seenstore.php` |
| выбор тестовых (старое «1,15» → JSON при показе) | `lib/main/testusersoption.php`, чистая часть — `Announcements::testUsersSelectorValue()` |
| страница списка | `admin/list.php` через заглушку `Main\AdminPage`; строки таблицы и бейджи — `lib/announce/listview.php` |

## Решения, которые не «упрощать»

* **От shef.toolsai не зависеть.** Ни `requireModules`, ни `NEED_MODULES`, ни
  вызовов его классов — держит `tests/settings_test.php`.
* **Коды настроек и отметок — как в shef.toolsai** (`ANNOUNCE_list`,
  `ANNOUNCE_testusers`, `announce_seen`): на них лежат данные порталов,
  перенесённые до 1.2.0. Перенос убран — сменить код значит потерять данные.
* **Свой список побеждает модульный** при совпадении id: так администратор
  выключает чужое объявление. Удалить модульное нельзя — только заменить.
* **Модули зовутся по одному** (фильтр по `TO_MODULE_ID`): сбой одного
  обработчика — в конфликты и лог, остальные ответы остаются.
* **Кеш списка 10 минут** (`Collector::clearCache()` — при сохранении списка и
  установке).
* Страница в `/bitrix/admin` **пишется**, а не копируется (`Main\AdminPage`):
  путь — туда, где модуль стоит на самом деле; чужой файл не трогаем.

## Тесты

`tests/` гоняются как обычные скрипты, обвязка `tests/assert.php` — общая с
линейкой. Ядро — заглушки `tests/stub/bitrix.php`, классы модуля — настоящие.

| тест | что держит |
|---|---|
| `announce_test.php` | разбор, аудитория, вид окна, отметки, скрипт без HTML-подстановки |
| `merge_test.php` | слияние со списком модулей, Collector, показ на странице CRM |
| `seen_test.php` | отметки (старые shef.toolsai не читаются), действие `seenAction`, повторная установка и удаление подписок |
| `settings_test.php` | зависимости, подписки установщика, удаление (savedata), контроллер, коды настроек |
| `adminpage_test.php` | заглушка в /bitrix/admin: путь, своя/чужая |
| `listview_test.php` | строки таблицы: бейджи, кнопки; название [SH], место пункта меню |
| `autoload_test.php` | класс лежит там, где его ищет ядро |

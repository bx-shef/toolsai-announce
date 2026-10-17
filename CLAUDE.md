# Памятка агенту: shef.toolsaiannounce

> Последняя сверка: 2026-10-17

Модуль коробочного Битрикс24 «Оповещения для сотрудников»: окно с объявлением
там, где указано у объявления (по умолчанию — CRM), с периодом, повтором и
аудиторией. Вынесен из `shef.toolsai` (1.31.0–1.49.x) без
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
| чистая логика: разбор, проверка формы, выбор (период, повтор, аудитория, новые сотрудники), слияние, окно | `lib/announce/announcements.php` |
| «Где показывать»: адрес, совпадение, готовые места, поиск | `lib/announce/places.php` (чистая), сбор — `lib/announce/placecollector.php` |
| видео (белый список хостов) и проверка картинки | `lib/announce/media.php` |
| события для модулей | `lib/public/event/onannouncementscollectevent.php`, `onannouncementplacescollectevent.php`; сбор — `lib/announce/collector.php` (`sendEach`, журнал `SYS_firstseen`) |
| показ на странице (main:OnEpilog) | `lib/internals/integration/main/eventhandler/onepilogeventhandler.php` |
| пункт в меню шестерёнки вида «ИИ» (shef.toolsai:OnViewSettingsMenuBuild, 1.4.1) | `lib/internals/integration/toolsai/eventhandler/onviewsettingsmenubuildeventhandler.php`; подписки после обновления копированием — `lib/main/maintenance.php` |
| отметки «видел» и «Понятно» (ajax) | `lib/controller/banner.php`, хранилище — `lib/announce/seenstore.php` |
| действия страницы списка (ajax) | `lib/controller/announcement.php`, свой список — `lib/announce/ownstore.php` |
| окно выбора мест (ui.entity-selector) | `lib/integration/ui/entityselector/placeprovider.php`, регистрация — `.settings.php` `ui.entity-selector` |
| выбор тестовых (старое «1,15» → JSON при показе) | `lib/main/testusersoption.php`, чистая часть — `Announcements::testUsersSelectorValue()` |
| страница списка | `admin/list.php` через заглушку `Main\AdminPage` (скрипт — в самой странице); строки, бейджи и меню «…» — `lib/announce/listview.php` |

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
* **Контроллер — без своего `run()`**: в ядре он final
  (`main/lib/engine/controller.php`), фильтры — `getDefaultPreFilters()`,
  право модуля — в каждом действии. Держит `tests/controller_test.php`.
* **Файлов вне `bitrix/modules` нет**: на портале модуль обновляют
  копированием, установщик не запускается. Скрипт страницы — в самой
  странице, провайдер окна выбора — ключом `.settings.php` (ядро читает его
  каждый раз). Понадобится файл вне модуля — сначала самовосстановление, как
  `Main\Maintenance` в shef.toolsaiassistant.
* **Совместимость старых объявлений**: у новых полей значения по умолчанию
  дают прежнее поведение (`places` `/crm/`, повтор «один раз»). Дату
  публикации старым даёт журнал первого появления — иначе новым-старым
  сотрудникам пропали бы непросмотренные.
* **Видео — только белый список** (`Media::VIDEO_HOSTS`), адрес плеера
  собирается из id; картинка — только файл этого модуля (`MODULE_ID` в CFile).
* Страница в `/bitrix/admin` **пишется**, а не копируется (`Main\AdminPage`):
  путь — туда, где модуль стоит на самом деле; чужой файл не трогаем.

## Тесты

`tests/` гоняются как обычные скрипты, обвязка `tests/assert.php` — общая с
линейкой. Ядро — заглушки `tests/stub/bitrix.php`, классы модуля — настоящие.

| тест | что держит |
|---|---|
| `announce_test.php` | разбор, аудитория, вид окна, отметки, скрипт без HTML-подстановки |
| `merge_test.php` | слияние со списком модулей, Collector, показ на странице по местам |
| `places_test.php` | адрес места, совпадение с адресом страницы, готовые места, поиск, событие мест |
| `schedule_test.php` | период, повтор, порядок, аудитория, новые сотрудники, виды окна, проверка формы, совместимость |
| `media_test.php` | видео из белого списка, проверка картинки |
| `controller_test.php` | действия страницы: права, проверка, копия модульного, фильтры, нет `run()`, сущность окна выбора |
| `seen_test.php` | отметки (старые shef.toolsai не читаются), действие `seenAction`, повторная установка и удаление подписок |
| `settings_test.php` | зависимости, подписки установщика, удаление (savedata), контроллер, коды настроек |
| `adminpage_test.php` | заглушка в /bitrix/admin: путь, своя/чужая |
| `listview_test.php` | строки таблицы: бейджи, меню «…», места; название [SH], место пункта меню |
| `autoload_test.php` | класс лежит там, где его ищет ядро |

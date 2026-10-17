<?php declare(strict_types=1);

/**
 * «[SH] Оповещения для сотрудников» — список объявлений.
 *
 * Оформление — штатные UI ядра: ui.alerts, ui.buttons, ui.forms, ui.label;
 * окно правки — main.popup (BX.PopupWindow), меню «…» —
 * BX.Main.MenuManager, выбор мест — ui.entity-selector (своя сущность
 * shef-announce-place), выбор сотрудников и отделов — ui.entity-selector
 * (user, department). Подписи, бейджи и набор пунктов меню — ListView::row().
 *
 * С 1.4.0 все действия — без перезагрузки, через контроллер
 * Shef\ToolsAiAnnounce\Controller\Announcement (BX.ajax.runAction, POST,
 * sessid, право модуля). Страница отдаёт первые строки в данных, дальше
 * таблицу перерисовывает ответ контроллера. Вывод — через textContent,
 * HTML из настроек на страницу не попадает.
 *
 * Открывается через /bitrix/admin/shef_toolsaiannounce_list.php — заглушку в
 * одну строку, которую пишет Main\AdminPage (каталог модуля браузеру
 * недоступен). Пункт меню — admin/menu.php.
 *
 * Права — уровень модуля в «Настройки → Настройки модулей → права доступа»:
 * R — смотреть и «Показать мне снова», W — править список. Администратор
 * портала — всегда W.
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\UserTable;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\ListView;
use Shef\ToolsAiAnnounce\Announce\Media;
use Shef\ToolsAiAnnounce\Announce\PlaceCollector;
use Shef\ToolsAiAnnounce\Announce\Places;
use Shef\ToolsAiAnnounce\Main\Constants;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';

/** @var \CMain $APPLICATION */
global $APPLICATION;

if(!Loader::includeModule('shef.toolsaiannounce'))
{
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage('Модуль shef.toolsaiannounce не подключился.');
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$right = $APPLICATION->GetGroupRight(Constants::MODULE_ID);
if($right < 'R')
{
	$APPLICATION->AuthForm('Нет доступа к странице «'.Constants::TITLE.'».');
}
$canEdit = $right >= 'W';

$h = static fn(mixed $v): string => htmlspecialcharsbx((string)$v);
$json = static fn(mixed $v): string => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$merged = Collector::collectWithConflicts();
$catalog = PlaceCollector::collect();

// Тестовые сотрудники — по именам, а не «1»
$testIds = Announcements::testUsers((string)Option::get(Constants::MODULE_ID, Constants::OPTION_TEST_USERS, '1'));
$names = [];
if($testIds !== [])
{
	$res = UserTable::getList(['select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'], 'filter' => ['@ID' => $testIds]]);
	while($u = $res->fetch())
	{
		$name = trim($u['NAME'].' '.$u['LAST_NAME']);
		$names[(int)$u['ID']] = $name !== '' ? $name : (string)$u['LOGIN'];
	}
}
$settingsUrl = '/bitrix/admin/settings.php?mid='.rawurlencode(Constants::MODULE_ID).'&lang='.rawurlencode(LANGUAGE_ID);

$APPLICATION->SetTitle(Constants::TITLE);
Extension::load(['main.core', 'main.popup', 'ui.alerts', 'ui.buttons', 'ui.forms', 'ui.label', 'ui.entity-selector']);
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
.shef-ann{max-width:1200px}
.shef-ann .ui-alert-message p{margin:0 0 6px}.shef-ann .ui-alert-message p:last-child{margin:0}
.shef-ann-card{background:#fff;border:1px solid #e6e9ec;border-radius:12px;padding:20px 24px;margin:0 0 20px;box-sizing:border-box}
.shef-ann-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
.shef-ann-head h3{margin:0;font-size:17px;font-weight:600;color:#333}
.shef-ann-list{width:100%;border-collapse:collapse;table-layout:fixed}
.shef-ann-list th{text-align:left;font-weight:500;color:#828b95;font-size:12px;padding:8px 10px;border-bottom:1px solid #e6e9ec}
.shef-ann-list td{padding:12px 10px;border-bottom:1px solid #f1f3f5;vertical-align:top;overflow-wrap:anywhere}
.shef-ann-list tr:last-child td{border-bottom:0}
.shef-ann-list .ui-label{margin:0 4px 4px 0;max-width:100%}
.shef-ann-list .ui-label-inner{white-space:normal}
.shef-ann-title{font-weight:600;color:#333;overflow-wrap:break-word;word-break:normal}
.shef-ann-sub{display:block;color:#a8adb4;font-size:12px;margin-top:2px}
.shef-ann-act{text-align:right;white-space:nowrap}
.shef-ann-act .ui-btn{margin:0 0 0 6px}
.shef-ann-empty{color:#828b95}
.shef-ann-pop{width:640px;max-width:calc(100vw - 48px);max-height:calc(100vh - 160px);overflow-y:auto;padding:4px 4px 0;box-sizing:border-box}
.shef-ann-pop .ui-form-row{margin-bottom:12px}
.shef-ann-pop .shef-ann-two{display:flex;gap:12px}.shef-ann-pop .shef-ann-two>div{flex:1}
.shef-ann-pop .shef-ann-hint{color:#828b95;font-size:12px;margin-top:4px}
.shef-ann-pop .shef-ann-img img{display:block;max-width:100%;max-height:160px;border-radius:8px;margin:6px 0}
.shef-ann-foot{display:flex;gap:8px;padding:14px 4px 4px}
</style>
<div class="shef-ann" id="shef-ann">
	<div class="ui-alert ui-alert-primary ui-alert-icon-info">
		<span class="ui-alert-message">
			<p>Объявление показывается сотруднику один раз — окном с текстом и ссылкой.</p>
			<p>Сначала проверьте на тестовых пользователях, потом переключите аудиторию на «все сотрудники».</p>
			<p>Новое объявление — новый показ всем. Правка текста уже видевшим не покажется снова.</p>
		</span>
	</div>
	<div class="ui-alert ui-alert-default">
		<span class="ui-alert-message">
			<p>Часть объявлений добавляют другие модули. Чтобы поменять или выключить такое объявление, нажмите «Изменить» — сохранится ваша версия.</p>
			<p>Тестовые объявления видят: <?=$h(ListView::testUsersText($testIds, $names))?>. Поменять — в <a href="<?=$h($settingsUrl)?>">настройках модуля</a>.</p>
		</span>
	</div>
	<div id="shef-ann-msg"></div>

	<div class="shef-ann-card">
		<div class="shef-ann-head">
			<h3>Объявления</h3>
			<?php if($canEdit): ?><button type="button" class="ui-btn ui-btn-sm ui-btn-primary" data-act="new">Новое объявление</button><?php endif; ?>
		</div>
		<table class="shef-ann-list">
			<colgroup><col><col style="width:150px"><col style="width:170px"><col style="width:170px"><col style="width:110px"><col style="width:<?=$canEdit ? 140 : 60?>px"></colgroup>
			<thead><tr><th>Объявление</th><th>Откуда</th><th>Кому и вид</th><th>Где показывать</th><th>Показ</th><th></th></tr></thead>
			<tbody id="shef-ann-rows"></tbody>
		</table>
	</div>
</div>
<script>
BX.ready(function(){
	'use strict';
	var D = {
		rows: <?=$json(ListView::rows($merged['items'], $canEdit, $catalog, static fn(int $id): string => $id > 0 ? (string)\CFile::GetPath($id) : ''))?>,
		conflicts: <?=$json($merged['conflicts'])?>,
		prefix: <?=$json(Constants::ACTION_PREFIX)?>,
		placeEntity: <?=$json(Constants::PLACE_ENTITY)?>,
		audiences: <?=$json(Announcements::AUDIENCES)?>,
		layouts: <?=$json(Announcements::LAYOUTS)?>,
		repeats: <?=$json(Announcements::REPEATS)?>,
		colors: <?=$json(Announcements::BUTTON_COLORS)?>,
		maxImage: <?=(int)Media::MAX_IMAGE_SIZE?>,
		empty: {id: '', title: '', text: '', url: <?=$json(Announcements::DEFAULT_URL)?>, linkText: 'Подробнее в базе знаний', audience: 'test', active: true,
			layout: 'cosmos', places: [<?=$json(Places::DEFAULT)?>], users: [], departments: [], dateFrom: '', dateTo: '', sort: <?=(int)Announcements::DEFAULT_SORT?>,
			repeat: 'once', repeatDays: 7, buttonColor: 'violet', image: 0, video: '', showToNew: false}
	};
	var page = document.getElementById('shef-ann');
	var tbody = document.getElementById('shef-ann-rows');
	var msg = document.getElementById('shef-ann-msg');

	function el(tag, cls, text){ var n = document.createElement(tag); if(cls) { n.className = cls; } if(text !== undefined) { n.textContent = text; } return n; }
	function badge(b){ var s = el('span', 'ui-label ui-label-sm ui-label-fill ' + b['class']); s.appendChild(el('span', 'ui-label-inner', b.text)); return s; }
	function errorsOf(r){ return (r && r.errors ? r.errors : []).map(function(e){ return e.message; }).filter(Boolean); }
	function alertBox(box, type, lines){
		box.innerHTML = '';
		if(!lines.length) { return; }
		var a = el('div', 'ui-alert ' + type), m = el('span', 'ui-alert-message');
		lines.forEach(function(l, i){ if(i) { m.appendChild(document.createElement('br')); } m.appendChild(document.createTextNode(l)); });
		a.appendChild(m); box.appendChild(a);
	}
	function run(action, data){ return BX.ajax.runAction(D.prefix + action, {data: data}); }
	function apply(r){ if(r && r.data && r.data.rows) { D.rows = r.data.rows; D.conflicts = r.data.conflicts || []; render(); } }

	function render(){
		tbody.innerHTML = '';
		var c = document.getElementById('shef-ann-conflicts');
		if(c) { c.parentNode.removeChild(c); }
		if(D.conflicts.length) {
			var box = el('div'); box.id = 'shef-ann-conflicts';
			alertBox(box, 'ui-alert-warning ui-alert-icon-warning', ['Конфликты объявлений:'].concat(D.conflicts));
			msg.parentNode.insertBefore(box, msg.nextSibling);
		}
		if(!D.rows.length) { var tr0 = el('tr'), td0 = el('td', 'shef-ann-empty', 'Объявлений нет.'); td0.colSpan = 6; tr0.appendChild(td0); tbody.appendChild(tr0); return; }
		D.rows.forEach(function(row){
			var tr = el('tr'), t = el('td');
			t.appendChild(el('div', 'shef-ann-title', row.title));
			t.appendChild(el('span', 'shef-ann-sub', row.id + (row.details.length ? ' · ' + row.details.join(' · ') : '')));
			var src = el('td'); src.appendChild(badge(row.source));
			var who = el('td'); who.appendChild(badge(row.audience)); who.appendChild(badge(row.layout));
			var where = el('td', '', row.places.join(', '));
			var on = el('td'); on.appendChild(badge(row.active));
			var act = el('td', 'shef-ann-act');
			if(row.canEdit) {
				var edit = el('button', 'ui-btn ui-btn-xs ui-btn-light-border', 'Изменить'); edit.type = 'button';
				edit.addEventListener('click', function(){ openForm(row); });
				act.appendChild(edit);
			}
			var more = el('button', 'ui-btn ui-btn-xs ui-btn-light-border', '…'); more.type = 'button'; more.title = 'Ещё действия';
			more.addEventListener('click', function(){ openMenu(more, row); });
			act.appendChild(more);
			[t, src, who, where, on, act].forEach(function(n){ tr.appendChild(n); });
			tbody.appendChild(tr);
		});
	}

	function openMenu(button, row){
		var menu = BX.Main.MenuManager.create({
			id: 'shef-ann-menu-' + row.id + '-' + Date.now(),
			bindElement: button, angle: true, autoHide: true,
			events: {onPopupClose: function(){ menu.destroy(); }},
			items: row.menu.map(function(m){
				return {text: BX.util.htmlspecialchars(m.text), onclick: function(){ menu.close(); doAction(m, row); }};
			})
		});
		menu.show();
	}

	function doAction(m, row){
		if(m.confirm && !window.confirm(m.confirm)) { return; }
		run(m.action, {id: row.id}).then(function(r){
			apply(r);
			alertBox(msg, 'ui-alert-success', [m.action === 'again' ? 'Готово: откройте страницу, где показывается объявление, — окно покажется снова.' : 'Сохранено.']);
		}, function(r){ alertBox(msg, 'ui-alert-danger', errorsOf(r).length ? errorsOf(r) : ['Не получилось. Обновите страницу.']); });
	}

	function field(label, control, hint){
		var row = el('div', 'ui-form-row'), l = el('div', 'ui-form-label'), c = el('div', 'ui-form-content');
		l.appendChild(el('div', 'ui-ctl-label-text', label));
		c.appendChild(control);
		if(hint) { c.appendChild(el('div', 'shef-ann-hint', hint)); }
		row.appendChild(l); row.appendChild(c);
		return row;
	}
	function input(type, value, attrs){
		var w = el('div', 'ui-ctl ui-ctl-textbox ui-ctl-w100'), i = el('input', 'ui-ctl-element');
		i.type = type; i.value = value === undefined || value === null ? '' : String(value);
		Object.keys(attrs || {}).forEach(function(k){ i.setAttribute(k, attrs[k]); });
		w.appendChild(i); w.input = i; return w;
	}
	function select(options, value){
		var w = el('div', 'ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100'), s = el('select', 'ui-ctl-element');
		w.appendChild(el('div', 'ui-ctl-after ui-ctl-icon-angle'));
		Object.keys(options).forEach(function(k){ var o = el('option', '', options[k]); o.value = k; o.selected = k === value; s.appendChild(o); });
		w.appendChild(s); w.input = s; return w;
	}
	function checkbox(text, checked){
		var l = el('label', 'ui-ctl ui-ctl-checkbox'), i = el('input', 'ui-ctl-element');
		i.type = 'checkbox'; i.checked = !!checked;
		l.appendChild(i); l.appendChild(el('div', 'ui-ctl-label-text', text)); l.input = i; return l;
	}

	function openForm(row){
		var isNew = !row;
		var v = Object.assign({}, D.empty, row ? row.form : {});
		var root = el('div', 'shef-ann-pop'), errors = el('div');
		var text = (function(){ var w = el('div', 'ui-ctl ui-ctl-textarea ui-ctl-w100 ui-ctl-resize-y'), t = el('textarea', 'ui-ctl-element'); t.rows = 5; t.maxLength = 2000; t.value = v.text; t.placeholder = 'Строка с «• » в начале — пункт списка.'; w.appendChild(t); w.input = t; return w; })();
		var f = {
			title: input('text', v.title, {maxlength: 120}),
			layout: select(D.layouts, v.layout),
			video: input('url', v.video, {placeholder: 'https://youtu.be/…, https://rutube.ru/video/…, https://vkvideo.ru/video-…'}),
			url: input('url', v.url, {placeholder: 'https://…'}),
			linkText: input('text', v.linkText, {maxlength: 60}),
			buttonColor: select(D.colors, v.buttonColor),
			audience: select(D.audiences, v.audience),
			dateFrom: input('date', v.dateFrom),
			dateTo: input('date', v.dateTo),
			repeat: select(D.repeats, v.repeat),
			repeatDays: input('number', v.repeatDays, {min: 1, max: 365}),
			sort: input('number', v.sort, {min: 0, max: 9999}),
			showToNew: checkbox('Показывать и новым сотрудникам (пришедшим после публикации)', isNew ? v.layout === 'video' : v.showToNew),
			active: checkbox('Показывать сотрудникам', v.active)
		};
		var image = v.image || 0;
		var imgBox = el('div', 'shef-ann-img');
		var file = el('input'); file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp,image/gif';
		var imgDrop = el('button', 'ui-btn ui-btn-xs ui-btn-link', 'Убрать картинку'); imgDrop.type = 'button';
		function showImage(src){ imgBox.innerHTML = ''; if(src) { var im = el('img'); im.src = src; im.alt = ''; imgBox.appendChild(im); } imgDrop.style.display = image ? '' : 'none'; }
		showImage(row && row.image ? row.image : '');
		imgDrop.addEventListener('click', function(){ image = 0; showImage(''); });
		file.addEventListener('change', function(){
			if(!file.files.length) { return; }
			if(file.files[0].size > D.maxImage) { alertBox(errors, 'ui-alert-danger', ['Картинка больше 2 МБ.']); file.value = ''; return; }
			var fd = new FormData(); fd.append('image', file.files[0]);
			BX.ajax.runAction(D.prefix + 'uploadImage', {data: fd}).then(function(r){
				image = r.data.id; showImage(r.data.src); alertBox(errors, '', []);
			}, function(r){ alertBox(errors, 'ui-alert-danger', errorsOf(r)); });
			file.value = '';
		});
		var imgWrap = el('div'); imgWrap.appendChild(file); imgWrap.appendChild(imgDrop); imgWrap.appendChild(imgBox);

		var places = new BX.UI.EntitySelector.TagSelector({
			multiple: true, addButtonCaption: 'Добавить место', addButtonCaptionMore: 'Ещё место',
			dialogOptions: {
				context: 'shef-announce-place', enableSearch: true, height: 300,
				entities: [{id: D.placeEntity, dynamicLoad: true, dynamicSearch: true}],
				preselectedItems: (v.places || []).map(function(p){ return [D.placeEntity, p]; })
			}
		});
		var placesBox = el('div'); places.renderTo(placesBox);
		var people = new BX.UI.EntitySelector.TagSelector({
			multiple: true, addButtonCaption: 'Выбрать',
			dialogOptions: {
				context: 'shef-announce-audience', enableSearch: true,
				entities: [{id: 'user', options: {inviteEmployeeLink: false}}, {id: 'department', options: {selectMode: 'usersAndDepartments', allowFlatDepartments: false}}],
				preselectedItems: (v.users || []).map(function(id){ return ['user', id]; }).concat((v.departments || []).map(function(id){ return ['department', id]; }))
			}
		});
		var peopleBox = el('div'); people.renderTo(peopleBox);

		var two = function(a, b){ var w = el('div', 'shef-ann-two'); w.appendChild(a); w.appendChild(b); return w; };
		var rVideo = field('Ссылка на видео', f.video, 'YouTube, Rutube, VK Видео (https) или файл .mp4 с портала (/upload/…).');
		var rPeople = field('Сотрудники и отделы', peopleBox);
		var rDays = field('Через сколько дней', f.repeatDays);
		[
			errors,
			field('Заголовок', f.title),
			field('Текст', text),
			two(field('Вид', f.layout), field('Цвет главной кнопки', f.buttonColor)),
			rVideo,
			field('Своя картинка (вместо стандартной)', imgWrap, 'jpg, png, webp или gif, до 2 МБ.'),
			field('Ссылка на подробности (можно пусто)', f.url),
			field('Текст главной кнопки', f.linkText),
			field('Где показывать', placesBox, 'Выберите место или введите начало адреса, например /shef/toolsai/view/?type=2&category=0'),
			field('Кому', f.audience),
			rPeople,
			two(field('Показывать с', f.dateFrom), field('по', f.dateTo)),
			two(field('Повтор', f.repeat), rDays),
			field('Порядок (меньше — раньше)', f.sort),
			f.showToNew,
			f.active
		].forEach(function(n){ root.appendChild(n); });
		function sync(){
			rVideo.style.display = f.layout.input.value === 'video' ? '' : 'none';
			rPeople.style.display = f.audience.input.value === 'custom' ? '' : 'none';
			rDays.style.visibility = f.repeat.input.value === 'days' ? '' : 'hidden';
		}
		f.layout.input.addEventListener('change', function(){ if(isNew) { f.showToNew.input.checked = f.layout.input.value === 'video'; } sync(); });
		f.audience.input.addEventListener('change', sync);
		f.repeat.input.addEventListener('change', sync);
		sync();

		var foot = el('div', 'shef-ann-foot');
		var save = el('button', 'ui-btn ui-btn-success', 'Сохранить'); save.type = 'button';
		var cancel = el('button', 'ui-btn ui-btn-link', 'Отмена'); cancel.type = 'button';
		foot.appendChild(save); foot.appendChild(cancel); root.appendChild(foot);

		var popup = new BX.PopupWindow('shef-ann-form-' + Date.now(), null, {
			titleBar: isNew ? 'Новое объявление' : 'Изменить объявление', content: root,
			closeIcon: true, closeByEsc: true, autoHide: false, draggable: true, overlay: {opacity: 40},
			events: {onPopupClose: function(){ popup.destroy(); }}
		});
		cancel.addEventListener('click', function(){ popup.close(); });
		save.addEventListener('click', function(){
			var who = people.getTags();
			var fields = {
				id: v.id, title: f.title.input.value, text: text.input.value, layout: f.layout.input.value, video: f.video.input.value,
				url: f.url.input.value, linkText: f.linkText.input.value, buttonColor: f.buttonColor.input.value, audience: f.audience.input.value,
				dateFrom: f.dateFrom.input.value, dateTo: f.dateTo.input.value, repeat: f.repeat.input.value, repeatDays: f.repeatDays.input.value,
				sort: f.sort.input.value, image: image, showToNew: f.showToNew.input.checked ? 'Y' : 'N', active: f.active.input.checked ? 'Y' : 'N',
				places: places.getTags().map(function(t){ return String(t.getId()); }),
				users: who.filter(function(t){ return t.getEntityId() === 'user'; }).map(function(t){ return parseInt(t.getId(), 10); }),
				departments: who.filter(function(t){ return t.getEntityId() === 'department'; }).map(function(t){ return parseInt(t.getId(), 10); })
			};
			BX.addClass(save, 'ui-btn-wait');
			run('save', {fields: fields}).then(function(r){
				apply(r); popup.close(); alertBox(msg, 'ui-alert-success', ['Сохранено.']);
			}, function(r){
				BX.removeClass(save, 'ui-btn-wait');
				alertBox(errors, 'ui-alert-danger', errorsOf(r).length ? errorsOf(r) : ['Не сохранено. Обновите страницу.']);
				root.scrollTop = 0;
			});
		});
		popup.show();
	}

	page.addEventListener('click', function(e){ if(e.target.closest && e.target.closest('[data-act="new"]')) { openForm(null); } });
	render();
});
</script>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

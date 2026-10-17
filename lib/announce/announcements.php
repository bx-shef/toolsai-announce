<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * «Оповещения для сотрудников»: объявления, которые сотрудник видит один раз
 * при открытии CRM — что появилось, зачем и ссылка на подробности.
 *
 * Перенесено из shef.toolsai (Shef\ToolsAi\Announce\Announcements, 1.31.0–1.49)
 * без изменения смысла. Что изменилось при выносе:
 *
 *  * объявлений «по умолчанию» в модуле нет — их отдают другие модули через
 *    событие shef.toolsaiannounce:OnAnnouncementsCollect (Collector), а свой
 *    список из настроек (ANNOUNCE_list) переопределяет объявление с тем же id
 *    (merge());
 *  * отметку «видел» ставит действие shef:toolsaiannounce.banner.seen.
 *
 * У объявления — аудитория: test (только тестовые пользователи из
 * ANNOUNCE_testusers) или all (все). Кто какое объявление видел — личная
 * настройка пользователя (CUserOptions announce_seen), по id объявления:
 * новый id — показ заново.
 *
 * Чистая логика, без ядра — tests/announce_test.php, tests/merge_test.php.
 */
final class Announcements
{
	public const AUDIENCE_TEST = 'test';
	public const AUDIENCE_ALL = 'all';
	public const AUDIENCES = [self::AUDIENCE_TEST => 'тестовые пользователи', self::AUDIENCE_ALL => 'все сотрудники'];
	public const LAYOUT_COSMOS = 'cosmos';
	public const LAYOUT_SLIDES = 'slides';
	public const LAYOUTS = [self::LAYOUT_COSMOS => 'нарядное окно', self::LAYOUT_SLIDES => 'слайды «Что нового»'];
	/** Сколько объявлений берём из одного источника: свой список или один модуль. */
	public const MAX_ITEMS = 20;
	public const MAX_SEEN = 200;
	/** Допустимый id объявления. */
	public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';
	/** Ссылка в форме нового объявления — база знаний ИИ-инструментов, как было в shef.toolsai. */
	public const DEFAULT_URL = 'https://b24-crm-tools-ai.bx-shef.by/guide/';
	/** Источник объявления из своего списка (настройки модуля). */
	public const SOURCE_SETTINGS = '';

	/**
	 * Свой список из настроек; пусто или битый JSON — пусто.
	 *
	 * В shef.toolsai пустая настройка давала объявление по умолчанию; теперь
	 * объявления по умолчанию приходят от модулей (merge()).
	 *
	 * @return list<array{id: string, title: string, text: string, url: string, linkText: string, audience: string, active: bool, layout: string}>
	 */
	public static function parse(?string $stored): array
	{
		if($stored === null || trim($stored) === '')
		{
			return [];
		}
		$data = json_decode($stored, true);

		return is_array($data) ? static::normalizeList($data) : [];
	}

	/**
	 * Список объявлений в допустимых пределах: без повторов id, не больше MAX_ITEMS.
	 *
	 * @param array<mixed> $data
	 * @return list<array>
	 */
	public static function normalizeList(array $data): array
	{
		$result = [];
		$ids = [];
		foreach($data as $item)
		{
			$item = is_array($item) ? static::normalize($item) : null;
			if($item === null || isset($ids[$item['id']]))
			{
				continue;
			}
			$ids[$item['id']] = true;
			$result[] = $item;
			if(count($result) >= self::MAX_ITEMS)
			{
				break;
			}
		}

		return $result;
	}

	/**
	 * Поля объявления в допустимых пределах; без id или заголовка — null.
	 *
	 * @param array<string, mixed> $item
	 * @return array{id: string, title: string, text: string, url: string, linkText: string, audience: string, active: bool, layout: string}|null
	 */
	public static function normalize(array $item): ?array
	{
		$id = strtolower(trim(is_scalar($item['id'] ?? null) ? (string)$item['id'] : ''));
		$title = trim(is_scalar($item['title'] ?? null) ? (string)$item['title'] : '');
		if(!preg_match(self::ID_PATTERN, $id) || $title === '')
		{
			return null;
		}
		$str = static fn(string $key): string => trim(is_scalar($item[$key] ?? null) ? (string)$item[$key] : '');
		$url = $str('url');

		return [
			'id' => $id,
			'title' => mb_substr($title, 0, 120),
			'text' => mb_substr($str('text'), 0, 2000),
			'url' => preg_match('~^https://[^\s"\'<>]+$~', $url) ? $url : '',
			'linkText' => mb_substr($str('linkText'), 0, 60) ?: 'Подробнее',
			'audience' => ($item['audience'] ?? '') === self::AUDIENCE_ALL ? self::AUDIENCE_ALL : self::AUDIENCE_TEST,
			'active' => !empty($item['active']),
			'layout' => ($item['layout'] ?? '') === self::LAYOUT_SLIDES ? self::LAYOUT_SLIDES : self::LAYOUT_COSMOS,
		];
	}

	/**
	 * Свой список и объявления модулей — в один.
	 *
	 * Порядок: сначала свой список, потом модули в порядке ответов. Объявление
	 * модуля с тем же id, что в своём списке, заменяется своим (так
	 * администратор правит или выключает чужое объявление). Совпали id у двух
	 * модулей — остаётся первый, остальное — в conflicts (в лог). Ответ без
	 * moduleId не принимается — тоже в conflicts.
	 *
	 * У каждого объявления результата — ключ source: модуль-источник, а для
	 * своего списка — SOURCE_SETTINGS; overrides — id модульного объявления,
	 * которое своё заменило (иначе нет ключа).
	 *
	 * @param list<array> $own свой список (parse())
	 * @param list<array{moduleId: string, announcements: mixed}> $sources ответы модулей
	 * @return array{items: list<array>, conflicts: list<string>}
	 */
	public static function merge(array $own, array $sources): array
	{
		$items = [];
		$owner = [];
		foreach($own as $item)
		{
			$item['source'] = self::SOURCE_SETTINGS;
			$items[$item['id']] = $item;
			$owner[$item['id']] = self::SOURCE_SETTINGS;
		}
		$conflicts = [];
		foreach($sources as $source)
		{
			$moduleId = trim((string)($source['moduleId'] ?? ''));
			$list = $source['announcements'] ?? null;
			if($moduleId === '')
			{
				$conflicts[] = 'ответ без moduleId — пропущен';
				continue;
			}
			if(!is_array($list))
			{
				$conflicts[] = $moduleId.': announcements не массив — пропущен';
				continue;
			}
			foreach(static::normalizeList($list) as $item)
			{
				$id = $item['id'];
				if(!isset($owner[$id]))
				{
					$item['source'] = $moduleId;
					$items[$id] = $item;
					$owner[$id] = $moduleId;
				}
				elseif($owner[$id] === self::SOURCE_SETTINGS)
				{
					if(!isset($items[$id]['overrides']))
					{
						$items[$id]['overrides'] = $moduleId;
					}
					else
					{
						$conflicts[] = $id.': есть у '.$items[$id]['overrides'].' и '.$moduleId.', в настройках — своё';
					}
				}
				elseif($owner[$id] !== $moduleId)
				{
					$conflicts[] = $id.': есть у '.$owner[$id].' и '.$moduleId.', показываем от '.$owner[$id];
				}
			}
		}

		return ['items' => array_values($items), 'conflicts' => $conflicts];
	}

	/**
	 * ID тестовых пользователей из настройки ANNOUNCE_testusers.
	 *
	 * Понимает два вида: JSON-список штатного выбора сотрудников
	 * (shef.options Department: `[{"id":1,"entityId":"user",...}]`, берутся
	 * только записи entityId=user; экранированный `&quot;` тоже) и старый «ID через запятую» (`1,15`).
	 *
	 * @return list<int>
	 */
	public static function testUsers(?string $value): array
	{
		$value = trim((string)$value);
		if(str_starts_with($value, '[') && str_contains($value, '&quot;'))
		{
			// значение могло сохраниться экранированным — shef.options само делает htmlspecialcharsback
			$value = htmlspecialchars_decode($value, ENT_QUOTES);
		}
		$ids = [];
		$decoded = str_starts_with($value, '[') ? json_decode($value, true) : null;
		if(is_array($decoded))
		{
			foreach($decoded as $row)
			{
				if(is_array($row) && ($row['entityId'] ?? '') === 'user' && is_numeric($row['id'] ?? null))
				{
					$ids[] = (int)$row['id'];
				}
			}
		}
		else
		{
			$ids = array_map('intval', preg_split('/[\s,;]+/', $value) ?: []);
		}
		$ids = array_filter($ids, static fn(int $id): bool => $id > 0);

		return array_values(array_unique($ids));
	}

	/**
	 * Первое активное непросмотренное объявление, доступное пользователю.
	 *
	 * @param list<array> $items
	 * @param list<string> $seen
	 * @param list<int> $testUsers
	 */
	public static function pick(array $items, array $seen, int $userId, array $testUsers): ?array
	{
		if($userId <= 0)
		{
			return null;
		}
		$seen = array_flip(array_map('strval', $seen));
		foreach($items as $item)
		{
			if(!$item['active'] || isset($seen[$item['id']]))
			{
				continue;
			}
			if($item['audience'] === self::AUDIENCE_ALL || in_array($userId, $testUsers, true))
			{
				return $item;
			}
		}

		return null;
	}

	/**
	 * Что показать за один заход: первая доступная новинка решает вид.
	 * Нарядное окно — одна новинка; слайды — все доступные новинки-слайды разом.
	 *
	 * @param list<array> $items
	 * @param list<string> $seen
	 * @param list<int> $testUsers
	 * @return array{layout: string, items: list<array>}|null
	 */
	public static function plan(array $items, array $seen, int $userId, array $testUsers): ?array
	{
		$available = [];
		$rest = $seen;
		while(($item = static::pick($items, $rest, $userId, $testUsers)) !== null)
		{
			$available[] = $item;
			$rest[] = $item['id'];
		}
		if($available === [])
		{
			return null;
		}
		if($available[0]['layout'] === self::LAYOUT_COSMOS)
		{
			return ['layout' => self::LAYOUT_COSMOS, 'items' => [$available[0]]];
		}

		return ['layout' => self::LAYOUT_SLIDES, 'items' => array_values(array_filter($available, static fn(array $i): bool => $i['layout'] === self::LAYOUT_SLIDES))];
	}

	/**
	 * Отметить просмотр; самые старые уходят сверх MAX_SEEN.
	 *
	 * @param list<string> $seen
	 * @return list<string>
	 */
	public static function markSeen(array $seen, string $id): array
	{
		$seen = array_values(array_filter(array_map('strval', $seen), static fn(string $v): bool => $v !== $id));
		$seen[] = $id;

		return array_slice($seen, -self::MAX_SEEN);
	}

	/**
	 * Отметки «видел» из shef.toolsai и свои — в одну: без повторов, свои
	 * последними (самые свежие), не больше MAX_SEEN. Для переноса при установке.
	 *
	 * @param mixed $own свои отметки (что лежит в CUserOptions)
	 * @param mixed $legacy отметки из shef.toolsai
	 * @return list<string>
	 */
	public static function mergeSeen(mixed $own, mixed $legacy): array
	{
		$result = [];
		foreach([is_array($legacy) ? $legacy : [], is_array($own) ? $own : []] as $list)
		{
			foreach($list as $id)
			{
				if(is_scalar($id) && preg_match(self::ID_PATTERN, (string)$id))
				{
					$result = static::markSeen($result, (string)$id);
				}
			}
		}

		return $result;
	}

	/**
	 * Перенос настройки: своё пусто, а в shef.toolsai есть — взять оттуда.
	 *
	 * @param string|null $own своё сохранённое значение (null — не сохраняли)
	 * @param string|null $legacy значение в shef.toolsai
	 * @return string|null что записать; null — не трогать
	 */
	public static function migrateOption(?string $own, ?string $legacy): ?string
	{
		if($own !== null && trim($own) !== '')
		{
			return null;
		}

		return $legacy !== null && trim($legacy) !== '' ? $legacy : null;
	}

	/**
	 * Скрипт показа: встаёт в штатную очередь баннеров Битрикс24 (ui.banner-dispatcher),
	 * чтобы не открываться поверх чужих окон. Вид — нарядное окно (светлая карточка со своей картинкой)
	 * или штатное «Что нового» (ui.dialogs.whats-new). Текст экранирован.
	 *
	 * @param array{layout: string, items: list<array>} $plan
	 */
	public static function script(array $plan): string
	{
		$json = static fn(mixed $v): string => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$data = $json([
			'layout' => $plan['layout'],
			'items' => array_map(static fn(array $i): array => ['id' => $i['id'], 'title' => $i['title'], 'text' => $i['text'], 'url' => $i['url'], 'linkText' => $i['linkText']], $plan['items']),
		]);
		$action = $json(Constants::SEEN_ACTION);

		return <<<JS
(function(){
var P = {$data};
if(window.top !== window || !window.BX) { return; }
function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function seen(){ P.items.forEach(function(a){ BX.ajax.runAction({$action}, {data: {id: a.id}}); }); }
function split(text){
	var intro = [], points = [];
	String(text).split('\\n').forEach(function(l){ l = l.trim(); if(!l) { return; } if(l.indexOf('• ') === 0) { points.push(l.slice(2)); } else { intro.push(l); } });
	return {intro: intro, points: points};
}
function body(a){
	var t = split(a.text);
	return t.intro.map(function(l){ return '<p>' + esc(l) + '</p>'; }).join('')
		+ (t.points.length ? '<ul>' + t.points.map(function(l){ return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>' : '');
}
var ART = '<svg viewBox="0 0 560 190" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
	+ '<defs><linearGradient id="shefAnnAi" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9b7bff"/><stop offset="1" stop-color="#5b3fd6"/></linearGradient>'
	+ '<linearGradient id="shefAnnCall" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4fd1a5"/><stop offset="1" stop-color="#1fa37a"/></linearGradient></defs>'
	+ '<g fill="#fff" opacity=".85"><ellipse cx="70" cy="150" rx="34" ry="18"/><ellipse cx="96" cy="140" rx="24" ry="20"/><ellipse cx="490" cy="52" rx="28" ry="14"/><ellipse cx="510" cy="44" rx="18" ry="14"/></g>'
	+ '<ellipse cx="280" cy="100" rx="140" ry="78" fill="#fff" opacity=".7"/>'
	+ '<g><rect x="168" y="38" width="190" height="52" rx="12" fill="#fff" stroke="#ebe6fb"/><circle cx="194" cy="64" r="12" fill="url(#shefAnnCall)"/>'
	+ '<g stroke="#fff" stroke-width="2.6" stroke-linecap="round"><path d="M187 61v6"/><path d="M191 57v14"/><path d="M195 59v10"/><path d="M199 55v18"/><path d="M203 61v6"/></g>'
	+ '<rect x="216" y="54" width="70" height="7" rx="3.5" fill="#c9b8ff"/><rect x="216" y="68" width="118" height="6" rx="3" fill="#e6e1f5"/></g>'
	+ '<g><rect x="208" y="104" width="190" height="52" rx="12" fill="#fff" stroke="#ebe6fb"/><circle cx="234" cy="130" r="12" fill="#d9ccff"/>'
	+ '<rect x="256" y="120" width="58" height="7" rx="3.5" fill="#c9b8ff"/><rect x="256" y="134" width="104" height="6" rx="3" fill="#e6e1f5"/>'
	+ '<path d="M334 117l4 4 8-9" stroke="#1fa37a" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>'
	+ '<circle cx="392" cy="150" r="30" fill="url(#shefAnnAi)"/><circle cx="392" cy="150" r="35" fill="none" stroke="#9b7bff" stroke-opacity=".25" stroke-width="6"/>'
	+ '<path d="M392 134l4 10 10 4-10 4-4 10-4-10-10-4 10-4z" fill="#fff"/><path d="M406 132l1.6 3.8 3.8 1.6-3.8 1.6-1.6 3.8-1.6-3.8-3.8-1.6 3.8-1.6z" fill="#fff" opacity=".85"/>'
	+ '</svg>';
var CSS = '.shef-ann-wrap{background:transparent!important;box-shadow:none!important}'
	+ '.shef-ann{width:560px;max-width:calc(100vw - 32px);max-height:calc(100vh - 48px);overflow-y:auto;box-sizing:border-box;background:#fff;border-radius:20px;box-shadow:0 12px 40px rgba(31,22,77,.22);font-family:var(--ui-font-family-primary,"Open Sans",sans-serif);color:#333}'
	+ '.shef-ann-head{position:relative;padding:22px 28px 0}'
	+ '.shef-ann-eye{display:inline-block;font-size:12px;font-weight:600;color:#5b3fd6;background:#f1ecff;border-radius:10px;padding:3px 10px;margin-bottom:10px}'
	+ '.shef-ann-t{font-size:22px;font-weight:600;line-height:1.25;color:#1f2433;padding-right:28px}'
	+ '.shef-ann-x{position:absolute;right:16px;top:16px;width:32px;height:32px;border:0;border-radius:50%;background:transparent;color:#a8adb4;font-size:22px;line-height:32px;cursor:pointer}.shef-ann-x:hover{background:#f2f2f5;color:#525c69}'
	+ '.shef-ann-art{margin:16px 28px 0;border-radius:14px;background:linear-gradient(135deg,#f6f2ff 0%,#eef5ff 100%);overflow:hidden}.shef-ann-art svg{display:block;width:100%;height:auto}'
	+ '.shef-ann-b{padding:18px 28px 0;font-size:14px;line-height:1.55;color:#525c69}.shef-ann-b p{margin:0 0 12px}'
	+ '.shef-ann-b ul{list-style:none;margin:0 0 4px;padding:0;display:flex;flex-direction:column;gap:10px}'
	+ '.shef-ann-b li{position:relative;padding-left:30px;color:#333}'
	+ '.shef-ann-b li:before{content:"✓";position:absolute;left:0;top:1px;width:20px;height:20px;border-radius:50%;background:#e3f7ef;color:#1fa37a;font-size:12px;font-weight:700;line-height:20px;text-align:center}'
	+ '.shef-ann-btns{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;padding:20px 28px 24px}'
	+ '.shef-ann-btn{border:0;border-radius:10px;padding:11px 20px;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:filter .15s}.shef-ann-btn:hover{filter:brightness(1.07)}'
	+ '.shef-ann-btn.--main{background:linear-gradient(135deg,#8a6cff,#5b3fd6);color:#fff;box-shadow:0 4px 12px rgba(91,63,214,.3)}'
	+ '.shef-ann-btn.--ok{background:#1fa37a;color:#fff}.shef-ann-btn.--ok.--soft{background:#e3f7ef;color:#137a5a}'
	+ '.shef-ann-btn:focus-visible{outline:3px solid #b9a6ff;outline-offset:2px}'
	+ '@media (max-width:520px){.shef-ann-head,.shef-ann-b{padding-left:20px;padding-right:20px}.shef-ann-art{margin:14px 20px 0}.shef-ann-btns{padding:18px 20px 20px}.shef-ann-btn{flex:1}}';
function cosmos(onDone){
	var a = P.items[0];
	if(!document.getElementById('shef-ann-css')) { var st = document.createElement('style'); st.id = 'shef-ann-css'; st.textContent = CSS; document.head.appendChild(st); }
	var root = document.createElement('div');
	root.className = 'shef-ann';
	root.innerHTML = '<div class="shef-ann-head"><div class="shef-ann-eye">✦ Новое в CRM</div>'
		+ '<div class="shef-ann-t">' + esc(a.title) + '</div><button type="button" class="shef-ann-x" data-act="close" aria-label="Закрыть">×</button></div>'
		+ '<div class="shef-ann-art">' + ART + '</div><div class="shef-ann-b">' + body(a) + '</div>'
		+ '<div class="shef-ann-btns"><button type="button" class="shef-ann-btn --ok' + (a.url ? ' --soft' : '') + '" data-act="close">Понятно</button>'
		+ (a.url ? '<button type="button" class="shef-ann-btn --main" data-act="link">' + esc(a.linkText) + '</button>' : '') + '</div>';
	var popup = new BX.PopupWindow('shef-toolsai-announce', null, {
		content: root, padding: 0, contentPadding: 0, className: 'shef-ann-wrap', closeIcon: false, closeByEsc: true, autoHide: false,
		overlay: {backgroundColor: '#1f2433', opacity: 45},
		events: {onPopupClose: function(){ popup.destroy(); }, onPopupDestroy: function(){ onDone(); }}
	});
	root.addEventListener('click', function(e){
		var el = e.target.closest ? e.target.closest('[data-act]') : null;
		var act = el && el.getAttribute('data-act');
		if(act === 'link') { window.open(a.url, '_blank', 'noopener'); }
		if(act) { popup.close(); }
	});
	popup.show();
	seen();
}
function slides(onDone){
	BX.Runtime.loadExtension('ui.dialogs.whats-new').then(function(ex){
		var WhatsNew = ex.WhatsNew || (BX.UI.Dialogs && BX.UI.Dialogs.WhatsNew);
		if(!WhatsNew) { onDone(); return; }
		var w = new WhatsNew({
			slides: P.items.map(function(a){
				return {title: esc(a.title), html: '<div style="padding:24px 28px;white-space:normal;font-size:14px;line-height:1.55;color:#333">' + body(a)
					+ (a.url ? '<p><a href="' + esc(a.url) + '" target="_blank" rel="noopener">' + esc(a.linkText) + '</a></p>' : '') + '</div>'};
			}),
			popupOptions: {height: 440},
			events: {onDestroy: onDone}
		});
		w.show();
		seen();
	}, onDone);
}
function show(onDone){ (P.layout === 'slides' ? slides : cosmos)(onDone || function(){}); }
BX.ready(function(){
	BX.Runtime.loadExtension('ui.banner-dispatcher').then(function(){
		var D = BX.UI && BX.UI.BannerDispatcher;
		if(D && D.high) { D.high.toQueue(show); } else { setTimeout(show, 800); }
	}, function(){ setTimeout(show, 800); });
});
})();
JS;
	}
}

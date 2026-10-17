<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * «Оповещения для сотрудников»: объявления, которые сотрудник видит окном —
 * что появилось, зачем и ссылка на подробности. Где (places, по умолчанию
 * /crm/ — Places), когда (dateFrom/dateTo), сколько раз (repeat), кому
 * (audience, users/departments, новые сотрудники — published/showToNew) и в
 * каком виде (layout, image, video, buttonColor) — поля объявления с 1.4.0;
 * у старых объявлений — значения по умолчанию, поведение прежнее.
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
	/** Выбранные сотрудники и отделы (поля users, departments). */
	public const AUDIENCE_CUSTOM = 'custom';
	public const AUDIENCES = [self::AUDIENCE_TEST => 'тестовые пользователи', self::AUDIENCE_ALL => 'все сотрудники', self::AUDIENCE_CUSTOM => 'выбранные сотрудники и отделы'];
	/** Обычное окно (до 1.4.0 — «нарядное окно»). */
	public const LAYOUT_COSMOS = 'cosmos';
	/** Важное: акцентный цвет, закрыть можно только кнопкой «Понятно». */
	public const LAYOUT_IMPORTANT = 'important';
	/** Предупреждение: красное. */
	public const LAYOUT_DANGER = 'danger';
	/** Поздравление: праздничное оформление. */
	public const LAYOUT_CONGRATS = 'congrats';
	/** Обучающее видео: плеер в окне, кнопка «Посмотрел». */
	public const LAYOUT_VIDEO = 'video';
	public const LAYOUT_SLIDES = 'slides';
	public const LAYOUTS = [
		self::LAYOUT_COSMOS => 'обычное окно',
		self::LAYOUT_IMPORTANT => 'важное',
		self::LAYOUT_DANGER => 'предупреждение',
		self::LAYOUT_CONGRATS => 'поздравление',
		self::LAYOUT_VIDEO => 'обучающее видео',
		self::LAYOUT_SLIDES => 'слайды «Что нового»',
	];
	/** Повтор: один раз. */
	public const REPEAT_ONCE = 'once';
	/** Повтор: на каждой подходящей странице, пока не нажмёт главную кнопку или «Понятно». */
	public const REPEAT_UNTIL = 'until';
	/** Повтор: раз в N дней (поле repeatDays). */
	public const REPEAT_DAYS = 'days';
	public const REPEATS = [self::REPEAT_ONCE => 'один раз', self::REPEAT_UNTIL => 'пока не нажмёт «Понятно»', self::REPEAT_DAYS => 'раз в N дней'];
	/** Цвет главной кнопки: код => название. */
	public const BUTTON_COLORS = ['violet' => 'фиолетовая', 'blue' => 'синяя', 'green' => 'зелёная', 'orange' => 'оранжевая', 'red' => 'красная'];
	/** Порядок по умолчанию: меньше — раньше. */
	public const DEFAULT_SORT = 500;
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
	 * Поля, которых не было до 1.4.0, у старых объявлений получают значения
	 * по умолчанию — поведение прежнее: places [/crm/], repeat once, без
	 * периода, без картинки и видео, sort 500, кнопка фиолетовая.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
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
		$layout = (string)($item['layout'] ?? '');
		$repeat = (string)($item['repeat'] ?? '');
		$color = (string)($item['buttonColor'] ?? '');
		$audience = (string)($item['audience'] ?? '');
		$video = $str('video');

		return [
			'id' => $id,
			'title' => mb_substr($title, 0, 120),
			'text' => mb_substr($str('text'), 0, 2000),
			'url' => preg_match('~^https://[^\s"\'<>]+$~', $url) ? $url : '',
			'linkText' => mb_substr($str('linkText'), 0, 60) ?: 'Подробнее',
			'audience' => isset(self::AUDIENCES[$audience]) ? $audience : self::AUDIENCE_TEST,
			'active' => !empty($item['active']),
			'layout' => isset(self::LAYOUTS[$layout]) ? $layout : self::LAYOUT_COSMOS,
			'places' => Places::normalizeList($item['places'] ?? null),
			'users' => static::ids($item['users'] ?? null),
			'departments' => static::ids($item['departments'] ?? null),
			'dateFrom' => static::date($item['dateFrom'] ?? null),
			'dateTo' => static::date($item['dateTo'] ?? null),
			'sort' => is_numeric($item['sort'] ?? null) ? max(0, min(9999, (int)$item['sort'])) : self::DEFAULT_SORT,
			'repeat' => isset(self::REPEATS[$repeat]) ? $repeat : self::REPEAT_ONCE,
			'repeatDays' => is_numeric($item['repeatDays'] ?? null) ? max(1, min(365, (int)$item['repeatDays'])) : 7,
			'buttonColor' => isset(self::BUTTON_COLORS[$color]) ? $color : 'violet',
			'image' => is_numeric($item['image'] ?? null) ? max(0, (int)$item['image']) : 0,
			'video' => Media::video($video) !== null ? $video : '',
			'published' => static::stamp($item['published'] ?? null),
			'showToNew' => array_key_exists('showToNew', $item) ? !empty($item['showToNew']) : ($layout === self::LAYOUT_VIDEO),
		];
	}

	/**
	 * Ошибки полей формы — по-русски, для окна правки. Пусто — можно
	 * сохранять (normalize() всё равно приведёт поля к пределам).
	 *
	 * @param array<string, mixed> $fields
	 * @return list<string>
	 */
	public static function validate(array $fields): array
	{
		$str = static fn(string $key): string => trim(is_scalar($fields[$key] ?? null) ? (string)$fields[$key] : '');
		$errors = [];
		if($str('title') === '')
		{
			$errors[] = 'Укажите заголовок.';
		}
		elseif(mb_strlen($str('title')) > 120)
		{
			$errors[] = 'Заголовок длиннее 120 символов.';
		}
		if(mb_strlen($str('text')) > 2000)
		{
			$errors[] = 'Текст длиннее 2000 символов.';
		}
		if($str('id') !== '' && !preg_match(self::ID_PATTERN, strtolower($str('id'))))
		{
			$errors[] = 'Код объявления — латиница, цифры и дефис, до 40 знаков.';
		}
		if($str('url') !== '' && !preg_match('~^https://[^\s"\'<>]+$~', $str('url')))
		{
			$errors[] = 'Ссылка должна начинаться с https://';
		}
		$bad = Places::invalid($fields['places'] ?? []);
		if($bad !== [])
		{
			$errors[] = 'Неверный адрес в «Где показывать»: '.implode(', ', $bad).'. Адрес начинается с «/», например /crm/deal/.';
		}
		if(is_array($fields['places'] ?? null) && count($fields['places']) > Places::MAX_PER_ITEM)
		{
			$errors[] = 'Мест показа — не больше '.Places::MAX_PER_ITEM.'.';
		}
		foreach(['dateFrom' => 'начала', 'dateTo' => 'окончания'] as $key => $what)
		{
			if($str($key) !== '' && static::date($str($key)) === '')
			{
				$errors[] = 'Дата '.$what.' — в виде ГГГГ-ММ-ДД.';
			}
		}
		if(static::date($str('dateFrom')) !== '' && static::date($str('dateTo')) !== '' && static::date($str('dateFrom')) > static::date($str('dateTo')))
		{
			$errors[] = 'Дата начала позже даты окончания.';
		}
		if($str('video') !== '' && Media::video($str('video')) === null)
		{
			$errors[] = 'Видео: нужна https-ссылка на YouTube, Rutube, VK Видео или файл .mp4 с портала (/upload/…).';
		}
		if(($fields['layout'] ?? '') === self::LAYOUT_VIDEO && $str('video') === '')
		{
			$errors[] = 'Для вида «обучающее видео» укажите ссылку на видео.';
		}
		if(($fields['audience'] ?? '') === self::AUDIENCE_CUSTOM && static::ids($fields['users'] ?? null) === [] && static::ids($fields['departments'] ?? null) === [])
		{
			$errors[] = 'Выберите сотрудников или отделы.';
		}

		return $errors;
	}

	/**
	 * Своё объявление — в список: тот же id заменяется на месте, новый —
	 * в конец. Больше MAX_ITEMS — null.
	 *
	 * @param list<array> $own
	 * @return list<array>|null
	 */
	public static function upsert(array $own, array $item): ?array
	{
		$found = false;
		foreach($own as $i => $old)
		{
			if(($old['id'] ?? null) === $item['id'])
			{
				$own[$i] = $item;
				$found = true;
			}
		}
		if(!$found)
		{
			$own[] = $item;
		}

		return count($own) > self::MAX_ITEMS ? null : array_values($own);
	}

	/**
	 * Список без объявления с этим id.
	 *
	 * @param list<array> $own
	 * @return list<array>
	 */
	public static function remove(array $own, string $id): array
	{
		return array_values(array_filter($own, static fn(array $i): bool => ($i['id'] ?? null) !== $id));
	}

	/**
	 * Дата публикации своего объявления при сохранении: уже есть — та же;
	 * нет и объявление включено — сейчас (создание или первое включение);
	 * выключено — пока нет.
	 */
	public static function publishedOnSave(array $item, ?array $old, int $now): int
	{
		$was = (int)($old['published'] ?? 0);
		if($was > 0)
		{
			return $was;
		}

		return !empty($item['active']) ? $now : 0;
	}

	/** Поля для своего списка: без служебных ключей слияния (source, overrides). */
	public static function stored(array $item): array
	{
		unset($item['source'], $item['overrides']);

		return $item;
	}

	/**
	 * Дата ГГГГ-ММ-ДД или пусто.
	 */
	public static function date(mixed $value): string
	{
		$value = is_scalar($value) ? trim((string)$value) : '';
		if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))
		{
			return '';
		}

		return $value;
	}

	/**
	 * Время (метка Unix) из числа или строки даты («2026-10-17», «2026-10-17 10:00»); иначе 0.
	 */
	public static function stamp(mixed $value): int
	{
		if(is_numeric($value))
		{
			return max(0, (int)$value);
		}
		if(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', trim($value)))
		{
			return max(0, (int)strtotime(trim($value)));
		}

		return 0;
	}

	/**
	 * Положительные целые без повторов (ID сотрудников и отделов), не больше 100.
	 *
	 * @return list<int>
	 */
	public static function ids(mixed $value): array
	{
		$ids = [];
		foreach(is_array($value) ? $value : [] as $v)
		{
			if(is_numeric($v) && (int)$v > 0)
			{
				$ids[(int)$v] = (int)$v;
			}
		}

		return array_slice(array_values($ids), 0, 100);
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
					// картинку модулю не задать: только свой файл со страницы списка
					$item['image'] = 0;
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
	 * Значение ANNOUNCE_testusers в формате выбора сотрудников (shef.options
	 * Department). JSON-список отдаётся как есть; старое «ID через запятую»
	 * переводится в JSON — так выбор показывает ровно тех, кому реально идут
	 * тестовые объявления.
	 *
	 * @param array<int, string> $titles имена по ID; нет имени — «#ID»
	 */
	public static function testUsersSelectorValue(?string $value, array $titles = []): string
	{
		$value = trim((string)$value);
		if($value === '' || str_starts_with($value, '['))
		{
			return $value === '' ? '[]' : $value;
		}
		$rows = [];
		foreach(static::testUsers($value) as $id)
		{
			$rows[] = static::testUserRow($id, (string)($titles[$id] ?? ''));
		}

		return (string)json_encode($rows, JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Одна запись выбора сотрудников в формате shef.options Department.
	 *
	 * @return array{id: int, title: string, entityId: string, entityType: string, link: string, avatar: string}
	 */
	public static function testUserRow(int $id, string $title = ''): array
	{
		return [
			'id' => $id,
			'title' => $title !== '' ? $title : '#'.$id,
			'entityId' => 'user',
			'entityType' => 'employee',
			'link' => '',
			'avatar' => '',
		];
	}

	/**
	 * Первое объявление, которое пора показать пользователю (isDue()), по
	 * порядку sort, при равном — как в списке.
	 *
	 * @param list<array> $items
	 * @param list<string> $seen id из отметки «видел» (старый вид отметок)
	 * @param list<int> $testUsers
	 * @param array{now?: int, stamps?: array<string, array{t?: int, ok?: bool}>, departments?: list<int>, registered?: int} $ctx
	 *   now — текущее время; stamps — когда показали и нажал ли «Понятно»;
	 *   departments — отделы пользователя; registered — дата регистрации (0 — не знаем)
	 */
	public static function pick(array $items, array $seen, int $userId, array $testUsers, array $ctx = []): ?array
	{
		if($userId <= 0)
		{
			return null;
		}
		$seen = array_flip(array_map('strval', $seen));
		foreach(static::sorted($items) as $item)
		{
			if(static::isDue($item, isset($seen[$item['id']]), $ctx) && static::isFor($item, $userId, $testUsers, $ctx))
			{
				return $item;
			}
		}

		return null;
	}

	/** Объявления по sort (меньше — раньше), при равном — как в списке. */
	public static function sorted(array $items): array
	{
		$i = 0;
		$keyed = array_map(static function(array $item) use (&$i): array { return [(int)($item['sort'] ?? self::DEFAULT_SORT), $i++, $item]; }, $items);
		usort($keyed, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

		return array_column($keyed, 2);
	}

	/**
	 * Пора ли показывать: включено, в периоде, и повтор разрешает.
	 *
	 *  * once — пока нет отметки «видел»;
	 *  * until — пока не нажал главную кнопку или «Понятно» (stamps[id].ok);
	 *  * days — не было показа или с прошлого прошло repeatDays дней; нажал
	 *    «Понятно» — тоже через N дней снова.
	 *
	 * Период: dateFrom с начала дня, dateTo — до конца дня (время сервера).
	 */
	public static function isDue(array $item, bool $seen, array $ctx = []): bool
	{
		if(empty($item['active']))
		{
			return false;
		}
		$now = (int)($ctx['now'] ?? time());
		$from = (string)($item['dateFrom'] ?? '');
		$to = (string)($item['dateTo'] ?? '');
		if(($from !== '' && $now < (int)strtotime($from.' 00:00:00')) || ($to !== '' && $now > (int)strtotime($to.' 23:59:59')))
		{
			return false;
		}
		$stamp = $ctx['stamps'][$item['id']] ?? null;
		$stamp = is_array($stamp) ? $stamp : null;

		return match((string)($item['repeat'] ?? self::REPEAT_ONCE))
		{
			self::REPEAT_UNTIL => empty($stamp['ok']),
			self::REPEAT_DAYS => $stamp === null || $now - (int)($stamp['t'] ?? 0) >= max(1, (int)($item['repeatDays'] ?? 7)) * 86400,
			default => !$seen,
		};
	}

	/**
	 * Нужны ли данные сотрудника из базы (отделы, дата регистрации) для
	 * выбора среди этих объявлений: есть аудитория custom или объявление без
	 * showToNew с датой публикации. Иначе запрос не делаем.
	 *
	 * @param list<array> $items кандидаты (уже по месту и isDue())
	 */
	public static function needsUserContext(array $items): bool
	{
		foreach($items as $item)
		{
			if(($item['audience'] ?? '') === self::AUDIENCE_CUSTOM || (empty($item['showToNew']) && (int)($item['published'] ?? 0) > 0))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Для этого ли пользователя: аудитория и «новые сотрудники».
	 *
	 * Сотрудник, зарегистрированный позже публикации объявления (published),
	 * его не видит — если у объявления не включено showToNew. Не знаем дату
	 * регистрации или публикации — показываем.
	 */
	public static function isFor(array $item, int $userId, array $testUsers, array $ctx = []): bool
	{
		$registered = (int)($ctx['registered'] ?? 0);
		$published = (int)($item['published'] ?? 0);
		if(empty($item['showToNew']) && $registered > 0 && $published > 0 && $registered > $published)
		{
			return false;
		}

		return match((string)($item['audience'] ?? self::AUDIENCE_TEST))
		{
			self::AUDIENCE_ALL => true,
			self::AUDIENCE_CUSTOM => in_array($userId, (array)($item['users'] ?? []), true)
				|| array_intersect(array_map('intval', (array)($item['departments'] ?? [])), array_map('intval', (array)($ctx['departments'] ?? []))) !== [],
			default => in_array($userId, $testUsers, true),
		};
	}

	/**
	 * Дата публикации для включённых объявлений без неё: из журнала первого появления
	 * (id => время), а кого там нет — $now, и он дописывается в журнал. Так
	 * старые объявления после обновления получают дату «сейчас» и
	 * сотрудникам, которые уже работают, не пропадают.
	 *
	 * @param list<array> $items
	 * @param array<string, int> $firstSeen
	 * @return array{items: list<array>, firstSeen: array<string, int>, changed: bool}
	 */
	public static function fillPublished(array $items, array $firstSeen, int $now): array
	{
		$changed = false;
		foreach($items as $i => $item)
		{
			// выключенное ещё не опубликовано: дата — при первом включении
			if((int)($item['published'] ?? 0) > 0 || empty($item['active']))
			{
				continue;
			}
			$id = (string)$item['id'];
			if(!isset($firstSeen[$id]) || (int)$firstSeen[$id] <= 0)
			{
				$firstSeen[$id] = $now;
				$changed = true;
			}
			$items[$i]['published'] = (int)$firstSeen[$id];
		}

		return ['items' => $items, 'firstSeen' => $firstSeen, 'changed' => $changed];
	}

	/**
	 * Что показать за один заход: первая доступная новинка решает вид.
	 * Одно окно любого вида, кроме слайдов; слайды — все доступные слайды разом.
	 *
	 * @param list<array> $items
	 * @param list<string> $seen
	 * @param list<int> $testUsers
	 * @return array{layout: string, items: list<array>}|null
	 */
	public static function plan(array $items, array $seen, int $userId, array $testUsers, array $ctx = []): ?array
	{
		$available = [];
		$rest = $items;
		while(($item = static::pick($rest, $seen, $userId, $testUsers, $ctx)) !== null)
		{
			$available[] = $item;
			$rest = array_values(array_filter($rest, static fn(array $i): bool => $i['id'] !== $item['id']));
		}
		if($available === [])
		{
			return null;
		}
		if($available[0]['layout'] !== self::LAYOUT_SLIDES)
		{
			return ['layout' => $available[0]['layout'], 'items' => [$available[0]]];
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
	 * Адрес своей картинки для окна: только путь портала от корня (его отдаёт
	 * CFile::GetPath) или https; иначе пусто.
	 */
	public static function imageSrc(string $src): string
	{
		return preg_match('~^(?:/(?!/)|https://)[^\s"\'<>`\\\\]+$~', $src) ? $src : '';
	}

	/**
	 * Отметка показа для повторов: время показа и нажал ли «Понятно».
	 * «Понятно» не снимается следующим показом; повторный показ раз в N дней
	 * обновляет время. Не больше MAX_SEEN записей — самые старые уходят.
	 *
	 * @param array<string, array{t?: int, ok?: bool}> $stamps
	 * @return array<string, array{t: int, ok: bool}>
	 */
	public static function markStamp(array $stamps, string $id, int $now, bool $ok): array
	{
		$old = is_array($stamps[$id] ?? null) ? $stamps[$id] : [];
		unset($stamps[$id]);
		$stamps[$id] = ['t' => $now, 'ok' => $ok || !empty($old['ok'])];

		return array_slice($stamps, -self::MAX_SEEN, null, true);
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
			'items' => array_map(static fn(array $i): array => [
				'id' => $i['id'], 'title' => $i['title'], 'text' => $i['text'], 'url' => $i['url'], 'linkText' => $i['linkText'],
				'buttonColor' => isset(self::BUTTON_COLORS[$i['buttonColor'] ?? '']) ? $i['buttonColor'] : 'violet',
				'image' => static::imageSrc((string)($i['imageSrc'] ?? '')),
				'video' => ($i['layout'] ?? '') === self::LAYOUT_VIDEO ? Media::video((string)($i['video'] ?? '')) : null,
			], $plan['items']),
		]);
		$action = $json(Constants::SEEN_ACTION);

		return <<<JS
(function(){
var P = {$data};
if(window.top !== window || !window.BX) { return; }
function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function seen(ok){ P.items.forEach(function(a){ BX.ajax.runAction({$action}, {data: ok ? {id: a.id, ok: 'Y'} : {id: a.id}}); }); }
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
var CONF = '<svg viewBox="0 0 560 190" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
	+ '<g opacity=".9"><rect x="40" y="30" width="10" height="18" rx="2" fill="#ff5752" transform="rotate(20 45 39)"/><rect x="120" y="140" width="10" height="18" rx="2" fill="#2fc6f6" transform="rotate(-25 125 149)"/>'
	+ '<rect x="470" y="40" width="10" height="18" rx="2" fill="#ffa900" transform="rotate(35 475 49)"/><rect x="500" y="130" width="10" height="18" rx="2" fill="#9dcf00" transform="rotate(-15 505 139)"/>'
	+ '<circle cx="90" cy="70" r="6" fill="#ffa900"/><circle cx="430" cy="150" r="6" fill="#ff5752"/><circle cx="200" cy="30" r="5" fill="#9dcf00"/><circle cx="370" cy="35" r="5" fill="#2fc6f6"/>'
	+ '<circle cx="250" cy="165" r="4" fill="#8a6cff"/><circle cx="160" cy="110" r="4" fill="#ff5752"/><circle cx="400" cy="95" r="4" fill="#9dcf00"/></g>'
	+ '<path d="M250 140l30-90 30 90z" fill="#ffd166"/><path d="M262 104h36M256 122h48" stroke="#ff8c42" stroke-width="6"/><circle cx="280" cy="48" r="9" fill="#ff5752"/>'
	+ '<path d="M330 70c20-30 50-30 60-10M230 70c-20-30-50-30-60-10" stroke="#8a6cff" stroke-width="4" fill="none" stroke-linecap="round"/>'
	+ '</svg>';
var KIND = {
	cosmos: {eye: '✦ Новое', cls: ''},
	important: {eye: '! Важно', cls: ' --important', strict: true},
	danger: {eye: '⚠ Внимание', cls: ' --danger'},
	congrats: {eye: '🎉 Поздравляем', cls: ' --congrats', art: CONF},
	video: {eye: '▶ Обучающее видео', cls: ' --video', ok: 'Посмотрел'}
};
var CSS = '.shef-ann-wrap{background:transparent!important;box-shadow:none!important}'
	+ '.shef-ann{width:560px;max-width:calc(100vw - 32px);max-height:calc(100vh - 48px);overflow-y:auto;box-sizing:border-box;background:#fff;border-radius:20px;box-shadow:0 12px 40px rgba(31,22,77,.22);font-family:var(--ui-font-family-primary,"Open Sans",sans-serif);color:#333;border-top:6px solid transparent}'
	+ '.shef-ann.--video{width:720px}'
	+ '.shef-ann-head{position:relative;padding:22px 28px 0}'
	+ '.shef-ann-eye{display:inline-block;font-size:12px;font-weight:600;color:#5b3fd6;background:#f1ecff;border-radius:10px;padding:3px 10px;margin-bottom:10px}'
	+ '.shef-ann.--important{border-top-color:#ffa900}.shef-ann.--important .shef-ann-eye{color:#8a5a00;background:#fff3d6}'
	+ '.shef-ann.--danger{border-top-color:#ff5752}.shef-ann.--danger .shef-ann-eye{color:#c21b17;background:#ffe8e8}.shef-ann.--danger .shef-ann-art{background:#fff1f1}'
	+ '.shef-ann.--congrats .shef-ann-eye{color:#b04a00;background:#fff0e0}.shef-ann.--congrats .shef-ann-art{background:linear-gradient(135deg,#fff7e6 0%,#fdeefe 100%)}'
	+ '.shef-ann.--video .shef-ann-eye{color:#0b66c3;background:#e5f3ff}'
	+ '.shef-ann-t{font-size:22px;font-weight:600;line-height:1.25;color:#1f2433;padding-right:28px}'
	+ '.shef-ann-x{position:absolute;right:16px;top:16px;width:32px;height:32px;border:0;border-radius:50%;background:transparent;color:#a8adb4;font-size:22px;line-height:32px;cursor:pointer}.shef-ann-x:hover{background:#f2f2f5;color:#525c69}'
	+ '.shef-ann-art{margin:16px 28px 0;border-radius:14px;background:linear-gradient(135deg,#f6f2ff 0%,#eef5ff 100%);overflow:hidden}.shef-ann-art svg,.shef-ann-art img{display:block;width:100%;height:auto}.shef-ann-art img{max-height:300px;object-fit:cover}'
	+ '.shef-ann-video{position:relative;padding-top:56.25%;background:#000}.shef-ann-video iframe,.shef-ann-video video{position:absolute;inset:0;width:100%;height:100%;border:0}'
	+ '.shef-ann-b{padding:18px 28px 0;font-size:14px;line-height:1.55;color:#525c69}.shef-ann-b p{margin:0 0 12px}'
	+ '.shef-ann-b ul{list-style:none;margin:0 0 4px;padding:0;display:flex;flex-direction:column;gap:10px}'
	+ '.shef-ann-b li{position:relative;padding-left:30px;color:#333}'
	+ '.shef-ann-b li:before{content:"✓";position:absolute;left:0;top:1px;width:20px;height:20px;border-radius:50%;background:#e3f7ef;color:#1fa37a;font-size:12px;font-weight:700;line-height:20px;text-align:center}'
	+ '.shef-ann-btns{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;padding:20px 28px 24px}'
	+ '.shef-ann-btn{border:0;border-radius:10px;padding:11px 20px;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:filter .15s;color:#fff}.shef-ann-btn:hover{filter:brightness(1.07)}'
	+ '.shef-ann-btn.--violet{background:linear-gradient(135deg,#8a6cff,#5b3fd6);box-shadow:0 4px 12px rgba(91,63,214,.3)}'
	+ '.shef-ann-btn.--blue{background:#2fc6f6}.shef-ann-btn.--green{background:#1fa37a}.shef-ann-btn.--orange{background:#ffa900}.shef-ann-btn.--red{background:#ff5752}'
	+ '.shef-ann-btn.--ok{background:#1fa37a}.shef-ann-btn.--ok.--soft{background:#e3f7ef;color:#137a5a}'
	+ '.shef-ann-btn:focus-visible{outline:3px solid #b9a6ff;outline-offset:2px}'
	+ '@media (max-width:520px){.shef-ann-head,.shef-ann-b{padding-left:20px;padding-right:20px}.shef-ann-art{margin:14px 20px 0}.shef-ann-btns{padding:18px 20px 20px}.shef-ann-btn{flex:1}}';
function media(a, k){
	if(a.video && a.video.type === 'iframe') { return '<div class="shef-ann-video"><iframe src="' + esc(a.video.src) + '" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>'; }
	if(a.video && a.video.type === 'mp4') { return '<div class="shef-ann-video"><video src="' + esc(a.video.src) + '" controls preload="metadata"></video></div>'; }
	if(a.image) { return '<img src="' + esc(a.image) + '" alt="">'; }
	return k.art || ART;
}
function cosmos(onDone){
	var a = P.items[0];
	var k = KIND[P.layout] || KIND.cosmos;
	if(!document.getElementById('shef-ann-css')) { var st = document.createElement('style'); st.id = 'shef-ann-css'; st.textContent = CSS; document.head.appendChild(st); }
	var root = document.createElement('div');
	root.className = 'shef-ann' + k.cls;
	root.innerHTML = '<div class="shef-ann-head"><div class="shef-ann-eye">' + esc(k.eye) + '</div>'
		+ '<div class="shef-ann-t">' + esc(a.title) + '</div>' + (k.strict ? '' : '<button type="button" class="shef-ann-x" data-act="close" aria-label="Закрыть">×</button>') + '</div>'
		+ '<div class="shef-ann-art">' + media(a, k) + '</div><div class="shef-ann-b">' + body(a) + '</div>'
		+ '<div class="shef-ann-btns"><button type="button" class="shef-ann-btn --ok' + (a.url ? ' --soft' : '') + '" data-act="ok">' + esc(k.ok || 'Понятно') + '</button>'
		+ (a.url ? '<button type="button" class="shef-ann-btn --' + esc(a.buttonColor) + '" data-act="link">' + esc(a.linkText) + '</button>' : '') + '</div>';
	var popup = new BX.PopupWindow('shef-toolsai-announce', null, {
		content: root, padding: 0, contentPadding: 0, className: 'shef-ann-wrap', closeIcon: false, closeByEsc: !k.strict, autoHide: false,
		overlay: {backgroundColor: '#1f2433', opacity: 45},
		events: {onPopupClose: function(){ popup.destroy(); }, onPopupDestroy: function(){ onDone(); }}
	});
	root.addEventListener('click', function(e){
		var el = e.target.closest ? e.target.closest('[data-act]') : null;
		var act = el && el.getAttribute('data-act');
		if(act === 'link') { window.open(a.url, '_blank', 'noopener'); }
		if(act === 'link' || act === 'ok') { seen(true); }
		if(act) { popup.close(); }
	});
	popup.show();
	seen(false);
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
		seen(true);
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

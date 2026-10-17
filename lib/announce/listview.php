<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

/**
 * Строка таблицы на странице списка — без ядра и без HTML.
 *
 * Страница (admin/list.php) только экранирует и раскладывает то, что вернул
 * row(): подписи и CSS-классы штатных бейджей ui.label. Так подписи, цвета и
 * набор кнопок держит тест, а не глаз.
 *
 * С 1.4.0 таблицу рисует скрипт страницы из rows() (первый раз — из данных
 * страницы, потом — из ответа контроллера). У каждой строки одинаковый набор
 * мест: «Изменить» (если есть право записи) и меню «…» — menu, в одном и том
 * же порядке: «Показать мне снова», «Включить»/«Выключить», «Удалить» или
 * «Вернуть как в модуле».
 */
final class ListView
{
	/** Бейдж ui.label: подпись и цвет (класс ui-label-*). */
	public const LABEL_SETTINGS = 'ui-label-primary';
	public const LABEL_OVERRIDE = 'ui-label-warning';
	public const LABEL_MODULE = 'ui-label-default';
	public const LABEL_AUDIENCE_TEST = 'ui-label-lightorange';
	public const LABEL_AUDIENCE_ALL = 'ui-label-lightgreen';
	public const LABEL_LAYOUT = 'ui-label-light';
	public const LABEL_ON = 'ui-label-success';
	public const LABEL_OFF = 'ui-label-light';

	/**
	 * @param array $item объявление после Collector (нормализованное, с source и,
	 *   возможно, overrides)
	 * @return array{
	 *   id: string, title: string,
	 *   source: array{text: string, class: string},
	 *   audience: array{text: string, class: string},
	 *   layout: array{text: string, class: string},
	 *   active: array{text: string, class: string},
	 *   canEdit: bool,
	 *   delete: ?array{button: string, confirm: string},
	 *   menu: list<array{action: string, text: string, confirm?: string}>,
	 *   places: list<string>,
	 *   details: list<string>,
	 *   form: array<string, mixed>
	 * }
	 * @param list<array{path: string, title: string}> $catalog готовые места — для названий
	 */
	public static function row(array $item, bool $canEdit, array $catalog = []): array
	{
		$own = (string)($item['source'] ?? '') === Announcements::SOURCE_SETTINGS;
		$overrides = isset($item['overrides']) ? (string)$item['overrides'] : null;
		$audience = (string)($item['audience'] ?? Announcements::AUDIENCE_TEST);
		$layout = (string)($item['layout'] ?? Announcements::LAYOUT_COSMOS);
		$active = (bool)($item['active'] ?? false);

		if(!$own)
		{
			$source = ['text' => 'модуль '.(string)$item['source'], 'class' => self::LABEL_MODULE];
		}
		elseif($overrides !== null)
		{
			$source = ['text' => 'настройки, вместо '.$overrides, 'class' => self::LABEL_OVERRIDE];
		}
		else
		{
			$source = ['text' => 'настройки', 'class' => self::LABEL_SETTINGS];
		}

		$delete = null;
		if($canEdit && $own)
		{
			$delete = $overrides !== null
				? ['button' => 'Вернуть как в модуле', 'confirm' => 'Вернуть объявление как в модуле? Ваша копия удалится.']
				: ['button' => 'Удалить', 'confirm' => 'Удалить объявление? Отменить нельзя.'];
		}

		$menu = [['action' => 'again', 'text' => 'Показать мне снова']];
		if($canEdit)
		{
			$menu[] = ['action' => 'toggle', 'text' => $active ? 'Выключить' : 'Включить'];
		}
		if($delete !== null)
		{
			$menu[] = ['action' => 'delete', 'text' => $delete['button'], 'confirm' => $delete['confirm']];
		}
		$places = Places::normalizeList($item['places'] ?? null);
		$details = [];
		$from = (string)($item['dateFrom'] ?? '');
		$to = (string)($item['dateTo'] ?? '');
		if($from !== '' || $to !== '')
		{
			$details[] = trim(($from !== '' ? 'с '.$from : '').' '.($to !== '' ? 'по '.$to : ''));
		}
		$repeat = (string)($item['repeat'] ?? Announcements::REPEAT_ONCE);
		if($repeat === Announcements::REPEAT_DAYS)
		{
			$details[] = 'раз в '.(int)($item['repeatDays'] ?? 7).' дн.';
		}
		elseif($repeat === Announcements::REPEAT_UNTIL)
		{
			$details[] = Announcements::REPEATS[$repeat];
		}
		if(!empty($item['showToNew']))
		{
			$details[] = 'и новым сотрудникам';
		}
		$form = Announcements::stored($item);
		unset($form['published']);

		return [
			'id' => (string)($item['id'] ?? ''),
			'title' => (string)($item['title'] ?? ''),
			'source' => $source,
			'audience' => [
				'text' => Announcements::AUDIENCES[$audience] ?? $audience,
				'class' => $audience === Announcements::AUDIENCE_ALL ? self::LABEL_AUDIENCE_ALL : self::LABEL_AUDIENCE_TEST,
			],
			'layout' => ['text' => Announcements::LAYOUTS[$layout] ?? $layout, 'class' => self::LABEL_LAYOUT],
			'active' => $active
				? ['text' => 'включён', 'class' => self::LABEL_ON]
				: ['text' => 'выключен', 'class' => self::LABEL_OFF],
			'canEdit' => $canEdit,
			'delete' => $delete,
			'menu' => $menu,
			'places' => array_map(static fn(string $p): string => Places::title($p, $catalog), $places),
			'details' => $details,
			'form' => $form,
		];
	}

	/**
	 * Все строки таблицы.
	 *
	 * @param list<array> $items
	 * @param list<array{path: string, title: string}> $catalog
	 * @param (\Closure(int): string)|null $imageSrc путь своей картинки по id файла (CFile::GetPath)
	 * @return list<array>
	 */
	public static function rows(array $items, bool $canEdit, array $catalog = [], ?\Closure $imageSrc = null): array
	{
		return array_map(static function(array $item) use ($canEdit, $catalog, $imageSrc): array
		{
			$row = static::row($item, $canEdit, $catalog);
			$image = (int)($item['image'] ?? 0);
			$row['image'] = $imageSrc !== null && $image > 0 ? $imageSrc($image) : '';

			return $row;
		}, $items);
	}

	/**
	 * Кому идут тестовые объявления — имена через запятую; нет имени — «#ID»;
	 * никого — «никого».
	 *
	 * @param list<int> $ids
	 * @param array<int, string> $names
	 */
	public static function testUsersText(array $ids, array $names): string
	{
		$list = array_map(static fn(int $id): string => trim((string)($names[$id] ?? '')) !== '' ? trim((string)$names[$id]) : '#'.$id, $ids);

		return $list === [] ? 'никого' : implode(', ', $list);
	}
}

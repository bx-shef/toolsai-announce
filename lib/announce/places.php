<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

/**
 * «Где показывать» объявление — чистая логика, без ядра.
 *
 * Место — начало адреса страницы от корня сайта: путь и, при желании,
 * параметры запроса. Например `/crm/` (вся CRM), `/crm/deal/` (сделки) или
 * `/shef/toolsai/view/?type=2&category=0`.
 *
 * Совпадение адреса страницы с местом (matches()):
 *
 *  * путь места с «/» на конце — начало пути страницы: `/crm/` подходит к
 *    `/crm/deal/details/5/` и к `/crm` (без последней «/»);
 *  * путь без «/» на конце — тот же путь или его подкаталог: `/crm/deal`
 *    подходит к `/crm/deal/…`, но не к `/crm/dealer/`;
 *  * параметры места должны быть в адресе с теми же значениями, порядок не
 *    важен, лишние параметры адреса не мешают;
 *  * часть после «#» в месте отбрасывается — браузер её не присылает.
 *
 * У объявления без мест (старые объявления из настроек, объявления модулей,
 * которые места не передают) место одно — `/crm/`: так было до 1.4.0.
 *
 * Тесты — tests/places_test.php.
 */
final class Places
{
	/** Место по умолчанию — вся CRM (поведение до 1.4.0). */
	public const DEFAULT = '/crm/';
	/** Сколько мест у одного объявления. */
	public const MAX_PER_ITEM = 10;
	/** Сколько готовых мест в списке выбора. */
	public const MAX_CATALOG = 50;
	/** Длина адреса места. */
	public const MAX_LENGTH = 255;

	/** Готовые места модуля: адрес => название. */
	public const BUILTIN = [
		'/crm/' => 'Вся CRM',
		'/crm/deal/' => 'CRM: сделки',
		'/crm/lead/' => 'CRM: лиды',
		'/crm/contact/' => 'CRM: контакты',
		'/crm/company/' => 'CRM: компании',
		'/crm/quote/' => 'CRM: предложения',
	];

	/**
	 * Место в допустимом виде или null.
	 *
	 * Только адрес от корня сайта: начинается с одной «/», без пробелов,
	 * кавычек и угловых скобок, не длиннее MAX_LENGTH. Часть после «#»
	 * отбрасывается.
	 */
	public static function normalize(mixed $place): ?string
	{
		if(!is_scalar($place))
		{
			return null;
		}
		$place = trim((string)$place);
		$hash = strpos($place, '#');
		if($hash !== false)
		{
			$place = substr($place, 0, $hash);
		}
		if(
			$place === ''
			|| $place[0] !== '/'
			|| str_starts_with($place, '//')
			|| mb_strlen($place) > self::MAX_LENGTH
			|| preg_match('~[\s"\'<>`\\\\]~u', $place)
		)
		{
			return null;
		}
		// «?» без параметров ничего не добавляет
		return rtrim($place, '?&');
	}

	/**
	 * Места объявления: допустимые, без повторов, не больше MAX_PER_ITEM;
	 * пусто или не список — [DEFAULT].
	 *
	 * @return list<string>
	 */
	public static function normalizeList(mixed $places): array
	{
		if(is_string($places))
		{
			$places = [$places];
		}
		$result = [];
		foreach(is_array($places) ? $places : [] as $place)
		{
			$place = static::normalize($place);
			if($place === null)
			{
				continue;
			}
			$key = static::key($place);
			if(!isset($result[$key]))
			{
				$result[$key] = $place;
			}
			if(count($result) >= self::MAX_PER_ITEM)
			{
				break;
			}
		}

		return $result === [] ? [self::DEFAULT] : array_values($result);
	}

	/**
	 * Неправильные места из списка, который прислала форма, — для сообщения
	 * об ошибке (normalizeList() их молча отбрасывает).
	 *
	 * @return list<string>
	 */
	public static function invalid(mixed $places): array
	{
		$bad = [];
		foreach(is_array($places) ? $places : [] as $place)
		{
			if(static::normalize($place) === null)
			{
				$bad[] = is_scalar($place) ? (string)$place : '?';
			}
		}

		return $bad;
	}

	/**
	 * Ключ для сравнения мест: путь и параметры по алфавиту
	 * (`/a/?y=1&x=2` и `/a/?x=2&y=1` — одно место).
	 */
	public static function key(string $place): string
	{
		[$path, $query] = static::split($place);
		ksort($query);

		return $path.($query === [] ? '' : '?'.http_build_query($query));
	}

	/**
	 * Подходит ли адрес страницы (путь и параметры, как в адресной строке)
	 * к месту.
	 */
	public static function matches(string $place, string $uri): bool
	{
		$place = static::normalize($place);
		if($place === null)
		{
			return false;
		}
		[$placePath, $placeQuery] = static::split($place);
		[$path, $query] = static::split($uri);

		if(str_ends_with($placePath, '/'))
		{
			$pathOk = str_starts_with($path, $placePath) || $path.'/' === $placePath;
		}
		else
		{
			$pathOk = $path === $placePath || str_starts_with($path, $placePath.'/');
		}
		if(!$pathOk)
		{
			return false;
		}
		foreach($placeQuery as $name => $value)
		{
			if(!array_key_exists($name, $query) || $query[$name] != $value)
			{
				return false;
			}
		}

		return true;
	}

	/** Подходит ли адрес хотя бы к одному месту. */
	public static function matchesAny(array $places, string $uri): bool
	{
		foreach($places as $place)
		{
			if(is_string($place) && static::matches($place, $uri))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Объявления, которые показываются на этой странице (места — ключ places;
	 * нет ключа — DEFAULT).
	 *
	 * @param list<array> $items
	 * @return list<array>
	 */
	public static function filter(array $items, string $uri): array
	{
		return array_values(array_filter(
			$items,
			static fn(array $item): bool => static::matchesAny(static::normalizeList($item['places'] ?? null), $uri)
		));
	}

	/**
	 * Список готовых мест: свои (BUILTIN), потом ответы модулей. Повтор
	 * адреса — остаётся первое название. Не больше MAX_CATALOG.
	 *
	 * @param list<array{moduleId: string, places: mixed}> $sources ответы модулей
	 * @return list<array{path: string, title: string, source: string}>
	 */
	public static function catalog(array $sources = []): array
	{
		$result = [];
		foreach(self::BUILTIN as $path => $title)
		{
			$result[static::key($path)] = ['path' => $path, 'title' => $title, 'source' => ''];
		}
		foreach($sources as $source)
		{
			$moduleId = trim((string)($source['moduleId'] ?? ''));
			$list = $source['places'] ?? null;
			if($moduleId === '' || !is_array($list))
			{
				continue;
			}
			foreach($list as $row)
			{
				$path = is_array($row) ? static::normalize($row['path'] ?? null) : null;
				$title = is_array($row) && is_scalar($row['title'] ?? null) ? trim((string)$row['title']) : '';
				if($path === null || isset($result[static::key($path)]) || count($result) >= self::MAX_CATALOG)
				{
					continue;
				}
				$result[static::key($path)] = ['path' => $path, 'title' => mb_substr($title !== '' ? $title : $path, 0, 80), 'source' => $moduleId];
			}
		}

		return array_values($result);
	}

	/**
	 * Название места по списку готовых; своего адреса там нет — сам адрес.
	 *
	 * @param list<array{path: string, title: string}> $catalog
	 */
	public static function title(string $place, array $catalog): string
	{
		$key = static::key($place);
		foreach($catalog as $row)
		{
			if(static::key($row['path']) === $key)
			{
				return $row['title'];
			}
		}

		return $place;
	}

	/**
	 * Поиск в окне выбора: готовые места, где запрос есть в названии или
	 * адресе, и сам запрос как «свой адрес», если он похож на адрес и его нет
	 * среди готовых.
	 *
	 * @param list<array{path: string, title: string}> $catalog
	 * @return list<array{path: string, title: string, custom: bool}>
	 */
	public static function search(string $query, array $catalog): array
	{
		$query = trim($query);
		$found = [];
		$known = false;
		$own = static::normalize($query);
		foreach($catalog as $row)
		{
			if($query === '' || mb_stripos($row['title'], $query) !== false || mb_stripos($row['path'], $query) !== false)
			{
				$found[] = ['path' => $row['path'], 'title' => $row['title'], 'custom' => false];
			}
			if($own !== null && static::key($row['path']) === static::key($own))
			{
				$known = true;
			}
		}
		if($own !== null && !$known)
		{
			array_unshift($found, ['path' => $own, 'title' => $own, 'custom' => true]);
		}

		return $found;
	}

	/**
	 * Путь и параметры адреса.
	 *
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private static function split(string $uri): array
	{
		$hash = strpos($uri, '#');
		if($hash !== false)
		{
			$uri = substr($uri, 0, $hash);
		}
		$pos = strpos($uri, '?');
		$path = $pos === false ? $uri : substr($uri, 0, $pos);
		$query = [];
		if($pos !== false)
		{
			parse_str(substr($uri, $pos + 1), $query);
		}

		return [$path === '' ? '/' : $path, $query];
	}
}

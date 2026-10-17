<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

/**
 * Видео и картинка объявления — чистая логика, без ядра.
 *
 * Видео встраивается только с белого списка хостов и только по https:
 * YouTube (через youtube-nocookie.com), Rutube, VK Видео; файл .mp4 — тегом
 * <video> (https или адрес портала от корня). Чужой адрес — null, такое видео
 * не сохраняется. Адрес плеера собирается из разобранного id, а не из
 * присланной строки — в iframe не попадёт ничего, кроме id ролика.
 *
 * Картинка — свой файл: jpg, png, webp, gif, до MAX_IMAGE_SIZE. SVG нельзя
 * (в нём может быть скрипт). Тип проверяется по содержимому файла
 * (getimagesize), а не по имени.
 *
 * Тесты — tests/media_test.php.
 */
final class Media
{
	/** Хосты видео, которые можно встраивать. */
	public const VIDEO_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'rutube.ru', 'vk.com', 'vkvideo.ru', 'm.vk.com'];
	/** Размер картинки, байт. */
	public const MAX_IMAGE_SIZE = 2 * 1024 * 1024;
	/** Типы картинки (по содержимому): MIME => расширение. */
	public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

	/**
	 * Плеер для ссылки на видео или null.
	 *
	 * @return array{type: 'iframe'|'mp4', src: string}|null
	 */
	public static function video(string $url): ?array
	{
		$url = trim($url);
		if($url === '' || preg_match('~[\s"\'<>`\\\\]~', $url))
		{
			return null;
		}
		if(preg_match('~^/(?!/)[^?#]+\.mp4$~i', $url))
		{
			return ['type' => 'mp4', 'src' => $url];
		}
		$parts = parse_url($url);
		if(!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['port']))
		{
			return null;
		}
		$host = strtolower($parts['host']);
		$path = (string)($parts['path'] ?? '');
		parse_str((string)($parts['query'] ?? ''), $query);
		if(preg_match('~\.mp4$~i', $path))
		{
			return ['type' => 'mp4', 'src' => 'https://'.$host.$path];
		}
		if(!in_array($host, self::VIDEO_HOSTS, true))
		{
			return null;
		}
		$id = null;
		if($host === 'youtu.be' && preg_match('~^/([\w-]{11})$~', $path, $m))
		{
			$id = $m[1];
		}
		elseif(str_ends_with($host, 'youtube.com'))
		{
			if($path === '/watch' && is_string($query['v'] ?? null) && preg_match('~^[\w-]{11}$~', $query['v']))
			{
				$id = $query['v'];
			}
			elseif(preg_match('~^/(?:embed|shorts|live)/([\w-]{11})$~', $path, $m))
			{
				$id = $m[1];
			}
		}
		if($id !== null)
		{
			return ['type' => 'iframe', 'src' => 'https://www.youtube-nocookie.com/embed/'.$id];
		}
		if($host === 'rutube.ru' && preg_match('~^/(?:video|play/embed|shorts)/([0-9a-f]{32})/?$~', $path, $m))
		{
			return ['type' => 'iframe', 'src' => 'https://rutube.ru/play/embed/'.$m[1]];
		}
		if(in_array($host, ['vk.com', 'm.vk.com', 'vkvideo.ru'], true))
		{
			if(preg_match('~^/video(-?\d+)_(\d+)$~', $path, $m))
			{
				return ['type' => 'iframe', 'src' => 'https://vk.com/video_ext.php?oid='.$m[1].'&id='.$m[2]];
			}
			if($path === '/video_ext.php' && is_string($query['oid'] ?? null) && is_string($query['id'] ?? null)
				&& preg_match('~^-?\d+$~', $query['oid']) && preg_match('~^\d+$~', $query['id']))
			{
				return ['type' => 'iframe', 'src' => 'https://vk.com/video_ext.php?oid='.$query['oid'].'&id='.$query['id']];
			}
		}

		return null;
	}

	/**
	 * Ошибка загрузки картинки или null — можно сохранять.
	 *
	 * @param string $mime тип по содержимому (getimagesize()['mime']); '' — не картинка
	 */
	public static function imageError(string $name, int $size, string $mime): ?string
	{
		if($size <= 0)
		{
			return 'Файл не загрузился.';
		}
		if($size > self::MAX_IMAGE_SIZE)
		{
			return 'Картинка больше 2 МБ.';
		}
		if(preg_match('~\.svgz?$~i', $name) || str_contains($mime, 'svg'))
		{
			return 'SVG нельзя: загрузите jpg, png, webp или gif.';
		}
		if(!isset(self::IMAGE_TYPES[$mime]))
		{
			return 'Это не картинка jpg, png, webp или gif.';
		}

		return null;
	}
}

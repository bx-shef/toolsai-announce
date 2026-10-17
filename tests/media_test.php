<?php declare(strict_types=1);

/**
 * Видео и картинка объявления (Announce\Media) без ядра: белый список
 * видеохостов, адрес плеера только из id ролика, проверка картинки.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAnnounce\Announce\Media as M;

Check::group('видео');
$yt = ['type' => 'iframe', 'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
Check::same('YouTube watch', M::video('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10'), $yt);
Check::same('youtu.be', M::video('https://youtu.be/dQw4w9WgXcQ'), $yt);
Check::same('shorts', M::video('https://youtube.com/shorts/dQw4w9WgXcQ'), $yt);
Check::same('Rutube', M::video('https://rutube.ru/video/0123456789abcdef0123456789abcdef/'), ['type' => 'iframe', 'src' => 'https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef']);
Check::same('VK Видео', M::video('https://vkvideo.ru/video-123_456'), ['type' => 'iframe', 'src' => 'https://vk.com/video_ext.php?oid=-123&id=456']);
Check::same('VK встраивание', M::video('https://vk.com/video_ext.php?oid=-1&id=2&hash=x'), ['type' => 'iframe', 'src' => 'https://vk.com/video_ext.php?oid=-1&id=2']);
Check::same('внешний mp4 — нет', M::video('https://cdn.example.by/a/b.mp4'), null);
Check::same('mp4 "//хост" — нет', M::video('//cdn.example.by/b.mp4'), null);
Check::same('mp4 с портала', M::video('/upload/v.mp4'), ['type' => 'mp4', 'src' => '/upload/v.mp4']);
Check::same('http — нет', M::video('http://youtu.be/dQw4w9WgXcQ'), null);
Check::same('чужой хост — нет', M::video('https://evil.by/embed/dQw4w9WgXcQ'), null);
Check::same('похожий хост — нет', M::video('https://youtube.com.evil.by/watch?v=dQw4w9WgXcQ'), null);
Check::same('кривой id — нет', M::video('https://youtu.be/"><script>'), null);
Check::same('javascript — нет', M::video('javascript:alert(1)'), null);
Check::same('логин в адресе — нет', M::video('https://a@youtu.be/dQw4w9WgXcQ'), null);

Check::group('картинка');
Check::same('png — можно', M::imageError('a.png', 1000, 'image/png'), null);
Check::same('svg — нельзя', M::imageError('a.svg', 1000, 'image/svg+xml') !== null, true);
Check::same('svg под видом png по имени — нельзя', M::imageError('a.svg', 1000, 'image/png') !== null, true);
Check::same('не картинка — нельзя', M::imageError('a.png', 1000, '') !== null, true);
Check::same('больше 2 МБ — нельзя', M::imageError('a.jpg', M::MAX_IMAGE_SIZE + 1, 'image/jpeg') !== null, true);
Check::same('пустой — нельзя', M::imageError('a.jpg', 0, 'image/jpeg') !== null, true);

Check::finish();

<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Main;

/**
 * Заглушка страницы списка «Новинки для сотрудников» в /bitrix/admin.
 *
 * Перенесено из shef.problems (Shef\Problems\Main\AdminPage) с заменой
 * имён файлов.
 *
 * Каталог модуля браузеру недоступен, поэтому в /bitrix/admin лежит файл в
 * одну строку, как принято в Битриксе:
 *
 *   <?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/shef.toolsaiannounce/admin/list.php');
 *
 * Путь в нём — туда, где модуль стоит НА САМОМ ДЕЛЕ: /bitrix/modules или
 * /local/modules. Готовый файл с зашитым /bitrix/modules модулю из
 * /local/modules дал бы белую страницу, поэтому заглушку не копируют, а
 * пишут: установщик и меню знают каталог модуля (dirname(__DIR__)).
 * Модуль вне корня сайта (симлинк) — путь абсолютный.
 *
 * Удаляется заглушка только СВОЯ — та, что ведёт на admin/list.php модуля
 * shef.toolsaiannounce. Проект мог положить на это место свой файл, и деинсталляция
 * не вправе его сносить; по той же причине чужой файл не перезаписывается.
 *
 * Класс самодостаточен — без зависимостей от ядра и модуля: установщик
 * подключает его явным require_once, на автозагрузку в установщике
 * полагаться нельзя.
 */
class AdminPage
{
	public const FILE = 'shef_toolsaiannounce_list.php';
	public const MODULE_PAGE = '/admin/list.php';

	/**
	 * Своя заглушка: require на .../shef.toolsaiannounce/admin/list.php — от корня
	 * сайта или абсолютным путём. Так выглядит любая версия, которую писал
	 * модуль, включая прежнюю с зашитым /bitrix/modules.
	 */
	private const OWN_PATTERN = '#^<\?php require\((?:\$_SERVER\[\'DOCUMENT_ROOT\'\]\.)?\'[^\']*/shef\.toolsaiannounce/admin/list\.php\'\);\s*$#';

	/** Полный путь заглушки в /bitrix/admin портала. */
	public static function getTarget(string $documentRoot): string
	{
		return rtrim($documentRoot, '/').'/bitrix/admin/'.static::FILE;
	}

	/**
	 * Содержимое заглушки для модуля, лежащего в $moduleDir.
	 */
	public static function getContent(string $documentRoot, string $moduleDir): string
	{
		$documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
		$page = rtrim(str_replace('\\', '/', $moduleDir), '/').static::MODULE_PAGE;

		if ($documentRoot !== '' && str_starts_with($page, $documentRoot.'/')) {
			return sprintf(
				"<?php require(\$_SERVER['DOCUMENT_ROOT'].'%s');\n",
				substr($page, strlen($documentRoot))
			);
		}

		return sprintf("<?php require('%s');\n", $page);
	}

	/**
	 * Своя заглушка: та, что модуль написал бы сейчас из $moduleDir, либо
	 * любая прежняя его версия — require на .../shef.toolsaiannounce/admin/list.php.
	 */
	public static function isOwn(string $content, string $documentRoot = '', string $moduleDir = ''): bool
	{
		if ($moduleDir !== '' && $content === static::getContent($documentRoot, $moduleDir)) {
			return true;
		}

		return 1 === preg_match(static::OWN_PATTERN, $content);
	}

	/**
	 * Положить заглушку. Нет файла — пишет. Своя, но с другим путём (модуль
	 * переехали, прежняя версия с зашитым путём) — переписывает. Чужая —
	 * не трогает.
	 *
	 * @return bool заглушка на месте и ведёт в этот модуль
	 */
	public static function install(string $documentRoot, string $moduleDir): bool
	{
		$target = static::getTarget($documentRoot);
		$content = static::getContent($documentRoot, $moduleDir);

		if (is_file($target)) {
			$current = (string)file_get_contents($target);
			if ($current === $content) {
				return true;
			}

			if (!static::isOwn($current, $documentRoot, $moduleDir)) {
				return false;
			}
		}

		if (!is_dir(dirname($target)) || !is_writable(dirname($target))) {
			return false;
		}

		return false !== file_put_contents($target, $content);
	}

	/**
	 * Убрать заглушку — только свою.
	 *
	 * @return bool своей заглушки больше нет
	 */
	public static function uninstall(string $documentRoot, string $moduleDir): bool
	{
		$target = static::getTarget($documentRoot);

		if (!is_file($target)) {
			return true;
		}

		if (!static::isOwn((string)file_get_contents($target), $documentRoot, $moduleDir)) {
			return false;
		}

		return unlink($target);
	}
}

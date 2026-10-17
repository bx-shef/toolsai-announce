<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Main;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;

/**
 * Обновление без установщика (1.4.1) — лёгкий вариант Main\Maintenance
 * shef.toolsaiassistant.
 *
 * На портале модуль обновляют копированием файлов в bitrix/modules, установщик
 * при этом не запускается — новая подписка из installEvents (1.4.1:
 * shef.toolsai:OnViewSettingsMenuBuild) сама не появится. Поэтому при первом
 * хите после смены версии (OnEpilog — подписка есть с первой версии) модуль
 * ставит подписки installEvents. registerEventHandler ядра идемпотентен
 * (INSERT IGNORE по UNIQUE_ID, main/lib/eventmanager.php), повтор безвреден.
 *
 * Версия, до которой довели, — опция SYS_eventsversion. Совпала — одно чтение
 * опции из кеша ядра. Сбой — версию не пишем, попробуем на следующем хите.
 * Исключений наружу нет.
 */
final class Maintenance
{
	public const OPTION_VERSION = 'SYS_eventsversion';

	private static bool $checked = false;

	/** Раз за хит: версия отстала — поставить подписки. */
	public static function ensureOnce(): void
	{
		if(self::$checked)
		{
			return;
		}
		self::$checked = true;
		try
		{
			$version = static::moduleVersion(dirname(__DIR__, 2));
			if(static::isNeeded((string)Option::get(Constants::MODULE_ID, self::OPTION_VERSION, ''), $version))
			{
				static::registerEvents((array)(Configuration::getInstance(Constants::MODULE_ID)->get('installEvents') ?? []));
				Option::set(Constants::MODULE_ID, self::OPTION_VERSION, $version);
			}
		}
		catch(\Throwable)
		{
		}
	}

	/** Нужно ли: сохранённая версия не та, что у модуля. */
	public static function isNeeded(string $saved, string $current): bool
	{
		return $current !== '' && $saved !== $current;
	}

	/** Подписки из installEvents — как InstallEvents() установщика, без снятия. */
	public static function registerEvents(array $events): void
	{
		$manager = EventManager::getInstance();
		foreach($events as $event)
		{
			$method = !empty($event['isCompatible']) ? 'registerEventHandlerCompatible' : 'registerEventHandler';
			$manager->{$method}(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
				(int)($event['to']['sort'] ?? 100)
			);
		}
	}

	/** Версия модуля из install/version.php. */
	public static function moduleVersion(string $moduleDir): string
	{
		$arModuleVersion = [];
		$file = $moduleDir.'/install/version.php';
		if(is_file($file))
		{
			include $file;
		}

		return (string)($arModuleVersion['VERSION'] ?? '');
	}

	/** Для тестов: снова проверить в этом же процессе. */
	public static function reset(): void
	{
		self::$checked = false;
	}
}

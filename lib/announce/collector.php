<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAnnounce\Main\Constants;
use Shef\ToolsAiAnnounce\Public\Event\OnAnnouncementsCollectEvent;

/**
 * Итоговый список объявлений: свой список из настроек плюс объявления
 * модулей из события OnAnnouncementsCollect (Announcements::merge()).
 *
 * Модули зовутся по одному (фильтр события по TO_MODULE_ID): исключение
 * обработчика одного модуля уходит в конфликты и лог, ответы остальных
 * остаются. Обработчики без TO_MODULE_ID (runtime addEventHandler без
 * модуля) не зовутся — подписка модуля-источника постоянная, из установщика.
 *
 * Список для страниц CRM кешируется (Bitrix\Main\Data\Cache, CACHE_TTL);
 * сброс — clearCache() при сохранении списка на странице «Оповещения для
 * сотрудников». Тестовые пользователи и отметки в кеш не входят. Конфликты id пишутся в лог один раз — пока набор
 * конфликтов не изменился (отпечаток в SYS_conflicts), иначе каждая
 * страница CRM писала бы одно и то же.
 */
final class Collector
{
	/** Сколько живёт кеш списка, секунд. */
	public const CACHE_TTL = 600;
	/** Каталог кеша. */
	public const CACHE_DIR = '/shef.toolsaiannounce';
	private const CACHE_KEY = 'announcements';

	/**
	 * Итоговый список (с ключом source у каждого объявления).
	 *
	 * @return list<array>
	 */
	public static function collect(): array
	{
		try
		{
			$cache = Cache::createInstance();
			if($cache->initCache(self::CACHE_TTL, self::CACHE_KEY, self::CACHE_DIR))
			{
				$items = $cache->getVars();
				if(is_array($items))
				{
					return $items;
				}
			}
		}
		catch(\Throwable)
		{
			$cache = null;
		}

		$merged = static::collectWithConflicts();
		static::logConflicts($merged['conflicts']);
		try
		{
			if($cache !== null && $cache->startDataCache())
			{
				$cache->endDataCache($merged['items']);
			}
		}
		catch(\Throwable)
		{
			// Без кеша — просто медленнее
		}

		return $merged['items'];
	}

	/** Сбросить кеш списка (после правки списка или смены модулей-источников). */
	public static function clearCache(): void
	{
		try
		{
			Cache::createInstance()->cleanDir(self::CACHE_DIR);
		}
		catch(\Throwable)
		{
		}
	}

	/**
	 * Список и конфликты — без записи в лог (страница списка показывает их сама).
	 *
	 * @return array{items: list<array>, conflicts: list<string>}
	 */
	public static function collectWithConflicts(): array
	{
		$own = Announcements::parse((string)Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, ''));
		$conflicts = [];
		$sources = static::fromResults(static::send($conflicts));
		$merged = Announcements::merge($own, $sources);
		$merged['conflicts'] = array_merge($conflicts, $merged['conflicts']);
		$merged['items'] = static::withPublished($merged['items']);

		return $merged;
	}

	/**
	 * Дата публикации объявлениям без неё — из журнала первого появления
	 * (SYS_firstseen, Announcements::fillPublished()). Новые id дописываются.
	 *
	 * @param list<array> $items
	 * @return list<array>
	 */
	public static function withPublished(array $items): array
	{
		try
		{
			$log = json_decode((string)Option::get(Constants::MODULE_ID, Constants::OPTION_FIRST_SEEN, ''), true);
			$filled = Announcements::fillPublished($items, is_array($log) ? array_map('intval', $log) : [], time());
			if($filled['changed'])
			{
				// не больше 500 записей: старые уходят
				Option::set(Constants::MODULE_ID, Constants::OPTION_FIRST_SEEN, (string)json_encode(array_slice($filled['firstSeen'], -500, null, true)));
			}

			return $filled['items'];
		}
		catch(\Throwable)
		{
			return $items;
		}
	}

	/**
	 * Ответы обработчиков в форму для merge(): только SUCCESS.
	 *
	 * @param iterable<mixed> $results EventResult из Event::getResults()
	 * @return list<array{moduleId: string, announcements: mixed}>
	 */
	public static function fromResults(iterable $results): array
	{
		$sources = [];
		foreach($results as $result)
		{
			if(!$result instanceof EventResult || $result->getType() !== EventResult::SUCCESS)
			{
				continue;
			}
			$params = $result->getParameters();
			$sources[] = [
				'moduleId' => (string)$result->getModuleId(),
				'announcements' => is_array($params) ? ($params['announcements'] ?? null) : null,
			];
		}

		return $sources;
	}

	/**
	 * Отправить событие каждому модулю-подписчику отдельно.
	 *
	 * @param list<string> $errors сюда — сбои обработчиков
	 * @return list<mixed> ответы обработчиков
	 */
	private static function send(array &$errors): array
	{
		return static::sendEach(OnAnnouncementsCollectEvent::TYPE, static fn(string $moduleId): \Bitrix\Main\Event => new OnAnnouncementsCollectEvent($moduleId), $errors);
	}

	/**
	 * Отправить событие модуля каждому модулю-подписчику отдельно.
	 *
	 * @param \Closure(string): \Bitrix\Main\Event $make событие для одного модуля
	 * @param list<string> $errors сюда — сбои обработчиков
	 * @return list<mixed> ответы обработчиков
	 */
	public static function sendEach(string $type, \Closure $make, array &$errors): array
	{
		$modules = [];
		foreach(EventManager::getInstance()->findEventHandlers(Constants::MODULE_ID, $type) as $handler)
		{
			$moduleId = (string)($handler['TO_MODULE_ID'] ?? '');
			if($moduleId !== '')
			{
				$modules[$moduleId] = true;
			}
		}
		$results = [];
		foreach(array_keys($modules) as $moduleId)
		{
			try
			{
				$event = $make($moduleId);
				$event->send();
				array_push($results, ...array_values($event->getResults()));
			}
			catch(\Throwable $throwable)
			{
				$errors[] = $moduleId.': обработчик события упал: '.$throwable->getMessage();
			}
		}

		return $results;
	}

	/**
	 * Записать конфликты в лог shef.problems, если набор изменился.
	 *
	 * @param list<string> $conflicts
	 */
	private static function logConflicts(array $conflicts): void
	{
		try
		{
			$hash = $conflicts === [] ? '' : md5(implode("\n", $conflicts));
			if($hash === (string)Option::get(Constants::MODULE_ID, Constants::OPTION_CONFLICTS, ''))
			{
				return;
			}
			Option::set(Constants::MODULE_ID, Constants::OPTION_CONFLICTS, $hash);
			if($conflicts === [] || !class_exists(\Shef\Problems\Factory\SystemLoggerFactory::class))
			{
				return;
			}
			\Shef\Problems\Factory\SystemLoggerFactory::build(
				logLevel: \Monolog\Level::Warning,
				auditType: \Shef\Problems\Main\Constants::AuditTypeProblem,
				moduleId: Constants::MODULE_ID,
				className: static::class,
				assigned: \Shef\Problems\Main\Constants::getDefUserId(),
			)->warning('Оповещения для сотрудников: конфликты объявлений', ['conflicts' => $conflicts]);
		}
		catch(\Throwable)
		{
			// Лог — не повод ломать CRM
		}
	}
}

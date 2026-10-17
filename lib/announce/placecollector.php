<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Bitrix\Main\EventResult;
use Bitrix\Main\ModuleManager;
use Shef\ToolsAiAnnounce\Public\Event\OnAnnouncementPlacesCollectEvent;

/**
 * Готовые места для поля «Где показывать»: свои (Places::BUILTIN), вид «ИИ»
 * модуля shef.toolsai, если он стоит (только адрес, без вызова его классов),
 * и ответы модулей на событие OnAnnouncementPlacesCollect.
 *
 * Список нужен только окну выбора и странице списка — на страницах портала
 * не собирается, поэтому без кеша.
 */
final class PlaceCollector
{
	/** Вид «ИИ» shef.toolsai: показываем в списке, когда модуль стоит. */
	public const TOOLSAI_VIEW = ['path' => '/shef/toolsai/view/', 'title' => 'Вид «ИИ»'];

	/**
	 * @return list<array{path: string, title: string, source: string}>
	 */
	public static function collect(): array
	{
		$sources = [];
		try
		{
			if(class_exists(ModuleManager::class) && ModuleManager::isModuleInstalled('shef.toolsai'))
			{
				$sources[] = ['moduleId' => 'shef.toolsai', 'places' => [self::TOOLSAI_VIEW]];
			}
		}
		catch(\Throwable)
		{
		}
		$errors = [];
		$results = Collector::sendEach(OnAnnouncementPlacesCollectEvent::TYPE, static fn(string $moduleId) => new OnAnnouncementPlacesCollectEvent($moduleId), $errors);

		return Places::catalog(array_merge(static::fromResults($results), $sources));
	}

	/**
	 * Ответы обработчиков: только SUCCESS с модулем-источником.
	 *
	 * @param iterable<mixed> $results
	 * @return list<array{moduleId: string, places: mixed}>
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
			$sources[] = ['moduleId' => (string)$result->getModuleId(), 'places' => is_array($params) ? ($params['places'] ?? null) : null];
		}

		return $sources;
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAnnounce\Main\Constants;
use Shef\ToolsAiAnnounce\Public\Event\OnAnnouncementsCollectEvent;

/**
 * Итоговый список объявлений: свой список из настроек плюс объявления
 * модулей из события OnAnnouncementsCollect (Announcements::merge()).
 *
 * Сбой обработчика чужого модуля не ломает показ: исключение уходит в лог,
 * остаётся свой список. Конфликты id пишутся в лог один раз — пока набор
 * конфликтов не изменился (отпечаток в SYS_conflicts), иначе каждая
 * страница CRM писала бы одно и то же.
 */
final class Collector
{
	/**
	 * Итоговый список (с ключом source у каждого объявления).
	 *
	 * @return list<array>
	 */
	public static function collect(): array
	{
		$merged = static::collectWithConflicts();
		static::logConflicts($merged['conflicts']);

		return $merged['items'];
	}

	/**
	 * Список и конфликты — без записи в лог (страница списка показывает их сама).
	 *
	 * @return array{items: list<array>, conflicts: list<string>}
	 */
	public static function collectWithConflicts(): array
	{
		$own = Announcements::parse((string)Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, ''));
		$sources = [];
		$conflicts = [];
		try
		{
			$sources = static::fromResults(static::send());
		}
		catch(\Throwable $throwable)
		{
			$conflicts[] = 'обработчик события упал: '.$throwable->getMessage();
		}
		$merged = Announcements::merge($own, $sources);
		$merged['conflicts'] = array_merge($conflicts, $merged['conflicts']);

		return $merged;
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
	 * Отправить событие.
	 *
	 * @return array<mixed> ответы обработчиков
	 */
	private static function send(): array
	{
		$event = new OnAnnouncementsCollectEvent();
		$event->send();

		return $event->getResults();
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
			)->warning('Новинки для сотрудников: конфликты объявлений', ['conflicts' => $conflicts]);
		}
		catch(\Throwable)
		{
			// Лог — не повод ломать CRM
		}
	}
}

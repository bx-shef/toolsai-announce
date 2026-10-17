<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Announce;

/**
 * Строка таблицы на странице списка — без ядра и без HTML.
 *
 * Страница (admin/list.php) только экранирует и раскладывает то, что вернул
 * row(): подписи и CSS-классы штатных бейджей ui.label. Так подписи, цвета и
 * набор кнопок держит тест, а не глаз.
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
	 *   delete: ?array{button: string, confirm: string}
	 * }
	 */
	public static function row(array $item, bool $canEdit): array
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
		];
	}
}

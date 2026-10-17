<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Integration\Ui\EntitySelector;

use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;
use Shef\ToolsAiAnnounce\Announce\PlaceCollector;
use Shef\ToolsAiAnnounce\Announce\Places;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Окно выбора «Где показывать» (ui.entity-selector), сущность
 * shef-announce-place. Регистрация — ключ 'ui.entity-selector' в
 * .settings.php модуля (ядро читает его у всех установленных модулей:
 * ui/lib/entityselector/configuration.php, load()).
 *
 * id элемента — сам адрес места. Готовые места — PlaceCollector; в поиске
 * введённый адрес, начинающийся с «/», предлагается как «свой адрес»
 * (Places::search()). Доступно тем, у кого право на модуль не ниже R.
 */
class PlaceProvider extends BaseProvider
{
	private const TAB = 'shef-announce-places';

	/** @param array<string, mixed> $options */
	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->options = [];
	}

	public function isAvailable(): bool
	{
		global $APPLICATION, $USER;

		return is_object($USER) && $USER->IsAuthorized() && is_object($APPLICATION) && $APPLICATION->GetGroupRight(Constants::MODULE_ID) >= 'R';
	}

	/** @return list<Item> */
	public function getItems(array $ids): array
	{
		$catalog = PlaceCollector::collect();
		$items = [];
		foreach($ids as $id)
		{
			$place = Places::normalize($id);
			if($place !== null)
			{
				$title = Places::title($place, $catalog);
				$items[] = $this->item($place, $title, $title === $place);
			}
		}

		return $items;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab(['id' => self::TAB, 'title' => 'Места', 'stub' => true]));
		foreach(PlaceCollector::collect() as $row)
		{
			$dialog->addItem($this->item($row['path'], $row['title'], false));
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		// свой адрес зависит от всей строки — поиск по началу кешировать нельзя
		$searchQuery->setCacheable(false);
		foreach(Places::search($searchQuery->getRawQuery(), PlaceCollector::collect()) as $row)
		{
			$dialog->addItem($this->item($row['path'], $row['custom'] ? 'Свой адрес: '.$row['path'] : $row['title'], $row['custom']));
		}
	}

	private function item(string $path, string $title, bool $custom): Item
	{
		return new Item([
			'id' => $path,
			'entityId' => Constants::PLACE_ENTITY,
			'title' => $title,
			'subtitle' => $custom ? 'начало адреса страницы' : $path,
			'tabs' => self::TAB,
		]);
	}
}

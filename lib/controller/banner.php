<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\SeenStore;

/**
 * Окно новинки:
 *
 *   BX.ajax.runAction('shef:toolsaiannounce.banner.seen', {data: {id}})
 *
 * Только POST, sessid, авторизованный пользователь. Пишет отметку только
 * себе — чужую отметку поставить нельзя.
 */
class Banner extends Controller
{
	/**
	 * Фильтры действий.
	 *
	 * @return array<string, array>
	 */
	public function configureActions(): array
	{
		return [
			'seen' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
		];
	}

	/**
	 * Объявление показано — второй раз не показывать.
	 *
	 * @return array{ok: bool}|null
	 */
	public function seenAction(string $id): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if($userId <= 0 || !preg_match(Announcements::ID_PATTERN, $id))
		{
			$this->addError(new Error('Неверный запрос'));

			return null;
		}
		SeenStore::mark($userId, $id);

		return ['ok' => true];
	}
}

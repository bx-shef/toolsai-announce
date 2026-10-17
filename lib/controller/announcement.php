<?php declare(strict_types=1);

namespace Shef\ToolsAiAnnounce\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\ListView;
use Shef\ToolsAiAnnounce\Announce\Media;
use Shef\ToolsAiAnnounce\Announce\OwnStore;
use Shef\ToolsAiAnnounce\Announce\PlaceCollector;
use Shef\ToolsAiAnnounce\Announce\SeenStore;
use Shef\ToolsAiAnnounce\Main\Constants;

/**
 * Страница списка «Оповещения для сотрудников» — все действия без
 * перезагрузки:
 *
 *   BX.ajax.runAction('shef:toolsaiannounce.announcement.<действие>', {data: {...}})
 *
 * | действие | право модуля | что делает |
 * |---|---|---|
 * | list | R | строки таблицы и конфликты |
 * | again | R | снять у себя отметку «видел» («Показать мне снова») |
 * | save | W | создать или изменить; у модульного — своя копия |
 * | delete | W | удалить своё; у копии модульного — «Вернуть как в модуле» |
 * | toggle | W | включить или выключить; у модульного — своя копия |
 * | uploadImage | W | своя картинка: файл в штатное хранилище (CFile) |
 *
 * У всех: авторизация, только POST, CSRF (sessid — его кладёт
 * BX.ajax.runAction сам). Право модуля — $APPLICATION->GetGroupRight()
 * (администратор портала — всегда W). Ошибки — addError(), их показывает
 * окно. Ответ save/delete/toggle — свежие строки, страница перерисовывает
 * таблицу.
 *
 * Метод run() не объявляем: в ядре он final (main/lib/engine/controller.php).
 */
class Announcement extends Controller
{
	/** Фильтры: одни и те же у всех действий. */
	protected function getDefaultPreFilters()
	{
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}

	/** Строки таблицы и конфликты. */
	public function listAction(): ?array
	{
		return $this->can('R') ? $this->rows() : null;
	}

	/** Показать мне снова: снять у себя отметки. */
	public function againAction(string $id): ?array
	{
		if(!$this->can('R') || !$this->validId($id))
		{
			return null;
		}
		SeenStore::forget((int)$this->getCurrentUser()?->getId(), $id);

		return ['ok' => true];
	}

	/**
	 * Создать или изменить объявление. Пустой id — новое.
	 *
	 * @param array<string, mixed> $fields поля формы
	 */
	public function saveAction(array $fields): ?array
	{
		if(!$this->can('W'))
		{
			return null;
		}
		$errors = Announcements::validate($fields);
		if($errors !== [])
		{
			foreach($errors as $message)
			{
				$this->addError(new Error($message, 'invalid'));
			}

			return null;
		}
		$id = strtolower(trim((string)($fields['id'] ?? '')));
		// новое: дата и случайный хвост — два сохранения в одну секунду не совпадут
		$fields['id'] = $id !== '' ? $id : 'n-'.date('Ymd').'-'.bin2hex(random_bytes(4));
		$fields['active'] = in_array($fields['active'] ?? '', ['Y', 'true', true, 1, '1'], true);
		$fields['showToNew'] = in_array($fields['showToNew'] ?? '', ['Y', 'true', true, 1, '1'], true);
		$old = $this->find($fields['id']);
		$image = is_numeric($fields['image'] ?? null) ? (int)$fields['image'] : 0;
		if($image > 0 && $image !== (int)($old['image'] ?? 0) && !$this->ownFile($image))
		{
			$this->addError(new Error('Картинка не найдена — загрузите её заново.', 'image'));

			return null;
		}
		$fields['published'] = Announcements::publishedOnSave($fields, $old, time());

		return $this->store(Announcements::normalize($fields), $old);
	}

	/**
	 * Включить или выключить. Флажок переворачивается у объявления из списка в
	 * БД под блокировкой (OwnStore::update()), а не у прочитанного до неё:
	 * правка из MCP между чтением и записью не теряется, удалённое своё не
	 * воскресает. Модульное без своей копии — копией из итогового списка.
	 */
	public function toggleAction(string $id): ?array
	{
		if(!$this->can('W') || !$this->validId($id))
		{
			return null;
		}
		$merged = $this->find($id);
		if($merged === null)
		{
			$this->addError(new Error('Объявление не найдено. Обновите страницу.', 'not_found'));

			return null;
		}
		$gone = false;
		$full = false;
		$invalid = false;
		$ok = $this->locked(static function() use($id, $merged, &$gone, &$full, &$invalid): void
		{
			OwnStore::update(static function(array $own) use($id, $merged, &$gone, &$full, &$invalid): ?array
			{
				$old = null;
				foreach($own as $item)
				{
					if($item['id'] === $id)
					{
						$old = $item;
						break;
					}
				}
				if($old === null && ($merged['source'] ?? '') === Announcements::SOURCE_SETTINGS)
				{
					$gone = true;

					return null;
				}
				$old ??= $merged;
				$old['published'] = max((int)($old['published'] ?? 0), (int)($merged['published'] ?? 0));
				$item = Announcements::stored($old);
				$item['active'] = empty($old['active']);
				$item['published'] = Announcements::publishedOnSave($item, $old, time());
				$item = Announcements::normalize($item);
				$invalid = $item === null;
				$list = $item !== null ? Announcements::upsert($own, $item) : null;
				$full = !$invalid && $list === null;

				return $list;
			});
		});
		if(!$ok)
		{
			return null;
		}
		if($gone)
		{
			$this->addError(new Error('Объявление не найдено. Обновите страницу.', 'not_found'));

			return null;
		}
		if($invalid)
		{
			$this->addError(new Error('Не сохранено: нужен заголовок, а код — латиница, цифры и дефис.', 'invalid'));

			return null;
		}
		if($full)
		{
			$this->addError(new Error('Не сохранено: в своём списке не больше '.Announcements::MAX_ITEMS.' объявлений.', 'full'));

			return null;
		}

		return $this->rows();
	}

	/** Удалить своё («Вернуть как в модуле» — то же для копии модульного). */
	public function deleteAction(string $id): ?array
	{
		if(!$this->can('W') || !$this->validId($id))
		{
			return null;
		}
		$image = 0;
		$ok = $this->locked(static function() use($id, &$image): void
		{
			OwnStore::update(static function(array $own) use($id, &$image): array
			{
				foreach($own as $item)
				{
					if($item['id'] === $id)
					{
						$image = (int)$item['image'];
					}
				}

				return Announcements::remove($own, $id);
			});
		});
		if(!$ok)
		{
			return null;
		}
		$this->dropFile($image);

		return $this->rows();
	}

	/**
	 * Своя картинка: поле файла «image». Тип — по содержимому (getimagesize),
	 * SVG нельзя, до 2 МБ (Media::imageError()). Файл — в штатное хранилище
	 * (CFile::SaveFile, модуль shef.toolsaiannounce).
	 *
	 * @return array{id: int, src: string}|null
	 */
	public function uploadImageAction(): ?array
	{
		if(!$this->can('W'))
		{
			return null;
		}
		[$file, $mime] = $this->upload();
		$error = Media::imageError((string)($file['name'] ?? ''), (int)($file['size'] ?? 0), $mime);
		if($error !== null)
		{
			$this->addError(new Error($error, 'image'));

			return null;
		}
		$file['name'] = 'announce.'.Media::IMAGE_TYPES[$mime];
		$file['type'] = $mime;
		$file['MODULE_ID'] = Constants::MODULE_ID;
		$id = (int)\CFile::SaveFile($file, Constants::MODULE_ID);
		if($id <= 0)
		{
			$this->addError(new Error('Не удалось сохранить картинку.', 'image'));

			return null;
		}

		return ['id' => $id, 'src' => (string)\CFile::GetPath($id)];
	}

	/**
	 * Удалить картинку, только если файл загружен этим модулем: картинку
	 * объявления модуля-источника (в копии или при возврате) не трогаем.
	 */
	private function dropFile(int $id): void
	{
		if($id > 0 && $this->ownFile($id))
		{
			\CFile::Delete($id);
		}
	}

	/**
	 * Загруженный файл формы (поле image) и его тип по содержимому.
	 *
	 * @return array{0: array<string, mixed>, 1: string} файл и MIME ('' — не картинка)
	 */
	protected function upload(): array
	{
		$file = Context::getCurrent()->getRequest()->getFile('image');
		$file = is_array($file) ? $file : [];
		$tmp = (string)($file['tmp_name'] ?? '');
		$info = $tmp !== '' && is_uploaded_file($tmp) ? @getimagesize($tmp) : false;

		return [$file, is_array($info) ? (string)$info['mime'] : ''];
	}

	/** Файл загружен этим модулем (uploadImage), а не чужой файл портала. */
	protected function ownFile(int $id): bool
	{
		if(!class_exists('CFile'))
		{
			return false;
		}
		$row = \CFile::GetByID($id)->Fetch();

		return is_array($row) && ($row['MODULE_ID'] ?? '') === Constants::MODULE_ID;
	}

	/** Право модуля не ниже $need; нет — ошибка. */
	protected function can(string $need): bool
	{
		if($this->right() >= $need)
		{
			return true;
		}
		$this->addError(new Error($need === 'W' ? 'Править список может только тот, у кого право «запись» на модуль.' : 'Нет доступа.', 'access_denied'));

		return false;
	}

	/** Уровень права текущего пользователя на модуль (D, R, W). */
	protected function right(): string
	{
		global $APPLICATION;

		return is_object($APPLICATION) ? (string)$APPLICATION->GetGroupRight(Constants::MODULE_ID) : 'D';
	}

	private function validId(string $id): bool
	{
		if(preg_match(Announcements::ID_PATTERN, $id))
		{
			return true;
		}
		$this->addError(new Error('Неверный запрос', 'invalid'));

		return false;
	}

	/** Объявление из итогового списка (своё или модульное) по id. */
	private function find(string $id): ?array
	{
		foreach(Collector::collectWithConflicts()['items'] as $item)
		{
			if($item['id'] === $id)
			{
				return $item;
			}
		}

		return null;
	}

	/** Записать в свой список и вернуть свежие строки. */
	private function store(?array $item, ?array $old): ?array
	{
		if($item === null)
		{
			$this->addError(new Error('Не сохранено: нужен заголовок, а код — латиница, цифры и дефис.', 'invalid'));

			return null;
		}
		$list = null;
		if(!$this->locked(static function() use($item, &$list): void { $list = OwnStore::update(static fn(array $own): ?array => Announcements::upsert($own, $item)); }))
		{
			return null;
		}
		if($list === null)
		{
			$this->addError(new Error('Не сохранено: в своём списке не больше '.Announcements::MAX_ITEMS.' объявлений.', 'full'));

			return null;
		}
		if($old !== null && (int)($old['image'] ?? 0) !== $item['image'])
		{
			$this->dropFile((int)($old['image'] ?? 0));
		}

		return $this->rows();
	}

	/** Правка списка под блокировкой; занят — ошибка в окно, false. */
	private function locked(callable $write): bool
	{
		try
		{
			$write();

			return true;
		}
		catch(\RuntimeException)
		{
			$this->addError(new Error('Список сейчас правится — повторите через несколько секунд.', 'busy'));

			return false;
		}
	}

	/** @return array{rows: list<array>, conflicts: list<string>} */
	private function rows(): array
	{
		$merged = Collector::collectWithConflicts();

		return ['rows' => ListView::rows($merged['items'], $this->right() >= 'W', PlaceCollector::collect(), static fn(int $id): string => class_exists('CFile') ? (string)\CFile::GetPath($id) : ''), 'conflicts' => $merged['conflicts']];
	}
}

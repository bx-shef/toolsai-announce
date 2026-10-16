<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Error;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

if(class_exists('shef_toolsaiannounce'))
{
	return;
}

Loc::loadMessages(__FILE__);

/**
 * Установка shef.toolsaiannounce — «Новинки для сотрудников».
 *
 * Канон — установщики shef.problems и shef.toolsai (проверки UTF-8, PHP,
 * версий; savedata; каталог модуля от самого установщика). Плюс:
 *
 *   1) постоянная подписка main:OnEpilog — по installEvents из .settings.php,
 *      снимается парным UnInstallEvents();
 *   2) заглушка страницы списка в /bitrix/admin — пишется, а не копируется
 *      (Main\AdminPage), удаляется только своя;
 *   3) перенос из shef.toolsai (Main\Migration): настройки ANNOUNCE_list,
 *      ANNOUNCE_testusers, если свои пусты, и отметки «видел».
 *
 * На классы модуля в установщике автозагрузка не рассчитана: всё, что нужно
 * до регистрации модуля, подключается явным require_once.
 */
class shef_toolsaiannounce
	extends CModule
{
	public $MODULE_ID = 'shef.toolsaiannounce';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_SORT;
	/** Права на модуль: R — смотреть список, W — править. */
	public $MODULE_GROUP_RIGHTS = 'Y';

	public $PARTNER_NAME;
	public $PARTNER_URI;

	/** @var string */
	public $PHP_MIN_VER = '8.2.0';
	/** @var string */
	public $NEED_MAIN_VERSION = '22.600.300';
	/**
	 * shef.options 3.0.0 — страница настроек на ShOptionsConfig без indexDoc;
	 * shef.problems 2.0.0 — логгер проблем. От shef.toolsai модуль не зависит.
	 *
	 * @var array<string, string>
	 */
	public $NEED_MODULES_BY_VERSION = [
		'shef.options' => '3.0.0',
		'shef.problems' => '2.0.0',
	];

	/** @var \CMain */
	private $application;

	/** Строки отчёта для формы после установки (уже экранированы). */
	private array $report = [];

	/** Версия, название, партнёр — из version.php и языкового файла. */
	public function __construct()
	{
		$arModuleVersion = [];

		require __DIR__.'/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

		$this->MODULE_NAME = Loc::getMessage('shef.toolsaiannounce_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('shef.toolsaiannounce_MODULE_DESC');

		$sort = 'MODULE_SORT';
		$this->{$sort} = (int)'1011';

		$this->PARTNER_NAME = Loc::getMessage('shef.toolsaiannounce_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('shef.toolsaiannounce_PARTNER_URI');

		$this->application = $GLOBALS['APPLICATION'];
	}

	// region DB && Register Module ////
	/**
	 * Регистрация модуля и перенос из shef.toolsai.
	 *
	 * @memo Need call first
	 */
	public function InstallDB(array $arParams = []): bool
	{
		RegisterModule($this->MODULE_ID);

		try
		{
			\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
			$done = \Shef\ToolsAiAnnounce\Main\Migration::run();
			\Shef\ToolsAiAnnounce\Announce\Collector::clearCache();
			$this->report[] = htmlspecialcharsbx((string)Loc::getMessage('SH_ANNOUNCE_MIGRATED', [
				'#OPTIONS#' => $done['options'] !== [] ? implode(', ', $done['options']) : Loc::getMessage('SH_ANNOUNCE_MIGRATED_NONE'),
				'#USERS#' => $done['users'],
			]));
		}
		catch(\Throwable $throwable)
		{
			$this->report[] = htmlspecialcharsbx((string)Loc::getMessage('SH_ANNOUNCE_MIGRATE_FAIL', [
				'#MESSAGE#' => $throwable->getMessage(),
			]));
		}

		return true;
	}

	/**
	 * Снятие регистрации.
	 *
	 * @param array $arParams ключ savedata = 'Y' оставляет настройки модуля и
	 *                        отметки «видел»
	 * @memo Need call last
	 */
	public function UnInstallDB(array $arParams = []): bool
	{
		/**
		 * Настройки и отметки уходят вместе с модулем. Ключ savedata — уговор
		 * ядра; формы удаления у модуля нет, поэтому умолчание — чистить.
		 */
		if(($arParams['savedata'] ?? 'N') !== 'Y')
		{
			Config\Option::delete($this->MODULE_ID);
			\CUserOptions::DeleteOptionsByName($this->MODULE_ID, 'announce_seen');
		}

		UnRegisterModule($this->MODULE_ID);

		$GLOBALS['CACHE_MANAGER']->CleanAll();

		return true;
	}
	// endregion ////

	// region Events ////
	/**
	 * Постоянные подписки из installEvents (.settings.php).
	 *
	 * @return list<array>
	 */
	private function getEventsList(): array
	{
		$list = Config\Configuration::getInstance($this->MODULE_ID)->get('installEvents');

		return is_array($list) ? $list : [];
	}

	/**
	 * Обработчик новинок shef.toolsai до 1.50.0: при установке этого модуля
	 * снимается, иначе на старом shef.toolsai окно показывалось бы дважды.
	 * Класс — в обоих написаниях: ядро сравнивает строку как есть.
	 */
	private const LEGACY_EPILOG = ['main', 'OnEpilog', 'shef.toolsai', 'Shef\\ToolsAi\\Announce\\Events', 'onEpilog'];

	/** Зарегистрировать подписки; повторная установка не плодит дублей. Снять старый обработчик shef.toolsai. */
	public function InstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		[$from, $event, $toModule, $class, $method] = self::LEGACY_EPILOG;
		foreach([$class, '\\'.$class] as $legacyClass)
		{
			$eventManager->unRegisterEventHandler($from, $event, $toModule, $legacyClass, $method);
		}
		foreach($this->getEventsList() as $event)
		{
			$eventManager->unRegisterEventHandler(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
			);
			$method = ($event['isCompatible'] ?? false) ? 'registerEventHandlerCompatible' : 'registerEventHandler';
			$eventManager->{$method}(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
				$event['to']['sort'] ?? 100,
			);
		}

		return true;
	}

	/**
	 * Снять подписки. Литералом — список из .settings.php: при удалении он
	 * ещё читается (модуль зарегистрирован, UnInstallDB() — последним).
	 */
	public function UnInstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		foreach($this->getEventsList() as $event)
		{
			$eventManager->unRegisterEventHandler(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
			);
			// Тот же класс без ведущего «\»: ядро сравнивает строку как есть.
			$eventManager->unRegisterEventHandler(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				ltrim($event['to']['class'], '\\'),
				$event['to']['function'],
			);
		}

		return true;
	}
	// endregion ////

	// region Files ////
	/** Каталог модуля — там, где он стоит на самом деле: /bitrix/modules или /local/modules. */
	private function getModuleDir(): string
	{
		return dirname(__DIR__);
	}

	/**
	 * Класс заглушки страницы списка — подключается явно: на автозагрузку
	 * классов модуля в установщике полагаться нельзя.
	 */
	private function getAdminPage(): string
	{
		require_once $this->getModuleDir().'/lib/main/adminpage.php';

		return \Shef\ToolsAiAnnounce\Main\AdminPage::class;
	}

	/** Заглушка /bitrix/admin/shef_toolsaiannounce_list.php. Чужой файл не трогает. */
	public function InstallFiles(array $arParams = []): bool
	{
		$page = $this->getAdminPage();
		if(!$page::install((string)Application::getDocumentRoot(), $this->getModuleDir()))
		{
			$this->report[] = htmlspecialcharsbx((string)Loc::getMessage('SH_ANNOUNCE_ADMIN_PAGE_FAIL', ['#FILE#' => $page::FILE]));
		}

		return true;
	}

	/** Убрать свою заглушку; модуль, зависящий от этого, удалить не даст. */
	public function UnInstallFiles(): bool
	{
		$response = $this->checkChildModules();
		if(!$response->isSuccess())
		{
			$this->application->ThrowException(
				join(PHP_EOL.'<br>',
					array_merge(
						[Loc::getMessage('SH_PROBLEM_UNINSTALL_MODULE')],
						$response->getErrorMessages()
					)
				)
			);

			return false;
		}

		$this->getAdminPage()::uninstall((string)Application::getDocumentRoot(), $this->getModuleDir());

		return true;
	}
	// endregion ////

	// region Install.Public ////
	/** Проверки окружения, затем регистрация, файлы, подписки, перенос. */
	public function DoInstall(): void
	{
		/**
		 * Модуль поставляется в UTF-8 и перекодировкой не занимается: на
		 * проекте в CP1251 русский текст стал бы мусором молча.
		 */
		if(!Application::isUtfMode())
		{
			$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_UTF8'));
		}

		$phpVer = phpversion();
		if(version_compare($phpVer, $this->PHP_MIN_VER, '<'))
		{
			$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_PHP_VER', [
				'#CURRENT#' => $phpVer,
				'#NEED#' => $this->PHP_MIN_VER,
			]));
		}

		foreach($this->NEED_MODULES_BY_VERSION as $module => $ver)
		{
			if(
				!ModuleManager::isModuleInstalled($module)
				|| version_compare((string)ModuleManager::getVersion($module), $ver) < 0
			)
			{
				$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_MODULES_BY_VERSION', [
					'#URL#' => 'https://github.com/bx-shef/'.substr($module, strlen('shef.')),
					'#NEED#' => $module,
					'#VER#' => $ver,
				]));
			}
		}

		if(version_compare(SM_VERSION, $this->NEED_MAIN_VERSION) < 0)
		{
			$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_RIGHT_VER', [
				'#NEED#' => $this->NEED_MAIN_VERSION,
			]));
		}

		$this->InstallDB();
		$this->InstallFiles();
		$this->InstallEvents();

		$this->ShowForm(
			'OK',
			Loc::getMessage('SH_MOD_INST_OK').($this->report !== [] ? '<br><br>'.implode('<br>', $this->report) : '')
		);
	}

	/** Файлы (с проверкой зависимых модулей), подписки, регистрация — последней. */
	public function DoUninstall(): void
	{
		if(!$this->UnInstallFiles())
		{
			$problem = $this->application->GetException();
			$this->ShowForm(
				'ERROR',
				$problem instanceof \CApplicationException ? $problem->GetString() : 'Error Uninstall'
			);
		}
		$this->UnInstallEvents();
		$this->UnInstallDB();
	}

	/**
	 * Форма с итогом. Заканчивается die(): прервать установку можно только так.
	 */
	private function ShowForm(string $type, string $message, string $buttonName = ''): void
	{
		/** @memo need for show title at prolog */
		$keys = array_keys($GLOBALS);
		for($i = 0; $i < count($keys); $i++)
		{
			if(
				$keys[$i] != 'i'
				&& $keys[$i] != 'GLOBALS'
				&& $keys[$i] != 'strTitle'
				&& $keys[$i] != 'filepath'
			)
			{
				global ${$keys[$i]};
			}
		}
		global $APPLICATION, $adminPage, $adminMenu, $adminChain, $USER;

		$this->application->SetTitle(Loc::getMessage('shef.toolsaiannounce_MODULE_NAME'));

		include(Application::getDocumentRoot().'/bitrix/modules/main/include/prolog_admin_after.php');

		\CAdminMessage::ShowMessage([
			'MESSAGE' => $message,
			'TYPE' => $type,
			'HTML' => true,
		]);
		?>
		<form action="<?=$this->application->GetCurPage()?>" method="get">
			<p>
				<input type="hidden" name="lang" value="<?=LANG?>">
				<input type="submit" value="<?=($buttonName <> '' ? $buttonName : Loc::getMessage('SH_MOD_BACK'))?>">
			</p>
		</form>
		<?php
		include(Application::getDocumentRoot().'/bitrix/modules/main/include/epilog_admin.php');
		die();
	}
	// endregion ////

	// region Tools ////
	/** Установленные модули, у которых этот — в requireModules. */
	private function checkChildModules(): Result
	{
		$result = new Result();
		foreach(ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			if($this->MODULE_ID === $moduleId)
			{
				continue;
			}

			$requireModules = Config\Configuration::getInstance($moduleId)->get('requireModules');
			if(empty($requireModules) || !is_array($requireModules))
			{
				continue;
			}

			if(in_array($this->MODULE_ID, $requireModules, true))
			{
				$result->addError(new Error(Loc::getMessage('SH_NEED_UNINSTALL_MODULE_BEFORE', [
					'#TARGET#' => $moduleId,
				])));
			}
		}

		return $result;
	}
	// endregion ////
}

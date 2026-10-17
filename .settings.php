<?php declare(strict_types=1);

/**
 * Настраиваемые параметры модуля shef.toolsaiannounce.
 *
 * * requireModules -> обязательные модули линейки: autoload.php подключает их
 *   при каждой загрузке, установщик их удаление не пропустит. Только
 *   shef.options и shef.problems: от shef.toolsai модуль не зависит, связь с
 *   другими модулями — событием OnAnnouncementsCollect.
 * * requirePhpExt -> обязательные расширения PHP
 * * registerAutoLoadClasses, registerNamespace -> пусто: свои классы ядро
 *   находит по соглашению (lib/ строчными), чужих библиотек нет
 * * installEvents -> постоянные подписки: регистрирует InstallEvents()
 *   установщика, снимает парный UnInstallEvents()
 * * installDir -> пусто: в /bitrix/admin модуль не копирует, а ПИШЕТ одну
 *   заглушку страницы списка (Main\AdminPage)
 * * controllers -> ajax: shef:toolsaiannounce.banner.seen ->
 *   Shef\ToolsAiAnnounce\Controller\Banner::seenAction(); страница списка —
 *   shef:toolsaiannounce.announcement.* (Controller\Announcement)
 * * ui.entity-selector -> своя сущность shef-announce-place (поле «Где
 *   показывать»); ядро читает ключ из .settings.php установленных модулей,
 *   установщик для неё не нужен
 */

return [
	'requireModules' => [
		'value' => [
			'shef.options',
			'shef.problems',
		],
		'readonly' => true,
	],
	'requirePhpExt' => [
		'value' => [
			'mbstring',
			'json',
		],
		'readonly' => true,
	],
	'registerAutoLoadClasses' => [
		'value' => [],
		'readonly' => true,
	],
	'registerNamespace' => [
		'value' => [],
		'readonly' => true,
	],
	'options' => [
		'value' => [],
		'readonly' => true,
	],
	'installEvents' => [
		'value' => [
			// Окно новинки на страницах CRM.
			[
				'isCompatible' => false,
				'from' => ['module' => 'main', 'event' => 'OnEpilog'],
				'to' => [
					'module' => 'shef.toolsaiannounce',
					'class' => '\\Shef\\ToolsAiAnnounce\\Internals\\Integration\\Main\\EventHandler\\OnEpilogEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// Пункт «Оповещения для сотрудников» в меню шестерёнки вида «ИИ»
			// shef.toolsai (1.53.0+). Нет shef.toolsai — событие никто не шлёт.
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnViewSettingsMenuBuild'],
				'to' => [
					'module' => 'shef.toolsaiannounce',
					'class' => '\\Shef\\ToolsAiAnnounce\\Internals\\Integration\\ToolsAi\\EventHandler\\OnViewSettingsMenuBuildEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
		],
		'readonly' => true,
	],
	'installDir' => [
		'value' => [],
		'readonly' => true,
	],
	// Окно выбора «Где показывать»: своя сущность ui.entity-selector.
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'shef-announce-place',
					'provider' => [
						'moduleId' => 'shef.toolsaiannounce',
						'className' => '\\Shef\\ToolsAiAnnounce\\Integration\\Ui\\EntitySelector\\PlaceProvider',
					],
				],
			],
		],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Shef\\ToolsAiAnnounce\\Controller',
			'namespaces' => [],
		],
		'readonly' => true,
	],
];

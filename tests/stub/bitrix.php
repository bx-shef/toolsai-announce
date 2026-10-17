<?php

/**
 * Заглушки ядра Битрикса для тестов.
 *
 * Ровно столько, сколько нужно, чтобы подключить настоящие классы модуля.
 * Логику модуля здесь не повторяем — иначе тест проверял бы заглушку, а не
 * код. Основа — заглушки shef.toolsai (tests/stub/bitrix.php там): настройки,
 * события, менеджер событий, личные настройки пользователя.
 *
 * Блочный синтаксис namespace: в одном файле нужно объявить классы сразу в
 * нескольких пространствах имён.
 */

namespace Bitrix\Main\Config
{
	if(!class_exists(Option::class))
	{
		/** Настройки модуля: значение — строка, как из формы. */
		class Option
		{
			/** @var array<string, array<string, mixed>> */
			public static array $values = [];

			public static function set(string $moduleId, string $name, mixed $value): void
			{
				static::$values[$moduleId][$name] = $value;
			}

			public static function get(string $moduleId, string $name, mixed $default = '', mixed $siteId = false): mixed
			{
				return static::$values[$moduleId][$name] ?? $default;
			}

			/** Как в ядре: без умолчаний default_option.php, не сохраняли — null. */
			public static function getRealValue(string $moduleId, string $name, mixed $siteId = false): ?string
			{
				return isset(static::$values[$moduleId][$name]) ? (string)static::$values[$moduleId][$name] : null;
			}

			public static function delete(string $moduleId, array $filter = []): void
			{
				unset(static::$values[$moduleId]);
			}
		}
	}

	if(!class_exists(Configuration::class))
	{
		/** Содержимое .settings.php: отдаёт value ключа, как читает autoload.php. */
		class Configuration
		{
			/** @var array<string, array<string, mixed>> */
			public static array $settings = [];

			public function __construct(private readonly string $moduleId = '') {}

			public static function getInstance(?string $moduleId = null): static
			{
				return new static((string)$moduleId);
			}

			public function get(string $key): mixed
			{
				return static::$settings[$this->moduleId][$key] ?? null;
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(EventResult::class))
	{
		/**
		 * Событие: send() зовёт обработчики из EventManager::$handlers и
		 * собирает ответы — как ядро, только без базы.
		 */
		class Event
		{
			/** @var list<mixed> */
			private array $results = [];

			public function __construct(
				private readonly string $moduleId = '',
				private readonly string $type = '',
				private readonly array $parameters = [],
				private readonly mixed $filter = null
			) {}

			public function getModuleId(): string
			{
				return $this->moduleId;
			}

			public function getEventType(): string
			{
				return $this->type;
			}

			public function getParameters(): array
			{
				return $this->parameters;
			}

			public function send(): void
			{
				foreach(EventManager::$handlers[$this->moduleId][$this->type] ?? [] as $toModule => $handler)
				{
					// Фильтр — как в ядре: по модулю назначения (ключ массива обработчиков).
					if($this->filter !== null && !in_array($toModule, (array)$this->filter, true))
					{
						continue;
					}
					$result = $handler($this);
					if($result !== null && $result !== false)
					{
						$this->results[] = $result instanceof EventResult ? $result : new EventResult(EventResult::UNDEFINED, $result);
					}
				}
			}

			/** @return list<mixed> */
			public function getResults(): array
			{
				return $this->results;
			}
		}

		class Error
		{
			public function __construct(private readonly string $message = '', private readonly int|string $code = 0) {}

			public function getMessage(): string
			{
				return $this->message;
			}
		}

		class EventResult
		{
			public const UNDEFINED = 0;
			public const SUCCESS = 1;
			public const ERROR = 2;

			public function __construct(
				private readonly int $type,
				private readonly mixed $parameters = null,
				private readonly ?string $moduleId = null
			) {}

			public function getType(): int
			{
				return $this->type;
			}

			public function getParameters(): mixed
			{
				return $this->parameters;
			}

			public function getModuleId(): ?string
			{
				return $this->moduleId;
			}
		}

		/** Обработчики в памяти и журнал регистраций. */
		class EventManager
		{
			/** @var array<string, array<string, list<callable>>> */
			public static array $handlers = [];

			/** @var list<array> */
			public static array $registered = [];

			/** @var list<array> */
			public static array $unregistered = [];

			public static function getInstance(): static
			{
				return new static();
			}

			/** Как ядро: записи с TO_MODULE_ID — ключ массива обработчиков модуля. */
			public function findEventHandlers(string $moduleId, string $eventType, ?array $filter = null): array
			{
				$list = [];
				foreach(array_keys(static::$handlers[$moduleId][$eventType] ?? []) as $toModule)
				{
					$list[] = ['TO_MODULE_ID' => is_string($toModule) ? $toModule : ''];
				}

				return $list;
			}

			public function registerEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				static::$registered[] = [$fromModule, $event, $toModule, $class, $method];
			}

			public function registerEventHandlerCompatible(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				static::$registered[] = [$fromModule, $event, $toModule, $class, $method];
			}

			/** Как таблица b_module_to_module: снимает точное совпадение строк. */
			public function unRegisterEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method): void
			{
				$row = [$fromModule, $event, $toModule, $class, $method];
				static::$unregistered[] = $row;
				static::$registered = array_values(array_filter(static::$registered, static fn(array $r): bool => $r !== $row));
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Context::class))
	{
		/** Текущий запрос: адрес, ajax, параметры — задаёт тест. */
		class Context
		{
			public static string $page = '/';
			/** Адрес как в адресной строке; пусто — $page. */
			public static string $uri = '';
			public static bool $ajax = false;
			/** @var array<string, string> */
			public static array $params = [];

			public static function getCurrent(): static
			{
				return new static();
			}

			public function getRequest(): object
			{
				return new class
				{
					public function getRequestedPage(): string
					{
						return Context::$page;
					}

					public function getRequestUri(): string
					{
						return Context::$uri !== '' ? Context::$uri : Context::$page;
					}

					public function isAjaxRequest(): bool
					{
						return Context::$ajax;
					}

					public function get(string $name): ?string
					{
						return Context::$params[$name] ?? null;
					}
				};
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(UserTable::class))
	{
		/** Пользователи — из $GLOBALS['TEST_USERS'] (id => строка); фильтр только =ID / @ID. */
		class UserTable
		{
			public static int $calls = 0;

			public static function getList(array $params = []): object
			{
				static::$calls++;
				$ids = (array)($params['filter']['=ID'] ?? $params['filter']['@ID'] ?? []);
				$rows = array_values(array_filter($GLOBALS['TEST_USERS'] ?? [], static fn(array $r): bool => in_array((int)$r['ID'], array_map('intval', $ids), true)));

				return new class($rows)
				{
					public function __construct(private array $rows) {}

					public function fetch(): array|false
					{
						return array_shift($this->rows) ?? false;
					}
				};
			}
		}
	}
}

namespace Bitrix\Main\Page
{
	if(!class_exists(Asset::class))
	{
		/** Что положили в страницу. */
		class Asset
		{
			/** @var list<string> */
			public static array $strings = [];

			public static function getInstance(): static
			{
				return new static();
			}

			public function addString(string $str): void
			{
				static::$strings[] = $str;
			}
		}
	}
}

namespace Bitrix\Main\Data
{
	if(!class_exists(Cache::class))
	{
		/** Кеш в памяти: ключ — каталог и строка. */
		class Cache
		{
			/** @var array<string, array<string, mixed>> */
			public static array $store = [];
			private string $dir = '';
			private string $key = '';

			public static function createInstance(array $params = []): static
			{
				return new static();
			}

			public function initCache(int $ttl, string $key, string $dir = ''): bool
			{
				$this->dir = $dir;
				$this->key = $key;

				return array_key_exists($key, static::$store[$dir] ?? []);
			}

			public function getVars(): mixed
			{
				return static::$store[$this->dir][$this->key] ?? null;
			}

			public function startDataCache(): bool
			{
				return true;
			}

			public function endDataCache(mixed $vars = false): void
			{
				static::$store[$this->dir][$this->key] = $vars;
			}

			public function cleanDir(string $dir = ''): void
			{
				unset(static::$store[$dir]);
			}
		}
	}
}

namespace Bitrix\Main\Engine
{
	if(!class_exists(Controller::class))
	{
		/** Текущий пользователь контроллера. */
		class CurrentUser
		{
			public function __construct(private readonly ?int $id) {}

			public function getId(): ?int
			{
				return $this->id;
			}
		}

		/** Контроллер: ошибки и текущий пользователь — как в ядре, без жизненного цикла. */
		class Controller
		{
			public static ?int $userId = null;
			/** @var list<\Bitrix\Main\Error> */
			public array $errors = [];

			public function addError(\Bitrix\Main\Error $error): static
			{
				$this->errors[] = $error;

				return $this;
			}

			final public function getCurrentUser(): ?CurrentUser
			{
				return new CurrentUser(static::$userId);
			}
		}
	}
}

namespace Bitrix\Main\Engine\ActionFilter
{
	if(!class_exists(HttpMethod::class))
	{
		class Authentication {}
		class Csrf {}
		class HttpMethod
		{
			public const METHOD_POST = 'POST';

			public function __construct(public readonly array $methods = []) {}
		}
	}
}

namespace Bitrix\Main\Localization
{
	if(!class_exists(Loc::class))
	{
		/** Подписи: отдаёт код — тесту важен не текст, а что его спросили. */
		class Loc
		{
			public static function loadMessages(string $file): void {}

			public static function getMessage(string $code, ?array $replace = null, ?string $language = null): ?string
			{
				return $code;
			}
		}
	}
}

namespace
{
	if(!class_exists('CModule'))
	{
		/** Базовый класс установщика: тестам нужен только сам класс. */
		class CModule {}
	}

	if(!function_exists('UnRegisterModule'))
	{
		/** Снятые модули — для проверки установщика. */
		function UnRegisterModule(string $moduleId): void
		{
			$GLOBALS['UNREGISTERED_MODULES'][] = $moduleId;
		}
	}
	if(!class_exists('CUserOptions'))
	{
		/** Личные настройки пользователей: категория → имя → пользователь → значение. */
		class CUserOptions
		{
			/** @var array<string, array<string, array<int, mixed>>> */
			public static array $values = [];

			public static function GetOption($category, $name, $default = false, $userId = false)
			{
				return static::$values[$category][$name][(int)$userId] ?? $default;
			}

			public static function SetOption($category, $name, $value, $common = false, $userId = false): bool
			{
				static::$values[$category][$name][(int)$userId] = $value;

				return true;
			}

			public static function DeleteOptionsByName($category, $name): bool
			{
				unset(static::$values[$category][$name]);

				return true;
			}

			/** Как ядро: строки b_user_option, VALUE — serialize(). */
			public static function GetList($order = [], $filter = []): object
			{
				$rows = [];
				foreach(static::$values[$filter['CATEGORY'] ?? ''][$filter['NAME'] ?? ''] ?? [] as $userId => $value)
				{
					$rows[] = ['USER_ID' => (string)$userId, 'VALUE' => is_string($value) ? $value : serialize($value)];
				}

				return new class($rows)
				{
					public function __construct(private array $rows) {}

					public function Fetch(): array|false
					{
						return array_shift($this->rows) ?? false;
					}
				};
			}
		}
	}
}

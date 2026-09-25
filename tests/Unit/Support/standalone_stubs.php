<?php

declare(strict_types=1);

/**
 * Minimal Nextcloud server-internal stubs for standalone PHPUnit runs
 * (e.g. the Infection mutation container) where no real kernel exists.
 *
 * Loaded by tests/bootstrap.php only when lib/base.php cannot be found.
 * All guards are class_exists checks, so this file is a no-op wherever the
 * workspace-level stubs (nextcloud/scripts/phpunit-ocp-doctrine-stubs.php)
 * already defined the same classes.
 */

if (!class_exists(\OC::class, false)) {
	eval(<<<'PHP'
class DC_Standalone_L10N implements \OCP\IL10N {
	public function t(string $text, $parameters = []): string {
		if ($parameters === []) {
			return $text;
		}
		$out = @vsprintf($text, (array) $parameters);
		return $out === false ? $text : $out;
	}
	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		$out = @vsprintf($count === 1 ? $text_singular : $text_plural, $parameters);
		return $out === false ? $text_plural : $out;
	}
	public function l(string $type, $data, array $options = []) {
		return (string) $data;
	}
	public function getLanguageCode(): string {
		return 'en';
	}
	public function getLocaleCode(): string {
		return 'en_US';
	}
}
class DC_Standalone_Server {
	public function get($serviceName) {
		return new class {
			public function get($app = null, $lang = null) {
				return new \DC_Standalone_L10N();
			}
			public function findLanguage($app = null) {
				return 'en';
			}
			public function getToken() {
				return new class {
					public function getEncryptedValue(): string {
						return 'stub-token';
					}
				};
			}
			public function sort($scripts, $deps) {
				return is_array($scripts) ? $scripts : [];
			}
			public function check($key = null) {
				return ['allowed' => true, 'retryAfter' => 0];
			}
			public function tryConsume($key = null) {
				return ['allowed' => true, 'retryAfter' => 0];
			}
			public function __call($name, $args) {
				return null;
			}
		};
	}
	public function __call($name, $args) {
		return null;
	}
}
class OC {
	public static $server;
}
OC::$server = new \DC_Standalone_Server();
PHP);
}

if (!class_exists(\OC_Util::class, false)) {
	eval(<<<'PHP'
class OC_Util {
	public static function __callStatic(string $name, array $args) {
		return match ($name) {
			'sanitizeHTML' => $args[0] ?? '',
			'getRequesttoken', 'callRegister' => 'stub-token',
			default => null,
		};
	}
}
class OC_Hook {
	public static function __callStatic(string $name, array $args) {
		return null;
	}
}
PHP);
}

if (!class_exists(\OC\AppScriptDependency::class, false)) {
	eval(<<<'PHP'
namespace OC;
class AppScriptDependency {
	private string $app;
	private array $deps;
	public function __construct(string $app, array $deps) {
		$this->app = $app;
		$this->deps = $deps;
	}
	public function addDep($dep): void {
		$this->deps[] = $dep;
	}
	public function getApp(): string {
		return $this->app;
	}
	public function getDependencies(): array {
		return $this->deps;
	}
}
PHP);
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Strict-id contract: every route placeholder whose controller parameter is
 * declared `int` must carry a `\d+` requirement in appinfo/routes.php.
 *
 * Nextcloud's dispatcher casts untyped route values via settype() — without a
 * requirement, "4181.0", "2e3", " 5" and "455junk" all silently truncate to
 * live row ids and mutate real data (budgetcheck 4181.0 hole, this app's own
 * pre-fix live probe: /api/periods/455abc/publish-readiness returned 200).
 * String params (userId, uid) intentionally keep the default [^/]+ match —
 * NC uids are arbitrary strings.
 */
final class RouteStrictIdContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	/**
	 * Map a route name prefix to its controller file (NC convention:
	 * 'rosterApi#updateTemplate' → lib/Controller/RosterApiController.php).
	 */
	private static function controllerSource(string $prefix): string
	{
		$file = self::appRoot() . '/lib/Controller/' . ucfirst($prefix) . 'Controller.php';
		self::assertFileExists($file, 'route prefix ' . $prefix . ' must resolve to a controller file');
		return (string) file_get_contents($file);
	}

	/**
	 * @return array<string, string> param name → declared scalar type
	 */
	private static function intParamsOfMethod(string $source, string $method): array
	{
		if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(([^)]*)\)/s', $source, $m)) {
			self::fail("controller method {$method} not found");
		}
		$types = [];
		foreach (explode(',', $m[1]) as $param) {
			if (preg_match('/\b(int|string|float|bool)\s+\$(\w+)/', $param, $pm)) {
				$types[$pm[2]] = $pm[1];
			}
		}
		return $types;
	}

	public function testEveryIntRouteParamHasDigitRequirement(): void
	{
		$violations = [];
		$checked = 0;
		$controllerCache = [];
		foreach (self::routes() as $route) {
			$name = (string) ($route['name'] ?? '');
			$url = (string) ($route['url'] ?? '');
			if (!str_contains($name, '#') || !preg_match_all('/\{(\w+)\}/', $url, $pm) || $pm[1] === []) {
				continue;
			}
			[$prefix, $method] = explode('#', $name, 2);
			$controllerCache[$prefix] ??= self::controllerSource($prefix);
			$paramTypes = self::intParamsOfMethod($controllerCache[$prefix], $method);
			foreach ($pm[1] as $param) {
				if (($paramTypes[$param] ?? null) !== 'int') {
					continue;
				}
				$checked++;
				$req = $route['requirements'][$param] ?? null;
				if ($req !== '\\d+') {
					$violations[] = "{$name} {$url}: int \${$param} lacks \\d+ requirement (got "
						. var_export($req, true) . ')';
				}
			}
		}
		self::assertGreaterThan(30, $checked, 'route inventory must actually cover int params');
		self::assertSame([], $violations, implode("\n", $violations));
	}
}

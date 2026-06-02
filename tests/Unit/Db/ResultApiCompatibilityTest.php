<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards against the Nextcloud 32 regression where database reads used the
 * Doctrine-style result methods that are only `@since 33.0.0` on
 * {@see \OCP\DB\IResult}. On Nextcloud 32 those raise
 * `Call to undefined method OC\DB\ResultAdapter::fetchAllAssociative()`.
 *
 * Because the development containers run Nextcloud 33 (where the methods
 * exist), this class cannot be caught at runtime locally — so we assert it
 * statically against every PHP source file the app ships.
 *
 * Only the API available since Nextcloud 21 (`fetch()` / `fetchAll()`) may be
 * used so the app keeps working on the whole declared range (Nextcloud 32–33).
 */
final class ResultApiCompatibilityTest extends TestCase
{
	/**
	 * IResult methods introduced in Nextcloud 33 that must never be called,
	 * because the app's info.xml declares `min-version="32"`.
	 *
	 * @return list<string>
	 */
	private static function forbiddenResultMethods(): array
	{
		return [
			'fetchAssociative',
			'fetchAllAssociative',
			'fetchNumeric',
			'fetchAllNumeric',
			'fetchFirstColumn',
			'iterateAssociative',
			'iterateNumeric',
		];
	}

	public function testNoSourceFileUsesNextcloud33OnlyResultMethods(): void
	{
		$libDir = \dirname(__DIR__, 3) . '/lib';
		self::assertDirectoryExists($libDir);

		$offenders = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($libDir, RecursiveDirectoryIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$contents = (string) file_get_contents($file->getPathname());
			foreach (self::forbiddenResultMethods() as $method) {
				if (preg_match('/->\s*' . preg_quote($method, '/') . '\s*\(/', $contents) === 1) {
					$offenders[] = $file->getPathname() . ' uses ->' . $method . '()';
				}
			}
		}

		self::assertSame(
			[],
			$offenders,
			"Use \OCP\DB\IResult::fetch()/fetchAll() (since Nextcloud 21) instead of the "
			. "Nextcloud 33-only result methods so the app keeps working on Nextcloud 32:\n"
			. implode("\n", $offenders),
		);
	}
}

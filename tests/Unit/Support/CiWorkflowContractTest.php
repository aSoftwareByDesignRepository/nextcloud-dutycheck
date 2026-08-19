<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * SF-02 — GitHub Actions must run the unit + JS gauntlet, not syntax-only smoke.
 */
final class CiWorkflowContractTest extends TestCase
{
	private function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testCiRunsPhpunitUnitSuiteAndJsContracts(): void
	{
		$yml = (string) file_get_contents($this->appRoot() . '/.github/workflows/ci.yml');
		self::assertNotSame('', $yml);
		self::assertStringContainsString('phpunit -c phpunit.xml --testsuite unit', $yml);
		self::assertStringContainsString('phpunit -c phpunit.xml --testsuite integration', $yml);
		self::assertStringContainsString('node --test tests/js/*.test.mjs', $yml);
		self::assertStringContainsString('run-hardening-followup-mutations.php', $yml);
		self::assertStringContainsString('run-roster-virtualization-mutations.php', $yml);
		self::assertStringContainsString('run-first-paint-mutations.php', $yml);
		self::assertStringContainsString('check-l10n-runtime.php --all', $yml);
		self::assertStringContainsString('check-l10n-parity.php', $yml);
		self::assertStringContainsString('check-l10n-placeholders.php', $yml);
		self::assertStringContainsString('run-l10n-catalog-mutations.php', $yml);
		self::assertStringContainsString('composer install', $yml);
		self::assertStringContainsString('php -l', $yml);
	}

	public function testPageControllerDeniesPlannerChromeWithoutMembership(): void
	{
		$src = (string) file_get_contents($this->appRoot() . '/lib/Controller/PageController.php');
		self::assertStringContainsString('CompanyService $companies', $src);
		self::assertStringContainsString('hasCompanyMembership($userId)', $src);
		self::assertStringContainsString("'companyAccessDenied'", $src);
	}
}

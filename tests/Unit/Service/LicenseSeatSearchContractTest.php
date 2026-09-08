<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact contract: license seat search must use license#searchUsers
 * (`items`), never the roster directoryUsers (`users`) shape alone.
 */
final class LicenseSeatSearchContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	private function read(string $rel): string
	{
		$path = $this->root . '/' . $rel;
		self::assertFileExists($path);
		return (string)file_get_contents($path);
	}

	public function testRouteExposesLicenseSearchUsers(): void
	{
		$routes = $this->read('appinfo/routes.php');
		self::assertStringContainsString("'name' => 'license#searchUsers'", $routes);
		self::assertStringContainsString("/api/license/search/users", $routes);
	}

	public function testPageWiresLicenseSearchNotRosterDirectory(): void
	{
		$page = $this->read('lib/Controller/PageController.php');
		self::assertStringContainsString("dutycheck.license.searchUsers", $page);
		self::assertStringNotContainsString(
			"licenseSearchUsersUrl' => \$this->urlGenerator->linkToRouteAbsolute('dutycheck.rosterApi.directoryUsers')",
			$page,
		);
	}

	public function testControllerReturnsItemsContract(): void
	{
		$ctrl = $this->read('lib/Controller/LicenseController.php');
		self::assertStringContainsString('function searchUsers(', $ctrl);
		self::assertStringContainsString("'items' => \$this->license->searchUsersForSeats", $ctrl);
	}

	public function testClientAcceptsItemsAndUsersShapes(): void
	{
		$js = $this->read('js/license-settings.js');
		self::assertStringContainsString('function normalizeSearchHits', $js);
		self::assertStringContainsString('res.data.items', $js);
		self::assertStringContainsString('res.data.users', $js);
		self::assertStringContainsString('normalizeSearchHits(raw)', $js);
		self::assertStringContainsString('seatSearchLoading', $js);
		self::assertStringContainsString('aria-busy', $js);
	}

	public function testPanelDataAttributePresent(): void
	{
		$tpl = $this->read('templates/parts/license-panel.php');
		self::assertStringContainsString('data-api-search-users="', $tpl);
		self::assertStringContainsString('id="dc-license-seat-search-input"', $tpl);
		self::assertStringContainsString('role="combobox"', $tpl);
	}
}

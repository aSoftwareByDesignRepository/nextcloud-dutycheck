<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Service\MobileDemoSeedOptions;
use OCA\DutyCheck\Service\MobileDemoSeedService;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\RosterService;
use OCP\App\IAppManager;
use OCP\Server;
use Test\TestCase;

/**
 * Live DB seed for mobile companion QA — no web UI.
 */
final class MobileDemoSeedIntegrationTest extends TestCase
{
	private MobileDemoSeedService $seedService;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$appManager = Server::get(IAppManager::class);
		$appManager->loadApp('dutycheck');
		if (!$appManager->isEnabledForUser('sbdlicenseops')) {
			$this->markTestSkipped('sbdlicenseops required to mint DTY2 demo license');
		}
		$this->seedService = Server::get(MobileDemoSeedService::class);
	}

	public function testSeedProducesSeatedRosterAndUnseatedGate(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$employee = 'dc.seed.' . $suffix;
		$unseated = 'dc.noseat.' . $suffix;
		$password = 'SeedDemo2026!';

		$appManager = Server::get(IAppManager::class);
		$appManager->loadApp('sbdlicenseops');
		$generator = Server::get(\OCA\SbdLicenseOps\Service\LicenseKeyGeneratorService::class);
		$wire = $generator->generate(
			'dutycheck',
			'integration-mobile-seed-' . $suffix,
			['mobileSeats' => 5],
			'2027-12-31',
			null,
		)['wireKey'];

		$result = $this->seedService->run(new MobileDemoSeedOptions(
			employeeUserId: $employee,
			employeePassword: $password,
			unseatedUserId: $unseated,
			unseatedPassword: $password,
			licenseWireKey: $wire,
		));

		self::assertSame($employee, $result->employeeUserId);
		self::assertGreaterThan(0, $result->employeeId);
		self::assertGreaterThan(0, $result->periodId);
		self::assertContains($result->periodStatus, ['published', 'closed']);

		$roster = Server::get(RosterService::class);
		$rows = $roster->myRoster($employee);
		self::assertNotEmpty($rows, 'Seated user must see at least one published assignment');

		$gate = Server::get(MobileGateService::class);
		$seatedBoot = $gate->bootstrapPayload($employee, 'Seed Employee', '0.1.43');
		self::assertTrue($seatedBoot['seatAssigned']);
		self::assertTrue($seatedBoot['licensing']['mobile']['enabledForUser']);

		$unseatedBoot = $gate->bootstrapPayload($unseated, 'No Seat', '0.1.43');
		self::assertFalse($unseatedBoot['seatAssigned']);
		self::assertFalse($unseatedBoot['licensing']['mobile']['enabledForUser']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Repair\EnsureDutyCheckSchema;
use OCA\DutyCheck\Repair\UninstallDropTables;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\RosterService;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Mirrors production app install/upgrade: repair steps and core services must resolve
 * from the server container (same path as OC_App::executeRepairSteps during occ upgrade).
 */
class UpgradeRepairIntegrationTest extends TestCase
{
	public function testInstallAndPostMigrationRepairStepsResolveFromContainer(): void
	{
		foreach ([
			EnsureDutyCheckSchema::class,
			UninstallDropTables::class,
		] as $class) {
			$step = \OC::$server->get($class);
			$this->assertInstanceOf($class, $step);
		}
	}

	public function testEnsureDutyCheckSchemaRunsWithoutFatal(): void
	{
		/** @var EnsureDutyCheckSchema $step */
		$step = \OC::$server->get(EnsureDutyCheckSchema::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testCoreServicesResolveAfterUpgradePath(): void
	{
		$this->assertInstanceOf(RosterService::class, \OC::$server->get(RosterService::class));
		$this->assertInstanceOf(AccessControlService::class, \OC::$server->get(AccessControlService::class));
	}
}

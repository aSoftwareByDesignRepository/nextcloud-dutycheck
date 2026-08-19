<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Cancel must CAS on assignment.version so a concurrent update cannot be silently overwritten.
 */
final class RosterServiceCancelVersionCasTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_assignments.status' => true,
			'dc_assignments.version' => true,
			'dc_assignments.slot_key' => true,
			'dc_periods.conflict_thresholds_json' => false,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	public function testCancelSourceUsesVersionCasAndStaleVersion(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		self::assertMatchesRegularExpression(
			'/function cancelAssignment[\s\S]{0,2200}?eq\(\'version\'/',
			$src,
		);
		self::assertMatchesRegularExpression(
			'/function cancelAssignment[\s\S]{0,2600}?STALE_VERSION/',
			$src,
		);
	}

	public function testPeekAssignmentSourceEnforcesActorCompanyAccess(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		$start = strpos($src, 'public function peekAssignment');
		self::assertNotFalse($start);
		$fn = substr($src, $start, strpos($src, 'private function assignmentRowById', $start) - $start);
		self::assertStringContainsString('assertPeriodCompanyAccess', $fn);
		self::assertStringContainsString('?string $actorUserId = null', $fn);
	}
}

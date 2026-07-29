<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard summary + setup-state derivation.
 *
 * The `setup.readyForPlanning` flag decides whether the dashboard hides the
 * whole "Setup progress" section, so every gate (schema, employees,
 * locations, open periods) is pinned by an exhaustive truth table. The
 * summary mapping test kills count-swap and status-literal mutants.
 */
final class RosterServiceDashboardSummaryTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	public function testSummaryMapsEachCountToItsOwnKey(): void
	{
		$openParams = [];
		$publishedParams = [];
		$employeeParams = [];
		$locationParams = [];
		$qbOpen = $this->rosterQb(['fetchOne' => 3], $openParams);
		$qbPublished = $this->rosterQb(['fetchOne' => 2], $publishedParams);
		$qbEmployees = $this->rosterQb(['fetchOne' => 5], $employeeParams);
		$qbLocations = $this->rosterQb(['fetchOne' => 4], $locationParams);
		$qbAssignments = $this->rosterQb(['fetchOne' => 7]);
		// SchemaProbe falls back to a SELECT probe on mocked connections.
		$qbStatusProbe = $this->rosterQb([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbOpen,
			$qbPublished,
			$qbEmployees,
			$qbLocations,
			$qbAssignments,
			$qbStatusProbe,
		);
		$db->method('tableExists')->willReturn(true);

		$summary = (new RosterService($db))->dashboardSummary();

		self::assertSame(3, $summary['openPeriods']);
		self::assertSame(2, $summary['publishedPeriods']);
		self::assertSame(5, $summary['activeEmployees']);
		self::assertSame(4, $summary['activeLocations']);
		self::assertSame(7, $summary['assignments']);
		self::assertSame(
			[
				'schemaReady' => true,
				'activeEmployees' => 5,
				'activeLocations' => 4,
				'openPeriods' => 3,
				'readyForPlanning' => true,
			],
			$summary['setup'],
		);

		$this->assertParamCaptured(['open'], $openParams, 'open periods count must filter status=open');
		$this->assertParamCaptured(['published'], $publishedParams, 'published periods count must filter status=published');
		$this->assertParamCaptured([1], $employeeParams, 'employee count must filter active=1');
		$this->assertParamCaptured([1], $locationParams, 'location count must filter active=1');
	}

	public function testSummaryReportsSchemaNotReadyWhenTablesMissing(): void
	{
		$qb = $this->rosterQb(['fetchOne' => 9]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('tableExists')->willReturn(false);

		$summary = (new RosterService($db))->dashboardSummary();

		self::assertFalse($summary['setup']['schemaReady']);
		self::assertFalse(
			$summary['setup']['readyForPlanning'],
			'A missing schema must veto readiness even with positive counts',
		);
	}

	/**
	 * @return iterable<string, array{bool, int, int, int, bool}>
	 */
	public static function setupStateTruthTable(): iterable
	{
		foreach ([true, false] as $schemaReady) {
			foreach ([0, 2] as $employees) {
				foreach ([0, 3] as $locations) {
					foreach ([0, 1] as $openPeriods) {
						$expected = $schemaReady && $employees > 0 && $locations > 0 && $openPeriods > 0;
						$name = sprintf(
							'schema=%s employees=%d locations=%d openPeriods=%d',
							$schemaReady ? 'ready' : 'missing',
							$employees,
							$locations,
							$openPeriods,
						);
						yield $name => [$schemaReady, $employees, $locations, $openPeriods, $expected];
					}
				}
			}
		}
	}

	/**
	 * @dataProvider setupStateTruthTable
	 */
	public function testDeriveSetupStateTruthTable(
		bool $schemaReady,
		int $employees,
		int $locations,
		int $openPeriods,
		bool $expectedReady,
	): void {
		$state = RosterService::deriveSetupState($schemaReady, $employees, $locations, $openPeriods);

		self::assertSame(
			[
				'schemaReady' => $schemaReady,
				'activeEmployees' => $employees,
				'activeLocations' => $locations,
				'openPeriods' => $openPeriods,
				'readyForPlanning' => $expectedReady,
			],
			$state,
		);
	}

	public function testDeriveSetupStateRejectsNegativeCountsAsNotReady(): void
	{
		// Counts can never be negative in production (SQL COUNT), but the
		// guard must still fail closed if a caller ever passes garbage.
		$state = RosterService::deriveSetupState(true, -1, 3, 1);
		self::assertFalse($state['readyForPlanning']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * F17 end-to-end: shift↔round link on dc_assignments.tck_round_id, the
 * TCK status event marking the period conflicts_dirty, and the computed
 * round_missed coverage conflict appearing/resolving via the normal
 * materialization pipeline. Fixture TCK rows are seeded into tck_round /
 * tck_site directly (test fixture, not app code) so the whole read path
 * through RoundLinkReadFacade is exercised for real.
 */
class TckRoundLinkIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private RosterService $roster;
	private ?int $periodId = null;
	private ?int $employeeId = null;
	private ?int $locationId = null;
	private ?int $tckSiteId = null;
	/** @var list<int> */
	private array $tckRoundIds = [];
	/** @var list<int> */
	private array $assignmentIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->roster = \OC::$server->get(RosterService::class);
		SchemaProbe::resetCache();
		if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'tck_round_id')) {
			$this->markTestSkipped('tck_round_id column missing — run migrations');
		}
		if (!$this->db->tableExists('tck_round') || !$this->db->tableExists('tck_site')) {
			$this->markTestSkipped('tourcheck schema absent');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->assignmentIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_assignments')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		if ($this->periodId !== null) {
			foreach (['dc_conflicts', 'dc_period_audit_log'] as $t) {
				if ($this->db->tableExists($t)) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete($t)->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)))->executeStatement();
				}
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_periods')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		if ($this->employeeId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_employees')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->employeeId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		if ($this->locationId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_locations')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->locationId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		foreach ($this->tckRoundIds as $id) {
			foreach (['tck_round_cp', 'tck_event', 'tck_position'] as $t) {
				if ($this->db->tableExists($t)) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete($t)->where($qb->expr()->eq('round_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
				}
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('tck_round')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		if ($this->tckSiteId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('tck_site')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->tckSiteId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		parent::tearDown();
	}

	public function testMissedRoundSurfacesAsCoverageConflictAndResolves(): void
	{
		$this->seedFixtures();
		$assignment = $this->createLinkedAssignment();
		$assignmentId = (int) $assignment['id'];
		$roundId = $this->tckRoundIds[0];

		// Scheduled round → no conflict, dirty flag untouched.
		self::assertSame(1, $this->roster->markConflictsDirtyForTckRound($roundId));
		$this->drainFixturePeriod();
		self::assertNull($this->roundMissedConflictRow());

		// Round missed → event path marks dirty → drain materializes.
		$this->setRoundStatus($roundId, 'missed');
		self::assertSame(1, $this->roster->markConflictsDirtyForTckRound($roundId));
		$this->drainFixturePeriod();
		$row = $this->roundMissedConflictRow();
		self::assertNotNull($row, 'missed round must surface as round_missed conflict');
		self::assertSame(0, (int) $row['is_resolved']);
		self::assertSame('soft', (string) $row['severity']);
		$payload = json_decode((string) $row['payload_json'], true);
		self::assertSame($roundId, (int) ($payload['details']['tckRoundId'] ?? 0));

		// Double drain must not duplicate the row.
		$this->roster->markConflictsDirtyForTckRound($roundId);
		$this->drainFixturePeriod();
		self::assertSame(1, $this->countRoundMissedRows());

		// Round corrected/completed → next drain auto-resolves the gap.
		$this->setRoundStatus($roundId, 'completed');
		$this->roster->markConflictsDirtyForTckRound($roundId);
		$this->drainFixturePeriod();
		$row = $this->roundMissedConflictRow();
		self::assertNotNull($row);
		self::assertSame(1, (int) $row['is_resolved']);
		self::assertNotNull($row['resolved_at']);

		self::assertSame($assignmentId, (int) $assignment['id']);
	}

	public function testLinkValidationRejectsMalformedIds(): void
	{
		$this->seedFixtures();
		foreach (['abc', '1.5', '-3', ' 5', true, ['x']] as $bad) {
			try {
				$this->roster->createAssignment([
					'periodId' => $this->periodId,
					'employeeId' => $this->employeeId,
					'locationId' => $this->locationId,
					'dutyDate' => '2098-06-04',
					'startTime' => '08:00',
					'endTime' => '12:00',
					'breakMinutes' => 0,
					'note' => '',
					'tckRoundId' => $bad,
				], 'tck-link-actor');
				self::fail('malformed tckRoundId accepted: ' . var_export($bad, true));
			} catch (\InvalidArgumentException $e) {
				self::assertSame('TCK_ROUND_ID_INVALID', $e->getMessage());
			}
		}
	}

	public function testUpdateKeepsLinkWhenFieldAbsentAndClearsOnNull(): void
	{
		$this->seedFixtures();
		$assignment = $this->createLinkedAssignment();
		$assignmentId = (int) $assignment['id'];
		$version = (int) ($assignment['version'] ?? 0);

		// Update WITHOUT tckRoundId → link survives (absent ≠ clear).
		$keep = $this->roster->updateAssignment($assignmentId, [
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-02',
			'startTime' => '09:00',
			'endTime' => '13:00',
			'breakMinutes' => 0,
			'note' => 'keep-link',
			'expectedVersion' => $version,
		], 'tck-link-actor');
		$version = (int) ($this->rowById($keep['assignments'], $assignmentId)['version'] ?? $version + 1);
		self::assertSame($this->tckRoundIds[0], (int) ($this->rowById($keep['assignments'], $assignmentId)['tckRoundId'] ?? 0));

		// Explicit null → link cleared.
		$clear = $this->roster->updateAssignment($assignmentId, [
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-02',
			'startTime' => '09:00',
			'endTime' => '13:00',
			'breakMinutes' => 0,
			'note' => 'clear-link',
			'tckRoundId' => null,
			'expectedVersion' => $version,
		], 'tck-link-actor');
		self::assertNull($this->rowById($clear['assignments'], $assignmentId)['tckRoundId'] ?? null);
	}

	public function testUnknownRoundIdIsStoredButSilent(): void
	{
		$this->seedFixtures();
		// A soft reference to a not-yet-existing/deleted round must not break
		// creation and must never produce a conflict row (no oracle, no noise).
		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-05',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => '',
			'tckRoundId' => 99999999,
		], 'tck-link-actor');
		$assignment = $data['assignments'][0];
		$this->assignmentIds[] = (int) $assignment['id'];
		self::assertSame(99999999, (int) $assignment['tckRoundId']);
		$this->drainFixturePeriod();
		self::assertSame(0, $this->countRoundMissedRows());
	}

	private function seedFixtures(): void
	{
		$suffix = 'tcklink-' . bin2hex(random_bytes(3));
		$catalog = $this->roster->createEmployee(['displayName' => 'Emp ' . $suffix, 'active' => true]);
		foreach ($catalog as $row) {
			if (($row['displayName'] ?? '') === 'Emp ' . $suffix) {
				$this->employeeId = (int) $row['id'];
			}
		}
		$locCatalog = $this->roster->createLocation(['name' => 'Loc ' . $suffix, 'timezone' => 'Europe/Berlin', 'active' => true]);
		foreach ($locCatalog as $row) {
			if (($row['name'] ?? '') === 'Loc ' . $suffix) {
				$this->locationId = (int) $row['id'];
			}
		}
		$period = $this->roster->createPeriod('2098-06-01', '2098-06-07', 'tck-link-actor');
		$this->periodId = (int) $period['id'];

		// TCK fixture rows (test-owned writes into the sibling schema).
		$now = '2098-06-02 12:00:00';
		$qb = $this->db->getQueryBuilder();
		$qb->insert('tck_site')->values([
			'name' => $qb->createNamedParameter('Site ' . $suffix),
			'tz' => $qb->createNamedParameter('Europe/Berlin'),
			'live_position' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		])->executeStatement();
		$this->tckSiteId = (int) $qb->getLastInsertId();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('tck_round')->values([
			'plan_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'site_id' => $qb->createNamedParameter($this->tckSiteId, IQueryBuilder::PARAM_INT),
			'snapshot_json' => $qb->createNamedParameter('{}'),
			'scheduled_start' => $qb->createNamedParameter('2098-06-02 08:00:00'),
			'window_end' => $qb->createNamedParameter('2098-06-02 20:00:00'),
			'status' => $qb->createNamedParameter('scheduled'),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		])->executeStatement();
		$this->tckRoundIds[] = (int) $qb->getLastInsertId();
	}

	private function createLinkedAssignment(): array
	{
		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-02',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => '',
			'tckRoundId' => $this->tckRoundIds[0],
		], 'tck-link-actor');
		$assignment = $data['assignments'][0];
		$this->assignmentIds[] = (int) $assignment['id'];
		self::assertSame($this->tckRoundIds[0], (int) ($assignment['tckRoundId'] ?? 0));
		return $assignment;
	}

	/**
	 * Shared-instance safe drain: the dirty queue is drained oldest-first
	 * under a per-call budget, so sibling lanes' dirty periods can starve
	 * this fixture. Loop until OUR period's flag clears (bounded — a
	 * stuck flag still fails the test via the assertions).
	 */
	private function drainFixturePeriod(): void
	{
		for ($i = 0; $i < 50; $i++) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('conflicts_dirty')->from('dc_periods')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)));
			if ((int) $qb->executeQuery()->fetchOne() !== 1) {
				return;
			}
			if ($this->roster->drainDirtyOpenPeriodConflicts(200) === 0) {
				return;
			}
		}
	}

	private function setRoundStatus(int $roundId, string $status): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('tck_round')
			->set('status', $qb->createNamedParameter($status))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roundId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function roundMissedConflictRow(): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_conflicts')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('round_missed')));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function countRoundMissedRows(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('dc_conflicts')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('round_missed')))
			->andWhere($qb->expr()->eq('is_resolved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function rowById(array $rows, int $id): ?array
	{
		foreach ($rows as $row) {
			if ((int) ($row['id'] ?? 0) === $id) {
				return $row;
			}
		}
		return null;
	}
}

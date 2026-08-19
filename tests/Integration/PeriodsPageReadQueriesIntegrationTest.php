<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Periods page GET helpers against a real database: COUNT queries must honour
 * cancelled/resolved/ack-hash semantics (the mock unit tests cannot execute SQL).
 */
final class PeriodsPageReadQueriesIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private RosterService $roster;
	private ?int $periodId = null;
	private ?int $employeeId = null;
	private ?int $locationId = null;
	/** @var list<int> */
	private array $assignmentIds = [];
	/** @var list<int> */
	private array $conflictIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->roster = \OC::$server->get(RosterService::class);
		SchemaProbe::resetCache();
		if (!$this->db->tableExists('dc_assignments') || !$this->db->tableExists('dc_conflicts')) {
			$this->markTestSkipped('schema not ready');
		}
		if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$this->markTestSkipped('assignment status column missing — run migrations');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->conflictIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_conflicts')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		foreach ($this->assignmentIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_assignments')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		if ($this->periodId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_conflicts')->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_period_audit_log')->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT)))->executeStatement();
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
		parent::tearDown();
	}

	public function testAcknowledgeStatsExcludesCancelledAndIgnoresMissingAck(): void
	{
		$this->seedCatalog();
		$ackedId = $this->addAssignment('2097-03-02');
		$openId = $this->addAssignment('2097-03-03');
		$cancelledId = $this->addAssignment('2097-03-04');
		$this->markAcknowledged($ackedId);
		$this->roster->cancelAssignment($cancelledId, 'periods-read-actor');

		$stats = $this->roster->periodAcknowledgeStats((int) $this->periodId);
		self::assertSame(2, $stats['total'], 'cancelled must not inflate the staff-seen denominator');
		self::assertSame(1, $stats['acknowledged']);
		self::assertSame(50.0, $stats['percent']);
		self::assertNotContains($cancelledId, [$ackedId, $openId]);
	}

	public function testPublishReadinessCountsHonourResolvedAndAckHash(): void
	{
		$this->seedCatalog();
		$hash = str_repeat('a', 64);
		$stale = str_repeat('b', 64);
		$huge = '{"message":"x","assignmentIds":[' . implode(',', range(1, 4000)) . ']}';

		$this->insertConflict('hard', 0, '', $huge);
		$this->insertConflict('soft', 0, '', '{"message":"unacked"}');
		$this->insertConflict('soft', 0, $hash, '{"message":"acked"}', $hash);
		$this->insertConflict('soft', 0, $stale, '{"message":"stale-ack"}', $hash);
		$this->insertConflict('hard', 1, '', '{"message":"resolved-hard"}');

		$out = $this->roster->publishReadiness((int) $this->periodId);
		self::assertSame(1, $out['hardConflicts'], 'resolved hard rows must not count');
		self::assertSame(3, $out['softConflicts']);
		self::assertSame(2, $out['unacknowledgedSoftConflicts'], 'missing + stale ack are unacknowledged; matching hash is not');
		self::assertFalse($out['canPublish']);
	}

	private function seedCatalog(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$empName = 'Periods Read Emp ' . $suffix;
		$locName = 'Periods Read Loc ' . $suffix;
		$catalog = $this->roster->createEmployee([
			'displayName' => $empName,
			'active' => true,
		]);
		$this->employeeId = $this->findCatalogId($catalog, $empName);
		$locCatalog = $this->roster->createLocation([
			'name' => $locName,
			'timezone' => 'Europe/Berlin',
			'active' => true,
		]);
		$this->locationId = $this->findCatalogId($locCatalog, $locName, 'name');
		$period = $this->roster->createPeriod('2097-03-01', '2097-03-31', 'periods-read-actor');
		$this->periodId = (int) $period['id'];
	}

	private function addAssignment(string $dutyDate): int
	{
		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => $dutyDate,
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => '',
		], 'periods-read-actor');
		$id = (int) ($data['createdAssignmentId'] ?? $data['assignments'][0]['id']);
		$this->assignmentIds[] = $id;
		return $id;
	}

	private function markAcknowledged(int $assignmentId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_assignments')
			->set('acknowledged_at', $qb->createNamedParameter('2097-03-02 12:00:00'))
			->set('acknowledged_by', $qb->createNamedParameter('worker'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function insertConflict(string $severity, int $resolved, string $ackHash, string $payload, ?string $contextHash = null): void
	{
		$context = $contextHash ?? str_repeat('a', 64);
		$qb = $this->db->getQueryBuilder();
		$values = [
			'period_id' => $qb->createNamedParameter($this->periodId, IQueryBuilder::PARAM_INT),
			'employee_id' => $qb->createNamedParameter($this->employeeId, IQueryBuilder::PARAM_INT),
			'type' => $qb->createNamedParameter('shift_too_long'),
			'severity' => $qb->createNamedParameter($severity),
			'detected_at' => $qb->createNamedParameter('2097-03-02 00:00:00'),
			'context_hash' => $qb->createNamedParameter($context),
			'payload_json' => $qb->createNamedParameter($payload),
			'is_resolved' => $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_INT),
		];
		if ($ackHash !== '') {
			$values['ack_context_hash'] = $qb->createNamedParameter($ackHash);
		}
		$qb->insert('dc_conflicts')->values($values)->executeStatement();
		$this->conflictIds[] = (int) $qb->getLastInsertId();
	}

	/**
	 * @param list<array<string,mixed>> $catalog
	 */
	private function findCatalogId(array $catalog, string $needle, string $field = 'displayName'): int
	{
		foreach ($catalog as $row) {
			if ((string) ($row[$field] ?? '') === $needle) {
				return (int) $row['id'];
			}
		}
		self::fail('catalog row not found for ' . $needle);
		return 0;
	}
}

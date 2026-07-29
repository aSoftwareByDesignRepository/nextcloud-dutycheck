<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\QualificationService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCA\DutyCheck\Service\SnapshotRetentionService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * End-to-end service checks on a live Nextcloud DB for Wave A0/A/B critical paths.
 */
final class WaveFeatureIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private RosterService $roster;
	private ?int $periodId = null;
	private ?int $employeeId = null;
	private ?int $locationId = null;
	/** @var list<int> */
	private array $assignmentIds = [];
	private ?int $templateId = null;
	private ?int $qualificationId = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->roster = \OC::$server->get(RosterService::class);
		if (!$this->db->tableExists('dc_assignments') || !$this->db->tableExists('dc_shift_templates')) {
			$this->markTestSkipped('Wave schema not ready');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->assignmentIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_assignments')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
		}
		if ($this->templateId !== null && $this->db->tableExists('dc_shift_templates')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_shift_templates')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->templateId)))->executeStatement();
		}
		if ($this->qualificationId !== null && $this->db->tableExists('dc_qualifications')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_loc_quals')->where($qb->expr()->eq('qualification_id', $qb->createNamedParameter($this->qualificationId)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_emp_quals')->where($qb->expr()->eq('qualification_id', $qb->createNamedParameter($this->qualificationId)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_qualifications')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->qualificationId)))->executeStatement();
		}
		if ($this->periodId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_conflicts')->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_period_audit_log')->where($qb->expr()->eq('period_id', $qb->createNamedParameter($this->periodId)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_periods')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->periodId)))->executeStatement();
		}
		if ($this->employeeId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_employees')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->employeeId)))->executeStatement();
		}
		if ($this->locationId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_locations')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->locationId)))->executeStatement();
		}
		parent::tearDown();
	}

	public function testUpdateCancelAcknowledgeAndTemplatePaths(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$empName = 'Wave IT Emp ' . $suffix;
		$locName = 'Wave IT Loc ' . $suffix;
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
		$period = $this->roster->createPeriod('2099-01-01', '2099-01-07', 'wave-it');
		$this->periodId = (int) $period['id'];

		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2099-01-02',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => 'created',
		], 'wave-it');
		$assignment = $data['assignments'][0] ?? null;
		self::assertNotNull($assignment);
		$assignmentId = (int) $assignment['id'];
		$this->assignmentIds[] = $assignmentId;

		$updated = $this->roster->updateAssignment($assignmentId, [
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2099-01-02',
			'startTime' => '09:00',
			'endTime' => '13:00',
			'breakMinutes' => 15,
			'note' => 'updated',
			'expectedVersion' => (int) ($assignment['version'] ?? 0),
		], 'wave-it');
		$found = null;
		foreach ($updated['assignments'] as $row) {
			if ((int) $row['id'] === $assignmentId) {
				$found = $row;
				break;
			}
		}
		self::assertNotNull($found);
		self::assertSame('09:00', substr((string) $found['startTime'], 0, 5));
		self::assertSame(15, (int) $found['breakMinutes']);

		$templates = \OC::$server->get(ShiftTemplateService::class);
		$tpl = $templates->create([
			'name' => 'Wave IT Tpl ' . $suffix,
			'startTime' => '14:00',
			'endTime' => '18:00',
			'breakMinutes' => 10,
		]);
		$this->templateId = (int) $tpl['id'];
		self::assertSame('14:00', substr((string) $tpl['startTime'], 0, 5));

		$policy = \OC::$server->get(ConflictPolicyService::class);
		$saved = $policy->save($policy->defaults(), 'wave-it');
		self::assertSame($policy->defaults()['maxPeriodSoft'], $saved['maxPeriodSoft']);
		self::assertGreaterThanOrEqual($saved['maxPeriodSoft'], $saved['maxPeriodHard']);

		$retention = \OC::$server->get(SnapshotRetentionService::class);
		$prune = $retention->pruneExpired();
		self::assertArrayHasKey('enabled', $prune);
		self::assertArrayHasKey('deleted', $prune);

		$this->roster->cancelAssignment($assignmentId, 'wave-it');
		$afterCancel = $this->roster->rosterData($this->periodId);
		foreach ($afterCancel['assignments'] as $row) {
			self::assertNotSame($assignmentId, (int) $row['id'], 'Cancelled assignment must not appear in planner list');
		}
		self::assertTrue(
			\OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status'),
			'status column must be detectable on ConnectionAdapter',
		);
	}

	public function testMissingQualificationBlocksAssignment(): void
	{
		if (!$this->db->tableExists('dc_qualifications')) {
			$this->markTestSkipped('qualifications table missing');
		}
		$suffix = bin2hex(random_bytes(4));
		$empName = 'Qual Emp ' . $suffix;
		$locName = 'Qual Loc ' . $suffix;
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
		$period = $this->roster->createPeriod('2099-02-01', '2099-02-07', 'wave-it');
		$this->periodId = (int) $period['id'];

		$quals = \OC::$server->get(QualificationService::class);
		$qual = $quals->create(['name' => 'Guard Cert ' . $suffix, 'code' => 'GC' . $suffix]);
		$this->qualificationId = (int) $qual['id'];
		$quals->requireForLocation($this->locationId, $this->qualificationId);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('QUALIFICATION_MISSING');
		$this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2099-02-02',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
		], 'wave-it');
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
		self::fail('Catalog row not found for ' . $needle);
	}
}

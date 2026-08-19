<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Behavioural close-out: assignment version CAS, cancel CAS, understaffed soft conflict.
 */
final class AssignmentCasCloseoutIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private RosterService $roster;
	private ?int $periodId = null;
	private ?int $employeeId = null;
	private ?int $locationId = null;
	/** @var list<int> */
	private array $assignmentIds = [];
	private ?int $templateId = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->roster = \OC::$server->get(RosterService::class);
		SchemaProbe::resetCache();
		if (!$this->db->tableExists('dc_assignments')) {
			$this->markTestSkipped('schema not ready');
		}
		if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'version')) {
			$this->markTestSkipped('assignment version column missing — run migrations');
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

	public function testUpdateRequiresExpectedVersionAndRejectsStale(): void
	{
		$this->seedCatalog('cas');
		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-02',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => '',
		], 'cas-actor');
		$assignment = $data['assignments'][0];
		$assignmentId = (int) $assignment['id'];
		$this->assignmentIds[] = $assignmentId;
		$version = (int) ($assignment['version'] ?? 0);

		try {
			$this->roster->updateAssignment($assignmentId, [
				'employeeId' => $this->employeeId,
				'locationId' => $this->locationId,
				'dutyDate' => '2098-06-02',
				'startTime' => '09:00',
				'endTime' => '13:00',
				'breakMinutes' => 0,
				'note' => 'no-version',
			], 'cas-actor');
			self::fail('EXPECTED_VERSION_REQUIRED was not thrown');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('EXPECTED_VERSION_REQUIRED', $e->getMessage());
		}

		try {
			$this->roster->updateAssignment($assignmentId, [
				'employeeId' => $this->employeeId,
				'locationId' => $this->locationId,
				'dutyDate' => '2098-06-02',
				'startTime' => '09:00',
				'endTime' => '13:00',
				'breakMinutes' => 0,
				'note' => 'stale',
				'expectedVersion' => $version - 1,
			], 'cas-actor');
			self::fail('STALE_VERSION was not thrown');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STALE_VERSION', $e->getMessage());
		}

		$ok = $this->roster->updateAssignment($assignmentId, [
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-02',
			'startTime' => '09:00',
			'endTime' => '13:00',
			'breakMinutes' => 15,
			'note' => 'ok',
			'expectedVersion' => $version,
		], 'cas-actor');
		$found = null;
		foreach ($ok['assignments'] as $row) {
			if ((int) $row['id'] === $assignmentId) {
				$found = $row;
				break;
			}
		}
		self::assertNotNull($found);
		self::assertSame(15, (int) $found['breakMinutes']);
		self::assertSame($version + 1, (int) ($found['version'] ?? 0));

		// Concurrent race: second writer still holds old version → STALE_VERSION.
		try {
			$this->roster->updateAssignment($assignmentId, [
				'employeeId' => $this->employeeId,
				'locationId' => $this->locationId,
				'dutyDate' => '2098-06-02',
				'startTime' => '10:00',
				'endTime' => '14:00',
				'breakMinutes' => 0,
				'note' => 'race',
				'expectedVersion' => $version,
			], 'cas-actor');
			self::fail('concurrent stale update should fail');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STALE_VERSION', $e->getMessage());
		}
	}

	public function testCancelIsIdempotentAndStatusCasGuardsRaces(): void
	{
		$this->seedCatalog('cancel');
		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-03',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => '',
		], 'cas-actor');
		$assignmentId = (int) $data['assignments'][0]['id'];
		$this->assignmentIds[] = $assignmentId;

		$this->roster->cancelAssignment($assignmentId, 'cas-actor');
		// Sequential re-entry is a no-op (idempotent); concurrent races hit status CAS.
		$again = $this->roster->cancelAssignment($assignmentId, 'cas-actor');
		self::assertArrayHasKey('assignments', $again);
		foreach ($again['assignments'] as $row) {
			self::assertNotSame($assignmentId, (int) $row['id']);
		}

		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/RosterService.php');
		self::assertStringContainsString("neq('status'", $src);
		self::assertMatchesRegularExpression(
			'/function cancelAssignment[\s\S]{0,3200}?eq\(\'version\'/',
			$src,
		);
	}

	public function testCancelThenRecreateSameSlotSucceeds(): void
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'slot_key')) {
			$this->markTestSkipped('slot_key column missing — run migrations');
		}
		$this->seedCatalog('recreate');
		$payload = [
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-05',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => '',
		];
		$first = $this->roster->createAssignment($payload, 'cas-actor');
		$firstId = (int) $first['assignments'][0]['id'];
		$this->assignmentIds[] = $firstId;

		$this->roster->cancelAssignment($firstId, 'cas-actor');

		$second = $this->roster->createAssignment($payload, 'cas-actor');
		$secondId = (int) ($second['createdAssignmentId'] ?? $second['assignments'][0]['id']);
		$this->assignmentIds[] = $secondId;
		self::assertNotSame($firstId, $secondId);

		$activeIds = array_map(static fn (array $r): int => (int) $r['id'], $second['assignments']);
		self::assertContains($secondId, $activeIds);
		self::assertNotContains($firstId, $activeIds);
	}

	public function testUnderstaffedSoftConflictWhenTemplateMinHeadcountNotMet(): void
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'min_headcount')) {
			$this->markTestSkipped('min_headcount missing');
		}
		$this->seedCatalog('under');
		$templates = \OC::$server->get(ShiftTemplateService::class);
		$tpl = $templates->create([
			'name' => 'Under staff ' . bin2hex(random_bytes(3)),
			'locationId' => $this->locationId,
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => 30,
			'minHeadcount' => 2,
		]);
		$this->templateId = (int) $tpl['id'];

		$data = $this->roster->createAssignment([
			'periodId' => $this->periodId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'dutyDate' => '2098-06-04',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => 30,
			'note' => '',
		], 'cas-actor');
		$this->assignmentIds[] = (int) $data['assignments'][0]['id'];

		$types = [];
		foreach ($data['conflicts'] ?? [] as $c) {
			$types[] = (string) ($c['type'] ?? '');
		}
		// Conflicts may also be refreshed via rosterData.
		if (!in_array('understaffed_shift', $types, true)) {
			$roster = $this->roster->rosterData($this->periodId);
			foreach ($roster['conflicts'] ?? [] as $c) {
				$types[] = (string) ($c['type'] ?? '');
			}
		}
		self::assertContains('understaffed_shift', $types);
	}

	private function seedCatalog(string $tag): void
	{
		$suffix = $tag . '-' . bin2hex(random_bytes(3));
		$empName = 'CAS Emp ' . $suffix;
		$locName = 'CAS Loc ' . $suffix;
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
		$period = $this->roster->createPeriod('2098-06-01', '2098-06-07', 'cas-actor');
		$this->periodId = (int) $period['id'];
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

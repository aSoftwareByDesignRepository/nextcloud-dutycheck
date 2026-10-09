<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Service\MobileDemoSeedOptions;
use OCA\DutyCheck\Service\MobileDemoSeedService;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\RosterService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Test\TestCase;

/**
 * Live DB seed for mobile companion QA — no web UI.
 *
 * The seed deliberately writes real rows (users, seat, employee, assignment,
 * open shift, license state). tearDown must purge every row this run created
 * and restore shared state it overwrote (license row, stolen display-name
 * employee link) — a missed child row here is how dc.seed.* users piled up.
 */
final class MobileDemoSeedIntegrationTest extends TestCase
{
	private MobileDemoSeedService $seedService;
	private ?IDBConnection $db = null;
	private string $employeeUid = '';
	private string $unseatedUid = '';
	/** @var array<int, array<string, mixed>> oc_dc_license_state rows before the test */
	private array $licenseStateBefore = [];
	/** @var array<int, string> dc_employees id => linked_user_id for the demo display name */
	private array $employeeLinkBefore = [];
	/** @var array<int, true> open-shift ids present before the test */
	private array $openShiftIdsBefore = [];
	private ?int $employeeId = null;
	private ?int $assignmentId = null;
	private ?int $openShiftId = null;

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
		$this->db = Server::get(IDBConnection::class);

		$suffix = bin2hex(random_bytes(3));
		$this->employeeUid = 'dc.seed.' . $suffix;
		$this->unseatedUid = 'dc.noseat.' . $suffix;

		// Snapshot shared state the seed overwrites or steals: the live license
		// row (apply() is delete-all+insert) and any employee row carrying the
		// demo display name (ensureEmployee re-links it in place).
		$this->licenseStateBefore = $this->rows('SELECT * FROM oc_dc_license_state');
		foreach ($this->rows(
			'SELECT id, linked_user_id FROM oc_dc_employees WHERE display_name = ?',
			[MobileDemoSeedOptions::DEFAULT_EMPLOYEE_NAME],
		) as $row) {
			$this->employeeLinkBefore[(int) $row['id']] = (string) ($row['linked_user_id'] ?? '');
		}
		foreach ($this->rows('SELECT id FROM oc_dc_open_shifts') as $row) {
			$this->openShiftIdsBefore[(int) $row['id']] = true;
		}
	}

	protected function tearDown(): void
	{
		try {
			if ($this->db !== null) {
				$employeeIds = [$this->employeeId];
				foreach ($this->rows(
					'SELECT id FROM oc_dc_employees WHERE linked_user_id = ? OR linked_user_id = ?',
					[$this->employeeUid, $this->unseatedUid],
				) as $row) {
					$employeeIds[] = (int) $row['id'];
				}
				$employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));

				// Child rows first, parents last — never leave FK-less orphans.
				foreach ($employeeIds as $empId) {
					$this->deleteWhere('dc_swap_requests', 'from_employee_id', $empId, true);
					$this->deleteWhere('dc_swap_requests', 'to_employee_id', $empId, true);
					$this->deleteWhere('dc_avail_blackouts', 'employee_id', $empId, true);
					$this->deleteWhere('dc_shift_preferences', 'employee_id', $empId, true);
					$this->deleteWhere('dc_blackout_overrides', 'employee_id', $empId, true);
					$this->deleteWhere('dc_emp_rot_assign', 'employee_id', $empId, true);
					$this->deleteWhere('dc_emp_quals', 'employee_id', $empId, true);
					$this->deleteWhere('dc_conflicts', 'employee_id', $empId, true);
					$this->deleteWhere('dc_assignments', 'employee_id', $empId, true);
				}
				if ($this->assignmentId !== null) {
					$this->deleteWhere('dc_assignments', 'id', $this->assignmentId, true);
				}
				if ($this->openShiftId !== null && !isset($this->openShiftIdsBefore[$this->openShiftId])) {
					$this->deleteWhere('dc_open_shifts', 'id', $this->openShiftId, true);
				}
				foreach ($employeeIds as $empId) {
					// A pre-existing display-name row gets its stolen link restored;
					// rows created by this run are deleted outright.
					if (isset($this->employeeLinkBefore[$empId])) {
						$qb = $this->db->getQueryBuilder();
						$qb->update('dc_employees')
							->set('linked_user_id', $qb->createNamedParameter($this->employeeLinkBefore[$empId]))
							->where($qb->expr()->eq('id', $qb->createNamedParameter($empId, IQueryBuilder::PARAM_INT)))
							->executeStatement();
					} else {
						$this->deleteWhere('dc_employees', 'id', $empId, true);
					}
				}
				$this->deleteWhere('dc_mobile_seats', 'uid', $this->employeeUid);
				$this->deleteWhere('dc_mobile_seats', 'uid', $this->unseatedUid);

				// license apply() is delete-all+insert — restore the prior row.
				$qb = $this->db->getQueryBuilder();
				$qb->delete('dc_license_state')->executeStatement();
				foreach ($this->licenseStateBefore as $row) {
					$ins = $this->db->getQueryBuilder();
					$values = [];
					foreach ($row as $col => $val) {
						$values[$col] = $ins->createNamedParameter($val);
					}
					$ins->insert('dc_license_state')->values($values)->executeStatement();
				}
			}
		} finally {
			$userManager = Server::get(IUserManager::class);
			foreach ([$this->employeeUid, $this->unseatedUid] as $uid) {
				if ($uid !== '') {
					$userManager->get($uid)?->delete();
				}
			}
			Server::get(IUserSession::class)->setUser(null);
			parent::tearDown();
		}
	}

	/**
	 * @param array<int, mixed> $params
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $sql, array $params = []): array
	{
		if ($this->db === null || !$this->db->tableExists($this->tableOf($sql))) {
			return [];
		}
		$result = $this->db->executeQuery($sql, $params);
		$out = $result->fetchAll(\PDO::FETCH_ASSOC);
		$result->closeCursor();
		return $out;
	}

	private function tableOf(string $sql): string
	{
		if (preg_match('/oc_(\w+)/', $sql, $m) === 1) {
			return $m[1];
		}
		return '';
	}

	private function deleteWhere(string $table, string $column, int|string $value, bool $existsCheck = false): void
	{
		if ($this->db === null || ($existsCheck && !$this->db->tableExists($table))) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$type = is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
		$qb->delete($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)))
			->executeStatement();
	}

	public function testSeedProducesSeatedRosterAndUnseatedGate(): void
	{
		$password = 'SeedDemo2026!';

		$appManager = Server::get(IAppManager::class);
		$appManager->loadApp('sbdlicenseops');
		$generator = Server::get(\OCA\SbdLicenseOps\Service\LicenseKeyGeneratorService::class);
		$wire = $generator->generate(
			'dutycheck',
			'integration-mobile-seed-' . substr($this->employeeUid, -6),
			['mobileSeats' => 5],
			'2027-12-31',
			null,
		)['wireKey'];

		$result = $this->seedService->run(new MobileDemoSeedOptions(
			employeeUserId: $this->employeeUid,
			employeePassword: $password,
			unseatedUserId: $this->unseatedUid,
			unseatedPassword: $password,
			licenseWireKey: $wire,
		));

		$this->employeeId = $result->employeeId;
		$this->assignmentId = $result->assignmentId;
		$this->openShiftId = $result->openShiftId;

		self::assertSame($this->employeeUid, $result->employeeUserId);
		self::assertGreaterThan(0, $result->employeeId);
		self::assertGreaterThan(0, $result->periodId);
		self::assertContains($result->periodStatus, ['published', 'closed']);

		$roster = Server::get(RosterService::class);
		$rows = $roster->myRoster($this->employeeUid);
		self::assertNotEmpty($rows, 'Seated user must see at least one published assignment');

		$gate = Server::get(MobileGateService::class);
		$seatedBoot = $gate->bootstrapPayload($this->employeeUid, 'Seed Employee', '0.1.43');
		self::assertTrue($seatedBoot['seatAssigned']);
		self::assertTrue($seatedBoot['licensing']['mobile']['enabledForUser']);

		$unseatedBoot = $gate->bootstrapPayload($this->unseatedUid, 'No Seat', '0.1.43');
		self::assertFalse($unseatedBoot['seatAssigned']);
		self::assertFalse($unseatedBoot['licensing']['mobile']['enabledForUser']);
	}
}

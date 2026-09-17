<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Exception\LicenseException;
use OCA\DutyCheck\Exception\MobileDemoSeedException;
use OCP\IUserManager;
use OCP\IUserSession;
use Throwable;

/**
 * Idempotent mobile companion demo dataset — no web UI required.
 *
 * Creates DTY2 + seated/unseated NC users, linked employee, published shift,
 * and one open shift for Marketplace claim. Safe to re-run on dev/staging.
 */
final class MobileDemoSeedService
{
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly LicenseService $license,
		private readonly RosterService $roster,
		private readonly OpenShiftService $openShifts,
	) {
	}

	public function run(MobileDemoSeedOptions $options): MobileDemoSeedResult
	{
		$wireKey = trim((string) $options->licenseWireKey);
		if ($wireKey === '') {
			throw new MobileDemoSeedException(
				'DTY2 license wire key is required. Mint one via sbdlicenseops or pass --license-key.',
			);
		}

		$admin = $this->userManager->get($options->adminUserId);
		if ($admin === null) {
			throw new MobileDemoSeedException('Admin user "' . $options->adminUserId . '" does not exist.');
		}
		$this->userSession->setUser($admin);

		$this->license->apply($options->adminUserId, $wireKey);
		$this->ensureUser($options->employeeUserId, $options->employeePassword);
		$this->ensureUser($options->unseatedUserId, $options->unseatedPassword);

		$seatAssigned = $this->ensureSeat($options->adminUserId, $options->employeeUserId);

		$employeeId = $this->ensureEmployee($options);
		$locationId = $this->ensureLocation($options);
		// Assignments may only be created while the period is open — never publish first.
		[$periodId] = $this->ensureDemoPeriod($options->adminUserId);

		$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
		$shiftDate = $today->modify('+1 day')->format('Y-m-d');
		$openShiftDate = $today->modify('+2 days')->format('Y-m-d');

		$assignmentId = $this->ensureAcknowledgableAssignment(
			$options->adminUserId,
			$options->employeeUserId,
			$periodId,
			$employeeId,
			$locationId,
			$shiftDate,
		);
		$openShiftId = $this->ensureOpenShift(
			$options->adminUserId,
			$periodId,
			$locationId,
			$openShiftDate,
			$options->employeeUserId,
		);
		$periodStatus = $this->ensureDemoPeriodPublished($periodId, $options->adminUserId);

		return new MobileDemoSeedResult(
			employeeUserId: $options->employeeUserId,
			unseatedUserId: $options->unseatedUserId,
			employeeId: $employeeId,
			locationId: $locationId,
			periodId: $periodId,
			shiftDate: $shiftDate,
			openShiftDate: $openShiftDate,
			assignmentId: $assignmentId,
			openShiftId: $openShiftId,
			periodStatus: $periodStatus,
			licenseApplied: true,
			seatAssigned: $seatAssigned,
		);
	}

	private function ensureUser(string $uid, string $password): void
	{
		if ($this->userManager->userExists($uid)) {
			return;
		}
		if (!$this->userManager->createUser($uid, $password)) {
			throw new MobileDemoSeedException('Could not create Nextcloud user "' . $uid . '".');
		}
	}

	private function ensureSeat(string $adminUid, string $employeeUid): bool
	{
		try {
			$result = $this->license->assignSeat($adminUid, $employeeUid);
			return (bool) ($result['created'] ?? true);
		} catch (LicenseException $e) {
			if ($this->license->isUserSeated($employeeUid)) {
				return true;
			}
			// Shared-dev / re-run: prior dc.seed.* seats can exhaust a fresh 5-seat DTY2.
			if ($e->getErrorCode() === 'seat_limit_reached' && $this->releaseDemoSeatsExcept($employeeUid)) {
				try {
					$result = $this->license->assignSeat($adminUid, $employeeUid);
					return (bool) ($result['created'] ?? true);
				} catch (LicenseException $retry) {
					if ($this->license->isUserSeated($employeeUid)) {
						return true;
					}
					throw new MobileDemoSeedException('Could not assign mobile seat: ' . $retry->getMessage(), 0, $retry);
				}
			}
			throw new MobileDemoSeedException('Could not assign mobile seat: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Drop leftover mobile-demo seats so a re-seed can assign under the current seat limit.
	 * Keeps $keepUid when already seated.
	 */
	private function releaseDemoSeatsExcept(string $keepUid): bool
	{
		$released = false;
		foreach ($this->license->listSeats(200, 0)['data'] as $row) {
			$uid = (string) ($row['uid'] ?? '');
			if ($uid === '' || $uid === $keepUid) {
				continue;
			}
			if (
				str_starts_with($uid, 'dc.seed.')
				|| str_starts_with($uid, 'dc.noseat.')
				|| str_starts_with($uid, 'dc.demo.')
			) {
				try {
					$this->license->removeSeat($uid);
					$released = true;
				} catch (Throwable) {
					// best-effort cleanup
				}
			}
		}
		return $released;
	}

	private function ensureEmployee(MobileDemoSeedOptions $options): int
	{
		$existing = $this->findEmployeeIdByLinkedUser($options->employeeUserId, $options->adminUserId);
		if ($existing !== null) {
			return $existing;
		}

		foreach ($this->roster->listEmployeeCatalog($options->adminUserId) as $row) {
			if (($row['displayName'] ?? '') === $options->employeeDisplayName) {
				$id = (int) ($row['id'] ?? 0);
				if ($id > 0) {
					$this->roster->updateEmployee($id, [
						'displayName' => $options->employeeDisplayName,
						'linkedUserId' => $options->employeeUserId,
						'active' => true,
					], $options->adminUserId);
					return $id;
				}
			}
		}

		$this->roster->createEmployee([
			'displayName' => $options->employeeDisplayName,
			'linkedUserId' => $options->employeeUserId,
			'active' => true,
		], $options->adminUserId);

		$created = $this->findEmployeeIdByLinkedUser($options->employeeUserId, $options->adminUserId);
		if ($created === null) {
			throw new MobileDemoSeedException('Employee catalog row missing after create.');
		}
		return $created;
	}

	private function findEmployeeIdByLinkedUser(string $linkedUserId, string $adminUserId): ?int
	{
		foreach ($this->roster->listEmployeeCatalog($adminUserId) as $row) {
			if (($row['linkedUserId'] ?? null) === $linkedUserId) {
				$id = (int) ($row['id'] ?? 0);
				return $id > 0 ? $id : null;
			}
		}
		return null;
	}

	private function ensureLocation(MobileDemoSeedOptions $options): int
	{
		foreach ($this->roster->listLocationCatalog($options->adminUserId) as $row) {
			if (($row['name'] ?? '') === $options->locationName) {
				$id = (int) ($row['id'] ?? 0);
				if ($id > 0) {
					return $id;
				}
			}
		}

		$this->roster->createLocation([
			'name' => $options->locationName,
			'timezone' => $options->timezone,
			'active' => true,
		], $options->adminUserId);

		foreach ($this->roster->listLocationCatalog($options->adminUserId) as $row) {
			if (($row['name'] ?? '') === $options->locationName) {
				$id = (int) ($row['id'] ?? 0);
				if ($id > 0) {
					return $id;
				}
			}
		}

		throw new MobileDemoSeedException('Location catalog row missing after create.');
	}

	/**
	 * Find or create a period covering today. Leaves status unchanged (may be open or published).
	 *
	 * @return array{0: int, 1: string}
	 */
	private function ensureDemoPeriod(string $adminUserId): array
	{
		$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
		$periodStart = $today->modify('monday this week')->format('Y-m-d');
		$periodEnd = $today->modify('+21 days')->format('Y-m-d');
		$nowIso = $today->format('Y-m-d');

		$periodId = null;
		$status = 'open';
		try {
			$period = $this->roster->createPeriod($periodStart, $periodEnd, $adminUserId);
			$periodId = (int) ($period['id'] ?? 0);
			$status = (string) ($period['status'] ?? 'open');
		} catch (Throwable) {
			foreach ($this->roster->listPeriods($adminUserId) as $periodRow) {
				$start = (string) ($periodRow['startDate'] ?? '');
				$end = (string) ($periodRow['endDate'] ?? '');
				if ($start <= $nowIso && $end >= $nowIso) {
					$periodId = (int) ($periodRow['id'] ?? 0);
					$status = (string) ($periodRow['status'] ?? 'open');
					break;
				}
			}
		}

		if ($periodId === null || $periodId <= 0) {
			throw new MobileDemoSeedException('Could not create or find a demo period covering today.');
		}

		return [$periodId, $status];
	}

	/**
	 * Publish after planning mutations. Idempotent when already published/closed.
	 */
	private function ensureDemoPeriodPublished(int $periodId, string $adminUserId): string
	{
		$status = 'open';
		foreach ($this->roster->listPeriods($adminUserId) as $periodRow) {
			if ((int) ($periodRow['id'] ?? 0) === $periodId) {
				$status = (string) ($periodRow['status'] ?? 'open');
				break;
			}
		}

		if ($status === 'open') {
			try {
				$updated = $this->roster->transitionPeriod($periodId, 'published', $adminUserId);
				$status = (string) ($updated['status'] ?? 'published');
			} catch (Throwable $e) {
				throw new MobileDemoSeedException(
					'Demo period exists but could not be published: ' . $e->getMessage(),
					0,
					$e,
				);
			}
		}

		if ($status !== 'published' && $status !== 'closed') {
			throw new MobileDemoSeedException('Demo period must be published for mobile roster (status=' . $status . ').');
		}

		return $status;
	}

	/**
	 * createAssignment requires status=open. Reopen published periods via closed → open.
	 */
	private function ensurePeriodOpenForAssignment(int $periodId, string $adminUserId): void
	{
		$status = 'open';
		foreach ($this->roster->listPeriods($adminUserId) as $periodRow) {
			if ((int) ($periodRow['id'] ?? 0) === $periodId) {
				$status = (string) ($periodRow['status'] ?? 'open');
				break;
			}
		}
		if ($status === 'open') {
			return;
		}

		$reason = 'Mobile demo seed: reopen period to add companion assignment';
		try {
			if ($status === 'published') {
				$this->roster->transitionPeriod($periodId, 'closed', $adminUserId, $reason);
				$status = 'closed';
			}
			if ($status === 'closed') {
				$this->roster->transitionPeriod($periodId, 'open', $adminUserId, $reason);
			}
		} catch (Throwable $e) {
			throw new MobileDemoSeedException(
				'Could not reopen demo period for assignment create: ' . $e->getMessage(),
				0,
				$e,
			);
		}
	}

	private function ensureAcknowledgableAssignment(
		string $adminUserId,
		string $employeeUserId,
		int $periodId,
		int $employeeId,
		int $locationId,
		string $shiftDate,
	): ?int {
		$reuseId = null;
		try {
			$existing = $this->roster->myRoster($employeeUserId);
			foreach ($existing as $row) {
				if (($row['dutyDate'] ?? '') === $shiftDate) {
					$reuseId = (int) ($row['id'] ?? 0) ?: null;
					// Idempotent reseed: clear ack so Home still has an acknowledge CTA.
					if ($reuseId !== null && ($row['acknowledged'] ?? false) === true) {
						$this->ensurePeriodOpenForAssignment($periodId, $adminUserId);
						$this->roster->updateAssignment($reuseId, [
							'locationId' => $locationId,
							'dutyDate' => $shiftDate,
							'startTime' => (string) ($row['startTime'] ?? '09:00'),
							'endTime' => (string) ($row['endTime'] ?? '17:00'),
							'breakMinutes' => (int) ($row['breakMinutes'] ?? 30),
							'note' => 'Mobile demo shift — acknowledge on Home',
							'version' => (int) ($row['version'] ?? 1),
						], $adminUserId);
					}
					if ($reuseId !== null) {
						return $reuseId;
					}
				}
			}
		} catch (Throwable) {
			// fall through to create
		}

		$this->ensurePeriodOpenForAssignment($periodId, $adminUserId);

		try {
			$data = $this->roster->createAssignment([
				'periodId' => $periodId,
				'employeeId' => $employeeId,
				'locationId' => $locationId,
				'dutyDate' => $shiftDate,
				'startTime' => '09:00',
				'endTime' => '17:00',
				'breakMinutes' => 30,
				'note' => 'Mobile demo shift — acknowledge on Home',
			], $adminUserId);
			$id = (int) (($data['assignments'][0] ?? [])['id'] ?? 0);
			return $id > 0 ? $id : null;
		} catch (Throwable $e) {
			$msg = $e->getMessage();
			if ($msg !== 'ASSIGNMENT_OVERLAP' && $msg !== 'ASSIGNMENT_DUPLICATE_SLOT') {
				throw new MobileDemoSeedException(
					'Could not create demo assignment: ' . $msg,
					0,
					$e,
				);
			}
			// Slot already exists while period is still open — myRoster only lists
			// published/closed periods, so publish briefly to resolve the row id.
			$this->ensureDemoPeriodPublished($periodId, $adminUserId);
			try {
				foreach ($this->roster->myRoster($employeeUserId) as $row) {
					if (($row['dutyDate'] ?? '') !== $shiftDate) {
						continue;
					}
					$reuseId = (int) ($row['id'] ?? 0);
					if ($reuseId <= 0) {
						continue;
					}
					if (($row['acknowledged'] ?? false) === true) {
						$this->ensurePeriodOpenForAssignment($periodId, $adminUserId);
						$this->roster->updateAssignment($reuseId, [
							'locationId' => $locationId,
							'dutyDate' => $shiftDate,
							'startTime' => (string) ($row['startTime'] ?? '09:00'),
							'endTime' => (string) ($row['endTime'] ?? '17:00'),
							'breakMinutes' => (int) ($row['breakMinutes'] ?? 30),
							'note' => 'Mobile demo shift — acknowledge on Home',
							'version' => (int) ($row['version'] ?? 1),
						], $adminUserId);
					}
					return $reuseId;
				}
			} catch (Throwable $inner) {
				throw new MobileDemoSeedException(
					'Demo assignment slot occupied but could not be resolved: ' . $inner->getMessage(),
					0,
					$inner,
				);
			}
			throw new MobileDemoSeedException(
				'Demo assignment slot occupied but not visible on myRoster — clear overlaps and re-run.',
				0,
				$e,
			);
		}
	}

	private function ensureOpenShift(
		string $adminUserId,
		int $periodId,
		int $locationId,
		string $openShiftDate,
		string $employeeUserId,
	): ?int {
		try {
			$existing = $this->openShifts->listOpen($periodId, $employeeUserId);
			if ($existing !== []) {
				$id = (int) ($existing[0]['id'] ?? 0);
				return $id > 0 ? $id : null;
			}

			$open = $this->openShifts->create([
				'periodId' => $periodId,
				'locationId' => $locationId,
				'dutyDate' => $openShiftDate,
				'startTime' => '14:00',
				'endTime' => '22:00',
				'breakMinutes' => 0,
				'note' => 'Mobile demo open shift — claim in Marketplace',
			], $adminUserId);
			$id = (int) ($open['id'] ?? 0);
			return $id > 0 ? $id : null;
		} catch (Throwable $e) {
			throw new MobileDemoSeedException(
				'Could not create demo open shift: ' . $e->getMessage(),
				0,
				$e,
			);
		}
	}
}

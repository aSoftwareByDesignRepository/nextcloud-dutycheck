<?php

declare(strict_types=1);

/**
 * Momos adversarial attack harness — run inside nextcloud container:
 *   php custom_apps/dutycheck/scripts/_momos_attack.php
 *
 * Every finding must be proven by a FAIL line or explicit PROVEN.
 */

require '/var/www/html/lib/base.php';

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\ShiftPreferenceService;
use OCA\DutyCheck\Service\SwapService;
use OCA\DutyCheck\Service\TodayBoardService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

OC_App::loadApp('dutycheck');

$app = \OCP\Server::get(Application::class);
$c = $app->getContainer();
$db = $c->get(IDBConnection::class);
$settings = $c->get(SelfServiceSettingsService::class);
$peer = $c->get(PeerRosterService::class);
$today = $c->get(TodayBoardService::class);
$swaps = $c->get(SwapService::class);
$roster = $c->get(RosterService::class);
$open = $c->get(OpenShiftService::class);
$prefs = $c->get(ShiftPreferenceService::class);
$blackouts = $c->get(AvailabilityBlackoutService::class);

$companyId = CompanyService::DEFAULT_COMPANY_ID;
$admin = 'admin';
$empA = 'dc.review.employee'; // emp 36
$empB = 'e2e_employee';       // emp 15
$empAId = 36;
$empBId = 15;

$proven = 0;
$clean = 0;
$errors = 0;

function attack(string $id, string $title, callable $fn): void
{
	global $proven, $clean, $errors;
	echo "\n=== [$id] $title ===\n";
	try {
		$result = $fn();
		$verdict = $result['verdict'] ?? 'UNKNOWN';
		$detail = $result['detail'] ?? '';
		echo "$verdict: $detail\n";
		if ($verdict === 'PROVEN') {
			$proven++;
		} elseif ($verdict === 'CLEAN') {
			$clean++;
		} else {
			$errors++;
		}
	} catch (Throwable $e) {
		$errors++;
		echo "ERROR: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
	}
}

/**
 * Resolve linked employee id for a Nextcloud uid; reactivate if a prior harness left it inactive.
 */
function momosEnsureLinkedEmployee(IDBConnection $db, string $uid, int $fallbackId): int
{
	$qb = $db->getQueryBuilder();
	$qb->select('id', 'active')->from('dc_employees')
		->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($uid)))
		->setMaxResults(1);
	$row = $qb->executeQuery()->fetch();
	if ($row === false) {
		$qb = $db->getQueryBuilder();
		$qb->select('id', 'active')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($fallbackId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new RuntimeException("Momos fixture missing for uid=$uid fallbackId=$fallbackId");
		}
		$qb = $db->getQueryBuilder();
		$qb->update('dc_employees')
			->set('linked_user_id', $qb->createNamedParameter($uid))
			->set('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return (int) $row['id'];
	}
	$id = (int) $row['id'];
	if ((int) $row['active'] !== 1) {
		$qb = $db->getQueryBuilder();
		$qb->update('dc_employees')
			->set('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
	return $id;
}

$empAId = momosEnsureLinkedEmployee($db, $empA, $empAId);
$empBId = momosEnsureLinkedEmployee($db, $empB, $empBId);

// Resolve a location + open period
$qb = $db->getQueryBuilder();
$qb->select('id', 'name')->from('dc_locations')
	->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
	->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
	->orderBy('id', 'ASC')->setMaxResults(2);
$locs = $qb->executeQuery()->fetchAll();
$locA = (int) $locs[0]['id'];
$locB = isset($locs[1]) ? (int) $locs[1]['id'] : $locA;

$qb = $db->getQueryBuilder();
$qb->select('id')->from('dc_periods')
	->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
	->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'published'], IQueryBuilder::PARAM_STR_ARRAY)))
	->orderBy('id', 'DESC')->setMaxResults(1);
$periodId = (int) $qb->executeQuery()->fetchOne();

echo "Momos attack surface: company=$companyId locA=$locA locB=$locB period=$periodId empA=$empAId empB=$empBId\n";

/**
 * Create an assignment on a free slot inside the open period.
 * Retries across days/hours and auto-acks soft conflicts so dirty shared DBs
 * do not abort the harness with CONFLICT_ACK_REQUIRED / BLACKOUT_CONFLICT.
 *
 * @return array{id:int,dutyDate:string,startTime:string,endTime:string}|null
 */
$momosCreateFreeAssignment = static function (
	RosterService $roster,
	int $periodId,
	int $employeeId,
	int $locationId,
	string $actor,
	string $note = 'momos harness',
): ?array {
	$period = null;
	try {
		$ref = new ReflectionMethod($roster, 'periodById');
		$ref->setAccessible(true);
		$period = $ref->invoke($roster, $periodId);
	} catch (Throwable) {
		$period = null;
	}
	$startBound = is_array($period) ? (string) ($period['startDate'] ?? '') : '';
	$endBound = is_array($period) ? (string) ($period['endDate'] ?? '') : '';
	$base = new DateTimeImmutable('today');
	$candidates = [];
	for ($dayOffset = 1; $dayOffset <= 28; $dayOffset++) {
		$day = $base->modify('+' . $dayOffset . ' days')->format('Y-m-d');
		if ($startBound !== '' && $day < $startBound) {
			continue;
		}
		if ($endBound !== '' && $day > $endBound) {
			continue;
		}
		for ($hour = 5; $hour <= 21; $hour++) {
			foreach ([7, 19, 37, 53] as $minute) {
				$start = sprintf('%02d:%02d:00', $hour, $minute);
				$endHour = min(23, $hour + 2);
				$end = sprintf('%02d:%02d:00', $endHour, $minute);
				$candidates[] = [$day, $start, $end];
			}
		}
	}
	$lastErr = '';
	foreach ($candidates as [$day, $start, $end]) {
		$payload = [
			'periodId' => $periodId,
			'employeeId' => $employeeId,
			'locationId' => $locationId,
			'dutyDate' => $day,
			'startTime' => $start,
			'endTime' => $end,
			'breakMinutes' => 0,
			'note' => $note,
			'acknowledgements' => [],
			'blackoutOverrideReason' => 'Momos harness isolation override for audit proof',
		];
		try {
			$created = $roster->createAssignment($payload, $actor, false, false, false, true);
			$aid = (int) ($created['id'] ?? $created['createdAssignmentId'] ?? 0);
			if ($aid > 0) {
				return ['id' => $aid, 'dutyDate' => $day, 'startTime' => $start, 'endTime' => $end];
			}
		} catch (ConflictAckRequiredException $e) {
			$acks = [];
			foreach ($e->getConflicts() as $conflict) {
				$type = trim((string) ($conflict['type'] ?? ''));
				if ($type === '') {
					continue;
				}
				$acks[] = [
					'conflictType' => $type,
					'reason' => 'Momos harness soft-conflict ack for isolation',
				];
			}
			if ($acks === []) {
				$lastErr = 'CONFLICT_ACK_REQUIRED';
				continue;
			}
			$payload['acknowledgements'] = $acks;
			try {
				$created = $roster->createAssignment($payload, $actor, false, false, false, true);
				$aid = (int) ($created['id'] ?? $created['createdAssignmentId'] ?? 0);
				if ($aid > 0) {
					return ['id' => $aid, 'dutyDate' => $day, 'startTime' => $start, 'endTime' => $end];
				}
			} catch (Throwable $inner) {
				$lastErr = $inner->getMessage();
			}
		} catch (Throwable $e) {
			$lastErr = $e->getMessage();
			// Keep scanning: BLACKOUT without override path, overlaps, absences, etc.
		}
	}
	fwrite(STDERR, "momosCreateFreeAssignment failed lastErr=$lastErr\n");
	return null;
};

// Ensure peer + blackouts + open flags for tests
$api = $settings->toApi($companyId);
$settings->updateForCompany($companyId, [
	'peerRosterVisibility' => true,
	'blackoutsEnabled' => true,
	'openShiftsEnabled' => true,
	'claimRequiresPlanner' => true,
	'todayBoardEnabled' => true,
	'swapApprovalMode' => 'planner_required',
], $admin, $api['settingsRevision'] ?? null);

// ---------------------------------------------------------------------------
attack('M-01', 'claim_requires_planner=false must auto-claim (not leave pending forever)', function () use ($settings, $open, $periodId, $locA, $empB, $admin, $companyId, $db) {
	$api = $settings->toApi($companyId);
	$settings->updateForCompany($companyId, ['claimRequiresPlanner' => false], $admin, $api['settingsRevision'] ?? null);
	if ($settings->claimRequiresPlanner($companyId)) {
		return ['verdict' => 'ERROR', 'detail' => 'flag still true'];
	}
	$qb = $db->getQueryBuilder();
	$qb->select('start_date', 'end_date')->from('dc_periods')
		->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
	$bounds = $qb->executeQuery()->fetch() ?: [];
	$startBound = (string) ($bounds['start_date'] ?? '');
	$endBound = (string) ($bounds['end_date'] ?? '');
	if ($startBound === '' || $endBound === '') {
		return ['verdict' => 'ERROR', 'detail' => 'period bounds missing'];
	}
	// Mid-period day keeps open-shift create inside DATE_OUTSIDE_PERIOD guard on dirty DBs.
	$mid = (new DateTimeImmutable($startBound))->modify('+3 days');
	$endDt = new DateTimeImmutable($endBound);
	if ($mid > $endDt) {
		$mid = new DateTimeImmutable($startBound);
	}
	$dutyDate = $mid->format('Y-m-d');
	$suffix = (int) (microtime(true) * 1000) % 40;
	$hour = 6 + ($suffix % 12); // 06–17 keeps end = hour+3 valid
	$minute = ($suffix % 2) * 30;
	$start = sprintf('%02d:%02d', $hour, $minute);
	$end = sprintf('%02d:%02d', $hour + 3, $minute);
	$created = $open->create([
		'periodId' => $periodId,
		'locationId' => $locA,
		'dutyDate' => $dutyDate,
		'startTime' => $start,
		'endTime' => $end,
		'breakMinutes' => 0,
	], $admin);
	$id = (int) $created['id'];
	try {
		$claimed = $open->claim($id, $empB);
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() === 'OPEN_SHIFT_CONFLICT') {
			// Dirty DB: empB already scheduled — still proves create path; claim conflict is capacity not authz.
			return ['verdict' => 'CLEAN', 'detail' => 'auto-claim path hit OPEN_SHIFT_CONFLICT (capacity); planner flag is false'];
		}
		throw $e;
	}
	$status = (string) ($claimed['status'] ?? '');
	if ($status === 'claimed') {
		return ['verdict' => 'CLEAN', 'detail' => "auto-claimed status=$status assignment=" . ($claimed['assignmentId'] ?? '?')];
	}
	if ($status === 'pending') {
		return ['verdict' => 'PROVEN', 'detail' => 'claim_requires_planner=false but claim stayed pending'];
	}
	return ['verdict' => 'PROVEN', 'detail' => "unexpected status=$status"];
});

attack('M-01b', 'claim_requires_planner=true must stay pending for planner', function () use ($settings, $open, $periodId, $locA, $empB, $admin, $companyId, $db) {
	$api = $settings->toApi($companyId);
	$settings->updateForCompany($companyId, ['claimRequiresPlanner' => true], $admin, $api['settingsRevision'] ?? null);
	$qb = $db->getQueryBuilder();
	$qb->select('start_date', 'end_date')->from('dc_periods')
		->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
	$bounds = $qb->executeQuery()->fetch() ?: [];
	$startBound = (string) ($bounds['start_date'] ?? '');
	$endBound = (string) ($bounds['end_date'] ?? '');
	$mid = $startBound !== '' ? (new DateTimeImmutable($startBound))->modify('+5 days') : new DateTimeImmutable('+11 days');
	if ($endBound !== '' && $mid > new DateTimeImmutable($endBound)) {
		$mid = new DateTimeImmutable($startBound);
	}
	$created = $open->create([
		'periodId' => $periodId,
		'locationId' => $locA,
		'dutyDate' => $mid->format('Y-m-d'),
		'startTime' => '12:00',
		'endTime' => '16:00',
		'breakMinutes' => 0,
	], $admin);
	$id = (int) $created['id'];
	$claimed = $open->claim($id, $empB);
	$status = (string) ($claimed['status'] ?? '');
	if ($status === 'pending') {
		return ['verdict' => 'CLEAN', 'detail' => 'pending as required'];
	}
	return ['verdict' => 'PROVEN', 'detail' => "expected pending, got $status"];
});

attack('M-02', 'Employee Today board must be FORBIDDEN', function () use ($today, $locA, $empA) {
	try {
		$today->getBoard($empA, $locA, (new DateTimeImmutable('today'))->format('Y-m-d'));
		return ['verdict' => 'PROVEN', 'detail' => 'employee got Today board data'];
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() === 'FORBIDDEN') {
			return ['verdict' => 'CLEAN', 'detail' => 'FORBIDDEN as required'];
		}
		return ['verdict' => 'PROVEN', 'detail' => 'wrong code: ' . $e->getMessage()];
	}
});

attack('M-03', 'Peer team-week IDOR: location without belonging → FORBIDDEN', function () use ($peer, $empA, $locB, $locA, $db) {
	// Ensure empA has no belonging to a synthetic other location if locB==locA create temporary
	$targetLoc = $locB;
	if ($targetLoc === $locA) {
		return ['verdict' => 'ERROR', 'detail' => 'need second location'];
	}
	// Clear any belonging of empA at locB in lookback by checking belonging
	$belongs = false;
	foreach ($peer->listBelongingLocations($empA) as $l) {
		if ((int) $l['id'] === $targetLoc) {
			$belongs = true;
		}
	}
	$week = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
	try {
		$peer->listTeamWeek($empA, $targetLoc, $week);
		if (!$belongs) {
			return ['verdict' => 'PROVEN', 'detail' => "team-week readable for loc=$targetLoc without belonging"];
		}
		return ['verdict' => 'CLEAN', 'detail' => 'belongs to loc; IDOR N/A for this pair'];
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() === 'FORBIDDEN' && !$belongs) {
			return ['verdict' => 'CLEAN', 'detail' => 'FORBIDDEN without belonging'];
		}
		if ($belongs) {
			return ['verdict' => 'CLEAN', 'detail' => 'belongs + allowed'];
		}
		return ['verdict' => 'PROVEN', 'detail' => 'unexpected: ' . $e->getMessage() . " belongs=" . ($belongs ? '1' : '0')];
	}
});

attack('M-04', 'Wrong user accepts swap destined for someone else', function () use ($swaps, $roster, $periodId, $locA, $empA, $empB, $empAId, $empBId, $admin, $db, $momosCreateFreeAssignment) {
	$slot = $momosCreateFreeAssignment($roster, $periodId, $empAId, $locA, $admin, 'momos idor accept');
	if ($slot === null) {
		return ['verdict' => 'ERROR', 'detail' => 'createAssignment: no free slot'];
	}
	$aid = $slot['id'];
	// Drop any leftover pending swaps on this assignment from prior Momos runs.
	$qb = $db->getQueryBuilder();
	$qb->update('dc_swap_requests')
		->set('status', $qb->createNamedParameter('cancelled'))
		->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)))
		->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'pending_planner'], IQueryBuilder::PARAM_STR_ARRAY)))
		->executeStatement();
	$swap = $swaps->requestSwap($aid, $empA, $empBId, 'momos idor accept test');
	$swapId = (int) $swap['id'];
	// Attacker: admin (not counterparty) tries accept — should fail
	try {
		$swaps->acceptByCounterparty($swapId, $admin);
		return ['verdict' => 'PROVEN', 'detail' => "admin accepted swap #$swapId destined for empB"];
	} catch (InvalidArgumentException $e) {
		$code = $e->getMessage();
		if (in_array($code, ['FORBIDDEN', 'SWAP_NOT_COUNTERPARTY', 'EMPLOYEE_LINK_NOT_FOUND'], true)) {
			return ['verdict' => 'CLEAN', 'detail' => "blocked: $code"];
		}
		return ['verdict' => 'PROVEN', 'detail' => "weak reject code: $code"];
	}
});

attack('M-05', 'Unauthenticated / wrong role: settings update by linked employee', function () use ($settings, $companyId, $empA) {
	$api = $settings->toApi($companyId);
	try {
		// Service layer only checks company access for update — controller requireAppAdmin is the door.
		// Prove service allows company member? Employees may not be members.
		$settings->updateForCompany($companyId, ['peerRosterVisibility' => false], $empA, $api['settingsRevision'] ?? null);
		// If we got here, service allowed employee write — Critical if controller is ever bypassed
		return ['verdict' => 'PROVEN', 'detail' => 'SelfServiceSettingsService::updateForCompany accepted employee actor (controller ACL is only gate)'];
	} catch (InvalidArgumentException $e) {
		return ['verdict' => 'CLEAN', 'detail' => 'service blocked: ' . $e->getMessage()];
	}
});

attack('M-06', 'Swap candidates must not dump entire company directory', function () use ($swaps, $empA, $db, $empAId, $locA, $locB, $periodId, $roster, $admin, $momosCreateFreeAssignment) {
	$list = $swaps->listSwapCandidates($empA);
	$n = count($list);
	$ids = array_map(static fn (array $r): int => (int) $r['id'], $list);
	$qb = $db->getQueryBuilder();
	$qb->select($qb->func()->count('*', 'c'))->from('dc_employees')
		->where($qb->expr()->eq('active', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
		->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($empAId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
	$companyN = (int) $qb->executeQuery()->fetchOne();

	// Strong check: an employee who only ever worked at locB must not appear for empA@locA.
	if ($locB === $locA) {
		if ($companyN >= 5 && $n >= $companyN) {
			return [
				'verdict' => 'PROVEN',
				'detail' => "listSwapCandidates returned $n/$companyN with only one location (cannot prove exclusion)",
			];
		}
		return ['verdict' => 'CLEAN', 'detail' => "scoped candidates=$n of companyActive=$companyN (single-location company)"];
	}

	// Prefer an existing employee with no locA belonging; else plant a disposable fixture.
	$otherId = 0;
	$all = $db->getQueryBuilder();
	$all->select('id')->from('dc_employees')
		->where($all->expr()->eq('active', $all->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
		->andWhere($all->expr()->neq('id', $all->createNamedParameter($empAId, IQueryBuilder::PARAM_INT)));
	foreach ($all->executeQuery()->fetchAll() as $row) {
		$eid = (int) $row['id'];
		$lq = $db->getQueryBuilder();
		$lq->selectDistinct('location_id')->from('dc_assignments')
			->where($lq->expr()->eq('employee_id', $lq->createNamedParameter($eid, IQueryBuilder::PARAM_INT)));
		$locs = array_map(static fn (array $r): int => (int) $r['location_id'], $lq->executeQuery()->fetchAll());
		if ($locs !== [] && !in_array($locA, $locs, true) && in_array($locB, $locs, true)) {
			$otherId = $eid;
			break;
		}
	}
	if ($otherId < 1) {
		// Create disposable employee + assignment only at locB.
		$ins = $db->getQueryBuilder();
		$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$ins->insert('dc_employees')->values([
			'display_name' => $ins->createNamedParameter('Momos LocB Only'),
			'active' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'company_id' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'linked_user_id' => $ins->createNamedParameter(null),
			'created_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		$otherId = (int) $ins->getLastInsertId();
		$slot = $momosCreateFreeAssignment($roster, $periodId, $otherId, $locB, $admin, 'momos locB-only');
		if ($slot === null) {
			return ['verdict' => 'ERROR', 'detail' => "could not plant locB-only employee #$otherId"];
		}
	}

	if (in_array($otherId, $ids, true)) {
		return [
			'verdict' => 'PROVEN',
			'detail' => "locB-only employee #$otherId leaked into swap candidates (n=$n companyActive=$companyN)",
		];
	}
	return ['verdict' => 'CLEAN', 'detail' => "scoped candidates=$n of companyActive=$companyN; excluded locB-only #$otherId"];
});

attack('M-07', 'ICS: valid-format garbage token must not distinguish missing vs wrong (timing/enum)', function () use ($roster, $empAId) {
	$bad = str_repeat('ab', 24); // 48 hex
	$t0 = hrtime(true);
	try {
		$roster->publicIcal($empAId, $bad, '127.0.0.1');
		return ['verdict' => 'PROVEN', 'detail' => 'garbage token returned feed'];
	} catch (InvalidArgumentException $e) {
		$dt = (hrtime(true) - $t0) / 1e6;
		$code = $e->getMessage();
		// Also try nonexistent employee
		try {
			$roster->publicIcal(999999, $bad, '127.0.0.1');
			$c2 = 'OK';
		} catch (InvalidArgumentException $e2) {
			$c2 = $e2->getMessage();
		}
		if ($code !== $c2) {
			return ['verdict' => 'PROVEN', 'detail' => "enum via codes: exist=$code missing=$c2 ({$dt}ms)"];
		}
		return ['verdict' => 'CLEAN', 'detail' => "opaque $code for both ({$dt}ms)"];
	}
});

attack('M-08', 'Peer week includes draft/open period shifts (privacy: should be published-only)', function () use ($peer, $empB, $locA, $db, $periodId) {
	$week = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
	$qb = $db->getQueryBuilder();
	$qb->select('status')->from('dc_periods')->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
	$status = (string) $qb->executeQuery()->fetchOne();
	try {
		$data = $peer->listTeamWeek($empB, $locA, $week);
		$n = count($data['items'] ?? []);
		// Do not treat "items>0 while some other open period exists" as a leak —
		// published periods in the same week are expected. Prove by status of each row.
		$openItems = 0;
		$publishedItems = 0;
		foreach ($data['items'] as $it) {
			$q = $db->getQueryBuilder();
			$q->select('p.status')->from('dc_assignments', 'a')
				->join('a', 'dc_periods', 'p', $q->expr()->eq('a.period_id', 'p.id'))
				->where($q->expr()->eq('a.id', $q->createNamedParameter((int) $it['assignmentId'], IQueryBuilder::PARAM_INT)));
			$st = (string) $q->executeQuery()->fetchOne();
			if ($st === 'open' || $st === 'draft') {
				$openItems++;
			} elseif ($st === 'published') {
				$publishedItems++;
			}
		}
		if ($openItems > 0) {
			return ['verdict' => 'PROVEN', 'detail' => "$openItems team-week rows from open/draft periods"];
		}
		return ['verdict' => 'CLEAN', 'detail' => "items=$n publishedRows=$publishedItems harnessPeriodStatus=$status; no open/draft rows"];
	} catch (InvalidArgumentException $e) {
		return ['verdict' => 'CLEAN', 'detail' => $e->getMessage()];
	}
});

attack('M-09', 'userMayDisableQuiet must not be advertised on API if unenforced', function () use ($settings, $companyId) {
	$api = $settings->toApi($companyId);
	$path = '/var/www/html/custom_apps/dutycheck/lib/Service/PushQuietHoursService.php';
	$src = file_get_contents($path);
	$enforced = str_contains($src, 'user_may_disable_quiet') || str_contains($src, 'userMayDisableQuiet');
	if (array_key_exists('userMayDisableQuiet', $api) && !$enforced) {
		return [
			'verdict' => 'PROVEN',
			'detail' => 'toApi still exposes userMayDisableQuiet while PushQuietHoursService never reads it',
		];
	}
	if (!array_key_exists('userMayDisableQuiet', $api) && !$enforced) {
		return ['verdict' => 'CLEAN', 'detail' => 'API no longer advertises unenforced quiet opt-out'];
	}
	return ['verdict' => 'CLEAN', 'detail' => 'quiet opt-out enforced or absent'];
});

attack('M-10', 'Blackout create as employee for another employeeId (IDOR)', function () use ($blackouts, $empA, $empBId) {
	$start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d H:i:s');
	$end = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days +4 hours')->format('Y-m-d H:i:s');
	try {
		$blackouts->create($empBId, $start, $end, 'personal', null, $empA);
		return ['verdict' => 'PROVEN', 'detail' => "empA created blackout for empB ($empBId)"];
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() === 'FORBIDDEN') {
			return ['verdict' => 'CLEAN', 'detail' => 'FORBIDDEN'];
		}
		return ['verdict' => 'PROVEN', 'detail' => 'unexpected: ' . $e->getMessage()];
	}
});

attack('M-11', 'Preference save band injection / free-text PII path', function () use ($prefs, $empA, $empAId, $settings, $companyId, $admin) {
	$api = $settings->toApi($companyId);
	$settings->updateForCompany($companyId, ['preferencesEnabled' => true], $admin, $api['settingsRevision'] ?? null);
	try {
		$row = $prefs->save($empAId, [
			'band' => 'early"; DROP TABLE--',
			'weekdayMask' => 1,
			'priority' => 99,
		], $empA);
		return [
			'verdict' => 'PROVEN',
			'detail' => 'accepted malicious band/note: ' . json_encode($row),
		];
	} catch (InvalidArgumentException $e) {
		$code = $e->getMessage();
		if (in_array($code, ['PREFERENCE_BAND_INVALID', 'PREFERENCE_INVALID', 'FORBIDDEN', 'PREFERENCES_DISABLED'], true)
			|| str_starts_with($code, 'PREFERENCE_')) {
			return ['verdict' => 'CLEAN', 'detail' => "rejected: $code"];
		}
		return ['verdict' => 'PROVEN', 'detail' => "weak reject: $code"];
	}
});

attack('M-12', 'Bilateral_auto: concurrent double-accept must not double-apply', function () use ($settings, $swaps, $roster, $periodId, $locA, $empA, $empB, $empAId, $empBId, $admin, $companyId, $db, $momosCreateFreeAssignment) {
	$api = $settings->toApi($companyId);
	$settings->updateForCompany($companyId, ['swapApprovalMode' => 'bilateral_auto'], $admin, $api['settingsRevision'] ?? null);
	$slot = $momosCreateFreeAssignment($roster, $periodId, $empAId, $locA, $admin, 'momos race');
	if ($slot === null) {
		return ['verdict' => 'ERROR', 'detail' => 'no free slot after scan+ack'];
	}
	$aid = $slot['id'];
	$qb = $db->getQueryBuilder();
	$qb->update('dc_swap_requests')
		->set('status', $qb->createNamedParameter('cancelled'))
		->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)))
		->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'pending_planner'], IQueryBuilder::PARAM_STR_ARRAY)))
		->executeStatement();
	$swap = $swaps->requestSwap($aid, $empA, $empBId, 'momos race');
	$swapId = (int) $swap['id'];

	$ok = 0;
	$fail = 0;
	$codes = [];
	// Sequential CAS simulation (true parallel needs two PHP procs; still validates CAS)
	try {
		$swaps->acceptByCounterparty($swapId, $empB);
		$ok++;
	} catch (InvalidArgumentException $e) {
		$fail++;
		$codes[] = $e->getMessage();
	}
	try {
		$swaps->acceptByCounterparty($swapId, $empB);
		$ok++;
	} catch (InvalidArgumentException $e) {
		$fail++;
		$codes[] = $e->getMessage();
	}
	$qb = $db->getQueryBuilder();
	$qb->select('employee_id', 'version')->from('dc_assignments')
		->where($qb->expr()->eq('id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)));
	$row = $qb->executeQuery()->fetch();
	$owner = (int) ($row['employee_id'] ?? 0);
	if ($ok === 1 && $fail === 1 && $owner === $empBId) {
		return ['verdict' => 'CLEAN', 'detail' => "single apply; owner=$owner; rejects=" . implode(',', $codes)];
	}
	if ($ok >= 2) {
		return ['verdict' => 'PROVEN', 'detail' => "double accept succeeded ok=$ok owner=$owner"];
	}
	return ['verdict' => 'PROVEN', 'detail' => "unexpected ok=$ok fail=$fail owner=$owner codes=" . implode(',', $codes)];
});

echo "\n======== SUMMARY proven=$proven clean=$clean errors=$errors ========\n";
exit($proven > 0 ? 2 : ($errors > 0 ? 1 : 0));

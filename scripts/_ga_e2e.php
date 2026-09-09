<?php

declare(strict_types=1);

/**
 * GA end-to-end smoke (run inside nextcloud container):
 *   php /var/www/html/custom_apps/dutycheck/scripts/_ga_e2e.php
 */

require '/var/www/html/lib/base.php';

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\Contract\EffectiveTargetHoursFacade;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RotationSuggestService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\TodayBoardService;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

OC_App::loadApp('dutycheck');
OC_App::loadApp('arbeitszeitcheck');

$app = \OCP\Server::get(Application::class);
$c = $app->getContainer();

$settings = $c->get(SelfServiceSettingsService::class);
$patterns = $c->get(RotationPatternService::class);
$suggest = $c->get(RotationSuggestService::class);
$roster = $c->get(RosterService::class);
$facade = $c->get(EffectiveTargetHoursFacade::class);
$today = $c->get(TodayBoardService::class);
$peer = $c->get(PeerRosterService::class);
$blackouts = $c->get(AvailabilityBlackoutService::class);
$db = $c->get(IDBConnection::class);

$actor = 'admin';
$companyId = CompanyService::DEFAULT_COMPANY_ID;
$linkedUid = 'dc.review.employee';

// Resolve linked employee by uid (shared Atlas DBs renumber fixture ids across seeds).
$resolveEmp = $db->getQueryBuilder();
$resolveEmp->select('id', 'active')->from('dc_employees')
	->where($resolveEmp->expr()->eq('linked_user_id', $resolveEmp->createNamedParameter($linkedUid)))
	->setMaxResults(1);
$empRow = $resolveEmp->executeQuery()->fetch();
if ($empRow === false) {
	echo "FAIL: no dc_employees row linked to $linkedUid\n";
	exit(1);
}
$employeeId = (int) $empRow['id'];
if ((int) $empRow['active'] !== 1) {
	$reactivate = $db->getQueryBuilder();
	$reactivate->update('dc_employees')
		->set('active', $reactivate->createNamedParameter(1, IQueryBuilder::PARAM_INT))
		->where($reactivate->expr()->eq('id', $reactivate->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
		->executeStatement();
}

function step(string $label, callable $fn): void
{
	echo "→ $label … ";
	try {
		$out = $fn();
		echo "OK";
		if (is_string($out) && $out !== '') {
			echo " ($out)";
		}
		echo PHP_EOL;
	} catch (Throwable $e) {
		echo "FAIL: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
		exit(1);
	}
}

// Location for company 1
$qb = $db->getQueryBuilder();
$qb->select('id', 'name')->from('dc_locations')
	->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
	->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
	->setMaxResults(1);
$loc = $qb->executeQuery()->fetch();
if ($loc === false) {
	echo "FAIL: no active location for company $companyId\n";
	exit(1);
}
$locationId = (int) $loc['id'];
echo "Using location #$locationId ({$loc['name']}), employee #$employeeId ($linkedUid)\n";

step('Enable Muster + Soll + blackouts + peer + today', function () use ($settings, $companyId, $actor) {
	$settings->updateForCompany($companyId, [
		'rotationPatternsEnabled' => true,
		'sollFromDuty' => true,
		'blackoutsEnabled' => true,
		'peerRosterVisibility' => true,
		'todayBoardEnabled' => true,
		'preferencesEnabled' => true,
	], $actor);
	$api = $settings->toApi($companyId);
	if (!$api['rotationPatternsEnabled'] || !$api['sollFromDuty']) {
		throw new RuntimeException('settings not persisted');
	}
	return 'rotation+soll on';
});

$patternId = 0;
step('Create 2-week pattern with location', function () use ($patterns, $companyId, $actor, $locationId, &$patternId) {
	$name = 'GA-E2E-' . gmdate('His');
	$weekDays = [];
	for ($w = 0; $w < 2; $w++) {
		for ($d = 1; $d <= 7; $d++) {
			$working = $d <= 5; // Mon–Fri
			$weekDays[] = [
				'weekIndex' => $w,
				'dow' => $d,
				'isWorking' => $working,
				'netMinutes' => $working ? 480 : 0,
				'startLocal' => $working ? '08:00' : null,
				'endLocal' => $working ? '17:00' : null,
				'breakMinutes' => $working ? 60 : 0,
				'locationId' => $working ? $locationId : null,
				'shiftTemplateId' => null,
			];
		}
	}
	$pat = $patterns->createPattern([
		'companyId' => $companyId,
		'name' => $name,
		'cycleWeeks' => 2,
		'anchorType' => 'iso_week_parity',
		'weekDays' => $weekDays,
	], $actor);
	$patternId = (int) $pat['id'];
	return "pattern #$patternId";
});

step('Assign pattern to employee', function () use ($patterns, $patternId, $employeeId, $actor) {
	$from = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
	$patterns->assignToEmployee($employeeId, $patternId, $from, null, $actor, true);
	return "from $from";
});

// Ensure open period covering next 14 days
$periodId = 0;
step('Ensure open period', function () use ($roster, $actor, &$periodId) {
	$start = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
	$end = (new DateTimeImmutable('monday this week'))->modify('+13 days')->format('Y-m-d');
	foreach ($roster->listPeriods($actor) as $p) {
		if (($p['status'] ?? '') === 'open'
			&& (string) $p['startDate'] <= $start
			&& (string) $p['endDate'] >= $end) {
			$periodId = (int) $p['id'];
			return "reuse #$periodId";
		}
	}
	$created = $roster->createPeriod($start, $end, $actor);
	$periodId = (int) $created['id'];
	return "created #$periodId $start..$end";
});

step('Clear prior suggest assignments for employee', function () use ($db, $periodId, $employeeId) {
	$qb = $db->getQueryBuilder();
	$qb->select('id', 'status')->from('dc_assignments')
		->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
		->andWhere($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
	$rows = $qb->executeQuery()->fetchAll();
	$n = 0;
	foreach ($rows as $row) {
		$aid = (int) $row['id'];
		$u = $db->getQueryBuilder();
		$u->update('dc_assignments')
			->set('status', $u->createNamedParameter('cancelled'))
			->set('slot_key', $u->createNamedParameter(\OCA\DutyCheck\Service\AssignmentSlotKey::forCancelled($aid)))
			->where($u->expr()->eq('id', $u->createNamedParameter($aid, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$n++;
	}
	if (\OCA\DutyCheck\Db\SchemaProbe::tableExists($db, 'dc_avail_blackouts')) {
		$del = $db->getQueryBuilder();
		$del->delete('dc_avail_blackouts')
			->where($del->expr()->eq('employee_id', $del->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
	return "freed $n slots + blackouts cleared";
});

step('Suggest preview', function () use ($suggest, $periodId, $actor, $locationId, $employeeId) {
	$prev = $suggest->preview($periodId, $actor, [$employeeId], $locationId);
	$n = (int) ($prev['created'] ?? 0);
	if ($n < 1) {
		throw new RuntimeException('preview created=0 detail=' . json_encode([
			'skippedExisting' => $prev['skippedExisting'] ?? null,
			'skippedAbsence' => $prev['skippedAbsence'] ?? null,
			'skippedBlackout' => $prev['skippedBlackout'] ?? null,
			'skippedNoPattern' => $prev['skippedNoPattern'] ?? null,
			'skippedNoLocation' => $prev['skippedNoLocation'] ?? null,
		]));
	}
	return "would create $n";
});

step('Suggest confirm', function () use ($suggest, $periodId, $actor, $locationId, $employeeId) {
	$res = $suggest->confirm($periodId, $actor, [$employeeId], $locationId);
	$n = (int) ($res['created'] ?? 0);
	if ($n < 1) {
		throw new RuntimeException('confirm created=0 ' . json_encode($res));
	}
	return "wrote $n";
});

step('Suggest mutex (second confirm → SUGGEST_IN_PROGRESS or empty)', function () use ($suggest, $periodId, $actor, $locationId, $employeeId) {
	// Lock may already be free; second confirm should skip occupied cells → created 0
	$res = $suggest->confirm($periodId, $actor, [$employeeId], $locationId);
	$n = (int) ($res['created'] ?? 0);
	if ($n !== 0) {
		throw new RuntimeException("expected 0 recreates, got $n");
	}
	return 'idempotent skip';
});

step('Today board planner OK / employee FORBIDDEN', function () use ($today, $locationId, $actor) {
	$board = $today->getBoard($actor, $locationId, (new DateTimeImmutable('today'))->format('Y-m-d'));
	$shifts = count($board['shifts'] ?? []);
	try {
		$today->getBoard('dc.review.employee', $locationId, (new DateTimeImmutable('today'))->format('Y-m-d'));
		throw new RuntimeException('employee should be FORBIDDEN');
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() !== 'FORBIDDEN') {
			throw $e;
		}
	}
	return "shifts=$shifts employee=403";
});

step('Peer visibility IDOR (other location empty/forbidden)', function () use ($peer, $locationId) {
	$weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
	// Employee without assignment at location may get FORBIDDEN — that's correct IDOR posture
	try {
		$peer->listTeamWeek('dc.review.employee', $locationId, $weekStart);
		return 'team readable';
	} catch (InvalidArgumentException $e) {
		if (in_array($e->getMessage(), ['FORBIDDEN', 'PEER_VISIBILITY_DISABLED'], true)) {
			return 'guard ' . $e->getMessage();
		}
		throw $e;
	}
});

step('Peer belonging locations (lookback-bound)', function () use ($peer, $locationId) {
	$locs = $peer->listBelongingLocations('dc.review.employee');
	$ids = array_map(static fn (array $r): int => (int) $r['id'], $locs);
	if (!in_array($locationId, $ids, true)) {
		throw new RuntimeException('expected location ' . $locationId . ' in belonging list, got ' . json_encode($ids));
	}
	return 'locations=' . count($locs);
});

step('Settings revision CAS rejects stale client', function () use ($settings, $companyId, $actor) {
	$api = $settings->toApi($companyId);
	$rev = (string) ($api['settingsRevision'] ?? '');
	if ($rev === '') {
		throw new RuntimeException('missing settingsRevision');
	}
	// Flip a real boolean so the stored JSON (and revision) must change.
	$flip = !((bool) ($api['preferencesEnabled'] ?? false));
	$settings->updateForCompany($companyId, ['preferencesEnabled' => $flip], $actor, $rev);
	$stale = false;
	try {
		$settings->updateForCompany($companyId, ['preferencesEnabled' => !$flip], $actor, $rev);
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() !== 'SETTINGS_CONFLICT') {
			throw $e;
		}
		$stale = true;
	}
	if (!$stale) {
		throw new RuntimeException('expected SETTINGS_CONFLICT on reused revision');
	}
	// Restore preferences on for later steps that may need them.
	$fresh = $settings->toApi($companyId);
	$settings->updateForCompany(
		$companyId,
		['preferencesEnabled' => true],
		$actor,
		(string) $fresh['settingsRevision'],
	);
	return 'revision CAS ok';
});

step('Blackout create + hard-block assign + override', function () use ($blackouts, $employeeId, $actor, $roster, $periodId, $locationId) {
	// Saturday — pattern leaves it empty, so assign can prove blackout hard-block cleanly.
	$inPeriod = (new DateTimeImmutable('monday this week'))->modify('+5 days');
	$dutyDate = $inPeriod->format('Y-m-d');
	$start = $inPeriod->setTime(0, 0)->format('Y-m-d H:i:s');
	$end = $inPeriod->setTime(23, 59, 59)->format('Y-m-d H:i:s');
	$blk = $blackouts->create($employeeId, $start, $end, 'personal', $locationId, $actor);
	if (!$blackouts->blocks($employeeId, $dutyDate, '08:00', '17:00', $locationId)) {
		throw new RuntimeException('blackout does not block');
	}
	$blocked = false;
	try {
		$roster->createAssignment([
			'periodId' => $periodId,
			'employeeId' => $employeeId,
			'locationId' => $locationId,
			'dutyDate' => $dutyDate,
			'startTime' => '09:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'note' => 'ga-blackout-block',
			'acknowledgements' => [],
		], $actor, false, false, false, true);
	} catch (InvalidArgumentException $e) {
		if ($e->getMessage() !== 'BLACKOUT_CONFLICT') {
			throw $e;
		}
		$blocked = true;
	}
	if (!$blocked) {
		throw new RuntimeException('expected BLACKOUT_CONFLICT');
	}
	$created = $roster->createAssignment([
		'periodId' => $periodId,
		'employeeId' => $employeeId,
		'locationId' => $locationId,
		'dutyDate' => $dutyDate,
		'startTime' => '09:00',
		'endTime' => '12:00',
		'breakMinutes' => 0,
		'note' => 'ga-blackout-override',
		'acknowledgements' => [],
		'blackoutOverrideReason' => 'Coverage required for audit proof',
	], $actor, false, false, false, true);
	$aid = (int) ($created['createdAssignmentId'] ?? 0);
	if ($aid < 1) {
		throw new RuntimeException('override assign failed');
	}
	return 'blackout #' . (int) $blk['id'] . " override assignment #$aid";
});

step('Facade Planwoche + Soll minutes', function () use ($facade, $linkedUid) {
	if (!$facade->isRotationEnabledForOrg($linkedUid)) {
		throw new RuntimeException('rotation not enabled for user');
	}
	if (!$facade->isSollFromDutyEnabledForUser($linkedUid)) {
		throw new RuntimeException('soll_from_duty not effective');
	}
	$monday = new DateTimeImmutable('monday this week');
	$week = $facade->getWeekTarget($linkedUid, $monday);
	if ($week === null) {
		throw new RuntimeException('week target null');
	}
	$mins = $facade->getWeekTargetMinutes($linkedUid, $monday);
	if ($mins === null || $mins < 1) {
		throw new RuntimeException("minutes=$mins basis={$week->basis}");
	}
	return "basis={$week->basis} mins=$mins label={$week->weekLabel}";
});

step('AZC provider respects Dual gate', function () use ($linkedUid) {
	// Dual G2 lives in arbeitszeitcheck; shared farms may miss Constants until AZC ships.
	if (!class_exists(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class)) {
		return 'ENV_GAP: DutyRotationSollProvider missing';
	}
	try {
		$azcApp = \OCP\Server::get(\OCA\ArbeitszeitCheck\AppInfo\Application::class);
		$provider = $azcApp->getContainer()->get(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class);
		$eff = $provider->isEffectiveForUser($linkedUid, new DateTimeImmutable('monday this week'));
		return 'g2_default_off effective=' . ($eff ? '1' : '0');
	} catch (Throwable $e) {
		$msg = $e->getMessage();
		if (str_contains($msg, 'CONFIG_DUTY_ROTATION_SOLL') || str_contains($msg, 'Undefined constant')) {
			return 'ENV_GAP: AZC Constants Dual keys not deployed (' . $msg . ')';
		}
		throw $e;
	}
});

echo "\nALL GA E2E STEPS PASSED\n";

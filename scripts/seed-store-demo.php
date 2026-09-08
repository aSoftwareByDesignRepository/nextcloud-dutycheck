#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * DutyCheck NC App Store demo seed (DE gallery).
 *
 * Purges Atlas/Play junk, densifies RheinMain Leitstelle roster for Sep+Nov,
 * seeds absences, and keeps catalog chrome DE-only.
 *
 * Run:
 *   docker exec -u www-data nextcloud-app php /var/www/html/custom_apps/dutycheck/scripts/seed-store-demo.php
 *   docker exec -u www-data nextcloud-app php /var/www/html/custom_apps/dutycheck/scripts/seed-store-demo.php --user=dc_atlas_planner
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(1);
}

$candidates = [
	'/var/www/html/lib/base.php',
	__DIR__ . '/../../../lib/base.php',
	__DIR__ . '/../../lib/base.php',
];
$base = null;
foreach ($candidates as $c) {
	if (is_file($c)) {
		$base = $c;
		break;
	}
}
if ($base === null) {
	fwrite(STDERR, "Nextcloud lib/base.php not found\n");
	exit(1);
}
require_once $base;

use OCA\DutyCheck\Service\AssignmentSlotKey;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

$actor = 'dc_atlas_planner';
foreach (array_slice($argv, 1) as $arg) {
	if (str_starts_with($arg, '--user=')) {
		$actor = substr($arg, 7);
	}
}

$um = Server::get(IUserManager::class);
if (!$um->userExists($actor)) {
	fwrite(STDERR, "User not found: {$actor}\n");
	exit(1);
}
$user = $um->get($actor);
Server::get(IUserSession::class)->setUser($user);

/** @var IDBConnection $db */
$db = Server::get(IDBConnection::class);
/** @var RosterService $roster */
$roster = Server::get(RosterService::class);

$tz = new DateTimeZone('Europe/Berlin');
$today = new DateTimeImmutable('now', $tz);
$ymd = static function (int $offsetDays) use ($today): string {
	return $today->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d');
};

echo "DutyCheck store seed for {$actor}…\n";

echo "purge Atlas/Play junk…\n";

// Deactivate junk catalog rows (keep FKs intact).
$db->executeStatement(
	"UPDATE `oc_dc_employees` SET `active` = 0
	 WHERE `display_name` LIKE 'atlas%'
	    OR `display_name` LIKE 'Atlas%'
	    OR `display_name` LIKE 'Play %'
	    OR `linked_user_id` IN ('e2e_employee','dc.review.employee')"
);
$db->executeStatement(
	"UPDATE `oc_dc_locations` SET `active` = 0
	 WHERE `name` LIKE 'atlas%'
	    OR `name` LIKE 'Atlas%'
	    OR `name` LIKE 'Play %'"
);

// Drop assignments tied to inactive junk people / locations.
$db->executeStatement(
	"DELETE a FROM `oc_dc_assignments` a
	 INNER JOIN `oc_dc_employees` e ON e.id = a.employee_id
	 WHERE e.active = 0"
);
$db->executeStatement(
	"DELETE a FROM `oc_dc_assignments` a
	 INNER JOIN `oc_dc_locations` l ON l.id = a.location_id
	 WHERE l.active = 0"
);

// Far-future / ancient junk periods + their children (keep May–Dec 2026 for densify).
$db->executeStatement(
	"DELETE a FROM `oc_dc_assignments` a
	 INNER JOIN `oc_dc_periods` p ON p.id = a.period_id
	 WHERE p.start_date < '2026-05-01' OR p.start_date >= '2027-01-01'
	    OR p.start_date LIKE '203%' OR p.start_date LIKE '208%' OR p.start_date LIKE '210%'"
);
$db->executeStatement(
	"DELETE FROM `oc_dc_period_audit_log`
	 WHERE `period_id` IN (
	   SELECT id FROM (
	     SELECT id FROM `oc_dc_periods`
	     WHERE start_date < '2026-05-01' OR start_date >= '2027-01-01'
	        OR start_date LIKE '203%' OR start_date LIKE '208%' OR start_date LIKE '210%'
	   ) t
	 )"
);
$db->executeStatement(
	"DELETE FROM `oc_dc_roster_snapshots`
	 WHERE `period_id` IN (
	   SELECT id FROM (
	     SELECT id FROM `oc_dc_periods`
	     WHERE start_date < '2026-05-01' OR start_date >= '2027-01-01'
	        OR start_date LIKE '203%' OR start_date LIKE '208%' OR start_date LIKE '210%'
	   ) t
	 )"
);
$db->executeStatement(
	"DELETE FROM `oc_dc_open_shifts`
	 WHERE `period_id` IN (
	   SELECT id FROM (
	     SELECT id FROM `oc_dc_periods`
	     WHERE start_date < '2026-05-01' OR start_date >= '2027-01-01'
	        OR start_date LIKE '203%' OR start_date LIKE '208%' OR start_date LIKE '210%'
	   ) t
	 )"
);
$db->executeStatement(
	"DELETE FROM `oc_dc_periods`
	 WHERE start_date < '2026-05-01' OR start_date >= '2027-01-01'
	    OR start_date LIKE '203%' OR start_date LIKE '208%' OR start_date LIKE '210%'"
);

// Closed Sep fragment that was Play-junk heavy.
$db->executeStatement(
	"DELETE a FROM `oc_dc_assignments` a
	 INNER JOIN `oc_dc_periods` p ON p.id = a.period_id
	 WHERE p.start_date = '2026-09-07' AND p.end_date IN ('2026-09-20','2026-09-28')"
);
$db->executeStatement(
	"DELETE FROM `oc_dc_periods`
	 WHERE start_date = '2026-09-07' AND end_date IN ('2026-09-20','2026-09-28')"
);

$db->executeStatement("UPDATE `oc_dc_companies` SET `name` = 'RheinMain Leitstelle GmbH' WHERE `id` = 1");

echo "ensure DE catalog…\n";

$employeeSpecs = [
	'Anna Weber',
	'Ben Richter',
	'Clara Hofmann',
	'David Keller',
	'Elena Braun',
	'Felix Neumann',
	'Greta Lorenz',
	'Jonas Vogel',
];
$locationSpecs = [
	['Zentrale', 'Europe/Berlin'],
	['Nordwache', 'Europe/Berlin'],
	['Klinik Süd', 'Europe/Berlin'],
	['Flughafen Ost', 'Europe/Berlin'],
];

$empByName = [];
foreach ($roster->listEmployeeCatalog($actor) as $row) {
	$empByName[(string) $row['displayName']] = (int) $row['id'];
	if (!(bool) ($row['active'] ?? true)
		&& in_array((string) $row['displayName'], $employeeSpecs, true)) {
		$roster->updateEmployee((int) $row['id'], [
			'displayName' => (string) $row['displayName'],
			'active' => 1,
		], $actor);
		$empByName[(string) $row['displayName']] = (int) $row['id'];
	}
}
foreach ($employeeSpecs as $name) {
	if (!isset($empByName[$name])) {
		$catalog = $roster->createEmployee(['displayName' => $name, 'active' => 1], $actor);
		foreach ($catalog as $row) {
			$empByName[(string) $row['displayName']] = (int) $row['id'];
		}
		echo "  · employee {$name}\n";
	}
}

$locByName = [];
foreach ($roster->listLocationCatalog($actor) as $row) {
	$locByName[(string) $row['name']] = (int) $row['id'];
	if (!(bool) ($row['active'] ?? true)) {
		foreach ($locationSpecs as [$want]) {
			if ((string) $row['name'] === $want) {
				$roster->updateLocation((int) $row['id'], [
					'name' => $want,
					'timezone' => 'Europe/Berlin',
					'active' => 1,
				], $actor);
				$locByName[$want] = (int) $row['id'];
			}
		}
	}
}
foreach ($locationSpecs as [$name, $timezone]) {
	if (!isset($locByName[$name])) {
		$catalog = $roster->createLocation([
			'name' => $name,
			'timezone' => $timezone,
			'active' => 1,
		], $actor);
		foreach ($catalog as $row) {
			$locByName[(string) $row['name']] = (int) $row['id'];
		}
		echo "  · location {$name}\n";
	}
}

$empIds = array_map(static fn (string $n): int => $empByName[$n], $employeeSpecs);
$locZentrale = $locByName['Zentrale'];
$locNord = $locByName['Nordwache'];
$locKlinik = $locByName['Klinik Süd'];
$locFlughafen = $locByName['Flughafen Ost'];
$locs = [$locZentrale, $locNord, $locKlinik, $locFlughafen];

/** Ensure calendar month open periods for May–Dec 2026 (≥6–8 Zeiträume densify) */
$periodByMonth = [];
$monthKeys = ['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'];
foreach ($monthKeys as $ym) {
	$ensured = $roster->ensureOpenCalendarMonth($actor, $ym);
	$periodId = (int) ($ensured['period']['id'] ?? $ensured['id'] ?? 0);
	if ($periodId <= 0) {
		// Fallback: find by list
		foreach ($roster->listPeriods($actor) as $p) {
			if (str_starts_with((string) ($p['startDate'] ?? ''), $ym)) {
				$periodId = (int) $p['id'];
				break;
			}
		}
	}
	if ($periodId <= 0) {
		fwrite(STDERR, "Could not ensure period {$ym}\n");
		exit(1);
	}
	$periodByMonth[$ym] = $periodId;
	echo "  · period {$ym} id={$periodId}\n";
}

$mayId = $periodByMonth['2026-05'];
$junId = $periodByMonth['2026-06'];
$julId = $periodByMonth['2026-07'];
$augId = $periodByMonth['2026-08'];
$sepId = $periodByMonth['2026-09'];
$octId = $periodByMonth['2026-10'];
$novId = $periodByMonth['2026-11'];
$decId = $periodByMonth['2026-12'];
$allPeriodIds = [$mayId, $junId, $julId, $augId, $sepId, $octId, $novId, $decId];

/** Force periods back to open so re-seed can rewrite assignments / publish theatre. */
foreach ($allPeriodIds as $pid) {
	$db->executeStatement(
		'UPDATE `oc_dc_periods` SET `status` = ?, `published_at` = NULL, `closed_at` = NULL, `close_snapshot_id` = NULL WHERE `id` = ?',
		['open', $pid]
	);
}

foreach ($allPeriodIds as $pid) {
	$db->executeStatement('DELETE FROM `oc_dc_assignments` WHERE `period_id` = ?', [$pid]);
	$db->executeStatement('DELETE FROM `oc_dc_conflicts` WHERE `period_id` = ?', [$pid]);
	$db->executeStatement('DELETE FROM `oc_dc_open_shifts` WHERE `period_id` = ?', [$pid]);
	$db->executeStatement('DELETE FROM `oc_dc_roster_snapshots` WHERE `period_id` = ?', [$pid]);
}
$db->executeStatement('DELETE FROM `oc_dc_swap_requests` WHERE `company_id` = 1 OR `company_id` IS NULL');

$bands = [
	['06:00', '14:00', 30],
	['14:00', '22:00', 30],
];

$assign = static function (
	int $periodId,
	int $employeeId,
	int $locationId,
	string $date,
	string $start,
	string $end,
	int $breakMinutes,
	string $note,
	array $acks = [],
) use ($roster, $actor): void {
	try {
		$payload = [
			'periodId' => $periodId,
			'employeeId' => $employeeId,
			'locationId' => $locationId,
			'dutyDate' => $date,
			'startTime' => $start,
			'endTime' => $end,
			'breakMinutes' => $breakMinutes,
			'note' => $note,
		];
		if ($acks !== []) {
			$payload['acknowledgements'] = $acks;
		}
		$roster->createAssignment($payload, $actor, false, false, false, true);
	} catch (Throwable $e) {
		if (!preg_match('/OVERLAP|CONFLICT|DUPLICATE|SLOT|ABSENCE|ACK/i', $e->getMessage())) {
			fwrite(STDERR, "  ! assign {$date} emp={$employeeId}: {$e->getMessage()}\n");
		}
	}
};

$insertRawOverlap = static function (
	int $periodId,
	int $employeeId,
	int $locationId,
	string $date,
	string $start,
	string $end,
	string $note,
) use ($db, $actor): void {
	$slot = AssignmentSlotKey::forActive($periodId, $employeeId, $date, $start, $end);
	$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
	try {
		$db->executeStatement(
			'INSERT INTO `oc_dc_assignments`
			 (`period_id`,`employee_id`,`location_id`,`duty_date`,`start_time`,`end_time`,`break_minutes`,`note`,`created_by`,`created_at`,`status`,`version`,`slot_key`,`source`)
			 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
			[$periodId, $employeeId, $locationId, $date, $start, $end, 30, $note, $actor, $now, 'active', 0, $slot, 'manual']
		);
	} catch (Throwable $e) {
		fwrite(STDERR, "  ! raw overlap {$date}: {$e->getMessage()}\n");
	}
};

echo "enable Today + Muster flags…\n";
try {
	$settings = Server::get(SelfServiceSettingsService::class);
	$settings->updateForCompany(1, [
		'rotationPatternsEnabled' => true,
		'todayBoardEnabled' => true,
		'preferencesEnabled' => true,
		'blackoutsEnabled' => true,
		'peerRosterVisibility' => true,
		'sollFromDuty' => true,
	], $actor);
} catch (Throwable $e) {
	fwrite(STDERR, "  ! settings: {$e->getMessage()}\n");
}

echo "raise period hard caps for dense store theatre…\n";
$db->executeStatement(
	'UPDATE `oc_dc_conflict_policy` SET
		`max_period_hard` = GREATEST(COALESCE(`max_period_hard`, 0), 20000),
		`max_period_soft` = GREATEST(COALESCE(`max_period_soft`, 0), 16000),
		`max_daily_hard` = GREATEST(COALESCE(`max_daily_hard`, 0), 720),
		`min_rest_minutes` = LEAST(COALESCE(`min_rest_minutes`, 660), 660)
	 WHERE `id` >= 1'
);

echo "densify September (publish + Heute)…\n";
$sepStart = new DateTimeImmutable('2026-09-01', $tz);
for ($d = 0; $d < 30; $d++) {
	$day = $sepStart->modify("+{$d} days");
	$date = $day->format('Y-m-d');
	$dow = (int) $day->format('N');
	if ($dow >= 6) {
		continue;
	}
	// Lighter month load so period hard caps stay green; Heute gets the density spike.
	if ($d % 2 === 1) {
		continue;
	}
	$assign($sepId, $empIds[0], $locZentrale, $date, '06:00', '14:00', 30, 'Früh Zentrale');
	$assign($sepId, $empIds[1], $locNord, $date, '06:00', '14:00', 30, 'Früh Nordwache');
	$assign($sepId, $empIds[2], $locZentrale, $date, '14:00', '22:00', 30, 'Spät Zentrale');
	$assign($sepId, $empIds[3], $locNord, $date, '14:00', '22:00', 30, 'Spät Nordwache');
	$assign($sepId, $empIds[4], $locKlinik, $date, '06:00', '14:00', 30, 'Klinik Früh');
	$assign($sepId, $empIds[5], $locFlughafen, $date, '14:00', '22:00', 30, 'Flughafen Spät');
}

$todayYmd = $today->format('Y-m-d');
if (str_starts_with($todayYmd, '2026-09')) {
	echo "densify Heute {$todayYmd} (multi-Standort timeline)…\n";
	// Wipe today's rows then rebuild multi-Standort non-overlapping set.
	$db->executeStatement(
		'DELETE FROM `oc_dc_assignments` WHERE `period_id` = ? AND `duty_date` = ?',
		[$sepId, $todayYmd]
	);
	// Zentrale 8 cards + Nordwache/Klinik/Flughafen packs for multi-Standort theatre.
	$heuteSpecs = [
		[$empIds[0], $locZentrale, '05:30', '07:30', 'Übergabe'],
		[$empIds[1], $locZentrale, '07:30', '09:30', 'Früh A'],
		[$empIds[2], $locZentrale, '09:30', '11:30', 'Früh B'],
		[$empIds[3], $locZentrale, '11:30', '13:30', 'Mittag'],
		[$empIds[4], $locZentrale, '13:30', '15:30', 'Tag A'],
		[$empIds[5], $locZentrale, '15:30', '17:30', 'Tag B'],
		[$empIds[6], $locZentrale, '17:30', '19:30', 'Spät A'],
		[$empIds[7], $locZentrale, '19:30', '21:30', 'Spät B'],
		// Parallel Standort packs (same people cannot overlap — use staggered windows already exclusive per emp).
		// Re-use via non-overlapping times only for unused slots: each emp already used at Zentrale.
		// Seed dedicated Standort rows on alternate days instead — inject via raw for visual multi-board.
	];
	foreach ($heuteSpecs as [$eid, $lid, $s, $e, $note]) {
		$assign($sepId, $eid, $lid, $todayYmd, $s, $e, 10, $note);
	}
	// Extra Standort theatre: use raw inserts with distinct employees on other locations
	// by splitting the day — employees 0–3 also cover Nord/Klinik mornings before Zentrale handoff
	// is not possible (overlap). Instead seed visual-only parallel locations with short slots
	// on employees already finished: emp0 finishes 07:30 → Nord 21:30–23:00 etc. not useful.
	// Capture composes multi-Standort from API across locations seeded on other Sep days;
	// for TODAY add 4 concurrent location bands using INSERT that won't collide names:
	// Create "ghost" density via additional published shifts at other locations using
	// employees only if we free them — so seed Nordwache/Klinik/Flughafen with the same
	// 8 people on a DIFFERENT published day is wrong. Better: add 2nd company locations
	// with overlapping times requires distinct employees.
	// Practical approach: keep 8 Zentrale; capture injects multi-Standort DOM from API
	// for Nordwache/Klinik/Flughafen on nearby published days (09-07 / 09-09).
	foreach (
		[
			[$empIds[0], $locNord, '2026-09-07', '06:00', '14:00', 'Nord Früh'],
			[$empIds[1], $locNord, '2026-09-07', '14:00', '22:00', 'Nord Spät'],
			[$empIds[2], $locNord, '2026-09-07', '08:00', '12:00', 'Nord Tag'],
			[$empIds[3], $locNord, '2026-09-07', '12:00', '16:00', 'Nord Cover'],
			[$empIds[4], $locKlinik, '2026-09-09', '06:00', '14:00', 'Klinik Früh'],
			[$empIds[5], $locKlinik, '2026-09-09', '14:00', '22:00', 'Klinik Spät'],
			[$empIds[6], $locKlinik, '2026-09-09', '08:00', '16:00', 'Klinik Tag'],
			[$empIds[7], $locFlughafen, '2026-09-09', '05:00', '13:00', 'Flug Früh'],
			[$empIds[0], $locFlughafen, '2026-09-09', '13:00', '21:00', 'Flug Spät'],
			[$empIds[1], $locFlughafen, '2026-09-09', '09:00', '17:00', 'Flug Tag'],
		] as [$eid, $lid, $d, $s, $e, $note]
	) {
		$assign($sepId, $eid, $lid, $d, $s, $e, 15, $note);
	}
}

echo "densify May–Aug (closed/published theatre for Zeiträume rows)…\n";
foreach (
	[
		[$mayId, '2026-05-01', 12, 'Mai'],
		[$junId, '2026-06-01', 12, 'Jun'],
		[$julId, '2026-07-01', 10, 'Jul'],
		[$augId, '2026-08-01', 12, 'Aug'],
	] as [$pid, $start, $days, $label]
) {
	$startDt = new DateTimeImmutable($start, $tz);
	for ($d = 0; $d < $days; $d++) {
		$day = $startDt->modify("+{$d} days");
		$date = $day->format('Y-m-d');
		if ((int) $day->format('N') >= 6) {
			continue;
		}
		$band = $bands[$d % 2];
		$assign($pid, $empIds[$d % 8], $locs[$d % 4], $date, $band[0], $band[1], $band[2], "{$label} Rotation");
	}
}

echo "densify October (close theatre)…\n";
$octStart = new DateTimeImmutable('2026-10-01', $tz);
for ($d = 0; $d < 20; $d++) {
	$day = $octStart->modify("+{$d} days");
	$date = $day->format('Y-m-d');
	if ((int) $day->format('N') >= 6) {
		continue;
	}
	$band = $bands[$d % 2];
	$assign($octId, $empIds[$d % 8], $locs[$d % 4], $date, $band[0], $band[1], $band[2], 'Okt Rotation');
}

echo "densify November grid (roster hero)…\n";
$novStart = new DateTimeImmutable('2026-11-01', $tz);
for ($d = 0; $d < 30; $d++) {
	$day = $novStart->modify("+{$d} days");
	$date = $day->format('Y-m-d');
	$dow = (int) $day->format('N');
	if ($dow >= 6) {
		continue;
	}
	$band = $bands[$d % 2];
	$assign($novId, $empIds[$d % 8], $locs[$d % 4], $date, $band[0], $band[1], $band[2], 'Nov Rotation');
	$assign($novId, $empIds[($d + 3) % 8], $locs[($d + 1) % 4], $date, $band[0], $band[1], $band[2], 'Nov Pair');
	$assign($novId, $empIds[($d + 5) % 8], $locs[($d + 2) % 4], $date, $bands[($d + 1) % 2][0], $bands[($d + 1) % 2][1], 30, 'Nov Cover');
}

echo "densify December (publish so newest OPEN = Nov)…\n";
$decStart = new DateTimeImmutable('2026-12-01', $tz);
for ($d = 0; $d < 12; $d++) {
	$day = $decStart->modify("+{$d} days");
	$date = $day->format('Y-m-d');
	if ((int) $day->format('N') >= 6) {
		continue;
	}
	$band = $bands[$d % 2];
	$assign($decId, $empIds[$d % 8], $locs[$d % 4], $date, $band[0], $band[1], $band[2], 'Dez Rotation');
}

echo "seed absences (≥10 pending + approved mix; no Heute collision)…\n";
$db->executeStatement('DELETE FROM `oc_dc_absences` WHERE `company_id` = 1 OR `company_id` IS NULL');
// Keep approved windows off "today" so Sep can publish cleanly for the Heute board.
$absenceSpecs = [
	['Elena Braun', 'vacation', '2026-09-22', '2026-09-26', 'approved'],
	['Felix Neumann', 'sick', '2026-09-15', '2026-09-17', 'approved'],
	['Clara Hofmann', 'vacation', '2026-10-06', '2026-10-08', 'approved'],
	['Ben Richter', 'training', '2026-10-13', '2026-10-15', 'approved'],
	['David Keller', 'sick', '2026-08-18', '2026-08-20', 'approved'],
	['Anna Weber', 'vacation', '2026-08-03', '2026-08-07', 'approved'],
	['Greta Lorenz', 'training', $ymd(12), $ymd(14), 'pending'],
	['Jonas Vogel', 'vacation', $ymd(20), $ymd(24), 'pending'],
	['Anna Weber', 'unpaid', $ymd(28), $ymd(29), 'pending'],
	['Elena Braun', 'other', $ymd(16), $ymd(17), 'pending'],
	['Felix Neumann', 'vacation', $ymd(30), $ymd(32), 'pending'],
	['Clara Hofmann', 'sick', $ymd(18), $ymd(19), 'pending'],
];
foreach ($absenceSpecs as [$name, $kind, $start, $end, $status]) {
	$eid = $empByName[$name] ?? null;
	if ($eid === null) {
		continue;
	}
	try {
		$list = $roster->createAbsence([
			'employeeId' => $eid,
			'kind' => $kind,
			'startDate' => $start,
			'endDate' => $end,
		], $actor);
		$created = null;
		foreach ($list as $row) {
			if ((int) ($row['employeeId'] ?? 0) === $eid
				&& (string) ($row['startDate'] ?? '') === $start
				&& (string) ($row['endDate'] ?? '') === $end) {
				$created = $row;
			}
		}
		if ($created !== null && $status === 'approved') {
			$roster->transitionAbsence((int) $created['id'], 'approved', 'Store-Demo Genehmigung', $actor);
		}
		echo "  · absence {$name} {$kind} {$status}\n";
	} catch (Throwable $e) {
		fwrite(STDERR, "  ! absence {$name}: {$e->getMessage()}\n");
	}
}

// Drop Sep assignments that collide with approved absences (hard publish gate).
$db->executeStatement(
	'DELETE a FROM `oc_dc_assignments` a
	 INNER JOIN `oc_dc_absences` abs ON abs.employee_id = a.employee_id
	 WHERE a.period_id = ?
	   AND abs.status = ?
	   AND a.duty_date BETWEEN abs.start_date AND abs.end_date',
	[$sepId, 'approved']
);

echo "publish May–Aug / Sep / close Oct / publish Dec…\n";
$forcePublish = static function (int $periodId, string $label) use ($roster, $actor, $db): void {
	try {
		// Materialize then require zero hard conflicts for a real publish snapshot.
		$roster->createAssignment([
			'periodId' => $periodId,
			'employeeId' => 0,
			'locationId' => 0,
			'dutyDate' => '2099-01-01',
			'startTime' => '00:00',
			'endTime' => '01:00',
			'breakMinutes' => 0,
			'note' => 'noop',
		], $actor, false, false, true, true);
	} catch (Throwable) {
		// expected — used only to force refresh when possible
	}
	try {
		$ref = new ReflectionClass($roster);
		$method = $ref->getMethod('refreshAndListConflicts');
		$method->setAccessible(true);
		$method->invoke($roster, $periodId);
	} catch (Throwable) {
		/* ignore */
	}
	try {
		$roster->transitionPeriod($periodId, 'published', $actor, '');
		echo "  · {$label} published\n";
	} catch (Throwable $e) {
		fwrite(STDERR, "  ! publish {$label}: {$e->getMessage()}\n");
		$db->executeStatement(
			'UPDATE `oc_dc_periods` SET `status` = ?, `published_at` = NOW() WHERE `id` = ?',
			['published', $periodId]
		);
		echo "  · {$label} published (sql fallback)\n";
	}
};

foreach ([$mayId => 'May', $junId => 'Jun', $julId => 'Jul', $augId => 'Aug'] as $pid => $label) {
	$forcePublish($pid, $label);
}
// Close May–Jul for lifecycle mix; leave Aug published alongside Sep.
foreach ([$mayId => 'May', $junId => 'Jun', $julId => 'Jul'] as $pid => $label) {
	try {
		$roster->transitionPeriod($pid, 'closed', $actor, '');
		echo "  · {$label} closed\n";
	} catch (Throwable $e) {
		$db->executeStatement(
			'UPDATE `oc_dc_periods` SET `status` = ?, `closed_at` = NOW() WHERE `id` = ?',
			['closed', $pid]
		);
		echo "  · {$label} closed (sql fallback)\n";
	}
}
$forcePublish($sepId, 'Sep');
$forcePublish($octId, 'Oct');
try {
	$roster->transitionPeriod($octId, 'closed', $actor, '');
	echo "  · Oct closed\n";
} catch (Throwable $e) {
	$db->executeStatement(
		'UPDATE `oc_dc_periods` SET `status` = ?, `closed_at` = NOW() WHERE `id` = ?',
		['closed', $octId]
	);
	echo "  · Oct closed (sql fallback)\n";
}
$forcePublish($decId, 'Dec');

echo "inject Nov hard + soft Planungsprobleme…\n";
// Hard: double-book Anna on 2026-11-10 (overlap Zentrale + Nordwache).
$insertRawOverlap($novId, $empIds[0], $locZentrale, '2026-11-10', '06:00', '14:00', 'Konflikt Doppel Zentrale');
$insertRawOverlap($novId, $empIds[0], $locNord, '2026-11-10', '10:00', '18:00', 'Konflikt Doppel Nord');
// Soft: rest-time for Ben — late evening then early morning next day.
$assign($novId, $empIds[1], $locZentrale, '2026-11-11', '18:00', '23:00', 15, 'Spät Rest-Demo');
$assign(
	$novId,
	$empIds[1],
	$locNord,
	'2026-11-12',
	'06:00',
	'12:00',
	15,
	'Früh Rest-Demo',
	[['conflictType' => 'rest_time_violation', 'reason' => 'Store-Demo: Restzeit bewusst unterschritten']],
);
// Second hard: Clara double-book later in month.
$insertRawOverlap($novId, $empIds[2], $locKlinik, '2026-11-17', '06:00', '14:00', 'Konflikt Klinik');
$insertRawOverlap($novId, $empIds[2], $locFlughafen, '2026-11-17', '08:00', '16:00', 'Konflikt Flughafen');
// More hard rows so badge «7» matches fold theatre (≥7 named Muss-behoben).
$insertRawOverlap($novId, $empIds[5], $locZentrale, '2026-11-19', '06:00', '14:00', 'Konflikt Felix Zentrale');
$insertRawOverlap($novId, $empIds[5], $locNord, '2026-11-19', '10:00', '18:00', 'Konflikt Felix Nord');
$insertRawOverlap($novId, $empIds[6], $locKlinik, '2026-11-21', '06:00', '14:00', 'Konflikt Greta Klinik');
$insertRawOverlap($novId, $empIds[6], $locFlughafen, '2026-11-21', '08:00', '16:00', 'Konflikt Greta Flug');
$insertRawOverlap($novId, $empIds[7], $locZentrale, '2026-11-24', '14:00', '22:00', 'Konflikt Jonas Zentrale');
$insertRawOverlap($novId, $empIds[7], $locNord, '2026-11-24', '16:00', '23:00', 'Konflikt Jonas Nord');
$insertRawOverlap($novId, $empIds[3], $locKlinik, '2026-11-25', '06:00', '14:00', 'Konflikt David Klinik');
$insertRawOverlap($novId, $empIds[3], $locZentrale, '2026-11-25', '08:00', '16:00', 'Konflikt David Zentrale');

// Trigger conflict materialization by creating a clean bump assignment.
try {
	$roster->createAssignment([
		'periodId' => $novId,
		'employeeId' => $empIds[7],
		'locationId' => $locZentrale,
		'dutyDate' => '2026-11-20',
		'startTime' => '09:00',
		'endTime' => '13:00',
		'breakMinutes' => 15,
		'note' => 'Konflikt-Refresh',
	], $actor, false, false, true, true);
} catch (Throwable $e) {
	fwrite(STDERR, "  ! nov refresh: {$e->getMessage()}\n");
}

echo "seed named Tauschanfragen…\n";
$swapAssignments = $db->executeQuery(
	'SELECT `id`, `employee_id`, `duty_date`, `start_time`, `end_time`, `location_id`
	 FROM `oc_dc_assignments`
	 WHERE `period_id` = ? AND `status` = ? AND `employee_id` IN (?, ?)
	 ORDER BY `duty_date` ASC LIMIT 8',
	[$novId, 'active', $empIds[0], $empIds[4]]
)->fetchAll();
$pairTargets = [$empIds[1], $empIds[3]]; // Ben, David
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$swapCount = 0;
foreach ($swapAssignments as $i => $row) {
	if ($swapCount >= 2) {
		break;
	}
	$from = (int) $row['employee_id'];
	$to = $pairTargets[$i % 2];
	if ($to === $from) {
		$to = $empIds[5];
	}
	try {
		$db->executeStatement(
			'INSERT INTO `oc_dc_swap_requests`
			 (`assignment_id`,`from_employee_id`,`to_employee_id`,`status`,`reason`,`created_at`,`company_id`)
			 VALUES (?,?,?,?,?,?,?)',
			[(int) $row['id'], $from, $to, 'pending', 'Store-Demo Tausch', $now, 1]
		);
		$swapCount++;
		echo "  · swap emp {$from} → {$to} on {$row['duty_date']}\n";
	} catch (Throwable $e) {
		fwrite(STDERR, "  ! swap: {$e->getMessage()}\n");
	}
}

echo "seed open-shift pool on Nov…\n";
try {
	$openShifts = Server::get(OpenShiftService::class);
	foreach (
		[
			['2026-11-14', '06:00', '14:00', $locZentrale],
			['2026-11-14', '14:00', '22:00', $locNord],
			['2026-11-18', '08:00', '16:00', $locKlinik],
		] as [$d, $s, $e, $lid]
	) {
		$openShifts->create([
			'periodId' => $novId,
			'locationId' => $lid,
			'dutyDate' => $d,
			'startTime' => $s,
			'endTime' => $e,
			'breakMinutes' => 30,
		], $actor);
		echo "  · open shift {$d} {$s}-{$e}\n";
	}
} catch (Throwable $e) {
	fwrite(STDERR, "  ! open shifts: {$e->getMessage()}\n");
}

echo "seed Muster rotation week…\n";
try {
	$patterns = Server::get(RotationPatternService::class);
	$db->executeStatement('DELETE FROM `oc_dc_rotation_week_days` WHERE `pattern_id` IN (SELECT `id` FROM `oc_dc_rotation_patterns` WHERE `company_id` = 1)');
	$db->executeStatement('DELETE FROM `oc_dc_emp_rot_assign` WHERE `company_id` = 1 OR `pattern_id` IN (SELECT `id` FROM `oc_dc_rotation_patterns` WHERE `company_id` = 1)');
	$db->executeStatement('DELETE FROM `oc_dc_rotation_patterns` WHERE `company_id` = 1');
	$weekDays = [];
	for ($w = 0; $w < 2; $w++) {
		for ($dow = 1; $dow <= 5; $dow++) {
			$weekDays[] = [
				'weekIndex' => $w,
				'dow' => $dow,
				'isWorking' => true,
				'startLocal' => $dow % 2 === 1 ? '06:00' : '14:00',
				'endLocal' => $dow % 2 === 1 ? '14:00' : '22:00',
				'breakMinutes' => 30,
				'locationId' => $dow <= 2 ? $locZentrale : $locNord,
			];
		}
		for ($dow = 6; $dow <= 7; $dow++) {
			$weekDays[] = [
				'weekIndex' => $w,
				'dow' => $dow,
				'isWorking' => false,
			];
		}
	}
	$pat = $patterns->createPattern([
		'companyId' => 1,
		'name' => 'Leitstelle 2-Wochen Früh/Spät',
		'cycleWeeks' => 2,
		'anchorType' => 'iso_week_parity',
		'weekDays' => $weekDays,
		'contractAvgMinutes' => 2400,
	], $actor);
	$patterns->createPattern([
		'companyId' => 1,
		'name' => 'Klinik Wochenend-Reserve',
		'cycleWeeks' => 1,
		'anchorType' => 'iso_week_parity',
		'weekDays' => array_map(static function (int $dow) use ($locKlinik): array {
			$work = $dow >= 6;
			return [
				'weekIndex' => 0,
				'dow' => $dow,
				'isWorking' => $work,
				'startLocal' => $work ? '08:00' : null,
				'endLocal' => $work ? '16:00' : null,
				'breakMinutes' => $work ? 30 : 0,
				'locationId' => $work ? $locKlinik : null,
			];
		}, [1, 2, 3, 4, 5, 6, 7]),
	], $actor);
	$nordDays = [];
	for ($dow = 1; $dow <= 7; $dow++) {
		$work = $dow <= 5;
		$nordDays[] = [
			'weekIndex' => 0,
			'dow' => $dow,
			'isWorking' => $work,
			'startLocal' => $work ? '22:00' : null,
			'endLocal' => $work ? '06:00' : null,
			'breakMinutes' => $work ? 30 : 0,
			'locationId' => $work ? $locNord : null,
		];
	}
	$patterns->createPattern([
		'companyId' => 1,
		'name' => 'Nordwache Nachtwoche',
		'cycleWeeks' => 1,
		'anchorType' => 'iso_week_parity',
		'weekDays' => $nordDays,
		'contractAvgMinutes' => 2100,
	], $actor);
	$flugDays = [];
	for ($w = 0; $w < 2; $w++) {
		for ($dow = 1; $dow <= 7; $dow++) {
			$work = $dow <= 6;
			$early = ($w + $dow) % 2 === 0;
			$flugDays[] = [
				'weekIndex' => $w,
				'dow' => $dow,
				'isWorking' => $work,
				'startLocal' => $work ? ($early ? '05:00' : '13:00') : null,
				'endLocal' => $work ? ($early ? '13:00' : '21:00') : null,
				'breakMinutes' => $work ? 30 : 0,
				'locationId' => $work ? $locFlughafen : null,
			];
		}
	}
	$patterns->createPattern([
		'companyId' => 1,
		'name' => 'Flughafen Ost Rollierend',
		'cycleWeeks' => 2,
		'anchorType' => 'iso_week_parity',
		'weekDays' => $flugDays,
		'contractAvgMinutes' => 2400,
	], $actor);
	echo "  · patterns seeded id=" . (int) ($pat['id'] ?? 0) . " (+3 dense)\n";
} catch (Throwable $e) {
	fwrite(STDERR, "  ! patterns: {$e->getMessage()}\n");
}

// Explicit named swaps: Elena→Ben and Anna→David (fold theatre).
echo "ensure named Tausch fold (Elena→Ben, Anna→David)…\n";
try {
	$db->executeStatement("DELETE FROM `oc_dc_swap_requests` WHERE `reason` LIKE 'Store-Demo%' OR `company_id` = 1");
	$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
	$namedPairs = [
		['Elena Braun', 'Ben Richter'],
		['Anna Weber', 'David Keller'],
	];
	foreach ($namedPairs as [$fromName, $toName]) {
		$fromId = (int) ($empByName[$fromName] ?? 0);
		$toId = (int) ($empByName[$toName] ?? 0);
		$row = $db->executeQuery(
			'SELECT `id`, `duty_date`, `start_time`, `end_time`, `location_id`
			 FROM `oc_dc_assignments`
			 WHERE `period_id` = ? AND `status` = ? AND `employee_id` = ?
			 ORDER BY `duty_date` ASC LIMIT 1',
			[$novId, 'active', $fromId]
		)->fetch();
		if (!$row || $fromId <= 0 || $toId <= 0) {
			fwrite(STDERR, "  ! named swap missing assignment for {$fromName}\n");
			continue;
		}
		$db->executeStatement(
			'INSERT INTO `oc_dc_swap_requests`
			 (`assignment_id`,`from_employee_id`,`to_employee_id`,`status`,`reason`,`created_at`,`company_id`)
			 VALUES (?,?,?,?,?,?,?)',
			[(int) $row['id'], $fromId, $toId, 'pending', "Store-Demo {$fromName}→{$toName}", $now, 1]
		);
		echo "  · {$fromName} → {$toName} on {$row['duty_date']}\n";
	}
} catch (Throwable $e) {
	fwrite(STDERR, "  ! named swaps: {$e->getMessage()}\n");
}

$sepCount = (int) $db->executeQuery('SELECT COUNT(*) FROM `oc_dc_assignments` WHERE `period_id` = ?', [$sepId])->fetchOne();
$novCount = (int) $db->executeQuery('SELECT COUNT(*) FROM `oc_dc_assignments` WHERE `period_id` = ?', [$novId])->fetchOne();
$absCount = (int) $db->executeQuery('SELECT COUNT(*) FROM `oc_dc_absences`')->fetchOne();
$hardNov = (int) $db->executeQuery(
	'SELECT COUNT(*) FROM `oc_dc_conflicts` WHERE `period_id` = ? AND `severity` = ? AND `is_resolved` = 0',
	[$novId, 'hard']
)->fetchOne();
$softNov = (int) $db->executeQuery(
	'SELECT COUNT(*) FROM `oc_dc_conflicts` WHERE `period_id` = ? AND `severity` = ? AND `is_resolved` = 0',
	[$novId, 'soft']
)->fetchOne();
$pendingSwaps = (int) $db->executeQuery(
	"SELECT COUNT(*) FROM `oc_dc_swap_requests` WHERE `status` IN ('pending','proposed','awaiting_counterparty')"
)->fetchOne();
$statuses = $db->executeQuery(
	'SELECT `start_date`, `status` FROM `oc_dc_periods` WHERE `id` IN (?,?,?,?,?,?,?,?) ORDER BY `start_date`',
	$allPeriodIds
)->fetchAll();

echo "done sep={$sepCount} nov={$novCount} absences={$absCount} novHard={$hardNov} novSoft={$softNov} swaps={$pendingSwaps}\n";
echo json_encode([
	'actor' => $actor,
	'round' => 4,
	'periodMay' => $mayId,
	'periodJun' => $junId,
	'periodJul' => $julId,
	'periodAug' => $augId,
	'periodSep' => $sepId,
	'periodOct' => $octId,
	'periodNov' => $novId,
	'periodDec' => $decId,
	'periodStatuses' => $statuses,
	'employees' => $empByName,
	'locations' => $locByName,
	'sepAssignments' => $sepCount,
	'novAssignments' => $novCount,
	'absences' => $absCount,
	'novHardConflicts' => $hardNov,
	'novSoftConflicts' => $softNov,
	'pendingSwaps' => $pendingSwaps,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

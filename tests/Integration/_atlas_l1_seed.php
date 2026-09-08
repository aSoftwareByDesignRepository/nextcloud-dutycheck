<?php

declare(strict_types=1);

/**
 * Atlas L1 — seed period/employee/location for concurrent assignment race.
 * Usage (inside nextcloud container):
 *   php tests/Integration/_atlas_l1_seed.php
 * Prints JSON: {periodId, employeeId, locationId, dutyDate, start, end}
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

require '/var/www/html/lib/base.php';

$user = \OC::$server->get(\OCP\IUserManager::class)->get('admin');
if ($user === null) {
	fwrite(STDERR, "no admin\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($user);

$roster = \OC::$server->get(\OCA\DutyCheck\Service\RosterService::class);
$tag = 'atlas-l1-' . bin2hex(random_bytes(4));
// Unique far-future window so re-runs never hit PERIOD_RANGE_EXISTS (NC may exit 0 on uncaught CLI exceptions).
$offsetDays = (int) (time() % 8000) + random_int(500, 900);
$startDate = (new DateTimeImmutable('2088-01-01'))->modify('+' . $offsetDays . ' days')->format('Y-m-d');
$endDate = (new DateTimeImmutable($startDate))->modify('+6 days')->format('Y-m-d');
$dutyDate = (new DateTimeImmutable($startDate))->modify('+2 days')->format('Y-m-d');
$empName = $tag . '-emp';
$locName = $tag . '-loc';

try {
	$period = $roster->createPeriod($startDate, $endDate, 'admin');
} catch (Throwable $e) {
	fwrite(STDERR, 'createPeriod: ' . $e->getMessage() . "\n");
	exit(1);
}
$periodId = (int) ($period['id'] ?? 0);
if ($periodId <= 0) {
	fwrite(STDERR, 'createPeriod failed: ' . json_encode($period) . "\n");
	exit(1);
}

try {
	$empCatalog = $roster->createEmployee([
		'displayName' => $empName,
		'active' => true,
	], 'admin');
} catch (Throwable $e) {
	fwrite(STDERR, 'createEmployee: ' . $e->getMessage() . "\n");
	exit(1);
}
$employeeId = 0;
foreach ($empCatalog as $row) {
	if ((string) ($row['displayName'] ?? '') === $empName) {
		$employeeId = (int) $row['id'];
		break;
	}
}

$locCatalog = $roster->createLocation([
	'name' => $locName,
	'timezone' => 'Europe/Berlin',
	'active' => true,
], 'admin');
$locationId = 0;
foreach ($locCatalog as $row) {
	if ((string) ($row['name'] ?? '') === $locName) {
		$locationId = (int) $row['id'];
		break;
	}
}

if ($employeeId <= 0 || $locationId <= 0) {
	fwrite(STDERR, 'catalog seed failed emp=' . json_encode($empCatalog) . ' loc=' . json_encode($locCatalog) . "\n");
	exit(1);
}

echo json_encode([
	'ok' => true,
	'tag' => $tag,
	'periodId' => $periodId,
	'employeeId' => $employeeId,
	'locationId' => $locationId,
	'dutyDate' => $dutyDate,
	'start' => '08:00',
	'end' => '16:00',
	'break' => 30,
], JSON_UNESCAPED_SLASHES) . "\n";

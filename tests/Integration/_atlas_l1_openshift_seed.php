<?php

declare(strict_types=1);

/**
 * Atlas L1 — seed published period + open shift for claim race.
 * Reuses existing linked employee for e2e_employee when present.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

require '/var/www/html/lib/base.php';

$userMgr = \OC::$server->get(\OCP\IUserManager::class);
$user = $userMgr->get('admin');
if ($user === null) {
	fwrite(STDERR, "no admin\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($user);

$roster = \OC::$server->get(\OCA\DutyCheck\Service\RosterService::class);
$openShifts = \OC::$server->get(\OCA\DutyCheck\Service\OpenShiftService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);

$tag = 'atlas-os-' . bin2hex(random_bytes(3));
// Unique window: encode time+rand so re-runs never collide with PERIOD_RANGE_EXISTS.
$offsetDays = (int) (time() % 8000) + random_int(0, 400);
$startDate = (new DateTimeImmutable('2090-01-01'))->modify('+' . $offsetDays . ' days')->format('Y-m-d');
$endDate = (new DateTimeImmutable($startDate))->modify('+6 days')->format('Y-m-d');
$dutyDate = (new DateTimeImmutable($startDate))->modify('+2 days')->format('Y-m-d');
$employeeUserId = 'e2e_employee';

try {
	$period = $roster->createPeriod($startDate, $endDate, 'admin');
	$periodId = (int) $period['id'];

	$qb = $db->getQueryBuilder();
	$qb->select('id', 'display_name', 'active')
		->from('dc_employees')
		->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($employeeUserId)))
		->setMaxResults(1);
	$existing = $qb->executeQuery()->fetch();
	if (is_array($existing)) {
		$employeeId = (int) $existing['id'];
		if ((int) ($existing['active'] ?? 0) !== 1) {
			$upd = $db->getQueryBuilder();
			$upd->update('dc_employees')
				->set('active', $upd->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($employeeId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	} else {
		$empName = $tag . '-emp';
		$empCatalog = $roster->createEmployee([
			'displayName' => $empName,
			'linkedUserId' => $employeeUserId,
			'active' => true,
		], 'admin');
		$employeeId = 0;
		foreach ($empCatalog as $row) {
			if ((string) ($row['displayName'] ?? '') === $empName) {
				$employeeId = (int) $row['id'];
				break;
			}
		}
	}

	$locName = $tag . '-loc';
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
		throw new RuntimeException('catalog seed failed');
	}

	$created = $openShifts->create([
		'periodId' => $periodId,
		'locationId' => $locationId,
		'dutyDate' => $dutyDate,
		'startTime' => '09:00',
		'endTime' => '13:00',
		'breakMinutes' => 0,
	], 'admin');
	$openShiftId = (int) ($created['id'] ?? 0);
	if ($openShiftId <= 0) {
		throw new RuntimeException('open shift create failed: ' . json_encode($created));
	}

	$roster->transitionPeriod($periodId, 'published', 'admin');
} catch (Throwable $e) {
	fwrite(STDERR, 'seed: ' . $e->getMessage() . "\n");
	exit(1);
}

echo json_encode([
	'ok' => true,
	'periodId' => $periodId,
	'openShiftId' => $openShiftId,
	'employeeId' => $employeeId,
	'locationId' => $locationId,
	'dutyDate' => $dutyDate,
	'employeeUserId' => $employeeUserId,
], JSON_UNESCAPED_SLASHES) . "\n";

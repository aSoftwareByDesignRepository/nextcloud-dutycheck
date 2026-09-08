<?php

declare(strict_types=1);

/**
 * Atlas L1 — single createAssignment attempt (for parallel shell race).
 * Usage: php _atlas_l1_create_once.php <periodId> <employeeId> <locationId> <dutyDate> <start> <end>
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

$periodId = (int) ($argv[1] ?? 0);
$employeeId = (int) ($argv[2] ?? 0);
$locationId = (int) ($argv[3] ?? 0);
$dutyDate = (string) ($argv[4] ?? '');
$start = (string) ($argv[5] ?? '08:00');
$end = (string) ($argv[6] ?? '16:00');
$break = (int) ($argv[7] ?? 30);
if ($periodId <= 0 || $employeeId <= 0 || $locationId <= 0 || $dutyDate === '') {
	fwrite(STDERR, "usage: periodId employeeId locationId dutyDate start end [breakMinutes]\n");
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
try {
	$result = $roster->createAssignment([
		'periodId' => $periodId,
		'employeeId' => $employeeId,
		'locationId' => $locationId,
		'dutyDate' => $dutyDate,
		'startTime' => $start,
		'endTime' => $end,
		'breakMinutes' => $break,
		'note' => 'atlas-l1-race',
	], 'admin', false, false, false, true);
	$id = (int) ($result['createdAssignmentId'] ?? 0);
	echo json_encode(['ok' => true, 'assignmentId' => $id]) . "\n";
} catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class]) . "\n";
	exit(1);
}

<?php

declare(strict_types=1);

/**
 * Atlas L1 — concurrent period publish race.
 * Seeds an open period, then N workers call transitionPeriod(... published).
 * Ground truth: exactly one published period row; losers get PERIOD_STATUS_CONFLICT.
 *
 * Usage: php _atlas_l1_publish_once.php <periodId>
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

$periodId = (int) ($argv[1] ?? 0);
if ($periodId <= 0) {
	fwrite(STDERR, "usage: periodId\n");
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
	$result = $roster->transitionPeriod($periodId, 'published', 'admin');
	echo json_encode(['ok' => true, 'status' => $result['status'] ?? null, 'id' => $result['id'] ?? $periodId]) . "\n";
} catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class]) . "\n";
	exit(1);
}

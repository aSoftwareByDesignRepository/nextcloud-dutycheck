<?php

declare(strict_types=1);

/** Atlas L1 — single open-shift claim attempt. Usage: php _atlas_l1_claim_once.php <openShiftId> <userId> */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

$openShiftId = (int) ($argv[1] ?? 0);
$userId = (string) ($argv[2] ?? 'e2e_employee');
if ($openShiftId <= 0) {
	fwrite(STDERR, "usage: openShiftId [userId]\n");
	exit(2);
}

require '/var/www/html/lib/base.php';

$user = \OC::$server->get(\OCP\IUserManager::class)->get($userId);
if ($user === null) {
	fwrite(STDERR, "no user $userId\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($user);

$svc = \OC::$server->get(\OCA\DutyCheck\Service\OpenShiftService::class);
try {
	$result = $svc->claim($openShiftId, $userId);
	echo json_encode(['ok' => true, 'status' => $result['status'] ?? null, 'id' => $result['id'] ?? $openShiftId]) . "\n";
} catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class]) . "\n";
	exit(1);
}

<?php
// One-shot: publish period 44 for Atlas mobile demo
require '/var/www/html/lib/base.php';
\OC_App::loadApp('dutycheck');
$roster = \OCP\Server::get(\OCA\DutyCheck\Service\RosterService::class);
$admin = \OCP\Server::get(\OCP\IUserManager::class)->get('admin');
\OCP\Server::get(\OCP\IUserSession::class)->setUser($admin);
$p = $roster->listPeriods('admin');
foreach ($p as $row) {
	if ((int)$row['id'] === 44) {
		echo 'before=' . $row['status'] . PHP_EOL;
	}
}
try {
	$r = $roster->transitionPeriod(44, 'published', 'admin');
	echo 'after=' . ($r['status'] ?? '?') . PHP_EOL;
} catch (Throwable $e) {
	echo 'ERR=' . $e->getMessage() . PHP_EOL;
}

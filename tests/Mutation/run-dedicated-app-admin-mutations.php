<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Dedicated App Admin OR-semantics for DutyCheck.
 *
 * Usage:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/dutycheck/tests/Mutation/run-dedicated-app-admin-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';

$mutations = [
	[
		'file' => $appRoot . '/lib/Service/AccessControlService.php',
		'from' => 'return $this->isSystemAdmin($userId) || in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);',
		'to' => 'return $this->isSystemAdmin($userId) && in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);',
		'label' => 'app_admin_or_becomes_and_narrow',
	],
	[
		'file' => $appRoot . '/lib/Service/AccessControlService.php',
		'from' => 'return $this->isSystemAdmin($userId) || in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);',
		'to' => 'return $this->isSystemAdmin($userId);',
		'label' => 'drops_dedicated_admin_list',
	],
	[
		'file' => $appRoot . '/templates/common/navigation.php',
		'from' => 'Directory and linked Nextcloud accounts',
		'to' => 'Directory and linked user IDs',
		'label' => 'nav_hint_reverts_to_user_ids',
	],
];

function duty_phpunit_filter(): string
{
	return 'DedicatedAppAdminContractTest|DirectoryPickerContractTest::testEmployeesNavHintDoesNotSayUserIds';
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg(duty_phpunit_filter());
	passthru($cmd, $code);
	return (int)$code;
}

echo "Baseline…\n";
if (run_phpunit($phpunit, $appRoot) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}
$failed = 0;
$killed = 0;
foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP: {$m['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	echo "Mutant {$m['label']}…\n";
	$code = run_phpunit($phpunit, $appRoot);
	file_put_contents($m['file'], $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$m['label']}\n");
		$failed++;
	} else {
		echo "Killed: {$m['label']}\n";
		$killed++;
	}
}
echo "Done: killed={$killed} failed={$failed} total=" . count($mutations) . "\n";
exit($failed === 0 ? 0 : 1);

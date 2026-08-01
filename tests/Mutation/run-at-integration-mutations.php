<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for DutyCheck ↔ ArbeitszeitCheck integration core.
 *
 * Runs targeted mutants against IntegrationOpsConstants consumers / rate limiter /
 * effective semantics and asserts the unit suite kills them.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

$phpunit = $root . '/vendor/bin/phpunit';
$suiteFilter = 'tests/Unit/Integration';

function run_phpunit(string $phpunit, string $root, string $filterDir): bool
{
	$cmd = sprintf(
		'cd %s && php %s --colors=never %s 2>&1',
		escapeshellarg($root),
		escapeshellarg($phpunit),
		escapeshellarg($filterDir),
	);
	exec($cmd, $out, $code);
	return $code === 0;
}

echo "== Baseline Integration unit suite ==\n";
if (!run_phpunit($phpunit, $root, $suiteFilter)) {
	fwrite(STDERR, "Baseline failed — fix tests before mutation.\n");
	exit(1);
}
echo "Baseline OK\n";

/** @var list<array{file:string, search:string, replace:string, label:string}> $mutants */
$mutants = [
	[
		'file' => 'lib/Integration/IntegrationOpsConstants.php',
		'search' => 'public const MIN_PEER_VERSION = \'1.2.0\';',
		'replace' => 'public const MIN_PEER_VERSION = \'99.0.0\';',
		'label' => 'min-peer-version-impossible',
	],
	[
		'file' => 'lib/Integration/IntegrationOpsConstants.php',
		'search' => 'public const SYNC_RL_PER_ADMIN_INTERVAL = 60;',
		'replace' => 'public const SYNC_RL_PER_ADMIN_INTERVAL = 0;',
		'label' => 'rate-limit-interval-zero',
	],
	[
		'file' => 'lib/Integration/IntegrationOpsConstants.php',
		'search' => 'public const SYNC_RL_PER_ADMIN_HOUR = 6;',
		'replace' => 'public const SYNC_RL_PER_ADMIN_HOUR = 1;',
		'label' => 'rate-limit-admin-hour-one',
	],
	[
		'file' => 'lib/Integration/IntegrationSyncRateLimiter.php',
		'search' => "return ['allowed' => true];",
		'replace' => "return ['allowed' => false, 'retryAfter' => 1, 'code' => 'INTEGRATION_SYNC_RATE_LIMIT'];",
		'label' => 'rate-limiter-always-deny',
	],
	[
		'file' => 'lib/Integration/IntegrationOpsConstants.php',
		'search' => 'public const RD_PERIOD_SECONDS = 900;',
		'replace' => 'public const RD_PERIOD_SECONDS = 1;',
		'label' => 'rd-period-one-second',
	],
];

$killed = 0;
$survived = 0;
$errors = 0;

foreach ($mutants as $m) {
	$path = $root . '/' . $m['file'];
	$orig = file_get_contents($path);
	if ($orig === false || !str_contains($orig, $m['search'])) {
		fwrite(STDERR, "SKIP/ERR missing search for {$m['label']}\n");
		$errors++;
		continue;
	}
	$mutated = str_replace($m['search'], $m['replace'], $orig, $count);
	if ($count < 1) {
		fwrite(STDERR, "ERR replace failed {$m['label']}\n");
		$errors++;
		continue;
	}
	file_put_contents($path, $mutated);
	$passed = run_phpunit($phpunit, $root, $suiteFilter);
	file_put_contents($path, $orig);
	if ($passed) {
		echo "SURVIVED: {$m['label']}\n";
		$survived++;
	} else {
		echo "KILLED: {$m['label']}\n";
		$killed++;
	}
}

$total = $killed + $survived;
$msi = $total > 0 ? (int) round(100 * $killed / $total) : 0;
echo "\nMutation score: {$killed}/{$total} killed ({$msi}%) errors={$errors}\n";
if ($survived > 0 || $errors > 0 || $msi < 75) {
	exit(1);
}
exit(0);

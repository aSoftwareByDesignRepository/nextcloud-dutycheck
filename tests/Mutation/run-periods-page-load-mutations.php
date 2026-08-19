<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Periods page GET must stay SQL-count cheap and the UI
 * must paint the period list without waiting on detail fan-out.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-periods-page-load-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'RosterServicePeriodsPageReadTest|PeriodsTemplateRenderTest|PublishStaleGateTest|PeriodsPageReadQueriesIntegrationTest';

function run_phpunit(string $appRoot, string $phpunit): int
{
	$compose = dirname($appRoot, 2) . '/docker-compose.yml';
	if (is_file($compose) && trim((string) shell_exec('command -v docker')) !== '') {
		$cmd = 'docker compose -f ' . escapeshellarg($compose)
			. ' exec -T -u www-data -w /var/www/html/custom_apps/dutycheck nextcloud php'
			. ' -d opcache.enable_cli=0 -d opcache.enable=0'
			. ' vendor/bin/phpunit -c phpunit.xml --cache-result-file=/tmp/dutycheck-phpunit.cache'
			. ' --filter ' . escapeshellarg(PHP_FILTER);
		passthru($cmd, $code);
		return (int) $code;
	}
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg(PHP_FILTER);
	passthru($cmd, $code);
	return (int) $code;
}

function run_node(string $node, string $appRoot): int
{
	$tests = [
		$appRoot . '/tests/js/language-locale-and-lightweight-reads.test.mjs',
		$appRoot . '/tests/js/network-error-nav-contracts.test.mjs',
	];
	$code = 0;
	foreach ($tests as $test) {
		passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $part);
		$code = max($code, (int) $part);
	}
	return $code;
}

echo "== baseline periods-page-load tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$roster = $appRoot . '/lib/Service/RosterService.php';
$periodsJs = $appRoot . '/js/periods.js';

$mutations = [
	'ack_stats_hydrates_assignments' => [
		'file' => $roster,
		'from' => '$total = $this->countPeriodAssignments($periodId, false);',
		'to' => '$total = count($this->listAssignments($periodId));',
		'php' => true,
		'js' => true,
	],
	'publish_readiness_hydrates_payloads' => [
		'file' => $roster,
		'from' => '$bySeverity = $this->countUnresolvedConflictsBySeverity($periodId);',
		'to' => '$bySeverity = $this->listPersistedConflicts($periodId);',
		'php' => true,
		'js' => true,
	],
	'publish_readiness_full_recompute' => [
		'file' => $roster,
		'from' => '$bySeverity = $this->countUnresolvedConflictsBySeverity($periodId);',
		'to' => '$bySeverity = $this->refreshAndListConflicts($periodId);',
		'php' => false,
		'js' => true,
	],
	'can_publish_ignores_hard' => [
		'file' => $roster,
		'from' => '$hard === 0 && !$staleBlocked',
		'to' => 'true && !$staleBlocked',
		'php' => true,
		'js' => false,
	],
	'resolved_conflicts_counted' => [
		'file' => $roster,
		'from' => "->andWhere(\$qb->expr()->eq('is_resolved', \$qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))\n\t\t\t->groupBy('severity');",
		'to' => "->andWhere(\$qb->expr()->eq('is_resolved', \$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))\n\t\t\t->groupBy('severity');",
		'php' => true,
		'js' => false,
	],
	'cancelled_included_in_ack_total' => [
		'file' => $roster,
		'from' => '$qb->expr()->neq(\'status\', $qb->createNamedParameter(\'cancelled\'))',
		'to' => '$qb->expr()->eq(\'status\', $qb->createNamedParameter(\'cancelled\'))',
		'php' => true,
		'js' => false,
	],
	'periods_awaits_details' => [
		'file' => $periodsJs,
		'from' => 'void loadPeriodDetails(currentPeriodId);',
		'to' => 'await loadPeriodDetails(currentPeriodId);',
		'php' => false,
		'js' => true,
	],
];

$failed = 0;
foreach ($mutations as $name => $mut) {
	$source = (string) file_get_contents($mut['file']);
	if (!str_contains($source, $mut['from'])) {
		fwrite(STDERR, "Mutation {$name}: anchor not found in {$mut['file']}\n");
		$failed++;
		continue;
	}
	$backup = $mut['file'] . '.mut.bak';
	copy($mut['file'], $backup);
	file_put_contents($mut['file'], str_replace($mut['from'], $mut['to'], $source, $count));
	if ($count < 1) {
		fwrite(STDERR, "Mutation {$name}: expected replacements, got {$count}\n");
		rename($backup, $mut['file']);
		$failed++;
		continue;
	}
	echo "== mutant {$name} (replacements={$count}) ==\n";
	$phpCode = $mut['php'] ? run_phpunit($appRoot, $phpunit) : 0;
	$jsCode = $mut['js'] ? run_node($node, $appRoot) : 0;
	rename($backup, $mut['file']);
	$killed = ($mut['php'] && $phpCode !== 0) || ($mut['js'] && $jsCode !== 0);
	if (!$killed) {
		fwrite(STDERR, "SURVIVED {$name}\n");
		$failed++;
	} else {
		echo "killed {$name}\n";
	}
}

if ($failed !== 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failed} mutant(s) survived or could not be applied\n");
	exit(1);
}

echo "All periods-page-load mutants killed.\n";
exit(0);

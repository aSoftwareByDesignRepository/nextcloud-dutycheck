<?php

declare(strict_types=1);

/**
 * Targeted mutation gauntlet for the dashboard summary / setup-state logic
 * (no Infection dependency required).
 *
 * Runs the dashboard unit tests, then applies known-bad source mutations to
 * RosterService::deriveSetupState() / dashboardSummary() and to the dashboard
 * API access gate, asserting that the suite fails for every mutant — proving
 * the tests catch broken readiness logic, count swaps, and missing access
 * control.
 *
 * Usage (from app root, inside Docker when applicable):
 *   php tests/Mutation/run-dashboard-setup-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

const TEST_FILTER = 'RosterServiceDashboardSummaryTest|DashboardCompanyScopeTest|DashboardTemplateRenderTest';

function run_unit_tests(string $appRoot, string $phpunit): int {
	$phpBin = 'php';
	// Disable CLI opcache so file mutations are visible to the next PHPUnit process.
	$cmd = escapeshellarg($phpBin)
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg(TEST_FILTER);
	passthru($cmd, $code);
	return (int)$code;
}

function restore_all(array $backups): void {
	foreach ($backups as $source => $backup) {
		if (is_file($backup)) {
			rename($backup, $source);
		}
	}
}

$rosterService = $appRoot . '/lib/Service/RosterService.php';
$rosterController = $appRoot . '/lib/Controller/RosterApiController.php';
foreach ([$rosterService, $rosterController] as $file) {
	if (!is_file($file)) {
		fwrite(STDERR, "Missing source file: {$file}\n");
		exit(1);
	}
}

echo "== baseline dashboard tests ==\n";
$baseline = run_unit_tests($appRoot, $phpunit);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/**
 * Each mutation: file, from, to. Anchored on exact source strings so a drifted
 * anchor fails loudly instead of silently testing nothing.
 */
$mutations = [
	'ready_ignores_schema' => [
		'file' => $rosterService,
		'from' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
		'to' => "'readyForPlanning' => \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
	],
	'ready_ignores_employees' => [
		'file' => $rosterService,
		'from' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
		'to' => "'readyForPlanning' => \$schemaReady && \$activeLocations > 0 && \$openPeriods > 0,",
	],
	'ready_ignores_locations' => [
		'file' => $rosterService,
		'from' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
		'to' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$openPeriods > 0,",
	],
	'ready_ignores_open_periods' => [
		'file' => $rosterService,
		'from' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
		'to' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0,",
	],
	'ready_disjunction_instead_of_conjunction' => [
		'file' => $rosterService,
		'from' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
		'to' => "'readyForPlanning' => \$schemaReady || \$activeEmployees > 0 || \$activeLocations > 0 || \$openPeriods > 0,",
	],
	'ready_off_by_one_boundary' => [
		'file' => $rosterService,
		'from' => "'readyForPlanning' => \$schemaReady && \$activeEmployees > 0 && \$activeLocations > 0 && \$openPeriods > 0,",
		'to' => "'readyForPlanning' => \$schemaReady && \$activeEmployees >= 0 && \$activeLocations >= 0 && \$openPeriods >= 0,",
	],
	'summary_counts_swapped' => [
		'file' => $rosterService,
		'from' => "\$openPeriods = \$this->countScoped('dc_periods', 'status', 'open', \$actorUserId);\n\t\t\$publishedPeriods = \$this->countScoped('dc_periods', 'status', 'published', \$actorUserId);",
		'to' => "\$openPeriods = \$this->countScoped('dc_periods', 'status', 'published', \$actorUserId);\n\t\t\$publishedPeriods = \$this->countScoped('dc_periods', 'status', 'open', \$actorUserId);",
	],
	'summary_counts_inactive_employees' => [
		'file' => $rosterService,
		'from' => "\$employees = \$this->countScoped('dc_employees', 'active', 1, \$actorUserId);",
		'to' => "\$employees = \$this->countScoped('dc_employees', 'active', 0, \$actorUserId);",
	],
	'summary_schema_ready_hardcoded' => [
		'file' => $rosterService,
		'from' => "\$schemaReady = \$this->isSchemaReady();",
		'to' => "\$schemaReady = true;",
	],
	'dashboard_access_gate_removed' => [
		'file' => $rosterController,
		'from' => "\$this->access->requirePlannerOrAdmin(\$this->access->currentUserId());\n\t\t\t\$userId = \$this->access->currentUserId();\n\t\t\treturn new DataResponse(['ok' => true, 'data' => \$this->roster->dashboardSummary(\$userId)]);",
		'to' => "\$userId = \$this->access->currentUserId();\n\t\t\treturn new DataResponse(['ok' => true, 'data' => \$this->roster->dashboardSummary(\$userId)]);",
	],
];

$failedToKill = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$source = $pair['file'];
	$backup = $source . '.mutation-bak';
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read source\n");
		exit(1);
	}
	if (!str_contains($original, $pair['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($pair['from'], $pair['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		$failedToKill[] = $name . ' (no effect)';
		restore_all([$source => $backup]);
		continue;
	}
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated source for {$name}\n");
		$failedToKill[] = $name . ' (write failed)';
		restore_all([$source => $backup]);
		continue;
	}
	$code = run_unit_tests($appRoot, $phpunit);
	restore_all([$source => $backup]);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

restore_all([
	$rosterService => $rosterService . '.mutation-bak',
	$rosterController => $rosterController . '.mutation-bak',
]);

if ($failedToKill !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll dashboard setup mutations killed.\n";
exit(0);

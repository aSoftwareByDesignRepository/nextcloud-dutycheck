<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: roster DOM virtualization must stay wired.
 * GET assignment lists stay unpaginated (SF-06).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-roster-virtualization-mutations.php
 */

require __DIR__ . '/mutation-lock.php';

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'RosterVirtualizationContractTest|RosterReadPathArchitectureContractTest';

function run_phpunit(string $appRoot, string $phpunit): int
{
	$compose = dirname($appRoot, 2) . '/docker-compose.yml';
	if (is_file($compose) && trim((string) shell_exec('command -v docker')) !== '') {
		$cmd = 'docker compose -f ' . escapeshellarg($compose)
			. ' exec -T -u www-data -w /var/www/html/custom_apps/dutycheck nextcloud php'
			. ' -d opcache.enable_cli=0 -d opcache.enable=0'
			. ' vendor/bin/phpunit -c phpunit.xml --do-not-cache-result'
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
	$test = $appRoot . '/tests/js/roster-virtualization.test.mjs';
	passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $code);
	return (int) $code;
}

echo "== baseline roster virtualization tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$virtual = $appRoot . '/js/common/virtual-window.js';
$rosterJs = $appRoot . '/js/roster.js';

$mutations = [
	'drop_overscan' => [
		'file' => $virtual,
		'from' => 'const start = Math.max(0, first - overscan);',
		'to' => 'const start = Math.max(0, first);',
		'php' => true,
		'js' => true,
	],
	'unsized_viewport_paints_nothing' => [
		'file' => $virtual,
		'from' => "if (viewportHeight <= 0) {\n\t\t\tfirst = Math.min(total - 1, Math.floor(scrollTop / rowHeight));\n\t\t\tvisible = UNSIZED_WINDOW_ROWS;\n\t\t}",
		'to' => "if (viewportHeight <= 0) {\n\t\t\tfirst = 0;\n\t\t\tvisible = 0;\n\t\t}",
		'php' => false,
		'js' => true,
	],
	'unsized_ignores_scrolltop' => [
		'file' => $virtual,
		'from' => "if (viewportHeight <= 0) {\n\t\t\tfirst = Math.min(total - 1, Math.floor(scrollTop / rowHeight));\n\t\t\tvisible = UNSIZED_WINDOW_ROWS;\n\t\t}",
		'to' => "if (viewportHeight <= 0) {\n\t\t\tfirst = 0;\n\t\t\tvisible = UNSIZED_WINDOW_ROWS;\n\t\t}",
		'php' => true,
		'js' => true,
	],
	'paint_all_ignored' => [
		'file' => $virtual,
		'from' => 'const paintAll = options.paintAll === true;',
		'to' => 'const paintAll = false;',
		'php' => true,
		'js' => true,
	],
	'grid_paints_every_employee' => [
		'file' => $rosterJs,
		'from' => 'const windowEmployees = employees.slice(range.start, range.end);',
		'to' => 'const windowEmployees = employees;',
		'php' => true,
		'js' => true,
	],
	'list_paints_every_assignment' => [
		'file' => $rosterJs,
		'from' => 'const windowRows = assignments.slice(range.start, range.end);',
		'to' => 'const windowRows = assignments;',
		'php' => true,
		'js' => true,
	],
	'skip_print_expand' => [
		'file' => $rosterJs,
		'from' => "window.addEventListener('beforeprint', () => setRosterPaintAll(true));",
		'to' => "window.addEventListener('beforeprint', () => {});",
		'php' => true,
		'js' => true,
	],
	'skip_print_matchmedia' => [
		'file' => $rosterJs,
		'from' => "const printMq = window.matchMedia('print');",
		'to' => "const printMq = { addEventListener() {}, addListener() {} };",
		'php' => true,
		'js' => true,
	],
	'reveal_fights_view_switch' => [
		'file' => $rosterJs,
		'from' => "applyRosterViewChrome('list');",
		'to' => "setRosterView('list');",
		'php' => true,
		'js' => true,
	],
	'skip_horizontal_reveal' => [
		'file' => $rosterJs,
		'from' => "inline: 'nearest',",
		'to' => "inline: 'start',",
		'php' => true,
		'js' => true,
	],
	'skip_page_keys' => [
		'file' => $rosterJs,
		'from' => "const gridKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', ' ', 'Spacebar', 'Home', 'End', 'PageUp', 'PageDown'];",
		'to' => "const gridKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', ' ', 'Spacebar'];",
		'php' => true,
		'js' => true,
	],
	'skip_scroller_tabindex' => [
		'file' => $appRoot . '/templates/roster.php',
		'from' => 'id="dc-roster-grid-scroller" class="dc-roster-grid-scroller" tabindex="0"',
		'to' => 'id="dc-roster-grid-scroller" class="dc-roster-grid-scroller"',
		'php' => true,
		'js' => true,
	],
	'fallback_paints_nothing' => [
		'file' => $rosterJs,
		'from' => "end: total,",
		'to' => "end: 0,",
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
	if ($count !== 1) {
		fwrite(STDERR, "Mutation {$name}: expected 1 replacement, got {$count}\n");
		rename($backup, $mut['file']);
		$failed++;
		continue;
	}
	echo "== mutant {$name} (must FAIL) ==\n";
	$phpCode = $mut['php'] ? run_phpunit($appRoot, $phpunit) : 0;
	$jsCode = $mut['js'] ? run_node($node, $appRoot) : 0;
	rename($backup, $mut['file']);
	$killed = ($mut['php'] && $phpCode !== 0) || ($mut['js'] && $jsCode !== 0);
	if (!$killed) {
		fwrite(STDERR, "Mutation {$name} SURVIVED\n");
		$failed++;
	} else {
		echo "killed {$name}\n";
	}
}

if ($failed > 0) {
	fwrite(STDERR, "Roster virtualization mutation gauntlet FAILED ({$failed})\n");
	exit(1);
}
echo 'Roster virtualization mutation gauntlet OK (' . count($mutations) . " mutants killed).\n";
exit(0);

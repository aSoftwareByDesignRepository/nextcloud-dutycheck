<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: app-wide first-paint (CSRF, conflict-labels, dashboard SSR,
 * assignment modal cache, settings section gate, catalog windowing).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-first-paint-mutations.php
 */

require __DIR__ . '/mutation-lock.php';

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'FirstPaintContractTest|DashboardTemplateRenderTest|CiWorkflowContractTest';

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
	$tests = [
		$appRoot . '/tests/js/first-paint.test.mjs',
		$appRoot . '/tests/js/language-locale-and-lightweight-reads.test.mjs',
		$appRoot . '/tests/js/dashboard-setup-progress.test.mjs',
		$appRoot . '/tests/js/network-error-nav-contracts.test.mjs',
	];
	$code = 0;
	foreach ($tests as $test) {
		passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $part);
		if ((int) $part !== 0) {
			$code = (int) $part;
		}
	}
	return $code;
}

echo "== baseline first-paint tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$session = $appRoot . '/js/common/session.js';
$page = $appRoot . '/lib/Controller/PageController.php';
$dashboardJs = $appRoot . '/js/dashboard.js';
$rosterJs = $appRoot . '/js/roster.js';
$settingsJs = $appRoot . '/js/settings.js';
$windowed = $appRoot . '/js/common/windowed-table.js';

$mutations = [
	'eager_csrf_prefetch' => [
		'file' => $session,
		'from' => 'csrfPrefetch: false,',
		'to' => 'csrfPrefetch: true,',
		'php' => true,
		'js' => true,
	],
	'conflict_labels_on_every_page' => [
		'file' => $page,
		'from' => "if (in_array(\$template, ['dashboard', 'roster', 'periods'], true)) {\n\t\t\tUtil::addScript(Application::APP_ID, 'common/conflict-labels');\n\t\t}",
		'to' => "Util::addScript(Application::APP_ID, 'common/conflict-labels');",
		'php' => true,
		'js' => true,
	],
	'dashboard_skips_ssr_extras' => [
		'file' => $page,
		'from' => "\$this->dashboardPageExtras(),",
		'to' => "[],",
		'php' => true,
		'js' => false,
	],
	'dashboard_always_hits_api' => [
		'file' => $dashboardJs,
		'from' => "const ssr = readSsrSummary();\n\t\tif (ssr) {\n\t\t\tapplySummaryData(ssr);\n\t\t\treturn Promise.resolve();\n\t\t}\n\t\treturn loadSummary();",
		'to' => "return loadSummary();",
		'php' => false,
		'js' => true,
	],
	'modal_prereqs_sequential' => [
		'file' => $rosterJs,
		'from' => "const jobs = [loadTemplates()];\n\t\tif (state.planningDefaultsFresh !== true) {\n\t\t\tjobs.push(refreshPlanningDefaultFromServer());\n\t\t}\n\t\tawait Promise.all(jobs);",
		'to' => "await refreshPlanningDefaultFromServer();\n\t\tawait loadTemplates();",
		'php' => true,
		'js' => true,
	],
	'templates_never_cached' => [
		'file' => $rosterJs,
		'from' => 'if (state.templatesLoaded) {',
		'to' => 'if (false && state.templatesLoaded) {',
		'php' => true,
		'js' => true,
	],
	'settings_wires_every_section' => [
		'file' => $settingsJs,
		'from' => "const wire = SECTION_WIRES[section];\n\t\tif (typeof wire === 'function') {\n\t\t\tawait wire();\n\t\t}",
		'to' => "for (const w of Object.values(SECTION_WIRES)) {\n\t\t\tawait w();\n\t\t}",
		'php' => true,
		'js' => true,
	],
	'windowed_table_always_paint_all' => [
		'file' => $windowed,
		'from' => 'paintAll: paintAll === true || list.length <= threshold,',
		'to' => 'paintAll: true,',
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

echo "All first-paint mutants killed.\n";
exit(0);

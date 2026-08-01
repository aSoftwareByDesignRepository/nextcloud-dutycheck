<?php

declare(strict_types=1);

/**
 * Targeted mutation gauntlet for app-wide theming + CAS / HTTP mapping fixes.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-appwide-theme-cas-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

const TEST_FILTER = 'DesignSystemCssContractTest|ApiJsonErrorResponseTest|RosterServiceTransitionAbsenceTest|RosterServiceCasContractTest|RosterWaveContractTest|SettingsTemplateRenderTest|DashboardTemplateRenderTest';

function run_unit_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg(TEST_FILTER);
	passthru($cmd, $code);
	return (int) $code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

$css = $appRoot . '/css/app.css';
$apiErr = $appRoot . '/lib/Controller/ApiJsonErrorResponse.php';
$roster = $appRoot . '/lib/Service/RosterService.php';

echo "== baseline ==\n";
if (run_unit_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	'muted_reverts_to_transparent_mix' => [
		'file' => $css,
		'from' => "--dc-muted: var(\n\t\t--color-text-maxcontrast,\n\t\tcolor-mix(in srgb, var(--color-main-text, #1d1d1d) 67%, var(--color-main-background, #fff))\n\t);",
		'to' => "--dc-muted: color-mix(in srgb, var(--color-main-text, #1d1d1d) 72%, transparent);",
	],
	'scope_strip_loses_core_dt_reset' => [
		'file' => $css,
		'from' => ".dc-app .dc-scope-strip dt,\n.dc-app .dc-scope-strip dd,\n.dc-app .dc-scope-strip__label,\n.dc-app .dc-scope-strip__value {\n\tdisplay: block;\n\tfloat: none;\n\twidth: auto;\n\tmax-width: 100%;\n\tpadding: 0;\n\tmargin: 0;\n\ttext-align: start;\n\twhite-space: normal;\n}",
		'to' => ".dc-app .dc-scope-strip dt,\n.dc-app .dc-scope-strip dd,\n.dc-app .dc-scope-strip__label,\n.dc-app .dc-scope-strip__value {\n\tdisplay: inline-block;\n\tfloat: none;\n\twidth: 130px;\n\tmax-width: 100%;\n\tpadding: 12px;\n\tmargin: 0;\n\ttext-align: end;\n\twhite-space: nowrap;\n}",
	],
	'button_text_collapses_hit_target' => [
		'file' => $css,
		'from' => "\tmin-height: 44px;\n\tmin-width: 44px;\n\tpadding: var(--dc-space-2) var(--dc-space-3);\n\tborder: 0;\n\tbackground: transparent;\n\tcolor: var(--color-primary-element, var(--color-main-text));",
		'to' => "\tmin-height: 0;\n\tmin-width: 0;\n\tpadding: 0;\n\tborder: 0;\n\tbackground: transparent;\n\tcolor: var(--color-primary-element, var(--color-main-text));",
	],
	'entity_focus_ring_removed' => [
		'file' => $css,
		'from' => ".dc-entity-results li:focus-visible {\n\tbackground: var(--dc-tint-info);\n\toutline: 3px solid color-mix(in srgb, var(--color-primary-element, #0082c9) 60%, transparent);\n\toutline-offset: -3px;\n}",
		'to' => ".dc-entity-results li:focus-visible {\n\tbackground: var(--dc-tint-info);\n\toutline: none;\n}",
	],
	'company_mismatch_maps_to_400' => [
		'file' => $apiErr,
		'from' => "'FORBIDDEN', 'COMPANY_MISMATCH' => 403,",
		'to' => "'FORBIDDEN' => 403,",
	],
	'period_cas_removed' => [
		'file' => $roster,
		'from' => "->andWhere(\$qb->expr()->eq('status', \$qb->createNamedParameter(\$current)));",
		'to' => ";",
	],
	'absence_cas_removed' => [
		'file' => $roster,
		'from' => "->andWhere(\$qb->expr()->eq('status', \$qb->createNamedParameter((string) \$current['status'])));",
		'to' => ";",
	],
];

$failed = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$source = $pair['file'];
	$backup = $source . '.mutation-bak';
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $pair['from'])) {
		$failed[] = $name . ' (anchor missing)';
		fwrite(STDERR, "Anchor missing for {$name}\n");
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($pair['from'], $pair['to'], $original);
	file_put_contents($source, $mutated);
	$code = run_unit_tests($appRoot, $phpunit);
	restore($source, $backup);
	if ($code === 0) {
		$failed[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

foreach ([$css, $apiErr, $roster] as $f) {
	restore($f, $f . '.mutation-bak');
}

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll app-wide theme/CAS mutations killed.\n";
exit(0);

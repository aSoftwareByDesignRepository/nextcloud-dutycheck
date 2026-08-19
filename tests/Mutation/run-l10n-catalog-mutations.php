<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: l10n catalogs stay complete, JSON/JS stay in sync,
 * placeholders survive, and curated chrome strings stay translated.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-l10n-catalog-mutations.php
 */

require __DIR__ . '/mutation-lock.php';

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'L10nCatalogIntegrityTest|CiWorkflowContractTest|PeriodTranslationSenseTest';

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
	$test = $appRoot . '/tests/js/l10n-catalog.test.mjs';
	passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $code);
	return (int) $code;
}

echo "== baseline l10n catalog tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$deJson = $appRoot . '/l10n/de.json';
$deJs = $appRoot . '/l10n/de.js';
$frJson = $appRoot . '/l10n/fr.json';
$placeholders = $appRoot . '/scripts/check-l10n-placeholders.php';
$ciYml = $appRoot . '/.github/workflows/ci.yml';

$mutations = [
	'drop_german_window_key' => [
		'file' => $deJson,
		'from' => "        \"All {total} rows are on screen.\": \"Alle {total} Zeilen sind sichtbar.\",\n",
		'to' => '',
		'php' => true,
		'js' => true,
	],
	'strip_named_placeholder' => [
		'file' => $deJson,
		'from' => 'Zeilen {from}–{to} von {total}. Scrollen Sie, um den Rest zu sehen.',
		'to' => 'Zeilen {from}–{to} von. Scrollen Sie, um den Rest zu sehen.',
		'php' => true,
		'js' => false,
	],
	'desync_js_catalog' => [
		'file' => $deJs,
		'from' => '"All {total} rows are on screen." : "Alle {total} Zeilen sind sichtbar."',
		'to' => '"All {total} rows are on screen." : "STALE_JS_VALUE"',
		'php' => true,
		'js' => true,
	],
	'french_identity_status' => [
		'file' => $frJson,
		'from' => '"All {total} rows are on screen.": "Les {total} lignes sont toutes affichées."',
		'to' => '"All {total} rows are on screen.": "All {total} rows are on screen."',
		'php' => true,
		'js' => true,
	],
	'placeholders_omit_pt_br' => [
		'file' => $placeholders,
		'from' => "function dcCatalogLocales(string \$base): array\n{\n\t\$out = [];\n\tforeach (glob(\$base . '/*.json') ?: [] as \$path) {",
		'to' => "function dcCatalogLocales(string \$base): array\n{\n\treturn ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb'];\n\t\$out = [];\n\tforeach (glob(\$base . '/*.json') ?: [] as \$path) {",
		'php' => true,
		'js' => false,
	],
	'ci_drops_placeholder_gate' => [
		'file' => $ciYml,
		'from' => "      - run: php scripts/check-l10n-placeholders.php\n      - run: php tests/Mutation/run-hardening-followup-mutations.php",
		'to' => "      - run: php tests/Mutation/run-hardening-followup-mutations.php",
		'php' => true,
		'js' => false,
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

echo "All l10n catalog mutants killed.\n";
exit(0);

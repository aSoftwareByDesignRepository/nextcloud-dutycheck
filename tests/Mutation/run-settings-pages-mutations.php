<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the split settings sub-pages (no Infection required).
 *
 * Applies known-bad mutations to SettingsSectionCatalog, the settings.php
 * dispatcher, settings.js, and settings-legacy-redirect.js, then asserts the
 * dedicated suites fail — proving the tests catch real drift, not just lines.
 *
 * Kill strategies per mutation:
 *  - php:  PHPUnit (SettingsSectionCatalogTest|SettingsPagesContractTest|SettingsTemplateRenderTest)
 *  - node: node --test tests/js/settings-pages.test.mjs
 *  - both: killed if either suite fails
 *
 * Usage (from app root):
 *   php tests/Mutation/run-settings-pages-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'SettingsSectionCatalogTest|SettingsPagesContractTest|SettingsTemplateRenderTest';

function run_php_tests(string $appRoot, string $filter): int {
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	// Host PHP cannot reach MariaDB socket; prefer Docker when available.
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		passthru(escapeshellarg($dockerRunner) . ' dutycheck --filter ' . escapeshellarg($filter), $code);
		return (int) $code;
	}
	$phpunit = $appRoot . '/vendor/bin/phpunit';
	if (!is_file($phpunit)) {
		$phpunit = 'phpunit';
	}
	// Disable CLI opcache so file mutations are visible to the next PHPUnit process.
	passthru(
		'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter),
		$code,
	);
	return (int) $code;
}

function run_node_tests(string $appRoot): int {
	passthru('cd ' . escapeshellarg($appRoot) . ' && node --test tests/js/settings-pages.test.mjs', $code);
	return (int) $code;
}

/**
 * @return bool true when the mutation was killed (at least one suite failed)
 */
function mutation_killed(string $strategy, string $appRoot, string $phpFilter): bool {
	if ($strategy === 'node' || $strategy === 'both') {
		if (run_node_tests($appRoot) !== 0) {
			return true;
		}
		if ($strategy === 'node') {
			return false;
		}
	}
	return run_php_tests($appRoot, $phpFilter) !== 0;
}

$mutations = [
	'catalog_default_to_license' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "public const DEFAULT_SECTION = 'access';",
		'to' => "public const DEFAULT_SECTION = 'license';",
		'kill' => 'php',
	],
	'catalog_drop_qualifications' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "\t\t'qualifications',\n",
		'to' => '',
		'kill' => 'php',
	],
	'catalog_requirement_comma_glue' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "return implode('|', self::SECTIONS);",
		'to' => "return implode(',', self::SECTIONS);",
		'kill' => 'php',
	],
	'catalog_retarget_quals_anchor' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "'dc-settings-quals' => 'qualifications',",
		'to' => "'dc-settings-quals' => 'access',",
		'kill' => 'php',
	],
	'catalog_isSection_always_true' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => 'return in_array($section, self::SECTIONS, true);',
		'to' => 'return true;',
		'kill' => 'php',
	],
	'catalog_label_access_generic' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "'access' => \$l->t('Access control'),",
		'to' => "'access' => \$l->t('Settings'),",
		'kill' => 'php',
	],
	'catalog_nav_label_access_long' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "'access' => \$l->t('Access'),",
		'to' => "'access' => \$l->t('Access control'),",
		'kill' => 'php',
	],
	'catalog_label_untranslated' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "'privacy' => \$l->t('Privacy & words we use'),",
		'to' => "'privacy' => 'Privacy & words we use',",
		'kill' => 'php',
	],
	'catalog_help_blank_privacy' => [
		'file' => 'lib/Service/SettingsSectionCatalog.php',
		'from' => "\t\t\t'privacy' => \$l->t('How DutyCheck treats personal data, and the plain-language terms used in this app.'),\n",
		'to' => '',
		'kill' => 'php',
	],
	'dispatcher_fail_closed_removed' => [
		'file' => 'templates/settings.php',
		'from' => "if (!isset(\$dcSettingsSectionFiles[\$dcRequestedSection])) {\n\t\tthrow new \\RuntimeException('DutyCheck settings: unknown section reached the template dispatcher.');\n\t}\n\tinclude __DIR__ . '/parts/settings/' . \$dcSettingsSectionFiles[\$dcRequestedSection];",
		'to' => "include __DIR__ . '/parts/settings/' . (\$dcSettingsSectionFiles[\$dcRequestedSection] ?? \$dcSettingsSectionFiles['license']);",
		'kill' => 'php',
	],
	'dispatcher_drop_inpage_nav' => [
		'file' => 'templates/settings.php',
		'from' => "include __DIR__ . '/parts/settings-nav.php';\n\t",
		'to' => '',
		'kill' => 'php',
	],
	'settingsjs_keep_wiring_after_redirect' => [
		'file' => 'js/settings.js',
		'from' => "window.location.replace(redirectUrl);\n\t\t\t\treturn;",
		'to' => 'window.location.replace(redirectUrl);',
		'kill' => 'php',
	],
	'redirectjs_forward_same_section' => [
		'file' => 'js/settings-legacy-redirect.js',
		'from' => "if (currentSection === '' || currentSection === targetSection) {",
		'to' => "if (currentSection === '') {",
		'kill' => 'node',
	],
	'redirectjs_drop_fragment' => [
		'file' => 'js/settings-legacy-redirect.js',
		'from' => "return sectionUrl + '#' + hash;",
		'to' => 'return sectionUrl;',
		'kill' => 'node',
	],
	'redirectjs_forward_outside_settings' => [
		'file' => 'js/settings-legacy-redirect.js',
		'from' => "const currentSection = String(rootEl.getAttribute('data-dc-settings-section') || '');",
		'to' => "const currentSection = String(rootEl.getAttribute('data-dc-settings-section') || 'x');",
		'kill' => 'node',
	],
	'redirectjs_retarget_quals' => [
		'file' => 'js/settings-legacy-redirect.js',
		'from' => "'dc-settings-quals': 'qualifications',",
		'to' => "'dc-settings-quals': 'access',",
		'kill' => 'both',
	],
	'redirectjs_unfreeze_map' => [
		'file' => 'js/settings-legacy-redirect.js',
		'from' => 'const ANCHOR_SECTIONS = Object.freeze({',
		'to' => 'const ANCHOR_SECTIONS = ({',
		'kill' => 'both',
	],
];

echo "== baseline (php + node) ==\n";
if (run_node_tests($appRoot) !== 0 || run_php_tests($appRoot, $phpFilter) !== 0) {
	fwrite(STDERR, "Baseline suites must pass before the mutation run\n");
	exit(1);
}

$failedToKill = [];
foreach ($mutations as $name => $mutation) {
	echo "\n== mutation: {$name} ==\n";
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutation-bak';
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$mutation['file']}\n");
		exit(1);
	}
	if (!str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		$failedToKill[] = $name . ' (no effect)';
		continue;
	}
	file_put_contents($backup, $original);
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated {$mutation['file']}\n");
		$failedToKill[] = $name . ' (write failed)';
		@unlink($backup);
		continue;
	}
	try {
		$killed = mutation_killed($mutation['kill'], $appRoot, $phpFilter);
	} finally {
		rename($backup, $source);
	}
	if ($killed) {
		echo "killed {$name}\n";
	} else {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll settings-pages mutations killed.\n";
exit(0);

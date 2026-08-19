#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style and named placeholders
 * as their msgid. Named placeholders like {from} are required in the same
 * order as the msgid so status lines stay unambiguous.
 *
 * Discovers every shipped catalog (en, de, pt_BR, de_DE, …) and skips
 * tooling JSON (_quality_fixes_*, *_dict, _runtime_translations).
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';

/**
 * @return list<string>
 */
function dcCatalogLocales(string $base): array
{
	$out = [];
	foreach (glob($base . '/*.json') ?: [] as $path) {
		$name = basename($path, '.json');
		if ($name === '' || $name[0] === '_') {
			continue;
		}
		if (str_ends_with($name, '_dict') || $name === 'formal_scandinavian_data') {
			continue;
		}
		$out[] = $name;
	}
	sort($out);

	return $out;
}

/**
 * @return list<string>
 */
function dcPrintfPlaceholders(string $s): array
{
	preg_match_all('/%%|%(?:\d+\$)?[sd]/', $s, $m);

	return $m[0];
}

/**
 * @return list<string>
 */
function dcNamedPlaceholders(string $s): array
{
	preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);

	return $m[0];
}

$localeFiles = dcCatalogLocales($base);
if ($localeFiles === []) {
	fwrite(STDERR, "No l10n catalogs found in {$base}\n");
	exit(1);
}
if (!in_array('en', $localeFiles, true) || !in_array('pt_BR', $localeFiles, true)) {
	fwrite(STDERR, "Required catalogs missing (need en and pt_BR). Found: " . implode(', ', $localeFiles) . "\n");
	exit(1);
}

$catalogs = [];
foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	$catalogs[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enT = $catalogs['en']['translations'] ?? [];
$failed = false;

foreach ($enT as $key => $enVal) {
	$keyPrintf = dcPrintfPlaceholders($key);
	$keyNamed = dcNamedPlaceholders($key);

	foreach ($localeFiles as $lang) {
		$langT = $catalogs[$lang]['translations'] ?? [];
		if (!isset($langT[$key])) {
			continue;
		}
		$val = (string) $langT[$key];

		$valPrintf = dcPrintfPlaceholders($val);
		if ($keyPrintf !== $valPrintf) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json printf placeholder mismatch for key: {$key}\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyPrintf) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valPrintf) . "\n");
		}

		$valNamed = dcNamedPlaceholders($val);
		if ($keyNamed !== $valNamed) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json named placeholder mismatch for key: {$key}\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyNamed) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valNamed) . "\n");
		}
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo 'l10n placeholder check OK (' . implode('/', $localeFiles) . " printf and named placeholders match msgids).\n";
exit(0);

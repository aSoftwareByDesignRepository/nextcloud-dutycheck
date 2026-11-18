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

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(1);
}

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
	preg_match_all('/%%|%(?:\d+\$)?[sd]|%/', $s, $m);

	return $m[0];
}

/**
 * Token multiset match for printf placeholders. A bare '%' in the msgid may be
 * carried as '%' or '%%' in the msgstr: '%%' is the vsprintf-safe form the
 * fleet quality sweep requires in .json catalogs, and the JS call sites
 * unescape it (.replace(/%%/g, '%')). A '%%' in the msgid must stay '%%'.
 */
function dcPrintfTokensMatch(array $expected, array $actual): bool
{
	$norm = static fn (string $t): string => $t === '%%' ? '%' : $t;
	if (array_count_values(array_map($norm, $expected)) != array_count_values(array_map($norm, $actual))) {
		return false;
	}
	return count(array_filter($actual, static fn (string $t): bool => $t === '%%'))
		>= count(array_filter($expected, static fn (string $t): bool => $t === '%%'));
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

/**
 * Combined plural keys "_sg_::_pl_" carry a [sg, pl(, many)] value array.
 * Each cell is checked against its own msgid form; extra cells (e.g. a third
 * "many" form for nplurals=3 locales) compare against the plural form.
 *
 * @return list<string>|null msgid forms, or null for plain msgids
 */
function dcPluralForms(string $key): ?array
{
	if (!str_starts_with($key, '_') || !str_ends_with($key, '_') || !str_contains($key, '_::_')) {
		return null;
	}
	return explode('_::_', substr($key, 1, -1));
}

foreach ($enT as $key => $enVal) {
	$forms = dcPluralForms($key);

	foreach ($localeFiles as $lang) {
		$langT = $catalogs[$lang]['translations'] ?? [];
		if (!isset($langT[$key])) {
			continue;
		}
		$raw = $langT[$key];

		if ($forms === null) {
			if (!is_string($raw)) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json array value under non-plural key: {$key}\n");
				continue;
			}
			$cells = [$raw];
			$cellKeys = [$key];
		} else {
			if (!is_array($raw)) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json non-array value under plural key: {$key}\n");
				continue;
			}
			$cells = array_values($raw);
			$cellKeys = [];
			$singleForm = count($cells) === 1;
			foreach ($cells as $i => $_cell) {
				// nplurals=1 locales (tr/zh_*) ship one all-count cell selected
				// for every count — it answers the plural msgid side (the %n
				// form), never the literal-'1' singular side.
				$cellKeys[] = $singleForm ? $forms[count($forms) - 1] : $forms[min($i, count($forms) - 1)];
			}
		}

		foreach ($cells as $i => $val) {
			if (!is_string($val)) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json non-string plural cell for key: {$key}\n");
				continue;
			}
			$keyPrintf = dcPrintfPlaceholders($cellKeys[$i]);
			$keyNamed = dcNamedPlaceholders($cellKeys[$i]);

			$valPrintf = dcPrintfPlaceholders($val);
			if (!dcPrintfTokensMatch($keyPrintf, $valPrintf)) {
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
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo 'l10n placeholder check OK (' . implode('/', $localeFiles) . " printf and named placeholders match msgids).\n";
exit(0);

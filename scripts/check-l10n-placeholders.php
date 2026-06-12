<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style placeholders as their msgid.
 * Named placeholders like {project} are ignored (Nextcloud notifications / JS tPl).
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb'];

/**
 * @return list<string>
 */
function dcPrintfPlaceholders(string $s): array {
	preg_match_all('/%%|%(?:\d+\$)?[sd]/', $s, $m);

	return $m[0];
}

/**
 * @return list<string>
 */
function dcNamedPlaceholders(string $s): array {
	preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);

	return $m[0];
}

$catalogs = [];
foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
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
			fwrite(STDERR, "{$lang}.json printf placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyPrintf) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valPrintf) . "\n");
		}

		$valNamed = dcNamedPlaceholders($val);
		if ($keyNamed !== $valNamed) {
			$failed = true;
			fwrite(STDERR, "${lang}.json named placeholder mismatch for key: $key\n");
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

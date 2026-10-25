#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate l10n/*.js from l10n/*.json (Nextcloud OC.L10N.register format).
 *
 * CSP (Nextcloud ≥26): register() must receive only (appId, translations).
 * Do NOT pass pluralForm as a third argument — legacy shims compile it with
 * new Function(), which violates strict script-src (nonce) without unsafe-eval.
 * Plural rules for t()/n() come from @nextcloud/l10n getPlural() instead.
 *
 * Usage: php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';
$locales = [];
foreach (glob($base . '/*.json') ?: [] as $jsonFile) {
	$locales[] = basename($jsonFile, '.json');
}
sort($locales);

foreach ($locales as $lang) {
	$jsonPath = $base . '/' . $lang . '.json';
	$jsPath = $base . '/' . $lang . '.js';
	if (!is_file($jsonPath)) {
		fwrite(STDERR, "Missing: $jsonPath\n");
		exit(1);
	}
	$cat = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
	$translations = $cat['translations'] ?? [];

	$lines = ["OC.L10N.register(\n", "\t\"dutycheck\",\n", "\t{\n"];
	foreach ($translations as $key => $val) {
		$k = json_encode($key, JSON_UNESCAPED_UNICODE);
		if (is_array($val)) {
			$v = json_encode($val, JSON_UNESCAPED_UNICODE);
		} else {
			$v = json_encode((string)$val, JSON_UNESCAPED_UNICODE);
		}
		$lines[] = "\t" . $k . ' : ' . $v . ",\n";
	}
	$last = array_pop($lines);
	$last = rtrim($last, ",\n") . "\n";
	$lines[] = $last;
	$lines[] = "\t}\n";
	$lines[] = ");\n";
	file_put_contents($jsPath, implode('', $lines));
	echo "Wrote $jsPath (" . count($translations) . " keys)\n";
}

echo "l10n JS regeneration OK.\n";

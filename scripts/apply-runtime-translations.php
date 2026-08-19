#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Overwrite existing catalog keys from l10n/_runtime_translations.json.
 *
 * sync-l10n-from-runtime.php only inserts *missing* keys (English padding).
 * This script is the SSOT apply path for curated translations that must
 * replace identity leftovers (and then re-mirror regional variants).
 *
 * Usage (from app root):
 *   php scripts/apply-runtime-translations.php
 *   php scripts/apply-runtime-translations.php --translations=l10n/_runtime_translations.json
 *   php scripts/apply-runtime-translations.php --dry-run
 */

$appRoot = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$translationsPath = $appRoot . '/l10n/_runtime_translations.json';
foreach ($argv ?? [] as $arg) {
	if (str_starts_with($arg, '--translations=')) {
		$rel = substr($arg, strlen('--translations='));
		$translationsPath = str_starts_with($rel, '/') ? $rel : $appRoot . '/' . $rel;
	}
}

if (!is_file($translationsPath)) {
	fwrite(STDERR, "Translations file not found: {$translationsPath}\n");
	exit(1);
}

/** @var array<string, array<string, string>> $overrides */
$overrides = json_decode((string) file_get_contents($translationsPath), true, 512, JSON_THROW_ON_ERROR);

$allowed = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];
$variants = [
	'da_DK' => 'da',
	'de_DE' => 'de',
	'es_ES' => 'es',
	'fr_FR' => 'fr',
	'it_IT' => 'it',
	'nb_NO' => 'nb',
	'nl_NL' => 'nl',
	'pl_PL' => 'pl',
	'sv_SE' => 'sv',
];

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

function dcPlaceholdersMatch(string $msgid, string $msgstr): bool
{
	return dcPrintfPlaceholders($msgid) === dcPrintfPlaceholders($msgstr)
		&& dcNamedPlaceholders($msgid) === dcNamedPlaceholders($msgstr);
}

function dcWriteJson(string $path, array $data): void
{
	$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
	$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
	if (file_put_contents($tmp, $encoded) === false) {
		fwrite(STDERR, "Could not write {$tmp}\n");
		exit(1);
	}
	if (!rename($tmp, $path)) {
		@unlink($tmp);
		fwrite(STDERR, "Could not replace {$path}\n");
		exit(1);
	}
}

$pending = [];
foreach ($overrides as $lang => $pairs) {
	if (!is_string($lang) || !is_array($pairs)) {
		fwrite(STDERR, "Invalid override block for locale key.\n");
		exit(1);
	}
	if ($lang === 'en') {
		fwrite(STDERR, "Refusing to overwrite en.json from runtime translations (English msgid is the source).\n");
		exit(1);
	}
	if (!in_array($lang, $allowed, true)) {
		fwrite(STDERR, "Unknown locale in runtime translations: {$lang}\n");
		exit(1);
	}
	$path = $appRoot . '/l10n/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: {$path}\n");
		exit(1);
	}
	$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$trans = $data['translations'] ?? [];
	foreach ($pairs as $msgid => $msgstr) {
		if (!is_string($msgid) || !is_string($msgstr) || $msgstr === '') {
			fwrite(STDERR, "{$lang}: invalid msgid/msgstr pair.\n");
			exit(1);
		}
		if (!array_key_exists($msgid, $trans)) {
			fwrite(STDERR, "{$lang}.json missing key (run sync first): {$msgid}\n");
			exit(1);
		}
		if (!dcPlaceholdersMatch($msgid, $msgstr)) {
			fwrite(STDERR, "{$lang}.json placeholder mismatch for: {$msgid}\n");
			exit(1);
		}
		$trans[$msgid] = $msgstr;
	}
	$pending[$lang] = ['path' => $path, 'data' => $data, 'trans' => $trans];
}

$totalChanged = 0;
foreach ($pending as $lang => $item) {
	$original = json_decode((string) file_get_contents($item['path']), true, 512, JSON_THROW_ON_ERROR);
	$before = $original['translations'] ?? [];
	$changed = 0;
	foreach ($item['trans'] as $msgid => $msgstr) {
		if (($before[$msgid] ?? null) !== $msgstr) {
			$changed++;
		}
	}
	echo ($dryRun ? '[dry-run] ' : '') . "{$lang}: {$changed} key(s) updated\n";
	$totalChanged += $changed;
	if ($changed === 0 || $dryRun) {
		continue;
	}
	ksort($item['trans'], SORT_STRING);
	$item['data']['translations'] = $item['trans'];
	dcWriteJson($item['path'], $item['data']);
}

foreach ($variants as $variant => $baseLang) {
	$variantPath = $appRoot . '/l10n/' . $variant . '.json';
	$basePath = $appRoot . '/l10n/' . $baseLang . '.json';
	if (!is_file($variantPath) || !is_file($basePath)) {
		continue;
	}
	if (!isset($overrides[$baseLang]) || !is_array($overrides[$baseLang])) {
		continue;
	}
	$variantData = json_decode((string) file_get_contents($variantPath), true, 512, JSON_THROW_ON_ERROR);
	$baseData = json_decode((string) file_get_contents($basePath), true, 512, JSON_THROW_ON_ERROR);
	$variantTrans = $variantData['translations'] ?? [];
	$baseTrans = $baseData['translations'] ?? [];
	$changed = 0;
	foreach (array_keys($overrides[$baseLang]) as $msgid) {
		if (!array_key_exists($msgid, $baseTrans)) {
			fwrite(STDERR, "{$baseLang}.json missing key while mirroring {$variant}: {$msgid}\n");
			exit(1);
		}
		if (!array_key_exists($msgid, $variantTrans)) {
			fwrite(STDERR, "{$variant}.json missing key (run sync first): {$msgid}\n");
			exit(1);
		}
		if ($variantTrans[$msgid] === $baseTrans[$msgid]) {
			continue;
		}
		$variantTrans[$msgid] = $baseTrans[$msgid];
		$changed++;
	}
	echo ($dryRun ? '[dry-run] ' : '') . "{$variant} <- {$baseLang}: {$changed} key(s) updated\n";
	$totalChanged += $changed;
	if ($changed === 0 || $dryRun) {
		continue;
	}
	ksort($variantTrans, SORT_STRING);
	$variantData['translations'] = $variantTrans;
	dcWriteJson($variantPath, $variantData);
}

echo "apply-runtime-translations OK ({$totalChanged} replacements" . ($dryRun ? ', dry-run' : '') . ").\n";
echo "Run: php scripts/regenerate-l10n-js.php && php scripts/check-l10n-runtime.php --all && php scripts/check-l10n-parity.php && php scripts/check-l10n-placeholders.php\n";
exit(0);

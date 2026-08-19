#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add runtime translation strings (from PHP/JS sources) to all l10n/*.json catalogs.
 *
 * Missing keys are appended after existing entries.
 * English msgids are stored as values in en.json; other locales default to English
 * unless --translations=PATH points at JSON: { "de": { "msgid": "…" }, … }.
 *
 * Usage (from app root):
 *   php scripts/sync-l10n-from-runtime.php
 *   php scripts/sync-l10n-from-runtime.php --translations=l10n/_runtime_translations.json
 *   php scripts/sync-l10n-from-runtime.php --dry-run
 */

$appRoot = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$translationsPath = null;
foreach ($argv ?? [] as $arg) {
	if (str_starts_with($arg, '--translations=')) {
		$translationsPath = substr($arg, strlen('--translations='));
	}
}

$locales = array (
  0 => 'en',
  1 => 'de',
  2 => 'fr',
  3 => 'es',
  4 => 'da',
  5 => 'nl',
  6 => 'it',
  7 => 'pl',
  8 => 'sv',
  9 => 'nb',
  10 => 'pt_BR',
);
$enPath = $appRoot . '/l10n/en.json';
$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
$catalog = $en['translations'] ?? [];

// (?<![a-zA-Z]) avoids matching Util::addScript('dutycheck', 'common/…') as t().
$patterns = [
	'/\$this->l10n->t\(\s*\'((?:\\\\\'|[^\'])*)\'\s*\)/',
	'/\$this->l10n->t\(\s*"((?:\\\\"|[^"])*)"\s*\)/',
	'/\$this->l10n->t\(\s*\'((?:\\\\\'|[^\'])*)\'\s*,/',
	'/\$this->l10n->t\(\s*"((?:\\\\"|[^"])*)"\s*,/',
	'/\$l->t\(\s*\'((?:\\\\\'|[^\'])*)\'\s*\)/',
	'/\$l->t\(\s*"((?:\\\\"|[^"])*)"\s*\)/',
	'/\$l->t\(\s*\'((?:\\\\\'|[^\'])*)\'\s*,/',
	'/\$l->t\(\s*"((?:\\\\"|[^"])*)"\s*,/',
	'/(?<![a-zA-Z])window\.t\(\s*[\'"]dutycheck[\'"]\s*,\s*\'((?:\\\\\'|[^\'])*)\'\s*\)/',
	'/(?<![a-zA-Z])window\.t\(\s*[\'"]dutycheck[\'"]\s*,\s*"((?:\\\\"|[^"])*)"\s*\)/',
	'/(?<![a-zA-Z])window\.t\(\s*[\'"]dutycheck[\'"]\s*,\s*\'((?:\\\\\'|[^\'])*)\'\s*,/',
	'/(?<![a-zA-Z])window\.t\(\s*[\'"]dutycheck[\'"]\s*,\s*"((?:\\\\"|[^"])*)"\s*,/',
	'/(?<![a-zA-Z])t\(\s*[\'"]dutycheck[\'"]\s*,\s*\'((?:\\\\\'|[^\'])*)\'\s*\)/',
	'/(?<![a-zA-Z])t\(\s*[\'"]dutycheck[\'"]\s*,\s*"((?:\\\\"|[^"])*)"\s*\)/',
	'/(?<![a-zA-Z])t\(\s*[\'"]dutycheck[\'"]\s*,\s*\'((?:\\\\\'|[^\'])*)\'\s*,/',
	'/(?<![a-zA-Z])t\(\s*[\'"]dutycheck[\'"]\s*,\s*"((?:\\\\"|[^"])*)"\s*,/',
];

$scanDirs = [$appRoot . '/lib', $appRoot . '/templates', $appRoot . '/js'];
$found = [];
$scanFiles = function (string $path) use ($patterns, &$found): void {
	$content = (string)file_get_contents($path);
	foreach ($patterns as $pattern) {
		if (preg_match_all($pattern, $content, $matches)) {
			foreach ($matches[1] as $raw) {
				$found[stripcslashes($raw)] = true;
			}
		}
	}
};

foreach ($scanDirs as $dir) {
	if (!is_dir($dir)) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
	foreach ($iter as $file) {
		if (!$file->isFile()) {
			continue;
		}
		if (!preg_match('/\.(php|js)$/', $file->getPathname())) {
			continue;
		}
		$scanFiles($file->getPathname());
	}
}

/** Regional variants mirror their base catalog (README: de_DE mirrors de). */
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
 * Copy keys that exist in the base catalog but not in the variant. Existing
 * variant entries are never overwritten, so deliberate regional divergence
 * survives. Returns the number of catalogs touched.
 */
function mirror_variants(string $appRoot, array $variants, bool $dryRun): int {
	$touched = 0;
	foreach ($variants as $variant => $baseLang) {
		$variantPath = $appRoot . '/l10n/' . $variant . '.json';
		$basePath = $appRoot . '/l10n/' . $baseLang . '.json';
		if (!is_file($variantPath) || !is_file($basePath)) {
			continue;
		}
		$variantData = json_decode((string)file_get_contents($variantPath), true, 512, JSON_THROW_ON_ERROR);
		$baseData = json_decode((string)file_get_contents($basePath), true, 512, JSON_THROW_ON_ERROR);
		$variantTrans = $variantData['translations'] ?? [];
		$added = 0;
		foreach (($baseData['translations'] ?? []) as $msgid => $value) {
			if (!array_key_exists($msgid, $variantTrans)) {
				$variantTrans[$msgid] = $value;
				$added++;
			}
		}
		if ($added === 0) {
			continue;
		}
		$touched++;
		if ($dryRun) {
			echo "[dry-run] Would mirror {$added} key(s) {$baseLang} -> {$variant}\n";
			continue;
		}
		ksort($variantTrans, SORT_STRING);
		$variantData['translations'] = $variantTrans;
		file_put_contents(
			$variantPath,
			json_encode($variantData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
		);
		echo "Mirrored {$added} key(s) {$baseLang} -> {$variant} (total " . count($variantTrans) . ")\n";
	}
	return $touched;
}

$missing = [];
foreach (array_keys($found) as $msgid) {
	if (!array_key_exists($msgid, $catalog)) {
		$missing[] = $msgid;
	}
}
sort($missing);

if ($missing === []) {
	$mirrored = mirror_variants($appRoot, $variants, $dryRun);
	echo "No missing runtime strings — l10n catalogs are up to date.\n";
	if ($mirrored > 0) {
		echo "Run: php scripts/regenerate-l10n-js.php && php scripts/check-l10n-runtime.php --all && php scripts/check-l10n-parity.php\n";
	}
	exit(0);
}

$overrides = [];
if ($translationsPath !== null) {
	$fullPath = str_starts_with($translationsPath, '/') ? $translationsPath : $appRoot . '/' . $translationsPath;
	if (!is_file($fullPath)) {
		fwrite(STDERR, "Translations file not found: {$fullPath}\n");
		exit(1);
	}
	$overrides = json_decode((string)file_get_contents($fullPath), true, 512, JSON_THROW_ON_ERROR);
}

echo ($dryRun ? '[dry-run] ' : '') . 'Adding ' . count($missing) . " missing runtime string(s) to l10n catalogs.\n";

if ($dryRun) {
	foreach ($missing as $msgid) {
		echo "  + {$msgid}\n";
	}
	exit(0);
}

foreach ($locales as $lang) {
	$path = $appRoot . '/l10n/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: {$path}\n");
		exit(1);
	}
	$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$trans = $data['translations'] ?? [];
	foreach ($missing as $msgid) {
		if ($lang === 'en') {
			$trans[$msgid] = $msgid;
		} elseif (isset($overrides[$lang][$msgid])) {
			$trans[$msgid] = $overrides[$lang][$msgid];
		} else {
			$trans[$msgid] = $msgid;
		}
	}
	// check-l10n-parity.php requires every catalog in sorted key order;
	// appending without re-sorting would fail the parity gate.
	ksort($trans, SORT_STRING);
	$data['translations'] = $trans;
	$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	file_put_contents($path, $encoded . "\n");
	echo "Updated {$path} (+ " . count($missing) . " keys, total " . count($trans) . ")\n";
}

mirror_variants($appRoot, $variants, $dryRun);

echo "Run: php scripts/regenerate-l10n-js.php && php scripts/check-l10n-runtime.php --all && php scripts/check-l10n-parity.php\n";

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for navigation/abort network-error handling.
 *
 * Applies surgical source mutants against js/common/api.js + messaging.js and
 * asserts node --test kills each mutant. MSI floor: 100% of applied mutants.
 */

$root = dirname(__DIR__, 2);
$node = trim((string) shell_exec('command -v node')) ?: 'node';
$tests = [
	$root . '/tests/js/api-abort-lifecycle.test.mjs',
	$root . '/tests/js/network-error-nav-contracts.test.mjs',
];

function run_node_tests(string $node, array $tests): bool
{
	$cmd = escapeshellarg($node) . ' --test';
	foreach ($tests as $t) {
		$cmd .= ' ' . escapeshellarg($t);
	}
	$cmd .= ' 2>&1';
	exec($cmd, $out, $code);
	return $code === 0;
}

echo "== Baseline abort lifecycle JS suite ==\n";
if (!run_node_tests($node, $tests)) {
	fwrite(STDERR, "Baseline failed — fix tests before mutation.\n");
	exit(1);
}
echo "Baseline OK\n";

/** @var list<array{file:string, search:string, replace:string, label:string}> $mutants */
$mutants = [
	[
		'file' => 'js/common/api.js',
		'search' => "throw classifyFetchFailure(cause, signal);",
		'replace' => "throw networkError(cause);",
		'label' => 'fetch-catch-always-network',
	],
	[
		'file' => 'js/common/api.js',
		'search' => "if (isPageUnloading()) {\n\t\t\treturn abortedError(cause);\n\t\t}",
		'replace' => "if (false && isPageUnloading()) {\n\t\t\treturn abortedError(cause);\n\t\t}",
		'label' => 'classify-ignore-unload',
	],
	[
		'file' => 'js/common/api.js',
		'search' => "err.code = 'REQUEST_ABORTED';",
		'replace' => "err.code = 'NETWORK_ERROR';",
		'label' => 'aborted-code-becomes-network',
	],
	[
		'file' => 'js/common/messaging.js',
		'search' => "if (window.DutyCheckApi && typeof window.DutyCheckApi.isAborted === 'function'\n\t\t\t&& window.DutyCheckApi.isAborted(err)) {\n\t\t\treturn;\n\t\t}",
		'replace' => "if (false && window.DutyCheckApi && typeof window.DutyCheckApi.isAborted === 'function'\n\t\t\t&& window.DutyCheckApi.isAborted(err)) {\n\t\t\treturn;\n\t\t}",
		'label' => 'messaging-no-abort-silence',
	],
	[
		'file' => 'js/common/messaging.js',
		'search' => "if (window.DutyCheckApi && typeof window.DutyCheckApi.isPageUnloading === 'function'\n\t\t\t\t&& window.DutyCheckApi.isPageUnloading()) {\n\t\t\t\treturn;\n\t\t\t}",
		'replace' => "if (false && window.DutyCheckApi && typeof window.DutyCheckApi.isPageUnloading === 'function'\n\t\t\t\t&& window.DutyCheckApi.isPageUnloading()) {\n\t\t\t\treturn;\n\t\t\t}",
		'label' => 'messaging-no-unload-silence',
	],
	[
		'file' => 'js/common/messaging.js',
		'search' => "if (window.DutyCheckApi && typeof window.DutyCheckApi.isAborted === 'function'\n\t\t\t&& window.DutyCheckApi.isAborted(err)) {\n\t\t\treturn;\n\t\t}\n\t\tconst status = Number((err && err.status) || 0);\n\t\tconst code = err && err.code ? String(err.code) : null;\n\t\tif (code === 'REQUEST_ABORTED' || (err && err.name === 'AbortError')) {\n\t\t\treturn;\n\t\t}",
		'replace' => "const status = Number((err && err.status) || 0);\n\t\tconst code = err && err.code ? String(err.code) : null;\n\t\t// mutated: abort silence removed",
		'label' => 'messaging-strip-all-abort-silence',
	],
	[
		'file' => 'js/common/api.js',
		'search' => "window.addEventListener('pagehide', markPageUnloading, true);",
		'replace' => "// window.addEventListener('pagehide', markPageUnloading, true);",
		'label' => 'no-pagehide-listener',
	],
];

$killed = 0;
$survived = 0;
$errors = 0;

foreach ($mutants as $m) {
	$path = $root . '/' . $m['file'];
	$orig = file_get_contents($path);
	if ($orig === false || !str_contains($orig, $m['search'])) {
		fwrite(STDERR, "SKIP/ERR missing search for {$m['label']}\n");
		$errors++;
		continue;
	}
	$mutated = str_replace($m['search'], $m['replace'], $orig, $count);
	if ($count < 1) {
		fwrite(STDERR, "ERR replace failed {$m['label']}\n");
		$errors++;
		continue;
	}
	file_put_contents($path, $mutated);
	$passed = run_node_tests($node, $tests);
	file_put_contents($path, $orig);
	if ($passed) {
		echo "SURVIVED: {$m['label']}\n";
		$survived++;
	} else {
		echo "KILLED: {$m['label']}\n";
		$killed++;
	}
}

$total = $killed + $survived;
$msi = $total > 0 ? (int) round(100 * $killed / $total) : 0;
echo "\nMutation score: {$killed}/{$total} killed ({$msi}%) errors={$errors}\n";
if ($survived > 0 || $errors > 0 || $msi < 100) {
	exit(1);
}
exit(0);

<?php

declare(strict_types=1);

/**
 * Mutation gauntlet — DutyCheck vertical scrollport CSS contract.
 *
 * Proves DesignSystemCssContractTest::testAppContentIsVerticalScrollportWithoutShellClip
 * catches regressions that truncate tall settings pages (license seats).
 *
 * Usage from app root:
 *   php tests/Mutation/run-vertical-scrollport-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$filter = 'DesignSystemCssContractTest::testAppContentIsVerticalScrollportWithoutShellClip';

$run = static function () use ($appRoot, $phpunit, $filter): int {
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		$cmd = escapeshellarg($dockerRunner) . ' dutycheck --filter ' . escapeshellarg($filter);
		passthru($cmd, $code);
		return (int)$code;
	}
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
};

echo "== baseline: vertical scrollport CSS contract ==\n";
if ($run() !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}

$mutations = [
	'drop-overflow-y-auto-on-scrollport' => [
		'file' => 'css/app.css',
		'from' => "\toverflow-x: hidden;\n\toverflow-x: clip;\n\toverflow-y: auto;\n",
		'to' => "\toverflow-x: hidden;\n\toverflow-x: clip;\n",
	],
	'reintroduce-shell-overflow-x-clip' => [
		'file' => 'css/app.css',
		'from' => "\tbox-sizing: border-box;\n\toverflow: visible;\n}\n/* Shell width modifiers",
		'to' => "\tbox-sizing: border-box;\n\toverflow-x: clip;\n\toverflow: visible;\n}\n/* Shell width modifiers",
	],
	'reintroduce-license-section-clip' => [
		'file' => 'css/license-settings.css',
		'from' => ".dc-license-section {\n\tscroll-margin-top: var(--dc-space-4);\n\tmin-width: 0;\n\tmax-width: 100%;\n",
		'to' => ".dc-license-section {\n\tscroll-margin-top: var(--dc-space-4);\n\tmin-width: 0;\n\tmax-width: 100%;\n\toverflow-x: clip;\n",
	],
];

$failed = [];
foreach ($mutations as $name => $m) {
	$path = $appRoot . '/' . $m['file'];
	$original = (string) file_get_contents($path);
	$from = $m['from'];
	$to = $m['to'];
	if (!str_contains($original, $from)) {
		fwrite(STDERR, "SKIP $name — from-string not found\n");
		continue;
	}
	$nth = (int) ($m['nth'] ?? 0);
	if ($nth > 0) {
		$pos = 0;
		$hit = 0;
		$mutated = $original;
		while (($pos = strpos($mutated, $from, $pos)) !== false) {
			$hit++;
			if ($hit === $nth) {
				$mutated = substr($mutated, 0, $pos) . $to . substr($mutated, $pos + strlen($from));
				break;
			}
			$pos += strlen($from);
		}
		if ($hit < $nth) {
			fwrite(STDERR, "SKIP $name — nth=$nth not found (hits=$hit)\n");
			continue;
		}
	} else {
		$mutated = str_replace($from, $to, $original, $count);
		if ($count < 1) {
			fwrite(STDERR, "SKIP $name — replace count 0\n");
			continue;
		}
	}
	file_put_contents($path, $mutated);
	echo "== mutate: $name ==\n";
	$code = $run();
	file_put_contents($path, $original);
	if ($code === 0) {
		echo "SURVIVED $name (contract did not catch)\n";
		$failed[] = $name;
	} else {
		echo "KILLED $name\n";
	}
}

echo "== restore baseline ==\n";
if ($run() !== 0) {
	fwrite(STDERR, "Restore baseline failed\n");
	exit(1);
}

if ($failed !== []) {
	fwrite(STDERR, 'Mutations survived: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "OK all vertical-scrollport mutations killed\n";
exit(0);

<?php

declare(strict_types=1);

/**
 * Mutation gauntlet — DutyCheck license seat search (items contract).
 *
 * Usage from app root (Docker when available):
 *   php tests/Mutation/run-license-seat-search-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$filter = 'LicenseSeatSearchTest|LicenseSearchUsersControllerTest|LicenseSeatSearchContractTest';

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

$runNode = static function () use ($appRoot): int {
	passthru('node --test ' . escapeshellarg($appRoot . '/tests/js/license-seat-search.test.mjs'), $code);
	return (int)$code;
};

echo "== baseline: license seat search ==\n";
if ($run() !== 0 || $runNode() !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}

$mutations = [
	'wire-search-back-to-directoryUsers' => [
		'file' => 'lib/Controller/PageController.php',
		'suite' => 'php',
		'from' => "dutycheck.license.searchUsers",
		'to' => "dutycheck.rosterApi.directoryUsers",
	],
	'drop-items-key-in-controller' => [
		'file' => 'lib/Controller/LicenseController.php',
		'suite' => 'php',
		'from' => "'items' => \$this->license->searchUsersForSeats(\$q, \$limit),",
		'to' => "'users' => \$this->license->searchUsersForSeats(\$q, \$limit),",
	],
	'drop-route' => [
		'file' => 'appinfo/routes.php',
		'suite' => 'php',
		'from' => "['name' => 'license#searchUsers', 'url' => '/api/license/search/users', 'verb' => 'GET'],",
		'to' => "['name' => 'license#searchPeople', 'url' => '/api/license/search/users', 'verb' => 'GET'],",
	],
	'revert-client-to-items-only' => [
		'file' => 'js/license-settings.js',
		'suite' => 'js',
		'from' => 'renderSuggestions(normalizeSearchHits(raw), null);',
		'to' => 'renderSuggestions(Array.isArray(res.data.items) ? res.data.items : [], null);',
	],
	'skip-disabled-filter' => [
		'file' => 'lib/Service/LicenseService.php',
		'suite' => 'php',
		'from' => "if (method_exists(\$user, 'isEnabled') && !\$user->isEnabled()) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'to' => "if (false && method_exists(\$user, 'isEnabled') && !\$user->isEnabled()) {\n\t\t\t\tcontinue;\n\t\t\t}",
	],
];

$failed = [];
foreach ($mutations as $name => $m) {
	$path = $appRoot . '/' . $m['file'];
	$orig = file_get_contents($path);
	if ($orig === false || !str_contains($orig, $m['from'])) {
		fwrite(STDERR, "Needle missing for mutant $name\n");
		exit(1);
	}
	file_put_contents($path, str_replace($m['from'], $m['to'], $orig));
	echo "== mutant: $name (expect FAIL) ==\n";
	$ok = ($m['suite'] === 'js' ? $runNode() : $run()) === 0;
	file_put_contents($path, $orig);
	if ($ok) {
		$failed[] = $name;
		fwrite(STDERR, "SURVIVOR: $name\n");
	} else {
		echo "killed: $name\n";
	}
}

echo "== post baseline ==\n";
if ($run() !== 0 || $runNode() !== 0) {
	fwrite(STDERR, "Post baseline failed\n");
	exit(1);
}

if ($failed !== []) {
	fwrite(STDERR, 'Survivors: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "All license-seat-search mutants killed.\n";

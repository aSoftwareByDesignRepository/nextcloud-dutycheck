<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: request-scoped caches and first-paint parallelism.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-app-wide-load-mutations.php
 */

require __DIR__ . '/mutation-lock.php';

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'AccessControlServiceTest|CompanyServiceRequestMemoTest|SchemaProbeTest|RosterServiceDashboardSummaryTest|RosterServicePeriodsPageReadTest|ArbeitszeitCheckIntegrationServiceTest|RosterApiCompanyIdorTest|RosterServiceMutationPeriodCompanyMappingTest|RosterServiceCancelVersionCasTest';

function run_phpunit(string $appRoot, string $phpunit): int
{
	$compose = dirname($appRoot, 2) . '/docker-compose.yml';
	if (is_file($compose) && trim((string) shell_exec('command -v docker')) !== '') {
		$cmd = 'docker compose -f ' . escapeshellarg($compose)
			. ' exec -T -u www-data -w /var/www/html/custom_apps/dutycheck nextcloud php'
			. ' -d opcache.enable_cli=0 -d opcache.enable=0'
			. ' vendor/bin/phpunit -c phpunit.xml --cache-result-file=/tmp/dutycheck-phpunit.cache'
			. ' --filter ' . escapeshellarg(PHP_FILTER);
		passthru($cmd, $code);
		return (int) $code;
	}
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg(PHP_FILTER);
	passthru($cmd, $code);
	return (int) $code;
}

function run_node(string $node, string $appRoot): int
{
	$test = $appRoot . '/tests/js/language-locale-and-lightweight-reads.test.mjs';
	passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $code);
	return (int) $code;
}

echo "== baseline app-wide-load tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$access = $appRoot . '/lib/Service/AccessControlService.php';
$company = $appRoot . '/lib/Service/CompanyService.php';
$roster = $appRoot . '/lib/Service/RosterService.php';
$integration = $appRoot . '/lib/Integration/ArbeitszeitCheckIntegrationService.php';
$rosterJs = $appRoot . '/js/roster.js';
$absencesJs = $appRoot . '/js/absences.js';

$mutations = [
	'role_lookup_not_memoized' => [
		'file' => $access,
		'from' => 'if (array_key_exists($userId, $this->globalRoleCache)) {',
		'to' => 'if (false && array_key_exists($userId, $this->globalRoleCache)) {',
		'php' => true,
		'js' => false,
	],
	'policy_cache_not_invalidated' => [
		'file' => $access,
		'from' => "\t\t\$this->forgetPolicyCaches();\n\t\treturn \$this->appPolicy();",
		'to' => "\t\treturn \$this->appPolicy();",
		'php' => true,
		'js' => false,
	],
	'multi_company_count_not_memoized' => [
		'file' => $company,
		'from' => 'if ($this->multiCompanyActiveCache !== null) {',
		'to' => 'if (false && $this->multiCompanyActiveCache !== null) {',
		'php' => true,
		'js' => false,
	],
	'schema_table_exists_uncached' => [
		'file' => $appRoot . '/lib/Db/SchemaProbe.php',
		'from' => 'if (array_key_exists($table, self::$tableCache)) {',
		'to' => 'if (false && array_key_exists($table, self::$tableCache)) {',
		'php' => true,
		'js' => false,
	],
	'schema_ready_tables_not_memoized' => [
		'file' => $roster,
		'from' => 'if ($this->schemaReadyCache !== null) {',
		'to' => 'if (false && $this->schemaReadyCache !== null) {',
		'php' => true,
		'js' => false,
	],
	'peer_installed_not_memoized' => [
		'file' => $integration,
		'from' => 'if ($this->peerInstalledCache !== null) {',
		'to' => 'if (false && $this->peerInstalledCache !== null) {',
		'php' => true,
		'js' => false,
	],
	'cancel_assignment_no_version_cas' => [
		'file' => $roster,
		'from' => "throw new \\InvalidArgumentException('STALE_VERSION');",
		'to' => "throw new \\InvalidArgumentException('ASSIGNMENT_CANCELLED');",
		'php' => true,
		'js' => false,
	],
	'peek_assignment_skips_company_check' => [
		'file' => $roster,
		'from' => 'if ($actorUserId !== null) {
			$this->assertPeriodCompanyAccess($actorUserId, $periodId);
		}',
		'to' => '/* skip company */',
		'php' => true,
		'js' => false,
	],
	'conflict_payload_keeps_details' => [
		'file' => $roster,
		'from' => "'details' => [],",
		'to' => "'details' => \$payload['details'] ?? [],",
		'php' => true,
		'js' => true,
	],
	'conflict_assignment_ids_uncapped' => [
		'file' => $roster,
		'from' => 'if (count($assignmentIds) >= 2) {',
		'to' => 'if (count($assignmentIds) >= 400) {',
		'php' => true,
		'js' => true,
	],
	'roster_ack_stats_extra_get' => [
		'file' => $rosterJs,
		'from' => "const rows = Array.isArray(state.assignments) ? state.assignments : [];",
		'to' => "await Api.get('/apps/dutycheck/api/periods/' + id + '/acknowledge-stats', {}); const rows = Array.isArray(state.assignments) ? state.assignments : [];",
		'php' => false,
		'js' => true,
	],
	'roster_boot_sequential' => [
		'file' => $rosterJs,
		'from' => "await Promise.all([\n\t\t\tloadRoster(selectedPeriodIdFromUrl()),\n\t\t\tloadPendingSwaps(),\n\t\t\tloadPendingOpenClaims(),\n\t\t]);",
		'to' => "await loadRoster(selectedPeriodIdFromUrl());\n\t\tawait loadPendingSwaps();\n\t\tawait loadPendingOpenClaims();",
		'php' => false,
		'js' => true,
	],
	'absences_sequential' => [
		'file' => $absencesJs,
		'from' => "const [employeesResponse, absencesResponse] = await Promise.all([\n\t\t\t\tApi.get('/apps/dutycheck/api/employees'),\n\t\t\t\tApi.get('/apps/dutycheck/api/absences'),\n\t\t\t]);",
		'to' => "const employeesResponse = await Api.get('/apps/dutycheck/api/employees');\n\t\t\tconst absencesResponse = await Api.get('/apps/dutycheck/api/absences');",
		'php' => false,
		'js' => true,
	],
];

$failed = 0;
foreach ($mutations as $name => $mut) {
	$source = (string) file_get_contents($mut['file']);
	if (!str_contains($source, $mut['from'])) {
		fwrite(STDERR, "Mutation {$name}: anchor not found in {$mut['file']}\n");
		$failed++;
		continue;
	}
	$backup = $mut['file'] . '.mut.bak';
	copy($mut['file'], $backup);
	file_put_contents($mut['file'], str_replace($mut['from'], $mut['to'], $source, $count));
	if ($count < 1) {
		fwrite(STDERR, "Mutation {$name}: expected replacements, got {$count}\n");
		rename($backup, $mut['file']);
		$failed++;
		continue;
	}
	echo "== mutant {$name} (replacements={$count}) ==\n";
	$phpCode = $mut['php'] ? run_phpunit($appRoot, $phpunit) : 0;
	$jsCode = $mut['js'] ? run_node($node, $appRoot) : 0;
	rename($backup, $mut['file']);
	$killed = ($mut['php'] && $phpCode !== 0) || ($mut['js'] && $jsCode !== 0);
	if (!$killed) {
		fwrite(STDERR, "SURVIVED {$name}\n");
		$failed++;
	} else {
		echo "killed {$name}\n";
	}
}

if ($failed !== 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failed} mutant(s) survived or could not be applied\n");
	exit(1);
}

echo "All app-wide-load mutants killed.\n";
exit(0);

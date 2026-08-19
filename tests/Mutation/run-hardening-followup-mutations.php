<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: remaining Zeus Should-Fix / fail-mode items
 * (deny-all membership, copyPeriod transaction, conflict-ack CAS, bootstrap slim).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-hardening-followup-mutations.php
 */

require __DIR__ . '/mutation-lock.php';

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'CompanyServiceDenyAllTest|CompanyIsolationTest|CompanyServiceLegacyTest|CompanyServiceRequestMemoTest|RosterServiceCopyPeriodTransactionTest|RosterServiceAcknowledgeConflictCasTest|ApiControllerBootstrapTest|RosterServiceDashboardSummaryTest|DashboardTemplateRenderTest|ApiJsonErrorResponseTest|RosterWaveContractTest|CiWorkflowContractTest';

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
	$tests = [
		$appRoot . '/tests/js/language-locale-and-lightweight-reads.test.mjs',
		$appRoot . '/tests/js/dashboard-setup-progress.test.mjs',
		$appRoot . '/tests/js/api-abort-lifecycle.test.mjs',
	];
	$code = 0;
	foreach ($tests as $test) {
		passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $part);
		if ((int) $part !== 0) {
			$code = (int) $part;
		}
	}
	return $code;
}

echo "== baseline hardening-followup tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$company = $appRoot . '/lib/Service/CompanyService.php';
$roster = $appRoot . '/lib/Service/RosterService.php';
$api = $appRoot . '/lib/Controller/ApiController.php';
$dashboardJs = $appRoot . '/js/dashboard.js';

$mutations = [
	'membership_falls_back_to_default' => [
		'file' => $company,
		'from' => 'return $this->companyIdsByUser[$userId] = $ids;',
		'to' => 'return $this->companyIdsByUser[$userId] = ($ids !== [] ? $ids : [self::DEFAULT_COMPANY_ID]);',
		'php' => true,
		'js' => false,
	],
	'restrict_query_empty_is_unrestricted' => [
		'file' => $company,
		'from' => "if (\$allowed === []) {\n\t\t\t\$qb->andWhere(\$qb->expr()->eq(\n\t\t\t\t\$column,\n\t\t\t\t\$qb->createNamedParameter(self::DENY_ALL_COMPANY_ID, IQueryBuilder::PARAM_INT),\n\t\t\t));\n\t\t\treturn;\n\t\t}",
		'to' => "if (\$allowed === []) {\n\t\t\treturn;\n\t\t}",
		'php' => true,
		'js' => false,
	],
	'write_company_id_stamps_default_without_membership' => [
		'file' => $company,
		'from' => "if (\$allowed === []) {\n\t\t\tthrow new \\InvalidArgumentException('COMPANY_MEMBERSHIP_REQUIRED');\n\t\t}",
		'to' => "if (\$allowed === []) {\n\t\t\treturn self::DEFAULT_COMPANY_ID;\n\t\t}",
		'php' => true,
		'js' => false,
	],
	'copy_period_nests_inner_transactions' => [
		'file' => $roster,
		'from' => '], $actor, false, false, false, false);',
		'to' => '], $actor, false, false, false, true);',
		'php' => true,
		'js' => false,
	],
	'copy_period_skips_transaction' => [
		'file' => $roster,
		'from' => "if (!\$dryRun) {\n\t\t\t\$this->db->beginTransaction();\n\t\t}",
		'to' => "if (!\$dryRun) {\n\t\t\t/* skip txn */\n\t\t}",
		'php' => true,
		'js' => false,
	],
	'copy_period_skips_rollback' => [
		'file' => $roster,
		'from' => "if (!\$dryRun && \$this->db->inTransaction()) {\n\t\t\t\t\$this->db->rollBack();\n\t\t\t}",
		'to' => "if (!\$dryRun && \$this->db->inTransaction()) {\n\t\t\t\t/* skip rollback */\n\t\t\t}",
		'php' => true,
		'js' => false,
	],
	'conflict_ack_cas_resolved_inverted' => [
		'file' => $roster,
		'from' => "->andWhere(\$update->expr()->eq('is_resolved', \$update->createNamedParameter(0, IQueryBuilder::PARAM_INT)))",
		'to' => "->andWhere(\$update->expr()->eq('is_resolved', \$update->createNamedParameter(1, IQueryBuilder::PARAM_INT)))",
		'php' => true,
		'js' => false,
	],
	'conflict_ack_ignores_zero_affected' => [
		'file' => $roster,
		'from' => 'if ($affected < 1) {',
		'to' => 'if ($affected < 0) {',
		'php' => true,
		'js' => false,
	],
	'bootstrap_hydrates_roster' => [
		'file' => $api,
		'from' => "'roster' => null,",
		'to' => "'roster' => \$this->roster->rosterData(null, \$userId),",
		'php' => true,
		'js' => true,
	],
	'bootstrap_hydrates_my_roster' => [
		'file' => $api,
		'from' => "'myRoster' => null,",
		'to' => "'myRoster' => \$this->roster->myRoster(\$userId),",
		'php' => true,
		'js' => true,
	],
	'conflict_ack_stale_maps_to_400' => [
		'file' => $appRoot . '/lib/Controller/ApiJsonErrorResponse.php',
		'from' => "'PERIOD_STATUS_CONFLICT', 'ABSENCE_STATUS_CONFLICT', 'STALE_VERSION', 'ASSIGNMENT_TRANSFER_STALE', 'CONFLICT_ACK_STALE' => 409,",
		'to' => "'PERIOD_STATUS_CONFLICT', 'ABSENCE_STATUS_CONFLICT', 'STALE_VERSION', 'ASSIGNMENT_TRANSFER_STALE' => 409,",
		'php' => true,
		'js' => false,
	],
	'conflict_ack_stale_auto_reloads' => [
		'file' => $appRoot . '/js/common/messaging.js',
		'from' => "if (code === 'CONFLICT_ACK_STALE') {",
		'to' => "if (code === 'CONFLICT_ACK_STALE_DISABLED') {",
		'php' => false,
		'js' => true,
	],
	'company_membership_swallowed_by_generic_403' => [
		'file' => $appRoot . '/js/common/messaging.js',
		'from' => "if (code === 'COMPANY_MEMBERSHIP_REQUIRED') {",
		'to' => "if (code === 'COMPANY_MEMBERSHIP_REQUIRED_DISABLED') {",
		'php' => false,
		'js' => true,
	],
	'dashboard_ignores_company_denied' => [
		'file' => $dashboardJs,
		'from' => 'const denied = Boolean(payload.companyAccessDenied);',
		'to' => 'const denied = false;',
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

echo "All hardening-followup mutants killed.\n";
exit(0);

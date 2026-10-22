#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the 2026-10-22 customer-report fixes:
 *
 *  - suggest-fill break resolution (org default + per-field template fallback),
 *  - notification fan-outs requiring active employees,
 *  - messaging 404 known-code ordering,
 *  - today-board inactive-location filtering + stale-pick clearing,
 *  - pattern editor break round-trip.
 *
 * Each mutant must be killed by the unit and/or node suites (MSI 100%).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-break-resolution-and-notify-mutations.php
 */

require __DIR__ . '/mutation-lock.php';

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'RotationSuggestServiceBreakResolutionTest|PublishNotificationServiceTest|NotificationActiveRecipientContractTest|PushQuietHoursRecipientGateTest|InactiveEntityAndOrgDefaultContractTest|OpenShiftCreateBreakDefaultTest';

function run_phpunit(string $appRoot, string $phpunit): int
{
	$compose = dirname($appRoot, 2) . '/docker-compose.yml';
	if (is_file($compose) && trim((string) shell_exec('command -v docker')) !== '') {
		$cmd = 'docker compose -f ' . escapeshellarg($compose)
			. ' exec -T -u www-data -w /var/www/html/custom_apps/dutycheck nextcloud php'
			. ' -d opcache.enable_cli=0 -d opcache.enable=0'
			. ' vendor/bin/phpunit -c phpunit.xml --do-not-cache-result'
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
		$appRoot . '/tests/js/messaging-404-ordering.test.mjs',
		$appRoot . '/tests/js/patterns-break-and-today-location.test.mjs',
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

echo "== baseline suggest-break + notify tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$messaging = $appRoot . '/js/common/messaging.js';
$today = $appRoot . '/js/today.js';
$patterns = $appRoot . '/js/patterns.js';
$suggest = $appRoot . '/lib/Service/RotationSuggestService.php';
$publish = $appRoot . '/lib/Service/PublishNotificationService.php';
$quiet = $appRoot . '/lib/Service/PushQuietHoursService.php';

$mutations = [
	'messaging_drops_friendly_on_404' => [
		'file' => $messaging,
		'from' => "announce(friendly || t('dutycheck', 'The server could not find this DutyCheck action.",
		'to' => "announce(t('dutycheck', 'The server could not find this DutyCheck action.",
		'php' => false,
		'js' => true,
	],
	'today_keeps_inactive_locations' => [
		'file' => $today,
		'from' => 'const list = catalog.filter((loc) => loc && loc.active !== false);',
		'to' => 'const list = catalog;',
		'php' => false,
		'js' => true,
	],
	'today_keeps_stale_location_pick' => [
		'file' => $today,
		'from' => 'window.localStorage.removeItem(STORAGE_KEY);',
		'to' => 'window.localStorage.getItem(STORAGE_KEY);',
		'php' => false,
		'js' => true,
	],
	'patterns_hardcodes_break_zero' => [
		'file' => $patterns,
		'from' => 'breakMinutes: isWorking ? Math.max(0, Math.min(720, Math.round(breakRaw))) : 0',
		'to' => 'breakMinutes: 0',
		'php' => false,
		'js' => true,
	],
	'suggest_no_org_break_default' => [
		'file' => $suggest,
		'from' => "\t\tif (\$break <= 0) {\n\t\t\t\$break = \$this->planningDefaults?->getDefaultBreakMinutes() ?? 0;\n\t\t}",
		'to' => "\t\tif (false) {\n\t\t\t\$break = \$this->planningDefaults?->getDefaultBreakMinutes() ?? 0;\n\t\t}",
		'php' => true,
		'js' => false,
	],
	'publish_notifies_deactivated' => [
		'file' => $publish,
		'from' => "\n\t\t\t->andWhere(\$qb->expr()->eq('e.active', \$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))",
		'to' => '',
		'php' => true,
		'js' => false,
	],
	'quiet_queue_delivers_to_deactivated' => [
		'file' => $quiet,
		'from' => 'if (!$this->recipientIsActiveEmployee((string) $row[\'user_id\'])) {',
		'to' => 'if (false && !$this->recipientIsActiveEmployee((string) $row[\'user_id\'])) {',
		'php' => true,
		'js' => false,
	],
	// ── 2026-10-22 sibling sweep mutants ──
	'suggest_loads_inactive_template' => [
		'file' => $suggest,
		'from' => "\t\t\t->andWhere(\$qb->expr()->eq('active', \$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))",
		'to' => '',
		'php' => true,
		'js' => false,
	],
	'on_duty_includes_deactivated' => [
		'file' => $appRoot . '/lib/Integration/MaintenanceCheckOnDutyReader.php',
		'from' => "\t\t\t\t->andWhere(\$qb->expr()->eq('e.active', \$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))",
		'to' => '',
		'php' => true,
		'js' => false,
	],
	'pattern_assign_accepts_inactive' => [
		'file' => $appRoot . '/lib/Service/RotationPatternService.php',
		'from' => "\t\t// Deactivated employees cannot take on new pattern assignments —\n\t\t// same active requirement as RosterService::assertEmployeeExists.\n\t\tif ((int) (\$row['active'] ?? 0) !== 1) {\n\t\t\tthrow new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');\n\t\t}\n",
		'to' => '',
		'php' => true,
		'js' => false,
	],
	'weekday_refs_unvalidated' => [
		'file' => $appRoot . '/lib/Service/RotationPatternService.php',
		'from' => "\$this->assertWeekDayReferences(\$weekDays, \$companyId);",
		'to' => 'true;',
		'php' => true,
		'js' => false,
	],
	'create_ignores_org_break_default' => [
		'file' => $appRoot . '/lib/Service/RosterService.php',
		'from' => "\t\t\$breakMinutes = array_key_exists('breakMinutes', \$payload)\n\t\t\t? PlanningDefaultsService::parseAssignmentBreakMinutes(\$payload['breakMinutes'])\n\t\t\t: (\$isStandby ? 0 : (\$this->planningDefaults?->getDefaultBreakMinutes() ?? 0));",
		'to' => "\t\t\$breakMinutes = PlanningDefaultsService::parseAssignmentBreakMinutes(\$payload['breakMinutes'] ?? null);",
		'php' => true,
		'js' => false,
	],
	'open_shift_ignores_org_break_default' => [
		'file' => $appRoot . '/lib/Service/OpenShiftService.php',
		'from' => "\t\t\$break = array_key_exists('breakMinutes', \$payload)\n\t\t\t? PlanningDefaultsService::parseAssignmentBreakMinutes(\$payload['breakMinutes'])\n\t\t\t: (\$this->planningDefaults?->getDefaultBreakMinutes() ?? 0);",
		'to' => "\t\t\$break = max(0, (int) (\$payload['breakMinutes'] ?? 0));",
		'php' => true,
		'js' => false,
	],
	'open_shift_form_hardcodes_break' => [
		'file' => $appRoot . '/js/roster.js',
		'from' => 'breakMinutes: clampBreakMinutes(Number(document.getElementById(\'dc-os-break\')?.value || 0)),',
		'to' => 'breakMinutes: 0,',
		'php' => true,
		'js' => false,
	],
	'settings_keeps_inactive_locations' => [
		'file' => $appRoot . '/js/settings.js',
		'from' => 'if (row.active === false) continue;',
		'to' => 'if (false) continue;',
		'php' => true,
		'js' => false,
	],
	'asg_create_injects_null_break_key' => [
		'file' => $appRoot . '/lib/Controller/RosterApiController.php',
		'from' => "if (array_key_exists('breakMinutes', \$params)) {\n\t\t\t\t\$createPayload['breakMinutes'] = \$params['breakMinutes'];",
		'to' => "if (true) {\n\t\t\t\t\$createPayload['breakMinutes'] = \$params['breakMinutes'];",
		'php' => true,
		'js' => false,
	],
	'asg_update_injects_null_break_key' => [
		'file' => $appRoot . '/lib/Controller/RosterApiController.php',
		'from' => "if (array_key_exists('breakMinutes', \$params)) {\n\t\t\t\t\$updatePayload['breakMinutes'] = \$params['breakMinutes'];",
		'to' => "if (true) {\n\t\t\t\t\$updatePayload['breakMinutes'] = \$params['breakMinutes'];",
		'php' => true,
		'js' => false,
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

echo $failed === 0 ? "All mutants killed.\n" : "{$failed} mutant(s) survived.\n";
exit($failed === 0 ? 0 : 1);

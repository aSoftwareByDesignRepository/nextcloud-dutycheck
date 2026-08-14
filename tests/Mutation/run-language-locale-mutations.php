<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: language vs locale must stay distinct, and Periods must
 * not load the full roster payload.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-language-locale-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}
$node = trim((string) shell_exec('command -v node')) ?: 'node';

const PHP_FILTER = 'LocaleFormatServiceLanguageVsLocaleTest|LocaleFormatServiceHtmlLangTest|PeriodTranslationSenseTest|DashboardTemplateRenderTest|RosterApiControllerContractTest::testListPeriods';

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
		return (int)$code;
	}
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg(PHP_FILTER);
	passthru($cmd, $code);
	return (int)$code;
}

function run_node(string $node, string $appRoot): int
{
	$test = $appRoot . '/tests/js/language-locale-and-lightweight-reads.test.mjs';
	passthru(escapeshellarg($node) . ' --test ' . escapeshellarg($test), $code);
	return (int)$code;
}

echo "== baseline language/locale + lightweight-read tests ==\n";
$baselinePhp = run_phpunit($appRoot, $phpunit);
$baselineJs = run_node($node, $appRoot);
if ($baselinePhp !== 0 || $baselineJs !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$localeService = $appRoot . '/lib/Service/LocaleFormatService.php';
$periodsJs = $appRoot . '/js/periods.js';
$dashboardJs = $appRoot . '/js/dashboard.js';
$datesJs = $appRoot . '/js/common/dates.js';
$roster = $appRoot . '/lib/Service/RosterService.php';

$mutations = [
	'html_lang_from_locale' => [
		'file' => $localeService,
		'from' => '$htmlLang = $this->canonicalHtmlLangFromLocaleString($language !== \'\' ? $language : $localeRaw);',
		'to' => '$htmlLang = $this->canonicalHtmlLangFromLocaleString($localeRaw !== \'\' ? $localeRaw : $language);',
		'php' => true,
		'js' => false,
	],
	'locale_from_language' => [
		'file' => $localeService,
		'from' => '$intlLocale = $this->canonicalHtmlLangFromLocaleString($localeRaw !== \'\' ? $localeRaw : $language);',
		'to' => '$intlLocale = $this->canonicalHtmlLangFromLocaleString($language !== \'\' ? $language : $localeRaw);',
		'php' => true,
		'js' => false,
	],
	'week_start_always_sunday' => [
		'file' => $localeService,
		'from' => 'return 1;',
		'to' => 'return 0;',
		'php' => true,
		'js' => false,
	],
	'periods_loads_roster' => [
		'file' => $periodsJs,
		'from' => "Api.get('/apps/dutycheck/api/periods')",
		'to' => "Api.get('/apps/dutycheck/api/roster')",
		'php' => false,
		'js' => true,
	],
	'relative_time_uses_locale' => [
		'file' => $datesJs,
		'from' => 'new Intl.RelativeTimeFormat(currentLanguage(), { numeric: \'auto\' })',
		'to' => 'new Intl.RelativeTimeFormat(currentLocale(), { numeric: \'auto\' })',
		'php' => false,
		'js' => true,
	],
	'publish_readiness_writes' => [
		'file' => $roster,
		'from' => '$conflicts = $this->listPersistedConflicts($periodId);',
		'to' => '$conflicts = $this->refreshAndListConflicts($periodId);',
		'php' => false,
		'js' => true,
	],
	'dashboard_pulse_roster' => [
		'file' => $dashboardJs,
		'from' => "Api.get('/apps/dutycheck/api/periods')",
		'to' => "Api.get('/apps/dutycheck/api/roster')",
		'php' => false,
		'js' => true,
	],
	'week_name_from_locale' => [
		'file' => $localeService,
		'from' => '\'weekStartDayName\' => $this->weekStartDayName($language !== \'\' ? $language : \'en\', $firstDay),',
		'to' => '\'weekStartDayName\' => $this->weekStartDayName($localeRaw !== \'\' ? $localeRaw : $language, $firstDay),',
		'php' => true,
		'js' => false,
	],
	'weekday_uses_locale' => [
		'file' => $datesJs,
		'from' => 'new Intl.DateTimeFormat(currentLanguage(), {',
		'to' => 'new Intl.DateTimeFormat(currentLocale(), {',
		'php' => false,
		'js' => true,
	],
	'roster_get_refreshes' => [
		'file' => $roster,
		'from' => '$conflicts = $selected !== null ? $this->listPersistedConflicts($selected) : [];',
		'to' => '$conflicts = $selected !== null ? $this->refreshAndListConflicts($selected) : [];',
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
	if ($count !== 1) {
		fwrite(STDERR, "Mutation {$name}: expected 1 replacement, got {$count}\n");
		rename($backup, $mut['file']);
		$failed++;
		continue;
	}
	echo "== mutant {$name} (must FAIL) ==\n";
	$phpCode = $mut['php'] ? run_phpunit($appRoot, $phpunit) : 0;
	$jsCode = $mut['js'] ? run_node($node, $appRoot) : 0;
	rename($backup, $mut['file']);
	$killed = ($mut['php'] && $phpCode !== 0) || ($mut['js'] && $jsCode !== 0);
	if (!$killed) {
		fwrite(STDERR, "Mutation {$name} SURVIVED\n");
		$failed++;
	} else {
		echo "killed {$name}\n";
	}
}

if ($failed > 0) {
	fwrite(STDERR, "Language/locale mutation gauntlet FAILED ({$failed})\n");
	exit(1);
}
echo "Language/locale mutation gauntlet OK (" . count($mutations) . " mutants killed).\n";
exit(0);

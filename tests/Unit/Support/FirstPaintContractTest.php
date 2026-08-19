<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * App-wide first-paint: no CSRF prefetch, conflict-labels only where used,
 * dashboard SSR extras, catalog windowing scripts, settings section gate.
 */
final class FirstPaintContractTest extends TestCase
{
	private function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private function read(string $rel): string
	{
		$src = (string) file_get_contents($this->appRoot() . '/' . $rel);
		self::assertNotSame('', $src, $rel . ' must not be empty');
		return $src;
	}

	public function testSessionJsDoesNotPrefetchCsrf(): void
	{
		$src = $this->read('js/common/session.js');
		self::assertStringContainsString('csrfPrefetch: false', $src);
		self::assertStringNotContainsString('refreshCsrfToken', $src);
		self::assertStringNotContainsString('DOMContentLoaded', $src);
	}

	public function testApiJsStillRetriesCsrfOn412(): void
	{
		$src = $this->read('js/common/api.js');
		self::assertStringContainsString('function refreshCsrfToken', $src);
		self::assertStringContainsString('412', $src);
	}

	public function testConflictLabelsLoadOnlyOnDashboardRosterPeriods(): void
	{
		$src = $this->read('lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/if \(in_array\(\\\$template, \['dashboard', 'roster', 'periods'\], true\)\) \{\s*Util::addScript\(Application::APP_ID, 'common\/conflict-labels'\);/s",
			$src,
		);
		self::assertDoesNotMatchRegularExpression(
			"/Util::addScript\(Application::APP_ID, 'common\/messaging'\);\s*Util::addScript\(Application::APP_ID, 'common\/conflict-labels'\);/s",
			$src,
		);
	}

	public function testDashboardPageSsrCallsSummaryOnceOutsideGenericPage(): void
	{
		$src = $this->read('lib/Controller/PageController.php');
		self::assertStringContainsString('function dashboardPageExtras', $src);
		self::assertStringContainsString('$this->dashboardPageExtras()', $src);
		self::assertSame(1, substr_count($src, '$this->roster->dashboardSummary'));
		$pageFn = strpos($src, 'private function page(');
		self::assertNotFalse($pageFn);
		self::assertStringNotContainsString(
			'dashboardSummary',
			substr($src, $pageFn),
			'page() must not run dashboardSummary for every template',
		);
	}

	public function testCatalogPagesLoadVirtualWindowAndWindowedTable(): void
	{
		$src = $this->read('lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/if \(in_array\(\\\$template, \['employees', 'locations', 'absences'\], true\)\) \{\s*Util::addScript\(Application::APP_ID, 'common\/virtual-window'\);\s*Util::addScript\(Application::APP_ID, 'common\/windowed-table'\);/s",
			$src,
		);
	}

	public function testCatalogTemplatesExposeWindowedScrollers(): void
	{
		foreach (['employees', 'locations', 'absences'] as $page) {
			$tpl = $this->read('templates/' . $page . '.php');
			self::assertStringContainsString('dc-windowed-table', $tpl);
			self::assertStringContainsString('id="dc-' . $page . '-table-wrap"', $tpl);
			self::assertStringContainsString('id="dc-' . $page . '-table-status"', $tpl);
			self::assertMatchesRegularExpression(
				'/id="dc-' . preg_quote($page, '/') . '-table-wrap"[^>]*tabindex="0"/',
				$tpl,
			);
		}
	}

	public function testDashboardTemplateReadsSsrMetrics(): void
	{
		$tpl = $this->read('templates/dashboard.php');
		self::assertStringContainsString("\$_['dashboardSummary']", $tpl);
		self::assertStringContainsString('$metricOpen', $tpl);
		self::assertStringContainsString('dc-metric-open-periods', $tpl);
		$shell = $this->read('templates/common/page-start.php');
		self::assertStringContainsString('data-dc-dashboard-summary', $shell);
	}

	public function testSettingsWiresOnlyTheActiveSection(): void
	{
		$src = $this->read('js/settings.js');
		self::assertStringContainsString('const SECTION_WIRES', $src);
		self::assertStringContainsString('function wireActiveSettingsSection', $src);
		self::assertStringContainsString('SECTION_WIRES[section]', $src);
		self::assertStringContainsString('typeof wire === \'function\'', $src);
		self::assertStringNotContainsString('Object.values(SECTION_WIRES)', $src);
		$boot = substr($src, (int) strpos($src, 'DOMContentLoaded'));
		self::assertStringContainsString('await wireActiveSettingsSection()', $boot);
		self::assertStringNotContainsString('await wireCompanies()', $boot);
		self::assertStringNotContainsString('await wireAtIntegration()', $boot);
	}

	public function testRosterModalLoadsPrereqsInParallelAndCachesTemplates(): void
	{
		$src = $this->read('js/roster.js');
		self::assertStringContainsString('async function ensureAssignmentModalPrereqs', $src);
		self::assertStringContainsString('await Promise.all(jobs)', $src);
		self::assertStringContainsString('state.templatesLoaded', $src);
		self::assertStringContainsString('state.planningDefaultsFresh', $src);
		self::assertStringContainsString('await ensureAssignmentModalPrereqs()', $src);
		self::assertDoesNotMatchRegularExpression(
			'/await refreshPlanningDefaultFromServer\(\);\s*await loadTemplates\(\);/',
			$src,
		);
	}

	public function testWindowedTableHelperIsDomThinAndFailOpen(): void
	{
		$src = $this->read('js/common/windowed-table.js');
		self::assertStringContainsString('WINDOW_THRESHOLD', $src);
		self::assertStringContainsString('list.slice(range.start, range.end)', $src);
		self::assertStringContainsString('DutyCheckVirtualWindow', $src);
		self::assertStringContainsString('dc-virtual-spacer', $src);
		self::assertStringNotContainsString('innerHTML', $src);
		self::assertStringContainsString('FALLBACK', $src);
	}
}

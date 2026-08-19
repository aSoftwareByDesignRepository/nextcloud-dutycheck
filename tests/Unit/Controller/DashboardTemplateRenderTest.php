<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Renders templates/dashboard.php (including the shared page chrome) without
 * a Nextcloud kernel and pins its security + accessibility contract:
 *
 *  - untrusted values (page title, URLs, timezone) are HTML-escaped,
 *  - JS-driven sections ship `hidden` so the no-JS baseline stays clean,
 *  - live regions, skip link, and labelled landmarks exist for AT users,
 *  - the role badge tone mapping stays intact.
 */
final class DashboardTemplateRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	public function testTemplateStubsDefinePrintUnescapedForIconCatalog(): void
	{
		$stubs = (string) file_get_contents(dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php');
		self::assertStringContainsString('function print_unescaped', $stubs);
		self::assertTrue(\function_exists('print_unescaped'));
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function renderDashboard(array $overrides = []): string
	{
		$l = new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/**
			 * @param array<int|string, mixed> $parameters
			 */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};

		$_ = array_merge([
			'pageId' => 'dashboard',
			'pageTitle' => 'Dashboard',
			'pageHelp' => 'Coverage, conflicts, and publish-readiness at a glance.',
			'role' => 'admin',
			'roleLabel' => 'Administrator',
			'isEmployee' => false,
			'hasLinkedEmployee' => false,
			'isAppAdmin' => true,
			'isPlannerOrAdmin' => true,
			'urls' => [
				'dashboard' => '/apps/dutycheck/dashboard',
				'roster' => '/apps/dutycheck/roster',
				'periods' => '/apps/dutycheck/periods',
				'absences' => '/apps/dutycheck/absences',
				'employees' => '/apps/dutycheck/employees',
				'locations' => '/apps/dutycheck/locations',
				'settings' => '/apps/dutycheck/settings',
				'myRoster' => '/apps/dutycheck/my-roster',
				'myAbsences' => '/apps/dutycheck/my-absences',
			],
			'clientHints' => [
				'htmlLang' => 'en-US',
				'locale' => 'en_US',
				'timezone' => 'Europe/Berlin',
				'weekStartDayName' => 'Monday',
			],
			'integrationBootstrapJson' => '',
		], $overrides);

		ob_start();
		try {
			include dirname(__DIR__, 3) . '/templates/dashboard.php';
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function testRendersLandmarksLiveRegionsAndInitialState(): void
	{
		$html = $this->renderDashboard();

		self::assertStringContainsString('class="dc-scope-strip"', $html);
		self::assertStringContainsString('dc-scope-strip__item', $html);
		self::assertStringContainsString('<dt class="dc-scope-strip__label"', $html);
		self::assertStringContainsString('<dd class="dc-scope-strip__value"', $html);
		self::assertStringNotContainsString('dc-scope-strip__sep', $html);
		self::assertStringContainsString('Start of week', $html);
		self::assertStringContainsString('Monday', $html);
		self::assertStringContainsString('id="dc-main-content"', $html);
		self::assertStringContainsString('class="dc-skip-link" href="#dc-main-content"', $html);
		self::assertStringContainsString('id="dc-live-region"', $html);
		self::assertStringContainsString('id="dc-alert-region"', $html);
		self::assertStringContainsString('aria-live="polite"', $html);
		self::assertStringContainsString('aria-live="assertive"', $html);

		// JS-managed sections must ship hidden so the no-JS baseline is clean.
		self::assertMatchesRegularExpression(
			'/<section[^>]*id="dc-dashboard-setup"[^>]*\shidden\b/',
			$html,
			'Setup progress section must start hidden',
		);
		self::assertStringContainsString(
			'Finish the highlighted step — then your team can plan duties.',
			$html,
			'Setup progress subtitle must describe the single next-step flow',
		);
		self::assertStringNotContainsString(
			'Each item links to the right page.',
			$html,
			'Must not claim every row is a link (done rows are not)',
		);
		self::assertMatchesRegularExpression(
			'/<section[^>]*id="dc-quickstart"[^>]*\shidden\b/',
			$html,
			'Quick start section must start hidden',
		);
		self::assertMatchesRegularExpression(
			'/<div[^>]*id="dc-dashboard-setup-schema-alert"[^>]*\shidden\b/',
			$html,
			'Schema alert must start hidden',
		);
		self::assertStringContainsString('id="dc-company-access-banner"', $html);
		self::assertMatchesRegularExpression(
			'/<aside[^>]*id="dc-company-access-banner"[^>]*\shidden\b/',
			$html,
			'Company-access banner must start hidden unless the server set companyAccessDenied',
		);

		foreach ([
			'dc-metric-open-periods',
			'dc-metric-published-periods',
			'dc-metric-employees',
			'dc-metric-assignments',
		] as $metricId) {
			self::assertMatchesRegularExpression(
				'/id="' . preg_quote($metricId, '/') . '"[^>]*>0</',
				$html,
				"Metric {$metricId} must render with a neutral 0 baseline",
			);
		}

		self::assertMatchesRegularExpression(
			'/id="dc-dashboard-conflict-pulse"[^>]*role="status"/s',
			$html,
			'Conflict pulse must be a status live region',
		);
		self::assertStringContainsString('aria-busy="true"', $html);

		// Every dashboard section is a labelled region.
		foreach ([
			'dc-dashboard-setup-title',
			'dc-quickstart-title',
			'dc-dashboard-summary-title',
			'dc-dashboard-checklist-title',
			'dc-dashboard-conflicts-title',
		] as $titleId) {
			self::assertStringContainsString('aria-labelledby="' . $titleId . '"', $html);
			self::assertStringContainsString('id="' . $titleId . '"', $html);
		}
	}

	public function testEscapesUntrustedTitleUrlsAndTimezone(): void
	{
		$html = $this->renderDashboard([
			'pageTitle' => '<script>alert(1)</script>"Dash"',
			'clientHints' => [
				'htmlLang' => 'en-US',
				'locale' => 'en_US',
				'timezone' => '"><img src=x onerror=alert(2)>',
				'weekStartDayName' => '<img src=x onerror=alert(9)>',
			],
			'urls' => [
				'dashboard' => '/x?a=1&b=2',
				'roster' => '"><script>alert(3)</script>',
				'periods' => '/apps/dutycheck/periods',
				'absences' => '/apps/dutycheck/absences',
				'employees' => '/apps/dutycheck/employees',
				'locations' => '/apps/dutycheck/locations',
				'settings' => '/apps/dutycheck/settings',
				'myRoster' => '/apps/dutycheck/my-roster',
				'myAbsences' => '/apps/dutycheck/my-absences',
			],
		]);

		self::assertStringNotContainsString('<script>alert(1)</script>', $html);
		self::assertStringNotContainsString('<script>alert(3)</script>', $html);
		self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
		self::assertStringNotContainsString('<img src=x onerror=alert(9)>', $html);
		self::assertStringContainsString('&lt;img src=x onerror=alert(9)&gt;', $html);
		self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
		// The urls JSON payload is attribute-escaped as a whole.
		self::assertMatchesRegularExpression('/data-dc-urls="[^"]*"/', $html);
	}

	public function testCompanyAccessBannerEscapesSettingsUrlAndIsAStatusRegion(): void
	{
		$html = $this->renderDashboard([
			'companyAccessDenied' => true,
			'isAppAdmin' => true,
			'urls' => [
				'dashboard' => '/apps/dutycheck/dashboard',
				'roster' => '/apps/dutycheck/roster',
				'periods' => '/apps/dutycheck/periods',
				'absences' => '/apps/dutycheck/absences',
				'employees' => '/apps/dutycheck/employees',
				'locations' => '/apps/dutycheck/locations',
				'settings' => '/apps/dutycheck/settings',
				'companiesSettings' => '"><script>alert(7)</script>',
				'myRoster' => '/apps/dutycheck/my-roster',
				'myAbsences' => '/apps/dutycheck/my-absences',
			],
		]);

		self::assertDoesNotMatchRegularExpression(
			'/<aside[^>]*id="dc-company-access-banner"[^>]*\shidden\b/',
			$html,
		);
		self::assertMatchesRegularExpression(
			'/<aside[^>]*id="dc-company-access-banner"[^>]*role="status"/',
			$html,
		);
		self::assertStringNotContainsString('<script>alert(7)</script>', $html);
		self::assertStringContainsString('No company assigned yet', $html);
		self::assertStringContainsString('Open Companies', $html);
	}

	public function testRoleBadgeToneMapping(): void
	{
		self::assertStringContainsString('dc-badge--critical', $this->renderDashboard(['role' => 'admin']));
		self::assertStringContainsString('dc-badge--info', $this->renderDashboard(['role' => 'planner']));
		self::assertStringContainsString('dc-badge--info', $this->renderDashboard(['role' => 'planner_employee']));
		self::assertStringContainsString('dc-badge--neutral', $this->renderDashboard(['role' => 'self_service']));
		self::assertStringContainsString('dc-badge--success', $this->renderDashboard(['role' => 'employee']));
	}

	public function testChecklistLinksUseProvidedUrls(): void
	{
		$html = $this->renderDashboard();

		self::assertStringContainsString('href="/apps/dutycheck/periods"', $html);
		self::assertStringContainsString('href="/apps/dutycheck/roster"', $html);
	}

	public function testMissingUrlsFallBackToInertAnchors(): void
	{
		$html = $this->renderDashboard(['urls' => []]);

		// Checklist buttons degrade to '#', never to a PHP notice or raw null.
		self::assertStringContainsString('href="#"', $html);
		self::assertStringNotContainsString('href=""', $html);
	}

	public function testSsrSummaryPaintsMetricTiles(): void
	{
		$html = $this->renderDashboard([
			'dashboardSummary' => [
				'openPeriods' => 4,
				'publishedPeriods' => 2,
				'activeEmployees' => 11,
				'assignments' => 40,
			],
		]);
		self::assertMatchesRegularExpression('/id="dc-metric-open-periods"[^>]*>4</', $html);
		self::assertMatchesRegularExpression('/id="dc-metric-published-periods"[^>]*>2</', $html);
		self::assertMatchesRegularExpression('/id="dc-metric-employees"[^>]*>11</', $html);
		self::assertMatchesRegularExpression('/id="dc-metric-assignments"[^>]*>40</', $html);
	}

	public function testDashboardSummaryJsonIsAttributeEscaped(): void
	{
		$html = $this->renderDashboard([
			'dashboardSummaryJson' => htmlspecialchars(
				json_encode(['x' => '"><img src=x onerror=alert(1)>'], JSON_THROW_ON_ERROR),
				ENT_QUOTES,
				'UTF-8',
			),
		]);
		self::assertStringNotContainsString('<img src=x', $html);
		self::assertMatchesRegularExpression('/data-dc-dashboard-summary="/', $html);
		self::assertMatchesRegularExpression(
			'/data-dc-dashboard-summary="[^"]*&lt;img/',
			$html,
			'Broken-out markup must stay entity-escaped inside the data attribute',
		);
	}

	public function testAppShellKeepsLanguageDistinctFromLocale(): void
	{
		$html = $this->renderDashboard([
			'clientHints' => [
				'htmlLang' => 'en-US',
				'language' => 'en',
				'locale' => 'nl-NL',
				'firstDayOfWeek' => 1,
				'weekStartDayName' => 'Monday',
				'timezone' => 'Europe/Amsterdam',
			],
		]);
		self::assertMatchesRegularExpression('/id="app-content"[^>]*\slang="en-US"/', $html);
		self::assertMatchesRegularExpression('/id="app-content"[^>]*\sdata-language="en"/', $html);
		self::assertMatchesRegularExpression('/id="app-content"[^>]*\sdata-locale="nl-NL"/', $html);
		self::assertMatchesRegularExpression('/id="app-content"[^>]*\sdata-first-day-of-week="1"/', $html);
		self::assertStringContainsString('Start of week', $html);
		self::assertStringContainsString('Monday', $html);
		self::assertDoesNotMatchRegularExpression('/id="app-content"[^>]*\slang="nl-NL"/', $html);
	}
}

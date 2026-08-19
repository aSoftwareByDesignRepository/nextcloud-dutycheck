<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Periods page chrome: first paint shows Loading… (not a blank table),
 * verify is disabled until a period is selected, tables expose aria-busy.
 */
final class PeriodsTemplateRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function render(array $overrides = []): string
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
			'pageId' => 'periods',
			'pageTitle' => 'Periods',
			'pageHelp' => 'Manage period lifecycle: open, publish, close, and re-open.',
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
			include dirname(__DIR__, 3) . '/templates/periods.php';
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function testFirstPaintShowsLoadingRowsAndBusyTables(): void
	{
		$html = $this->render();

		self::assertStringContainsString('id="dc-periods-table-body"', $html);
		self::assertStringContainsString('dc-table__loading-row', $html);
		self::assertStringContainsString('Loading…', $html);
		self::assertGreaterThanOrEqual(3, substr_count($html, 'aria-busy="true"'));
		self::assertMatchesRegularExpression(
			'/<button[^>]*id="dc-verify-snapshots-button"[^>]*\sdisabled\b/',
			$html,
			'Verify must start disabled until a period is selected',
		);
		self::assertStringContainsString('id="dc-publish-readiness"', $html);
		self::assertMatchesRegularExpression(
			'/<span[^>]*id="dc-publish-readiness"[^>]*role="status"/',
			$html,
		);
		self::assertStringContainsString('id="dc-period-form"', $html);
		self::assertStringContainsString('for="dc-period-start"', $html);
		self::assertStringContainsString('for="dc-period-end"', $html);
		self::assertDoesNotMatchRegularExpression(
			'/<tbody id="dc-periods-table-body"[^>]*><\/tbody>/',
			$html,
			'Periods tbody must not ship empty — first paint needs a loading row',
		);
	}

	public function testPlannerWithoutReopenFlag(): void
	{
		$html = $this->render(['isAppAdmin' => false, 'role' => 'planner', 'roleLabel' => 'Planner']);
		self::assertStringContainsString('data-can-reopen="0"', $html);
	}

	public function testAdminCanReopenFlag(): void
	{
		$html = $this->render();
		self::assertStringContainsString('data-can-reopen="1"', $html);
	}
}

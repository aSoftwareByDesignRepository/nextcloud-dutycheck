<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;

/**
 * Rolling calendar-month ensure must stay wired for Tobias-style continuous months.
 */
final class EnsureCalendarMonthContractTest extends TestCase
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

	public function testServiceExposesEnsureOpenCalendarMonth(): void
	{
		self::assertTrue(method_exists(RosterService::class, 'ensureOpenCalendarMonth'));
		$src = $this->read('lib/Service/RosterService.php');
		self::assertStringContainsString('function ensureOpenCalendarMonth', $src);
		self::assertStringContainsString('KIND_ENSURE_MONTH', $src);
		self::assertStringContainsString('INVALID_YEAR_MONTH', $src);
		self::assertStringContainsString('ENSURE_MONTH_IN_PROGRESS', $src);
	}

	public function testApiRouteAndControllerAreWired(): void
	{
		$routes = $this->read('appinfo/routes.php');
		self::assertStringContainsString("rosterApi#ensureCalendarMonth", $routes);
		self::assertStringContainsString('/api/periods/ensure-calendar-month', $routes);
		$ctrl = $this->read('lib/Controller/RosterApiController.php');
		self::assertStringContainsString('function ensureCalendarMonth', $ctrl);
		self::assertStringContainsString('requirePlannerOrAdmin', $ctrl);
	}

	public function testRosterUiHasMonthNavigator(): void
	{
		$tpl = $this->read('templates/roster.php');
		$js = $this->read('js/roster.js');
		$css = $this->read('css/app.css');
		self::assertStringContainsString('id="dc-roster-month-prev"', $tpl);
		self::assertStringContainsString('id="dc-roster-month-next"', $tpl);
		self::assertStringContainsString('id="dc-roster-month-today"', $tpl);
		self::assertStringContainsString('ensure-calendar-month', $js);
		self::assertStringContainsString('goToCalendarMonth', $js);
		self::assertStringContainsString('dc-roster-month-nav', $css);
		self::assertStringContainsString('gridMonthClamp', $js);
		self::assertStringContainsString('currentCompanyYearMonth', $js);
		self::assertStringContainsString('Step through months continuously', $tpl);
		// Month nav must bind before the initial ensure — otherwise #dc-roster-grid
		// (present in the template) makes waitForSelector return while clicks are dead.
		self::assertStringContainsString(
			"wireMonthNavigator();\n\t\tawait Promise.all([",
			$js,
			'wireMonthNavigator must run before initial goToCalendarMonth Promise.all',
		);
	}

	public function testLockKindRegistered(): void
	{
		$src = $this->read('lib/Service/PeriodLockService.php');
		self::assertStringContainsString("KIND_ENSURE_MONTH = 'ensure_mo'", $src);
	}
}

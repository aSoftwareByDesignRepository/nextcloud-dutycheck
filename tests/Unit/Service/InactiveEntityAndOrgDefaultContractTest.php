<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static contract pins for the 2026-10-22 sibling sweep — six defect
 * classes of the same family as the shipped fixes:
 *
 *   - inactive entities must not participate in reads or writes
 *     (shift templates, employees, locations)
 *   - the organisation default break must be inherited by every create
 *     path that persists break minutes (suggest, manual assignment,
 *     open shift)
 *   - persisted reference IDs must be validated server-side
 *
 * Behavioural coverage lives in the service unit tests and the live
 * probe; these legs ensure the guards cannot be silently deleted in a
 * refactor.
 */
final class InactiveEntityAndOrgDefaultContractTest extends TestCase
{
	private static string $libDir;
	private static string $appDir;

	public static function setUpBeforeClass(): void
	{
		self::$appDir = \dirname(__DIR__, 3);
		self::$libDir = self::$appDir . '/lib';
	}

	private function src(string $rel): string
	{
		$body = @file_get_contents(self::$libDir . '/' . $rel);
		self::assertIsString($body, "$rel missing");
		return (string) $body;
	}

	public function testOnDutyReaderRequiresActiveEmployee(): void
	{
		$body = $this->src('Integration/MaintenanceCheckOnDutyReader.php');
		self::assertStringContainsString(
			"eq('e.active'",
			$body,
			'onDutyToday() must filter oc_dc_employees.active — deactivated '
			. 'employees must never be reported as on duty to peer apps'
		);
	}

	public function testRotationAssignRejectsInactiveEmployee(): void
	{
		$body = $this->src('Service/RotationPatternService.php');
		$start = strpos($body, 'function assertEmployeeInCompany');
		self::assertNotFalse($start);
		$block = substr($body, (int) $start, 1500);
		self::assertStringContainsString(
			"\$row['active']",
			$block,
			'assignPattern() must reject deactivated employees server-side — '
			. 'the UI filters its select but the API is reachable directly'
		);
		self::assertStringContainsString('EMPLOYEE_NOT_FOUND', $block);
	}

	public function testWeekDayReferencesValidatedOnCreateAndUpdate(): void
	{
		$body = $this->src('Service/RotationPatternService.php');
		self::assertStringContainsString(
			'function assertWeekDayReferences',
			$body,
			'pattern week-day locationId/shiftTemplateId must be validated '
			. 'against existing company rows — dangling FKs break planning'
		);
		$create = strpos($body, 'function createPattern');
		$update = strpos($body, 'function updatePattern');
		self::assertNotFalse($create);
		self::assertNotFalse($update);
		self::assertStringContainsString(
			'assertWeekDayReferences',
			substr($body, (int) $create, (int) $update - (int) $create),
			'createPattern() must validate week-day references'
		);
		self::assertStringContainsString(
			'assertWeekDayReferences',
			substr($body, (int) $update),
			'updatePattern() must validate week-day references'
		);
	}

	public function testManualAssignmentInheritsOrgBreakDefault(): void
	{
		$body = $this->src('Service/RosterService.php');
		$start = strpos($body, 'function createAssignment');
		self::assertNotFalse($start);
		$block = substr($body, (int) $start, 4000);
		self::assertStringContainsString(
			"array_key_exists('breakMinutes'",
			$block,
			'createAssignment() must distinguish absent breakMinutes (inherit '
			. 'org default) from an explicit 0'
		);
		self::assertStringContainsString(
			'getDefaultBreakMinutes',
			$block,
			'createAssignment() must resolve the organisation default break '
			. 'when breakMinutes is absent — same gap as suggest-fill had'
		);
	}

	public function testOpenShiftCreateInheritsOrgBreakDefault(): void
	{
		$body = $this->src('Service/OpenShiftService.php');
		$start = strpos($body, 'function create');
		self::assertNotFalse($start);
		$block = substr($body, (int) $start, 4000);
		self::assertStringContainsString(
			"array_key_exists('breakMinutes'",
			$block,
			'OpenShiftService::create() must inherit the org default break '
			. 'when breakMinutes is absent instead of persisting 0'
		);
		self::assertStringContainsString('getDefaultBreakMinutes', $block);
		self::assertStringContainsString(
			'PlanningDefaultsService::parseAssignmentBreakMinutes',
			$block,
			'open-shift break must go through the strict parser — a loose '
			. '(int) cast silently turns garbage into 0'
		);
	}

	public function testOpenShiftFormRendersBreakInputAndSendsIt(): void
	{
		$tpl = @file_get_contents(self::$appDir . '/templates/roster.php');
		self::assertIsString($tpl);
		self::assertStringContainsString(
			'dc-os-break',
			(string) $tpl,
			'the open-shift form must render a break input — a hidden '
			. 'hardcoded 0 is how the org default got wiped in the first place'
		);
		$js = @file_get_contents(self::$appDir . '/js/roster.js');
		self::assertIsString($js);
		$pos = strpos((string) $js, 'dc-os-break');
		self::assertNotFalse($pos, 'roster.js must read the dc-os-break input');
		self::assertStringNotContainsString(
			'breakMinutes: 0',
			(string) $js,
			'no payload builder may emit a literal breakMinutes:0'
		);
	}

	public function testControllerDoesNotSynthesizeAbsentBreakKey(): void
	{
		$body = $this->src('Controller/RosterApiController.php');
		self::assertStringNotContainsString(
			"'breakMinutes' => \$params['breakMinutes'] ?? null",
			$body,
			'the controller must not inject a null breakMinutes key — it '
			. 'defeats the service-level absent check (org-default inherit '
			. 'on create) and wiped stored breaks on every partial update'
		);
		self::assertSame(
			2,
			substr_count($body, "array_key_exists('breakMinutes', \$params)"),
			'create + update must each guard breakMinutes behind '
			. 'array_key_exists so absent stays absent'
		);
	}

	public function testTemplateLocationSelectFiltersInactiveCatalogRows(): void
	{
		$js = @file_get_contents(self::$appDir . '/js/settings.js');
		self::assertIsString($js);
		$pos = strpos((string) $js, 'dc-template-location');
		self::assertNotFalse($pos, 'dc-template-location select not found');
		$block = substr((string) $js, (int) $pos, 3500);
		self::assertStringContainsString(
			'row.active === false',
			$block,
			'the template-location select must drop inactive catalog rows — '
			. 'the write path requires active=1, so offering them is a '
			. 'guaranteed LOCATION_NOT_FOUND (same class as today.js)'
		);
	}
}

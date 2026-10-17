<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\AssignmentKind;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the on-call / standby (Rufbereitschaft) feature.
 *
 * Pins the security-critical invariants: standby must never feed worked-time
 * totals, the company flag gates every standby read/write server-side, kind is
 * immutable, overlap semantics are kind-aware, and legacy clients get
 * shift-only payloads.
 */
final class StandbyFeatureContractTest extends TestCase
{
	private static string $root;
	private static string $roster;
	private static string $apiErr;
	private static string $mobile;
	private static string $routes;
	private static string $migration;

	public static function setUpBeforeClass(): void
	{
		self::$root = dirname(__DIR__, 3);
		self::$roster = (string) file_get_contents(self::$root . '/lib/Service/RosterService.php');
		self::$apiErr = (string) file_get_contents(self::$root . '/lib/Controller/ApiJsonErrorResponse.php');
		self::$mobile = (string) file_get_contents(self::$root . '/lib/Controller/MobileController.php');
		self::$routes = (string) file_get_contents(self::$root . '/appinfo/routes.php');
		self::$migration = (string) file_get_contents(self::$root . '/lib/Migration/Version1021Date20261020120000.php');
	}

	public function testKindVocabulary(): void
	{
		self::assertSame('shift', AssignmentKind::SHIFT);
		self::assertSame('standby', AssignmentKind::STANDBY);
		self::assertTrue(AssignmentKind::isValid('standby'));
		self::assertFalse(AssignmentKind::isValid('oncall'));
		self::assertFalse(AssignmentKind::isValid(1));
		self::assertSame('shift', AssignmentKind::normalize(null));
		self::assertSame('shift', AssignmentKind::normalize(''));
		self::assertSame('standby', AssignmentKind::normalize(' standby '));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_KIND');
		AssignmentKind::normalize('oncall');
	}

	public function testWritePathValidation(): void
	{
		// Equal start/end is legal only for standby (24h duty).
		self::assertMatchesRegularExpression(
			'/\$startTime === \$endTime && !\$isStandby[\s\S]{0,200}?EQUAL_DUTY_TIMES/',
			self::$roster,
		);
		// Standby never carries break minutes.
		self::assertStringContainsString('STANDBY_BREAK_FORBIDDEN', self::$roster);
		// Kind cannot flip after creation.
		self::assertStringContainsString('KIND_IMMUTABLE', self::$roster);
		// Feature flag fails closed on standby writes.
		self::assertStringContainsString('function assertStandbyEnabledForPeriod', self::$roster);
		self::assertStringContainsString('STANDBY_DISABLED', self::$roster);
		// Response-time bound shared with category defaults.
		self::assertStringContainsString('INVALID_RESPONSE_TIME', self::$roster);
	}

	public function testOverlapSemanticsAreKindAware(): void
	{
		// standby↔standby is always a hard conflict.
		self::assertStringContainsString('STANDBY_OVERLAP', self::$roster);
		// 24h standby swallowing a shift is a hard mixed collision…
		self::assertStringContainsString('STANDBY_SHIFT_COLLISION', self::$roster);
		// …while partial mixes stay planner-acknowledgeable soft conflicts.
		self::assertStringContainsString('standby_shift_collision', self::$roster);
		self::assertStringContainsString('standby_overlap', self::$roster);
		self::assertStringContainsString('standby_absence', self::$roster);
		// Absence writes report a standby-specific code.
		self::assertStringContainsString('STANDBY_ABSENCE_CONFLICT', self::$roster);
	}

	public function testStandbyNeverFeedsWorkedTime(): void
	{
		// Conflict engine minute checks run on shifts only.
		self::assertStringContainsString(
			'!AssignmentKind::isStandby($a[\'kind\'] ?? AssignmentKind::SHIFT)',
			self::$roster,
		);
		// Every minutes/on-duty reader excludes standby at the SQL level.
		foreach ([
			'lib/Service/RosterMinutesExportService.php',
			'lib/Service/ThresholdApproachNotifier.php',
			'lib/Service/EffectiveTargetHoursFacadeService.php',
			'lib/Service/TodayBoardService.php',
			'lib/Integration/MaintenanceCheckOnDutyReader.php',
		] as $file) {
			$src = (string) file_get_contents(self::$root . '/' . $file);
			self::assertStringContainsString('AssignmentKind::STANDBY', $src, $file);
			self::assertMatchesRegularExpression('/neq\([^)]*kind[^)]*AssignmentKind::STANDBY/', $src, $file);
		}
	}

	public function testFeatureFlagGatesReads(): void
	{
		// myRoster: flag wins over client capability — fail-closed when the
		// settings service is unavailable.
		$start = strpos(self::$roster, 'function myRoster');
		self::assertNotFalse($start);
		$fn = substr(self::$roster, $start, 9000);
		self::assertStringContainsString('isStandbyEnabled', $fn);
		self::assertStringContainsString('includeStandby', $fn);
		// iCal feed gates standby on the same flag.
		self::assertMatchesRegularExpression(
			'/publishedAssignmentsForEmployee[\s\S]{0,6000}?isStandbyEnabled/',
			self::$roster,
		);
		// Standby roster endpoint requires the flag.
		self::assertMatchesRegularExpression(
			'/standbyRosterData[\s\S]{0,1500}?isStandbyEnabled/',
			self::$roster,
		);
	}

	public function testAcknowledgeHonoursStandbyFlag(): void
	{
		$start = strpos(self::$roster, 'function acknowledgeAssignment');
		self::assertNotFalse($start);
		$fn = substr(self::$roster, $start, 4500);
		self::assertStringContainsString('STANDBY_ACKNOWLEDGE_DISABLED', $fn);
		self::assertStringContainsString('isStandbyAcknowledgeEnabled', $fn);
		// Shift rows keep the existing acknowledgeable contract; standby rows
		// are only acknowledgeable when the company toggle is on.
		self::assertStringContainsString('acknowledgeable', self::$roster);
	}

	public function testCopyAndAuditPreserveKind(): void
	{
		$start = strpos(self::$roster, 'function copyPeriodAssignments');
		self::assertNotFalse($start);
		$fn = substr(self::$roster, $start, 9000);
		foreach (['kind', 'standbyCategoryId', 'responseTimeMinutes'] as $field) {
			self::assertStringContainsString($field, $fn);
		}
		foreach (['standby_created', 'standby_updated', 'standby_deleted'] as $action) {
			self::assertStringContainsString("'{$action}'", self::$roster);
		}
	}

	public function testErrorCodeMapping(): void
	{
		// Uniform 404 — no existence oracle for foreign categories/periods.
		self::assertStringContainsString('STANDBY_CATEGORY_NOT_FOUND', self::$apiErr);
		self::assertMatchesRegularExpression('/STANDBY_CATEGORY_NOT_FOUND[^=]*=>\s*404/', self::$apiErr);
		self::assertMatchesRegularExpression("/'STANDBY_OVERLAP'[^=]*=>\s*409/", self::$apiErr);
		// Validation failures are 422.
		foreach (['STANDBY_DISABLED', 'STANDBY_ABSENCE_CONFLICT', 'STANDBY_SHIFT_COLLISION', 'STANDBY_BREAK_FORBIDDEN', 'STANDBY_CATEGORY_INVALID', 'INVALID_KIND', 'INVALID_RESPONSE_TIME', 'KIND_IMMUTABLE', 'STANDBY_CATEGORY_IN_USE', 'STANDBY_ACKNOWLEDGE_DISABLED'] as $code) {
			self::assertStringContainsString("'{$code}'", self::$apiErr);
		}
	}

	public function testRoutesRegistered(): void
	{
		foreach (['standby/categories', 'standby/roster'] as $needle) {
			self::assertStringContainsString($needle, self::$routes);
		}
	}

	public function testMobileContract(): void
	{
		// Capabilities advertised.
		self::assertStringContainsString('dutycheck.standby', self::$mobile);
		self::assertStringContainsString('dutycheck.standbyAcknowledge', self::$mobile);
		// Standby rows are opt-in: only caps=standby clients receive them.
		self::assertMatchesRegularExpression("/getParam\('caps'/", self::$mobile);
		self::assertStringContainsString("'standby'", self::$mobile);
		// Capability flags come from the server-side settings, not client claims.
		self::assertStringContainsString('standbyEnabled', self::$mobile);
		self::assertStringContainsString('standbyAcknowledgeEnabled', self::$mobile);
	}

	public function testMigrationCoversSchemaAndBackfill(): void
	{
		self::assertStringContainsString("'kind'", self::$migration);
		self::assertStringContainsString('standby_category_id', self::$migration);
		self::assertStringContainsString('response_time_minutes', self::$migration);
		self::assertStringContainsString('dc_standby_categories', self::$migration);
		self::assertStringContainsString('dc_asg_emp_day_kind_idx', self::$migration);
		// Existing active slot keys must be rewritten to the kind-aware format
		// inside the same migration — otherwise old/new formats coexist and the
		// unique index silently fails to protect same-kind duplicates.
		self::assertStringContainsString('slot_key', self::$migration);
		self::assertStringContainsString('AssignmentSlotKey', self::$migration);
	}

	public function testUninstallAndBackupCoverCategoryTable(): void
	{
		$uninstall = (string) file_get_contents(self::$root . '/lib/Repair/UninstallDropTables.php');
		$backup = (string) file_get_contents(self::$root . '/lib/Service/UpgradeBackupCatalog.php');
		self::assertStringContainsString('dc_standby_categories', $uninstall);
		self::assertStringContainsString('dc_standby_categories', $backup);
	}

	public function testSettingsDefaultsAndPatchSurface(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$companies = $this->createMock(CompanyService::class);
		$svc = new SelfServiceSettingsService($db, $companies);

		// Fail-closed defaults: standby is opt-in per company.
		$defaults = SelfServiceSettingsService::defaultsForNewCompany();
		self::assertFalse($defaults['standby_enabled']);
		self::assertFalse($defaults['standby_acknowledge_enabled']);

		$normalize = (new \ReflectionClass($svc))->getMethod('normalize');
		$out = $normalize->invoke($svc, []);
		self::assertFalse($out['standby_enabled']);
		self::assertFalse($out['standby_acknowledge_enabled']);

		// CamelCase patch keys map onto the stored flags.
		$filter = (new \ReflectionClass($svc))->getMethod('filterPatch');
		$patch = $filter->invoke($svc, [
			'standbyEnabled' => true,
			'standbyAcknowledgeEnabled' => true,
		]);
		self::assertTrue($patch['standby_enabled']);
		self::assertTrue($patch['standby_acknowledge_enabled']);
	}
}

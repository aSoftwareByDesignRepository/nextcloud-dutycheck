<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Controller\ApiJsonErrorResponse;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\RosterService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Close-out integrity: conflict message keys, HTTP mapping, GDPR purge wiring.
 */
final class IntegrityCloseoutContractTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	public function testConflictMessageKeysIncludeCalendarWeekAndBreak(): void
	{
		$keys = RosterService::rosterApiConflictMessageKeys();
		self::assertContains('Calendar-week hard cap exceeded for employee', $keys);
		self::assertContains('Calendar-week soft cap exceeded for employee', $keys);
		self::assertContains('Break is shorter than required for this shift length', $keys);
		self::assertContains('Location is understaffed relative to template headcount', $keys);
	}

	public function testStaleVersionMapsToConflict(): void
	{
		self::assertSame(409, ApiJsonErrorResponse::statusForInvalidArgument('STALE_VERSION'));
		self::assertSame(422, ApiJsonErrorResponse::statusForInvalidArgument('EXPECTED_VERSION_REQUIRED'));
	}

	public function testAssignmentUpdateRequiresVersionCasInSource(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		self::assertStringContainsString("'STALE_VERSION'", $src);
		self::assertStringContainsString("'EXPECTED_VERSION_REQUIRED'", $src);
		self::assertStringContainsString('ASSIGNMENT_CANCELLED', $src);
		self::assertMatchesRegularExpression(
			'/andWhere\(\$qb->expr\(\)->eq\(\'version\'/',
			$src,
		);
		self::assertStringContainsString('conflict_thresholds_json', $src);
		self::assertStringContainsString('policyThresholdsForPeriod', $src);
		self::assertStringContainsString('weekly_hours_hard_cap', $src);
		self::assertStringContainsString('break_too_short', $src);
		self::assertMatchesRegularExpression(
			'/createAssignment[\s\S]{0,3500}?assignmentHasSlotKeyColumn\(\)\)[\s\S]{0,200}?SCHEMA_NOT_READY/',
			$src,
		);
	}

	public function testRepairEnsuresSlotKeyUniqueIndex(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/EnsureDutyCheckSchema.php');
		self::assertStringContainsString('dc_asg_skey_uidx', $src);
		self::assertStringContainsString('missingCriticalIndexes', $src);
		self::assertStringContainsString('CRITICAL_INDEXES', $src);
	}

	public function testRetentionProtectsLatestCloseAcrossReopen(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/SnapshotRetentionService.php');
		self::assertStringContainsString('latestCloseSnapshotIdsPerPeriod', $src);
		self::assertStringContainsString('closedPeriodCloseSnapshotIds', $src);
	}

	public function testUpdateAndCancelRequireSlotKeyFailClosed(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		self::assertMatchesRegularExpression(
			'/function updateAssignment[\s\S]{0,4500}?assignmentHasSlotKeyColumn\(\)\)[\s\S]{0,200}?SCHEMA_NOT_READY/',
			$src,
		);
		self::assertMatchesRegularExpression(
			'/function cancelAssignment[\s\S]{0,1200}?assignmentHasSlotKeyColumn\(\)\)[\s\S]{0,200}?SCHEMA_NOT_READY/',
			$src,
		);
	}

	public function testTransferRewritesSlotKeyWithDonorCas(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		self::assertMatchesRegularExpression(
			'/function transferAssignmentEmployee[\s\S]{0,2500}?AssignmentSlotKey::forActive/',
			$src,
		);
		self::assertMatchesRegularExpression(
			'/function transferAssignmentEmployee[\s\S]{0,3500}?eq\(\'employee_id\'/',
			$src,
		);
		self::assertMatchesRegularExpression(
			'/function transferAssignmentEmployee[\s\S]{0,3500}?ASSIGNMENT_TRANSFER_STALE/',
			$src,
		);
	}

	public function testUserDeletedListenerUsesFullPurge(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Listener/UserDeletedListener.php');
		self::assertStringContainsString('purgeUser(', $src);
		self::assertStringNotContainsString('purgeUserDutyRole(', $src);
	}

	public function testPurgeUserScrubsPolicyListsAndEmployeeLink(): void
	{
		$config = $this->createMock(IConfig::class);
		$stored = [
			AccessControlService::KEY_APP_ADMINS => '["gone","keep"]',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => '["gone","other"]',
		];
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $stored[$key] ?? $default,
		);
		$config->expects(self::exactly(2))->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			},
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnMap([
			['dc_employees', true],
			['dc_mobile_seats', true],
			['dc_user_roles', true],
			['dc_company_members', true],
			['dc_planner_locs', true],
			['dc_user_preferences', true],
		]);

		$qbCalls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$qbCalls) {
			$qbCalls++;
			$qb = $this->getMockBuilder(\stdClass::class)
				->addMethods(['delete', 'update', 'set', 'where', 'expr', 'createNamedParameter', 'executeStatement'])
				->getMock();
			$expr = new class {
				public function eq(string $a, mixed $b): string
				{
					return 'eq';
				}
			};
			$qb->method('delete')->willReturnSelf();
			$qb->method('update')->willReturnSelf();
			$qb->method('set')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			$qb->method('executeStatement')->willReturn(1);
			return $qb;
		});

		$access = new AccessControlService(
			$db,
			$config,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
		$access->purgeUser('gone');

		self::assertSame('["keep"]', $stored[AccessControlService::KEY_APP_ADMINS]);
		self::assertSame('["other"]', $stored[AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS]);
		self::assertGreaterThanOrEqual(6, $qbCalls);
	}

	public function testPrintTemplateIncludesIntegrityFooter(): void
	{
		$html = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/roster-print.php');
		self::assertStringContainsString('dc-print-integrity', $html);
		self::assertStringContainsString('snapshotHash', $html);
	}

	public function testRosterTemplateIncludesAccessibleGrid(): void
	{
		$html = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/roster.php');
		$js = (string) file_get_contents(dirname(__DIR__, 3) . '/js/roster.js');
		self::assertStringContainsString('id="dc-roster-grid"', $html);
		self::assertStringContainsString('aria-labelledby="dc-assignments-title"', $html);
		self::assertStringNotContainsString('role="grid"', $html, 'grid role is assigned in JS once rows exist');
		self::assertStringContainsString("setAttribute('role', 'grid')", $js);
		self::assertStringContainsString("setAttribute('role', 'status')", $js);
		self::assertStringContainsString('id="dc-roster-bulk-apply"', $html);
	}

	public function testMigration1014AddsVersionAndFrozenThresholds(): void
	{
		$src = (string) file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version1014Date20260727120000.php',
		);
		self::assertStringContainsString("'version'", $src);
		self::assertStringContainsString('conflict_thresholds_json', $src);
		self::assertStringContainsString('min_headcount', $src);
	}
}

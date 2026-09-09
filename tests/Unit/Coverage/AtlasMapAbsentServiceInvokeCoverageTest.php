<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Coverage;

use OCA\DutyCheck\Service\AssignmentSlotKey;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\IconCatalog;
use OCA\DutyCheck\Service\LicenseUiStrings;
use OCA\DutyCheck\Service\MobileDemoSeedResult;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\QualificationService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SeatRank;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\SettingsSectionCatalog;
use OCA\DutyCheck\Service\UpgradeBackupCatalog;
use OCA\DutyCheck\Service\UpgradeBackupIntegrity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 REJECT r2 — invoke shipping-reachable Service publics previously ABSENT from coverage-map.
 */
final class AtlasMapAbsentServiceInvokeCoverageTest extends TestCase
{
	public function testAbsentReachableServicePublicsInvoke(): void
	{
		self::assertStringStartsWith('a:', AssignmentSlotKey::forActive(1, 2, '2026-09-08', '08:00', '16:00'));
		self::assertSame('c:42', AssignmentSlotKey::forCancelled(42));

		$defaults = ConflictPolicyService::defaults();
		self::assertIsArray($defaults);
		self::assertNotEmpty($defaults);

		$svg = IconCatalog::render('layout-grid', 'dc-icon');
		self::assertStringContainsString('<svg', $svg);
		self::assertSame('', IconCatalog::render('__unknown_icon__'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$panel = LicenseUiStrings::forPanel($l10n);
		self::assertArrayHasKey('loading', $panel);
		self::assertArrayHasKey('saveButton', $panel);

		$result = new MobileDemoSeedResult(
			'emp',
			'unseated',
			1,
			2,
			3,
			'2026-09-08',
			'2026-09-09',
			10,
			11,
			'open',
			true,
			true,
		);
		$arr = $result->toArray();
		self::assertSame('emp', $arr['employeeUserId']);
		self::assertSame(10, $arr['assignmentId']);

		self::assertSame(30, PlanningDefaultsService::parseAssignmentBreakMinutes('30'));
		self::assertSame(0, PlanningDefaultsService::parseAssignmentBreakMinutes(null));

		$conflicts = QualificationService::evaluateHeldAgainstRequired(
			7,
			'2026-09-08',
			[['id' => 5, 'name' => 'Forklift']],
			[],
		);
		self::assertCount(1, $conflicts);
		self::assertSame('qualification_missing', $conflicts[0]['type']);

		$keys = RosterService::rosterApiConflictMessageKeys();
		self::assertIsArray($keys);
		self::assertNotEmpty($keys);

		$setup = RosterService::deriveSetupState(true, 2, 1, 1);
		self::assertIsArray($setup);
		self::assertTrue($setup['readyForPlanning']);

		self::assertTrue(RosterService::dateWithinInclusiveRange('2026-09-08', '2026-09-01', '2026-09-30'));
		self::assertFalse(RosterService::dateWithinInclusiveRange('2026-10-01', '2026-09-01', '2026-09-30'));

		self::assertSame('dutycheck', RosterService::absenceCollisionSourceFromSpans('2026-09-08', [
			['startDate' => '2026-09-01', 'endDate' => '2026-09-10', 'source' => 'dutycheck'],
		]));
		self::assertNull(RosterService::absenceCollisionSourceFromSpans('2026-09-08', []));

		$ranks = SeatRank::ranks([
			['id' => 2, 'assignedAt' => 200],
			['id' => 1, 'assignedAt' => 100],
		]);
		self::assertSame([1 => 1, 2 => 2], $ranks);
		self::assertTrue(SeatRank::isWithinLimit([
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
		], 1, 1));
		self::assertFalse(SeatRank::isWithinLimit([
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
		], 2, 1));

		$companyDefaults = SelfServiceSettingsService::defaultsForNewCompany();
		self::assertIsArray($companyDefaults);
		self::assertTrue($companyDefaults['push_quiet_hours_enabled']);

		$routeReq = SettingsSectionCatalog::routeRequirement();
		self::assertNotSame('', $routeReq);

		self::assertTrue(UpgradeBackupCatalog::isBackupTable('dc_employees'));
		self::assertFalse(UpgradeBackupCatalog::isBackupTable('pc_projects'));
		self::assertSame(5, UpgradeBackupCatalog::clampMaxSnapshots(5));
		self::assertSame(1, UpgradeBackupCatalog::clampMaxSnapshots(0));
		$existing = UpgradeBackupCatalog::existingBackupTables(static fn (string $t): bool => $t === 'dc_employees');
		self::assertSame(['dc_employees'], $existing);
		$sorted = UpgradeBackupCatalog::sortedRestoreTables(['dc_employees', 'dc_companies']);
		self::assertSame('dc_companies', $sorted[0]);

		UpgradeBackupIntegrity::assertSnapshotId('20260908T120000Z-deadbeef');
		self::assertSame('manual', UpgradeBackupIntegrity::normalizeReason(''));
		self::assertSame('pre-update', UpgradeBackupIntegrity::normalizeReason('pre-update'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedColumn('employee_id'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedColumn('id;drop'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedTableName('dc_employees'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedTableName('dc;drop'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedConfigKey('installed_version'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedConfigKey('../evil'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedPreferenceUserId('alice'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedPreferenceUserId('../evil'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedPreferenceKey('locale'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedPreferenceKey('../evil'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedAppDataName('roster_files'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedAppDataName('..'));
		UpgradeBackupIntegrity::assertAppDataFolderName('roster_files');
		UpgradeBackupIntegrity::assertAppDataNodeName('manifest.json');

		$tables = ['dc_employees' => ['checksum' => 'abc', 'rowCount' => 0]];
		$integrity = hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR));
		UpgradeBackupIntegrity::validateManifest(
			[
				'format' => UpgradeBackupCatalog::FORMAT_VERSION,
				'appId' => UpgradeBackupCatalog::APP_ID,
				'id' => '20260908T120000Z-deadbeef',
				'complete' => true,
				'integrity' => $integrity,
				'tables' => $tables,
			],
			'20260908T120000Z-deadbeef',
			$tables,
		);
		$rows = [['id' => 1]];
		$content = json_encode($rows, JSON_THROW_ON_ERROR);
		UpgradeBackupIntegrity::assertTablePayload('dc_employees', $content, [
			'checksum' => hash('sha256', $content),
			'rowCount' => 1,
		]);
	}
}

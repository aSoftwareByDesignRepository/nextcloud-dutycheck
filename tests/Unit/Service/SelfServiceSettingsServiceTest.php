<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SelfServiceSettingsServiceTest extends TestCase
{
	public function testNewCompanyDefaultsQuietOn(): void
	{
		$d = SelfServiceSettingsService::defaultsForNewCompany();
		self::assertTrue($d['push_quiet_hours_enabled']);
		self::assertFalse($d['peer_roster_visibility']);
		self::assertFalse($d['rotation_patterns_enabled']);
		self::assertFalse($d['soll_from_duty']);
		self::assertSame(SelfServiceSettingsService::SWAP_PLANNER_REQUIRED, $d['swap_approval_mode']);
	}

	public function testSafeDefaultsQuietOffForLegacy(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$companies = $this->createMock(CompanyService::class);
		// No settings_json column → empty stored → SAFE_DEFAULTS
		$svc = new SelfServiceSettingsService($db, $companies);
		// Force path without schema by mocking SchemaProbe is hard; instead test normalize via update filter:
		$api = (new \ReflectionClass($svc))->getMethod('normalize');
		$out = $api->invoke($svc, []);
		self::assertFalse($out['push_quiet_hours_enabled']);
		self::assertFalse($out['peer_roster_visibility']);
		self::assertSame([1, 2, 3, 4], $out['rotation_allowed_cycle_weeks']);
	}

	public function testCamelCasePatchAccepted(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$companies = $this->createMock(CompanyService::class);
		$svc = new SelfServiceSettingsService($db, $companies);
		$filter = (new \ReflectionClass($svc))->getMethod('filterPatch');
		$out = $filter->invoke($svc, [
			'rotationPatternsEnabled' => true,
			'peerRosterVisibility' => true,
			'swapApprovalMode' => 'bilateral_auto',
			'unknownEvil' => 'x',
		]);
		self::assertTrue($out['rotation_patterns_enabled']);
		self::assertTrue($out['peer_roster_visibility']);
		self::assertSame('bilateral_auto', $out['swap_approval_mode']);
		self::assertArrayNotHasKey('unknownEvil', $out);
	}

	/**
	 * The 0.3.4 bug: the web client posts urlencoded bodies, so unchecked
	 * checkboxes arrive as the literal string "false" — and `(bool) "false"`
	 * turned every saved toggle ON. filterPatch must parse strictly.
	 */
	public function testFilterPatchParsesUrlencodedBoolStrings(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$filter = (new \ReflectionClass($svc))->getMethod('filterPatch');
		$out = $filter->invoke($svc, [
			'rotationPatternsEnabled' => 'true',
			'peerRosterVisibility' => 'false',
			'sollFromDuty' => '0',
			'claimRequiresPlanner' => '1',
			'blackoutsEnabled' => 'off',
			'preferencesEnabled' => 'on',
		]);
		self::assertSame(true, $out['rotation_patterns_enabled']);
		self::assertSame(false, $out['peer_roster_visibility']);
		self::assertSame(false, $out['soll_from_duty']);
		self::assertSame(true, $out['claim_requires_planner']);
		self::assertSame(false, $out['blackouts_enabled']);
		self::assertSame(true, $out['preferences_enabled']);
	}

	public function testFilterPatchRejectsAmbiguousBoolString(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$filter = (new \ReflectionClass($svc))->getMethod('filterPatch');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_BOOLEAN');
		$filter->invoke($svc, ['rotationPatternsEnabled' => 'banana']);
	}

	/**
	 * Stored settings_json may contain legacy representations (0/1 ints from
	 * older releases). normalize() stays lenient on reads so a corrupted row
	 * cannot brick every settings read — strictness lives at the write boundary.
	 */
	public function testNormalizeCoercesStoredIntFlags(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$normalize = (new \ReflectionClass($svc))->getMethod('normalize');
		$out = $normalize->invoke($svc, [
			'rotation_patterns_enabled' => 1,
			'peer_roster_visibility' => 0,
		]);
		self::assertTrue($out['rotation_patterns_enabled']);
		self::assertFalse($out['peer_roster_visibility']);
	}

	/**
	 * Every normalize() branch must fire: feeding a non-default value for each
	 * key proves no match arm silently falls through to `default` (kills
	 * MatchArmRemoval mutants across the whole table).
	 */
	public function testNormalizeExercisesEveryKeyBranch(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$normalize = (new \ReflectionClass($svc))->getMethod('normalize');
		$out = $normalize->invoke($svc, [
			// bool keys: 1/int must coerce true (lenient stored path)
			'rotation_patterns_enabled' => 1,
			'soll_from_duty' => 1,
			'peer_roster_visibility' => 1,
			'allow_cross_location_swaps' => 1,
			'claim_requires_planner' => 1,
			'preferences_enabled' => 1,
			'preference_ranking_on_suggest' => 1,
			'shift_terminal_plan_strip' => 1,
			'today_board_enabled' => 1,
			'blackouts_enabled' => 1,
			'push_quiet_hours_enabled' => 1,
			'push_allow_urgent_during_quiet' => 1,
			'user_may_disable_quiet' => 1,
			// non-bool arms
			'swap_approval_mode' => 'bilateral_auto',
			'rotation_allowed_cycle_weeks' => [2],
			'early_end_latest' => '11:30',
			'late_start_earliest' => '15:45',
			'push_quiet_hours_start' => '21:00',
			'push_quiet_hours_end' => '07:15',
		]);
		foreach ([
			'rotation_patterns_enabled', 'soll_from_duty', 'peer_roster_visibility',
			'allow_cross_location_swaps', 'claim_requires_planner', 'preferences_enabled',
			'preference_ranking_on_suggest', 'shift_terminal_plan_strip', 'today_board_enabled',
			'blackouts_enabled', 'push_quiet_hours_enabled', 'push_allow_urgent_during_quiet',
			'user_may_disable_quiet',
		] as $key) {
			self::assertTrue($out[$key], $key . ' must coerce to true');
		}
		self::assertSame('bilateral_auto', $out['swap_approval_mode']);
		self::assertSame([2], $out['rotation_allowed_cycle_weeks']);
		self::assertSame('11:30', $out['early_end_latest']);
		self::assertSame('15:45', $out['late_start_earliest']);
		self::assertSame('21:00', $out['push_quiet_hours_start']);
		self::assertSame('07:15', $out['push_quiet_hours_end']);
	}

	/**
	 * Invalid stored values must fall back to SAFE_DEFAULTS — kills the
	 * equivalent-mutant survivors where removing a match arm would pass the
	 * raw value through untouched.
	 */
	public function testNormalizeFallsBackOnInvalidStoredValues(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$normalize = (new \ReflectionClass($svc))->getMethod('normalize');
		$out = $normalize->invoke($svc, [
			'swap_approval_mode' => 'anything_goes',
			'rotation_allowed_cycle_weeks' => 'not-an-array',
			'early_end_latest' => '25:99',
			'late_start_earliest' => 'garbage',
			'push_quiet_hours_start' => 'noon',
			'push_quiet_hours_end' => '99:99',
		]);
		self::assertSame('planner_required', $out['swap_approval_mode']);
		self::assertSame([1, 2, 3, 4], $out['rotation_allowed_cycle_weeks']);
		self::assertSame('12:00', $out['early_end_latest']);
		self::assertSame('14:00', $out['late_start_earliest']);
		self::assertSame('22:00', $out['push_quiet_hours_start']);
		self::assertSame('06:00', $out['push_quiet_hours_end']);
	}

	public function testDecodeRawHandlesEmptyCorruptAndNonArrayPayloads(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$m = (new \ReflectionClass($svc))->getMethod('decodeRaw');
		self::assertSame([], $m->invoke($svc, null));
		self::assertSame([], $m->invoke($svc, ''));
		self::assertSame([], $m->invoke($svc, '   '));
		self::assertSame([], $m->invoke($svc, '{corrupt'));
		self::assertSame([], $m->invoke($svc, '42'));
		self::assertSame([], $m->invoke($svc, '"just a string"'));
		self::assertSame(['a' => 1], $m->invoke($svc, '{"a":1}'));
		self::assertSame(['a' => 1], $m->invoke($svc, '  {"a":1}  '));
	}

	public function testNormalizeTimeRejectsMalformedAndAnchorsInput(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$m = (new \ReflectionClass($svc))->getMethod('normalizeTime');
		self::assertSame('12:00', $m->invoke($svc, ' 12:00 ', '22:00'));
		self::assertSame('22:00', $m->invoke($svc, '24:00', '22:00'));
		self::assertSame('22:00', $m->invoke($svc, '9:30', '22:00'));
		self::assertSame('22:00', $m->invoke($svc, '12:60', '22:00'));
		// Regex anchors must hold: partial matches inside garbage are rejected.
		self::assertSame('22:00', $m->invoke($svc, 'x12:00', '22:00'));
		self::assertSame('22:00', $m->invoke($svc, '12:00x', '22:00'));
	}

	public function testNormalizeCycleWeeksFiltersCastsAndDeduplicates(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$m = (new \ReflectionClass($svc))->getMethod('normalizeCycleWeeks');
		self::assertSame([1, 2, 3, 4], $m->invoke($svc, 'nope'));
		self::assertSame([1, 2, 3, 4], $m->invoke($svc, []));
		self::assertSame([1, 2, 3, 4], $m->invoke($svc, [9, 0]));
		self::assertSame([2, 3], $m->invoke($svc, [2, 2, 9, '3']));
	}

	public function testGetForCompanyRejectsNonPositiveIdsWithoutDbAccess(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$svc = new SelfServiceSettingsService($db, $this->createMock(CompanyService::class));
		$out = $svc->getForCompany(0);
		self::assertFalse($out['rotation_patterns_enabled']);
		self::assertSame('planner_required', $out['swap_approval_mode']);
		self::assertSame($out, $svc->getForCompany(-3));
	}

	public function testUpdateForCompanyRejectsNonPositiveIds(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_NOT_FOUND');
		$svc->updateForCompany(0, ['rotation_patterns_enabled' => true], 'actor');
	}

	public function testFilterPatchDropsUnknownAndReservedKeys(): void
	{
		$svc = new SelfServiceSettingsService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
		);
		$m = (new \ReflectionClass($svc))->getMethod('filterPatch');
		$out = $m->invoke($svc, [
			'totally_unknown' => 'x',
			'user_may_disable_quiet' => 'true',
			'userMayDisableQuiet' => 'true',
			'rotationPatternsEnabled' => 'false',
		]);
		self::assertSame(['rotation_patterns_enabled' => false], $out);
	}

	public function testDefaultsForNewCompanySeedsQuietHoursMarker(): void
	{
		$defaults = SelfServiceSettingsService::defaultsForNewCompany();
		self::assertTrue($defaults['push_quiet_hours_enabled']);
		self::assertTrue($defaults['_ga_quiet_seeded']);
	}
}

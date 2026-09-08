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
		$api->setAccessible(true);
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
		$filter->setAccessible(true);
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
}

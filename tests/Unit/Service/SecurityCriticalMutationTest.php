<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Exception\MobileGateException;
use OCA\DutyCheck\License\Dty2Codec;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\SeatRank;
use OCA\DutyCheck\Service\SnapshotRetentionService;
use OCA\DutyCheck\Service\LicenseService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Targeted killers for Infection escapes on authorization / license / retention
 * boundaries. Each assertion maps to a known escaped mutant (see
 * tests/Mutation/logs/infection.log).
 */
final class SecurityCriticalMutationTest extends TestCase
{
	public function testIsSystemAdminRejectsEmptyUserId(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects(self::never())->method('isAdmin');
		$svc = $this->access($groups);
		self::assertFalse($svc->isSystemAdmin(''));
	}

	public function testIsSystemAdminRequiresAdminMembership(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnMap([
			['alice', true],
			['bob', false],
		]);
		$svc = $this->access($groups);
		self::assertTrue($svc->isSystemAdmin('alice'));
		self::assertFalse($svc->isSystemAdmin('bob'));
	}

	public function testIsAppAdminTrueForListedAppAdminEvenIfNotSystemAdmin(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'app_admin_user_ids') {
					return json_encode(['planner-boss'], JSON_THROW_ON_ERROR);
				}
				return $default;
			},
		);
		$svc = new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$groups,
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
		self::assertTrue($svc->isAppAdmin('planner-boss'));
		self::assertFalse($svc->isAppAdmin('nobody'));
	}

	public function testCanUseAppDeniesWhenRestrictionOnAndUserNotOnAllowlist(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'access_restriction_enabled' => '1',
					'access_allowed_user_ids' => '["allowed"]',
					'access_allowed_group_ids' => '[]',
					'app_admin_user_ids' => '[]',
					default => $default,
				};
			},
		);

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(42);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new AccessControlService(
			$db,
			$config,
			$groups,
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
		// Stranger is blocked by the restriction gate (never reaches the link lookup).
		self::assertFalse($svc->canUseApp('stranger'));
		// Allow-listed + linked employee must still be admitted — kills the
		// LogicalNot mutant that inverts the allowlist check.
		self::assertTrue($svc->canUseApp('allowed'));
	}

	public function testSeatRankOrdersByAssignedAtThenId(): void
	{
		$seats = [
			['id' => 30, 'assignedAt' => 200],
			['id' => 10, 'assignedAt' => 100],
			['id' => 20, 'assignedAt' => 100],
		];
		$ranks = SeatRank::ranks($seats);
		self::assertSame([10 => 1, 20 => 2, 30 => 3], $ranks);
		// limit 0 must reject everyone (kills <=0 → <0)
		self::assertFalse(SeatRank::isWithinLimit($seats, 10, 0));
		self::assertFalse(SeatRank::isWithinLimit($seats, 10, -1));
		self::assertTrue(SeatRank::isWithinLimit($seats, 10, 1));
		self::assertFalse(SeatRank::isWithinLimit($seats, 20, 1));
	}

	public function testSeatRankTieBreakUsesIdAscending(): void
	{
		$seats = [
			['id' => 5, 'assignedAt' => 50],
			['id' => 2, 'assignedAt' => 50],
		];
		self::assertSame([2 => 1, 5 => 2], SeatRank::ranks($seats));
	}

	public function testBootstrapEnabledForUserRequiresAllThreeSeatFlags(): void
	{
		$cases = [
			['hasLicense' => true, 'licenseValid' => true, 'seatAssigned' => true, 'seatWithinLimit' => true, 'expect' => true],
			['hasLicense' => true, 'licenseValid' => false, 'seatAssigned' => true, 'seatWithinLimit' => true, 'expect' => false],
			['hasLicense' => true, 'licenseValid' => true, 'seatAssigned' => false, 'seatWithinLimit' => true, 'expect' => false],
			['hasLicense' => true, 'licenseValid' => true, 'seatAssigned' => true, 'seatWithinLimit' => false, 'expect' => false],
		];
		foreach ($cases as $case) {
			$license = $this->createMock(LicenseService::class);
			$license->method('gateState')->willReturn([
				'hasLicense' => $case['hasLicense'],
				'licenseValid' => $case['licenseValid'],
				'seatAssigned' => $case['seatAssigned'],
				'seatWithinLimit' => $case['seatWithinLimit'],
				'payloadB64' => null,
				'signatureB64' => null,
			]);
			$license->method('status')->willReturn([
				'state' => ['validUntil' => '2099-01-01', 'mobileSeats' => 5],
				'seats' => ['assigned' => 1, 'limit' => 5],
			]);
			$svc = new MobileGateService($license);
			$payload = $svc->bootstrapPayload('u1', 'User', '0.1.25');
			self::assertSame(
				$case['expect'],
				$payload['licensing']['enabledForUser'],
				json_encode($case),
			);
		}
	}

	public function testAssertGatePassedThrowsDistinctCodesPerRung(): void
	{
		$matrix = [
			[['hasLicense' => false, 'licenseValid' => false, 'seatAssigned' => false, 'seatWithinLimit' => false], 'license_missing'],
			[['hasLicense' => true, 'licenseValid' => false, 'seatAssigned' => false, 'seatWithinLimit' => false], 'license_expired'],
			[['hasLicense' => true, 'licenseValid' => true, 'seatAssigned' => false, 'seatWithinLimit' => false], 'seat_required'],
			[['hasLicense' => true, 'licenseValid' => true, 'seatAssigned' => true, 'seatWithinLimit' => false], 'seat_limit_exceeded'],
		];
		foreach ($matrix as [$state, $code]) {
			$license = $this->createMock(LicenseService::class);
			$license->method('gateState')->willReturn($state + [
				'payloadB64' => null,
				'signatureB64' => null,
			]);
			$svc = new MobileGateService($license);
			try {
				$svc->assertGatePassed('u1');
				self::fail("expected MobileGateException($code)");
			} catch (MobileGateException $e) {
				self::assertSame($code, $e->getErrorCode());
			}
		}
	}

	public function testNormalizeWireKeyStripsAllWhitespace(): void
	{
		$raw = "DTY2.abc\n.def \t ghi";
		$normalized = Dty2Codec::normalizeWireKey($raw);
		self::assertSame('DTY2.abc.defghi', $normalized);
		self::assertStringNotContainsString(' ', $normalized);
		self::assertStringNotContainsString("\n", $normalized);
		self::assertStringNotContainsString("\t", $normalized);
	}

	public function testClassifyErrorRejectsWrongPartCountAndPrefix(): void
	{
		self::assertSame(Dty2Codec::ERROR_INVALID_FORMAT, Dty2Codec::classifyError('NOTDTY2.a.b'));
		self::assertSame(Dty2Codec::ERROR_INVALID_FORMAT, Dty2Codec::classifyError('DTY2.only-two'));
		self::assertSame(Dty2Codec::ERROR_INVALID_FORMAT, Dty2Codec::classifyError('DTY2.a.b.c.d'));
	}

	public function testRetentionDaysClampsToZeroThrough3650(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnOnConsecutiveCalls('-5', '0', '100', '99999');
		$svc = new SnapshotRetentionService(
			$this->createMock(IDBConnection::class),
			$config,
			$this->createMock(LoggerInterface::class),
		);
		self::assertSame(0, $svc->retentionDays());
		self::assertSame(0, $svc->retentionDays());
		self::assertSame(100, $svc->retentionDays());
		self::assertSame(3650, $svc->retentionDays());
	}

	public function testPruneExpiredNoopWhenRetentionDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$svc = new SnapshotRetentionService($db, $config, $this->createMock(LoggerInterface::class));
		$result = $svc->pruneExpired();
		self::assertSame(['enabled' => false, 'deleted' => 0, 'retentionDays' => 0], $result);
	}

	public function testSaveAppPolicyRejectsEmptyAllowlistWhenRestrictionEnabled(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('[]');
		$svc = new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$groups,
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ACCESS_LIST_REQUIRED');
		$svc->saveAppPolicy([
			'appAdminUserIds' => [],
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
			'accessRestrictionEnabled' => true,
		]);
	}

	public function testSaveAppPolicyAllowsRestrictionWhenUserAllowlistPresent(): void
	{
		$userManager = $this->createMock(IUserManager::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$userManager->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'app_admin_user_ids' => '[]',
					'access_allowed_user_ids' => '["alice"]',
					'access_allowed_group_ids' => '[]',
					'access_restriction_enabled' => '1',
					default => $default,
				};
			},
		);
		$stored = [];
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			},
		);
		$svc = new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$groups,
			$userManager,
			$this->createMock(IUserSession::class),
		);
		$svc->saveAppPolicy([
			'appAdminUserIds' => [],
			'allowedUserIds' => ['alice'],
			'allowedGroupIds' => [],
			'accessRestrictionEnabled' => true,
		]);
		self::assertSame('1', $stored['access_restriction_enabled']);
		self::assertSame('["alice"]', $stored['access_allowed_user_ids']);
	}

	public function testValidatePayloadFieldsSeatAndCustomerBoundaries(): void
	{
		$base = [
			'v' => 2,
			'product' => 'dutycheck',
			'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2026-12-31',
			'mobileSeats' => 5,
		];
		self::assertTrue(Dty2Codec::validatePayloadFields($base));
		self::assertTrue(Dty2Codec::validatePayloadFields(array_merge($base, ['mobileSeats' => 1])));
		self::assertTrue(Dty2Codec::validatePayloadFields(array_merge($base, ['mobileSeats' => 10000])));
		self::assertFalse(Dty2Codec::validatePayloadFields(array_merge($base, ['mobileSeats' => 0])));
		self::assertFalse(Dty2Codec::validatePayloadFields(array_merge($base, ['mobileSeats' => 10001])));
		// Equal issued/valid is allowed (< not <=).
		self::assertTrue(Dty2Codec::validatePayloadFields(array_merge($base, [
			'issuedAt' => '2026-06-01',
			'validUntil' => '2026-06-01',
		])));
		self::assertFalse(Dty2Codec::validatePayloadFields(array_merge($base, [
			'issuedAt' => '2026-06-02',
			'validUntil' => '2026-06-01',
		])));
		// Anchored customerId regex — leading/trailing junk must fail.
		self::assertFalse(Dty2Codec::validatePayloadFields(array_merge($base, ['customerId' => '!acme'])));
		self::assertFalse(Dty2Codec::validatePayloadFields(array_merge($base, ['customerId' => 'acme!'])));
		self::assertFalse(Dty2Codec::validatePayloadFields(array_merge($base, ['customerId' => 'ab'])));
	}

	public function testBootstrapOmitsEnvelopeWithoutPayloadParts(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn([
			'hasLicense' => true,
			'licenseValid' => true,
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'payloadB64' => null,
			'signatureB64' => null,
		]);
		$license->method('status')->willReturn([
			'state' => ['validUntil' => '2099-12-31', 'mobileSeats' => 3],
			'seats' => ['assigned' => 1, 'limit' => 3],
		]);
		$svc = new MobileGateService($license);
		$payload = $svc->bootstrapPayload('u1', 'User', '0.1.25');
		self::assertTrue($payload['licensing']['enabledForUser']);
		self::assertSame(3, $payload['licensing']['mobileSeats']);
		self::assertSame(1, $payload['licensing']['mobileSeatsUsed']);
		self::assertSame('2099-12-31', $payload['licensing']['expiresAt']);
		self::assertArrayNotHasKey('payloadB64', $payload['licensing']);
		self::assertArrayNotHasKey('envelope', $payload['licensing']);
	}

	public function testBootstrapIncludesEnvelopeWhenLicensePartsPresent(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn([
			'hasLicense' => true,
			'licenseValid' => true,
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'payloadB64' => 'payloadXXX',
			'signatureB64' => 'sigYYY',
		]);
		$license->method('status')->willReturn([
			'state' => ['validUntil' => '2099-12-31', 'mobileSeats' => 3],
			'seats' => ['assigned' => 1, 'limit' => 3],
		]);
		$svc = new MobileGateService($license);
		$payload = $svc->bootstrapPayload('u1', 'User', '0.1.25');
		self::assertSame('payloadXXX', $payload['licensing']['payloadB64']);
		self::assertSame('sigYYY', $payload['licensing']['signatureB64']);
		self::assertSame('payloadXXX', $payload['licensing']['envelope']['payloadB64']);
	}

	public function testClientLicenseMiddlewareRejectsCookieOnMobileMutations(): void
	{
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/my/assignments/1/acknowledge');

		$license = $this->createMock(LicenseService::class);
		$license->expects(self::never())->method('isMobilePlanActive');

		$mw = new \OCA\DutyCheck\Middleware\ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(\OCA\DutyCheck\Exception\MobileUnauthenticatedException::class);
		$mw->beforeController(new \stdClass(), 'acknowledgeAssignment');
	}

	private function access(IGroupManager $groups): AccessControlService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		return new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$groups,
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
	}
}

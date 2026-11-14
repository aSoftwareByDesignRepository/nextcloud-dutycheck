<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Exception\MobileDemoSeedException;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\MobileDemoSeedOptions;
use OCA\DutyCheck\Service\MobileDemoSeedService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MobileDemoSeedServiceTest extends TestCase
{
	private IUserManager&MockObject $userManager;
	private IUserSession&MockObject $userSession;
	private LicenseService&MockObject $license;
	private RosterService&MockObject $roster;
	private OpenShiftService&MockObject $openShifts;
	private MobileDemoSeedService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->license = $this->createMock(LicenseService::class);
		$this->roster = $this->createMock(RosterService::class);
		$this->openShifts = $this->createMock(OpenShiftService::class);
		$this->service = new MobileDemoSeedService(
			$this->userManager,
			$this->userSession,
			$this->license,
			$this->roster,
			$this->openShifts,
		);
	}

	public function testRejectsMissingLicenseWireKey(): void
	{
		$this->expectException(MobileDemoSeedException::class);
		$this->expectExceptionMessage('DTY2 license wire key is required');
		$this->service->run(new MobileDemoSeedOptions(licenseWireKey: null));
	}

	public function testRejectsMissingAdminUser(): void
	{
		$this->userManager->method('get')->with('admin')->willReturn(null);
		$this->expectException(MobileDemoSeedException::class);
		$this->expectExceptionMessage('Admin user');
		$this->service->run(new MobileDemoSeedOptions(licenseWireKey: 'DTY2.test.key'));
	}

	public function testOptionsRejectIdenticalDemoUsers(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new MobileDemoSeedOptions(
			employeeUserId: 'same.user',
			unseatedUserId: 'same.user',
			licenseWireKey: 'DTY2.x',
		);
	}

	public function testRunCreatesUsersAssignsSeatAndPublishesRoster(): void
	{
		$admin = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('admin')->willReturn($admin);
		$this->userSession->expects(self::once())->method('setUser')->with($admin);

		$this->license->expects(self::once())->method('apply')->with('admin', 'DTY2.demo.key');
		$this->userManager->method('userExists')->willReturn(false);
		$this->userManager->expects(self::exactly(2))->method('createUser')->willReturn(true);
		$this->license->expects(self::once())->method('assignSeat')->willReturn(['created' => true]);

		$this->roster->method('listEmployeeCatalog')->willReturnOnConsecutiveCalls(
			[],
			[],
			[['id' => 7, 'linkedUserId' => 'dc.review.employee', 'displayName' => 'Play Review Employee']],
		);
		$this->roster->expects(self::once())->method('createEmployee');
		$this->roster->method('listLocationCatalog')->willReturnOnConsecutiveCalls(
			[],
			[['id' => 3, 'name' => MobileDemoSeedOptions::DEFAULT_LOCATION_NAME]],
		);
		$this->roster->expects(self::once())->method('createLocation');

		$this->roster->expects(self::once())->method('createPeriod')->willReturn([
			'id' => 99,
			'status' => 'open',
			'startDate' => '2026-08-18',
			'endDate' => '2026-09-14',
		]);
		// listPeriods: open while creating assignment, still open before final publish.
		$this->roster->method('listPeriods')->willReturn([
			['id' => 99, 'status' => 'open', 'startDate' => '2026-08-18', 'endDate' => '2026-09-14'],
		]);
		$this->roster->method('myRoster')->willReturn([]);
		$this->roster->expects(self::once())->method('createAssignment')->willReturn([
			'assignments' => [['id' => 42]],
		]);

		$this->openShifts->method('listOpen')->willReturn([]);
		$this->openShifts->expects(self::once())->method('create')->willReturn(['id' => 5]);

		// Publish only after assignment + open-shift create (PERIOD_NOT_OPEN otherwise).
		$this->roster->expects(self::once())->method('transitionPeriod')
			->with(99, 'published', 'admin')
			->willReturn(['id' => 99, 'status' => 'published']);

		$result = $this->service->run(new MobileDemoSeedOptions(licenseWireKey: 'DTY2.demo.key'));

		self::assertSame('dc.review.employee', $result->employeeUserId);
		self::assertSame('dc.review.noseat', $result->unseatedUserId);
		self::assertSame(7, $result->employeeId);
		self::assertSame(3, $result->locationId);
		self::assertSame(99, $result->periodId);
		self::assertSame(42, $result->assignmentId);
		self::assertSame(5, $result->openShiftId);
		self::assertSame('published', $result->periodStatus);
		self::assertTrue($result->seatAssigned);
	}

	public function testRunReusesExistingUnacknowledgedShift(): void
	{
		$admin = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($admin);
		$this->license->method('apply');
		$this->userManager->method('userExists')->willReturn(true);
		$this->license->method('assignSeat')->willReturn(['created' => false]);

		$shiftDate = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

		$this->roster->method('listEmployeeCatalog')->willReturn([
			['id' => 7, 'linkedUserId' => 'dc.review.employee', 'displayName' => 'Play Review Employee'],
		]);
		$this->roster->method('listLocationCatalog')->willReturn([
			['id' => 3, 'name' => MobileDemoSeedOptions::DEFAULT_LOCATION_NAME],
		]);
		$this->roster->method('createPeriod')->willThrowException(new \InvalidArgumentException('DUPLICATE'));
		$this->roster->method('listPeriods')->willReturn([
			['id' => 99, 'status' => 'published', 'startDate' => '2020-01-01', 'endDate' => '2099-12-31'],
		]);
		// The reuse path only picks periods the seed could publish —
		// period 99 is conflict-free here.
		$this->roster->method('publishReadiness')->willReturn([
			'periodId' => 99,
			'hardConflicts' => 0,
			'canPublish' => true,
		]);
		$this->roster->method('myRoster')->willReturn([
			['id' => 88, 'dutyDate' => $shiftDate, 'acknowledged' => false],
		]);
		$this->roster->expects(self::never())->method('createAssignment');
		$this->openShifts->method('listOpen')->willReturn([['id' => 12]]);

		$result = $this->service->run(new MobileDemoSeedOptions(licenseWireKey: 'DTY2.demo.key'));

		self::assertSame(88, $result->assignmentId);
		self::assertSame(12, $result->openShiftId);
		self::assertSame('published', $result->periodStatus);
	}

	public function testRunDoesNotHijackConflictedPeriod(): void
	{
		$admin = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($admin);
		$this->license->method('apply');
		$this->userManager->method('userExists')->willReturn(true);
		$this->license->method('assignSeat')->willReturn(['created' => false]);

		$shiftDate = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

		$this->roster->method('listEmployeeCatalog')->willReturn([
			['id' => 7, 'linkedUserId' => 'dc.review.employee', 'displayName' => 'Play Review Employee'],
		]);
		$this->roster->method('listLocationCatalog')->willReturn([
			['id' => 3, 'name' => MobileDemoSeedOptions::DEFAULT_LOCATION_NAME],
		]);

		// First createPeriod overlaps an existing period and is rejected.
		// The only covering candidate is conflicted (hardConflicts > 0), so the
		// seed must not hijack it — the retry carries allowOverlap=true and
		// returns the isolated demo period 55 instead of real period 99.
		$createPeriodFlags = [];
		$this->roster->expects(self::exactly(2))->method('createPeriod')
			->willReturnCallback(static function (string $s, string $e, string $actor, bool $allowOverlap = false) use (&$createPeriodFlags): array {
				$createPeriodFlags[] = $allowOverlap;
				if (count($createPeriodFlags) === 1) {
					throw new \InvalidArgumentException('DUPLICATE');
				}
				return ['id' => 55, 'status' => 'open'];
			});
		$this->roster->method('listPeriods')->willReturnOnConsecutiveCalls(
			// ensureDemoPeriod scan: only the conflicted real period exists yet
			[['id' => 99, 'status' => 'published', 'startDate' => '2020-01-01', 'endDate' => '2099-12-31']],
			// ensureDemoPeriodPublished lookup (and any later call): isolated 55 exists
			[
				['id' => 99, 'status' => 'published', 'startDate' => '2020-01-01', 'endDate' => '2099-12-31'],
				['id' => 55, 'status' => 'open', 'startDate' => '2020-01-01', 'endDate' => '2099-12-31'],
			],
		);
		$this->roster->method('publishReadiness')->willReturnCallback(
			static fn (int $periodId): array => $periodId === 99
				? ['periodId' => 99, 'hardConflicts' => 2, 'canPublish' => false]
				: ['periodId' => $periodId, 'hardConflicts' => 0, 'canPublish' => true],
		);
		$this->roster->method('myRoster')->willReturn([
			['id' => 88, 'dutyDate' => $shiftDate, 'acknowledged' => false],
		]);
		$this->roster->expects(self::never())->method('createAssignment');
		$this->openShifts->method('listOpen')->willReturn([['id' => 12]]);
		$this->roster->expects(self::once())->method('transitionPeriod')
			->with(55, 'published', 'admin')
			->willReturn(['id' => 55, 'status' => 'published']);

		$result = $this->service->run(new MobileDemoSeedOptions(licenseWireKey: 'DTY2.demo.key'));

		self::assertSame(55, $result->periodId);
		self::assertSame([false, true], $createPeriodFlags);
		self::assertSame(88, $result->assignmentId);
		self::assertSame(12, $result->openShiftId);
		self::assertSame('published', $result->periodStatus);
	}
}

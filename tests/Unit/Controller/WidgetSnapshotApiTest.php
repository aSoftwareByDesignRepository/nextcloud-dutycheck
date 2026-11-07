<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\RosterApiController;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * SPEC NH-P2: /api/widget-snapshot — employee-scoped compact doc (myRoster
 * window + serverNow), 60 s per-user cache, shift-only rows.
 */
class WidgetSnapshotApiTest extends TestCase
{
	private function buildController(
		IRequest $request,
		AccessControlService $access,
		RosterService $roster,
		ICache $cache,
		?ITimeFactory $timeFactory = null,
	): RosterApiController {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->with('dutycheck_wsnap')->willReturn($cache);
		return new RosterApiController(
			'dutycheck',
			$request,
			$access,
			$roster,
			$this->createMock(RosterCsvFormatter::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(PlanningDefaultsService::class),
			$this->createMock(IConfig::class),
			$timeFactory ?? $this->createMock(ITimeFactory::class),
			$cacheFactory,
		);
	}

	private function employeeAccess(string $uid = 'emp-1'): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn($uid);
		$access->method('requireEmployee')->with($uid);
		return $access;
	}

	private function realClock(): ITimeFactory
	{
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')
			->willReturnCallback(static fn (string $t, ?\DateTimeZone $tz = null) => new \DateTime($t, $tz));
		return $timeFactory;
	}

	public function testHappyPathReturnsShiftOnlyRosterWithServerNow(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$access = $this->employeeAccess();
		$rows = [['id' => 7, 'dutyDate' => '2026-11-08', 'startTime' => '08:00', 'kind' => 'shift']];
		$expectedFrom = (new \DateTime('today'))->format('Y-m-d');
		$expectedTo = (new \DateTime('today'))->modify('+30 days')->format('Y-m-d');
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('myRoster')
			->with('emp-1', $expectedFrom, $expectedTo, false)
			->willReturn($rows);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with('emp-1|' . $expectedFrom . '|' . $expectedTo)->willReturn(null);
		$cache->expects($this->once())->method('set')->with(
			'emp-1|' . $expectedFrom . '|' . $expectedTo,
			$this->anything(),
			60,
		);

		$response = $this->buildController($request, $access, $roster, $cache, $this->realClock())
			->widgetSnapshot();

		self::assertInstanceOf(DataResponse::class, $response);
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame($rows, $data['data']['assignments']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['data']['serverNow']);
	}

	public function testExplicitWindowIsHonored(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(static fn (string $k) => ['from' => '2026-12-01', 'to' => '2026-12-07'][$k] ?? null);
		$access = $this->employeeAccess();
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('myRoster')
			->with('emp-1', '2026-12-01', '2026-12-07', false)
			->willReturn([]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with('emp-1|2026-12-01|2026-12-07')->willReturn(null);

		$this->buildController($request, $access, $roster, $cache, $this->realClock())->widgetSnapshot();
	}

	public function testCacheHitSkipsServiceCall(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$access = $this->employeeAccess();
		$roster = $this->createMock(RosterService::class);
		$roster->expects(self::never())->method('myRoster');
		$cached = ['ok' => true, 'data' => ['assignments' => [], 'serverNow' => '2026-11-07T10:00:00+00:00']];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cached);
		$cache->expects(self::never())->method('set');

		$response = $this->buildController($request, $access, $roster, $cache, $this->realClock())
			->widgetSnapshot();

		self::assertSame($cached, $response->getData());
	}

	public function testPlannerWithoutEmployeeLinkIsDenied(): void
	{
		// Widget audience is the personal roster — planner role alone must not
		// widen this into an org-wide roster leak (the original draft wrapped
		// roster(), which carries requirePlannerOrAdmin).
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('planner-1');
		$access->expects($this->once())
			->method('requireEmployee')
			->with('planner-1')
			->willThrowException(new AppAccessDeniedException(AccessControlService::DENIAL_EMPLOYEE_NOT_LINKED));
		$access->expects(self::never())->method('requirePlannerOrAdmin');
		$roster = $this->createMock(RosterService::class);
		$roster->expects(self::never())->method('myRoster');
		$roster->expects(self::never())->method('rosterData');
		$cache = $this->createMock(ICache::class);

		$response = $this->buildController($request, $access, $roster, $cache)->widgetSnapshot();

		self::assertSame(403, $response->getStatus());
		self::assertSame('EMPLOYEE_RECORD_LINK_REQUIRED', $response->getData()['error']['code']);
	}

	public function testUnauthenticatedMapsTo401JsonEnvelope(): void
	{
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willThrowException(new \RuntimeException('not_authenticated'));
		$roster = $this->createMock(RosterService::class);
		$cache = $this->createMock(ICache::class);

		$response = $this->buildController($request, $access, $roster, $cache)->widgetSnapshot();

		self::assertSame(401, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
		self::assertSame('NOT_AUTHENTICATED', $response->getData()['error']['code']);
	}

	public function testUnexpectedThrowableReturnsJsonEnvelopeNotHtml(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$access = $this->employeeAccess();
		$roster = $this->createMock(RosterService::class);
		$roster->method('myRoster')->willThrowException(new \RuntimeException('db down'));
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);

		$response = $this->buildController($request, $access, $roster, $cache)->widgetSnapshot();

		self::assertSame(500, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
		self::assertSame('INTERNAL_ERROR', $response->getData()['error']['code']);
	}
}

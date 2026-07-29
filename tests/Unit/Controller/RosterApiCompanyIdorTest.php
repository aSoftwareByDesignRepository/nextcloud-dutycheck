<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\RosterApiController;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Company isolation on period-scoped planner reads that previously skipped assertPeriodCompanyAccess. */
final class RosterApiCompanyIdorTest extends TestCase
{
	public function testPeriodAuditAssertsCompanyAccess(): void
	{
		[$controller, $roster, $access] = $this->controller();
		$access->expects($this->once())->method('requirePlannerOrAdmin');
		$roster->expects($this->once())->method('assertPeriodCompanyAccess')->with('planner-1', 42);
		$roster->expects($this->once())->method('periodAudit')->with(42)->willReturn([]);

		$response = $controller->periodAudit(42);
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertTrue($response->getData()['ok']);
	}

	public function testPeriodAcknowledgeStatsAssertsCompanyAccess(): void
	{
		[$controller, $roster, $access] = $this->controller();
		$access->expects($this->once())->method('requirePlannerOrAdmin');
		$roster->expects($this->once())->method('assertPeriodCompanyAccess')->with('planner-1', 7);
		$roster->expects($this->once())->method('periodAcknowledgeStats')->with(7)->willReturn(['pct' => 0]);

		$response = $controller->periodAcknowledgeStats(7);
		self::assertTrue($response->getData()['ok']);
	}

	public function testExportRosterCsvAssertsCompanyAccess(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(11);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('admin-1');
		$access->expects($this->once())->method('requireAppAdmin');
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())->method('assertPeriodCompanyAccess')->with('admin-1', 11);
		$roster->method('rosterExportBundle')->willReturn([
			'period' => ['id' => 11, 'startDate' => '2099-01-01', 'endDate' => '2099-01-07', 'status' => 'open'],
			'assignments' => [],
		]);
		$roster->expects($this->once())->method('logRosterDataExport');
		$csv = $this->createMock(RosterCsvFormatter::class);
		$csv->method('buildDutyRosterCsv')->willReturn("\xEF\xBB\xBF\"x\"\r\n");

		$controller = new RosterApiController(
			'dutycheck',
			$request,
			$access,
			$roster,
			$csv,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(PlanningDefaultsService::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);

		$response = $controller->exportRosterCsv();
		self::assertSame(200, $response->getStatus());
	}

	public function testAcknowledgeConflictDelegatesCompanyAssertToService(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('long enough reason text');
		[$controller, $roster, $access] = $this->controller($request);
		$access->expects($this->once())->method('requirePlannerOrAdmin');
		$roster->expects($this->once())->method('acknowledgeConflict')
			->with(9, 'planner-1', 'long enough reason text')
			->willReturn([]);

		$response = $controller->acknowledgeConflict(9);
		self::assertTrue($response->getData()['ok']);
	}

	/** @return array{0:RosterApiController,1:RosterService,2:AccessControlService} */
	private function controller(?IRequest $request = null): array
	{
		$request ??= $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('planner-1');
		$roster = $this->createMock(RosterService::class);
		$controller = new RosterApiController(
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
			$this->createMock(ITimeFactory::class),
		);
		return [$controller, $roster, $access];
	}
}

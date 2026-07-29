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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class DashboardCompanyScopeTest extends TestCase
{
	public function testDashboardPassesActorToSummary(): void
	{
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('planner-a');
		$access->expects($this->once())->method('requirePlannerOrAdmin')->with('planner-a');
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())->method('dashboardSummary')->with('planner-a')->willReturn([
			'openPeriods' => 1,
			'publishedPeriods' => 0,
			'activeEmployees' => 2,
			'activeLocations' => 1,
			'assignments' => 3,
			'setup' => ['schemaReady' => true, 'readyForPlanning' => true],
		]);

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

		$response = $controller->dashboard();
		self::assertTrue($response->getData()['ok']);
		self::assertSame(1, $response->getData()['data']['openPeriods']);
	}

	public function testDashboardDeniesNonPlannerWithoutTouchingSummary(): void
	{
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('intruder');
		$access->expects($this->once())
			->method('requirePlannerOrAdmin')
			->with('intruder')
			->willThrowException(new AppAccessDeniedException(AccessControlService::DENIAL_INSUFFICIENT_ROLE));
		$roster = $this->createMock(RosterService::class);
		$roster->expects(self::never())->method('dashboardSummary');

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

		$response = $controller->dashboard();
		self::assertSame(403, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
		self::assertSame('INSUFFICIENT_ROLE', $response->getData()['error']['code']);
	}
}

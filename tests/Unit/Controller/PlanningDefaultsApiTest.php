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
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class PlanningDefaultsApiTest extends TestCase
{
	public function testPlanningDefaultsReadAllowsPlanner(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('planner-1');
		$access->method('requirePlannerOrAdmin')->willReturnCallback(static function (): void {
		});
		$planning = $this->createMock(PlanningDefaultsService::class);
		$planning->method('toApi')->willReturn(['defaultBreakMinutes' => 30]);
		$controller = $this->makeController($access, $planning);
		$response = $controller->planningDefaults();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(30, $response->getData()['planning']['defaultBreakMinutes']);
	}

	public function testPlanningDefaultsReadDeniesNonPlanner(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('user-1');
		$access->method('requirePlannerOrAdmin')->willThrowException(
			new AppAccessDeniedException(AccessControlService::DENIAL_INSUFFICIENT_ROLE),
		);
		$controller = $this->makeController($access, $this->createMock(PlanningDefaultsService::class));
		$response = $controller->planningDefaults();
		$this->assertSame(403, $response->getStatus());
	}

	public function testSavePlanningDefaultsReturnsPersistedValue(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('admin-1');
		$access->method('requireAppAdmin')->willReturnCallback(static function (): void {
		});
		$planning = $this->createMock(PlanningDefaultsService::class);
		$planning->expects($this->once())->method('setFromPayload')->with(30);
		$planning->method('toApi')->willReturn(['defaultBreakMinutes' => 30]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['defaultBreakMinutes' => 30]);
		$controller = $this->makeController($access, $planning, $request);
		$response = $controller->savePlanningDefaults();
		$this->assertSame(200, $response->getStatus());
		/** @var array<string,mixed> $data */
		$data = $response->getData();
		$this->assertTrue($data['ok']);
		$this->assertSame(30, $data['planning']['defaultBreakMinutes']);
	}

	private function makeController(
		AccessControlService $access,
		PlanningDefaultsService $planning,
		?IRequest $request = null,
	): RosterApiController {
		return new RosterApiController(
			'dutycheck',
			$request ?? $this->createMock(IRequest::class),
			$access,
			$this->createMock(RosterService::class),
			$this->createMock(RosterCsvFormatter::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$planning,
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\ApiController;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ApiControllerBootstrapTest extends TestCase
{
	public function testBootstrapOmitsPlannerCatalogForEmployeeOnly(): void
	{
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$roster = $this->createMock(RosterService::class);
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('buildBootstrapForUser')->willReturn(['effective' => false]);

		$access->method('currentUserId')->willReturn('emp-1');
		$access->method('isAppAdmin')->with('emp-1')->willReturn(false);
		$access->method('isEmployee')->with('emp-1')->willReturn(true);
		$access->method('hasActiveLinkedEmployee')->with('emp-1')->willReturn(true);
		$access->method('isPlannerOrAdmin')->with('emp-1')->willReturn(false);

		$roster->expects(self::never())->method('dashboardSummary');
		$roster->expects(self::never())->method('rosterData');
		$roster->expects(self::never())->method('listAbsences');
		$roster->expects(self::never())->method('myRoster');
		$roster->expects(self::never())->method('myAbsences');

		$controller = new ApiController('dutycheck', $request, $access, $roster, $at);
		$response = $controller->bootstrap();

		self::assertInstanceOf(DataResponse::class, $response);
		/** @var array<string, mixed> $payload */
		$payload = $response->getData();
		self::assertTrue($payload['ok']);
		/** @var array<string, mixed> $data */
		$data = $payload['data'];
		self::assertSame(['effective' => false], $data['arbeitszeitCheckIntegration']);
		self::assertFalse($data['isPlannerOrAdmin']);
		/** @var array<string, mixed> $catalog */
		$catalog = $data['catalog'];
		self::assertNull($catalog['dashboard']);
		self::assertNull($catalog['roster']);
		self::assertNull($catalog['absences']);
		self::assertNull($catalog['myRoster']);
		self::assertNull($catalog['myAbsences']);
	}

	public function testBootstrapOmitsPlannerDashboardPayload(): void
	{
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$roster = $this->createMock(RosterService::class);
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('buildBootstrapForUser')->willReturn(['effective' => false]);

		$access->method('currentUserId')->willReturn('plan-1');
		$access->method('isAppAdmin')->with('plan-1')->willReturn(false);
		$access->method('isEmployee')->with('plan-1')->willReturn(false);
		$access->method('hasActiveLinkedEmployee')->with('plan-1')->willReturn(false);
		$access->method('isPlannerOrAdmin')->with('plan-1')->willReturn(true);

		$roster->expects(self::never())->method('dashboardSummary');
		$roster->expects(self::never())->method('rosterData');
		$roster->expects(self::never())->method('listAbsences');
		$roster->expects(self::never())->method('myRoster');
		$roster->expects(self::never())->method('myAbsences');

		$controller = new ApiController('dutycheck', $request, $access, $roster, $at);
		$response = $controller->bootstrap();

		self::assertInstanceOf(DataResponse::class, $response);
		/** @var array<string, mixed> $payload */
		$payload = $response->getData();
		/** @var array<string, mixed> $data */
		$data = $payload['data'];
		self::assertSame(['effective' => false], $data['arbeitszeitCheckIntegration']);
		self::assertTrue($data['isPlannerOrAdmin']);
		/** @var array<string, mixed> $catalog */
		$catalog = $data['catalog'];
		self::assertNull($catalog['dashboard']);
		self::assertNull($catalog['roster']);
		self::assertNull($catalog['absences']);
		self::assertNull($catalog['myRoster']);
		self::assertNull($catalog['myAbsences']);
	}

	public function testBootstrapNeverHydratesCatalogLists(): void
	{
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$roster = $this->createMock(RosterService::class);
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('buildBootstrapForUser')->willReturn(['effective' => false]);

		$access->method('currentUserId')->willReturn('plan-linked');
		$access->method('isAppAdmin')->with('plan-linked')->willReturn(false);
		$access->method('isEmployee')->with('plan-linked')->willReturn(false);
		$access->method('hasActiveLinkedEmployee')->with('plan-linked')->willReturn(true);
		$access->method('isPlannerOrAdmin')->with('plan-linked')->willReturn(true);

		$roster->expects(self::never())->method('dashboardSummary');
		$roster->expects(self::never())->method('rosterData');
		$roster->expects(self::never())->method('listAbsences');
		$roster->expects(self::never())->method('myRoster');
		$roster->expects(self::never())->method('myAbsences');

		$controller = new ApiController('dutycheck', $request, $access, $roster, $at);
		$response = $controller->bootstrap();

		self::assertInstanceOf(DataResponse::class, $response);
		/** @var array<string, mixed> $payload */
		$payload = $response->getData();
		self::assertSame(['effective' => false], $payload['data']['arbeitszeitCheckIntegration']);
		/** @var array<string, mixed> $catalog */
		$catalog = $payload['data']['catalog'];
		self::assertNull($catalog['myRoster']);
		self::assertNull($catalog['myAbsences']);
	}
}

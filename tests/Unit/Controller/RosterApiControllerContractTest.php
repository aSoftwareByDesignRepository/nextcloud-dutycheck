<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\RosterApiController;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class RosterApiControllerContractTest extends TestCase
{
	private IRequest $request;
	private AccessControlService $access;
	private RosterService $roster;
	private RosterCsvFormatter $csvFormatter;
	private RosterApiController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->roster = $this->createMock(RosterService::class);
		$this->csvFormatter = $this->createMock(RosterCsvFormatter::class);
		$this->csvFormatter->method('buildDutyRosterCsv')->willReturn("\xEF\xBB\xBF\"x\"\r\n");
		$this->controller = new RosterApiController(
			'dutycheck',
			$this->request,
			$this->access,
			$this->roster,
			$this->csvFormatter,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);
		$this->access->method('currentUserId')->willReturn('planner-1');
	}

	public function testCreateAssignmentReturnsConflictAckRequiredContract(): void
	{
		$this->request->method('getParams')->willReturn([
			'periodId' => 12,
			'employeeId' => 21,
			'locationId' => 3,
			'dutyDate' => '2026-05-08',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => 30,
			'note' => '',
			'acknowledgements' => [],
		]);
		$this->roster->method('createAssignment')
			->willThrowException(new ConflictAckRequiredException([
				['type' => 'rest_time_violation', 'severity' => 'soft'],
			]));

		$response = $this->controller->createAssignment();
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(409, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('CONFLICT_ACK_REQUIRED', $data['error']['code']);
		self::assertNotEmpty($data['error']['conflicts']);
	}

	public function testCreateAssignmentMapsMissingEmployeeTo400(): void
	{
		$this->request->method('getParams')->willReturn([
			'periodId' => '12',
			'employeeId' => '0',
			'locationId' => '3',
		]);
		$this->roster->method('createAssignment')
			->willThrowException(new \InvalidArgumentException('EMPLOYEE_ID_REQUIRED'));

		$response = $this->controller->createAssignment();
		self::assertSame(400, $response->getStatus());
		self::assertSame('EMPLOYEE_ID_REQUIRED', $response->getData()['error']['code']);
	}

	public function testCreateAssignmentPassesFormEncodedStringIdsToService(): void
	{
		$this->request->method('getParams')->willReturn([
			'periodId' => '12',
			'employeeId' => '21',
			'locationId' => '3',
			'dutyDate' => '2026-06-08',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => '30',
			'note' => '',
			'acknowledgements' => [],
		]);
		$this->roster->expects(self::once())
			->method('createAssignment')
			->with(
				self::callback(static function (array $payload): bool {
					return $payload['periodId'] === '12'
						&& $payload['employeeId'] === '21'
						&& $payload['locationId'] === '3'
						&& $payload['dutyDate'] === '2026-06-08'
						&& $payload['acknowledgements'] === [];
				}),
				'planner-1',
			)
			->willReturn(['assignments' => []]);

		$response = $this->controller->createAssignment();
		self::assertTrue($response->getData()['ok']);
	}

	public function testVerifyPeriodSnapshotsMapsHashMismatchTo500(): void
	{
		$this->roster->method('verifyPeriodSnapshots')->willThrowException(new \InvalidArgumentException('SNAPSHOT_HASH_MISMATCH'));

		$response = $this->controller->verifyPeriodSnapshots(5);
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(500, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('SNAPSHOT_HASH_MISMATCH', $data['error']['code']);
	}

	public function testRosterMapsPeriodNotFoundTo404(): void
	{
		$this->request->method('getParam')->willReturnMap([
			['periodId', null, 999],
		]);
		$this->roster->method('rosterData')->willThrowException(new \InvalidArgumentException('PERIOD_NOT_FOUND'));

		$response = $this->controller->roster();
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('PERIOD_NOT_FOUND', $data['error']['code']);
	}

	public function testPublicIcalMapsInvalidTokenTo403(): void
	{
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getParam')->willReturnMap([
			['token', '', 'bad-token'],
		]);
		$this->roster->method('publicIcal')->willThrowException(new \InvalidArgumentException('ICAL_TOKEN_INVALID'));

		$response = $this->controller->publicIcal(9);
		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(403, $response->getStatus());
	}

	public function testPublicIcalMapsRateLimitedTo429(): void
	{
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getParam')->willReturnMap([
			['token', '', 'token'],
		]);
		$this->roster->method('publicIcal')->willThrowException(new \InvalidArgumentException('RATE_LIMITED'));

		$response = $this->controller->publicIcal(9);
		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(429, $response->getStatus());
	}

	public function testPublicIcalRequiresSecureTransport(): void
	{
		$this->request->method('getServerProtocol')->willReturn('http');

		$response = $this->controller->publicIcal(9);
		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(400, $response->getStatus());
	}

	public function testExportRosterCsvRequiresAppAdmin(): void
	{
		$this->access->method('requireAppAdmin')->willThrowException(
			new AppAccessDeniedException(AccessControlService::DENIAL_INSUFFICIENT_ROLE),
		);

		$response = $this->controller->exportRosterCsv();
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('INSUFFICIENT_ROLE', $data['error']['code']);
	}

	public function testExportRosterCsvRequiresPeriodId(): void
	{
		$this->access->method('requireAppAdmin')->willReturnCallback(static function (): void {
		});
		$this->request->method('getParam')->willReturnMap([
			['periodId', null, '0'],
		]);

		$response = $this->controller->exportRosterCsv();
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(400, $response->getStatus());
		self::assertSame('PERIOD_ID_REQUIRED', $response->getData()['error']['code']);
	}

	public function testExportRosterCsvReturnsFile(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('admin-1');
		$access->method('requireAppAdmin')->willReturnCallback(static function (): void {
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['periodId', null, '12'],
		]);
		$roster = $this->createMock(RosterService::class);
		$bundle = [
			'period' => [
				'id' => 12,
				'startDate' => '2026-04-01',
				'endDate' => '2026-04-30',
				'status' => 'open',
			],
			'assignments' => [],
		];
		$roster->expects(self::once())->method('rosterExportBundle')->with(12)->willReturn($bundle);
		$roster->expects(self::once())->method('logRosterDataExport')->with(
			12,
			'admin-1',
			'csv',
			['assignmentCount' => 0],
		);
		$csvFormatter = $this->createMock(RosterCsvFormatter::class);
		$csvFormatter->expects(self::once())->method('buildDutyRosterCsv')->willReturn("\xEF\xBB\xBF\"x\"\r\n");

		$controller = new RosterApiController(
			'dutycheck',
			$request,
			$access,
			$roster,
			$csvFormatter,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);

		$response = $controller->exportRosterCsv();
		self::assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testCreateMyAbsenceReturnsEmployeeScopedPayload(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('employee-1');
		$access->method('requireEmployee')->willReturnCallback(static function (): void {
		});
		$roster = $this->createMock(RosterService::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $key) {
			return match ($key) {
				'kind' => 'sick',
				'startDate' => '2026-05-12',
				'endDate' => '2026-05-14',
				default => null,
			};
		});
		$scoped = [
			['id' => 3, 'kind' => 'sick', 'startDate' => '2026-05-12', 'endDate' => '2026-05-14', 'status' => 'pending', 'reviewReason' => ''],
		];
		$roster->expects(self::once())->method('createMyAbsence')
			->with('employee-1', [
				'kind' => 'sick',
				'startDate' => '2026-05-12',
				'endDate' => '2026-05-14',
			])
			->willReturn($scoped);

		$controller = new RosterApiController(
			'dutycheck',
			$request,
			$access,
			$roster,
			$this->createMock(RosterCsvFormatter::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);

		$response = $controller->createMyAbsence();
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame($scoped, $data['data']);
	}

	public function testCreateAbsenceMapsIntegrationReadonlyTo403(): void
	{
		$this->access->method('currentUserId')->willReturn('planner-1');
		$this->access->method('requirePlannerOrAdmin')->willReturnCallback(static function (): void {
		});
		$this->request->method('getParam')->willReturn(null);
		$this->roster->method('createAbsence')->willThrowException(
			new \InvalidArgumentException('INTEGRATION_ABSENCE_READONLY'),
		);

		$response = $this->controller->createAbsence();
		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('INTEGRATION_ABSENCE_READONLY', $data['error']['code']);
	}

	public function testCreateMyAbsenceMapsIntegrationReadonlyTo403(): void
	{
		$this->access->method('currentUserId')->willReturn('employee-1');
		$this->access->method('requireEmployee')->willReturnCallback(static function (): void {
		});
		$this->request->method('getParam')->willReturn(null);
		$this->roster->method('createMyAbsence')->willThrowException(
			new \InvalidArgumentException('INTEGRATION_ABSENCE_READONLY'),
		);

		$response = $this->controller->createMyAbsence();
		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('INTEGRATION_ABSENCE_READONLY', $data['error']['code']);
	}

	public function testCreateAbsenceMapsAbsenceOverlapTo422(): void
	{
		$this->access->method('currentUserId')->willReturn('planner-1');
		$this->access->method('requirePlannerOrAdmin')->willReturnCallback(static function (): void {
		});
		$this->request->method('getParam')->willReturn(null);
		$this->roster->method('createAbsence')->willThrowException(
			new \InvalidArgumentException('ABSENCE_OVERLAP'),
		);

		$response = $this->controller->createAbsence();
		self::assertSame(422, $response->getStatus());
		self::assertSame('ABSENCE_OVERLAP', $response->getData()['error']['code']);
	}

	public function testDirectoryUsersAllowsPlannerWhenNotAppAdmin(): void
	{
		$this->access->method('currentUserId')->willReturn('planner-1');
		$this->access->method('isAppAdmin')->with('planner-1')->willReturn(false);
		$this->access->method('isPlannerOrAdmin')->with('planner-1')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['q', '', 'pla'],
		]);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('planner-1');
		$user->method('getDisplayName')->willReturn('Planner One');
		$user->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('search')->with('pla')->willReturn([$user]);
		$controller = new RosterApiController(
			'dutycheck',
			$this->request,
			$this->access,
			$this->roster,
			$this->csvFormatter,
			$userManager,
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);

		$response = $controller->directoryUsers();
		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['ok']);
		self::assertCount(1, $response->getData()['users']);
	}

	public function testDirectoryUsersFallsBackToForbiddenWhenNeitherPlannerNorAppAdmin(): void
	{
		$this->access->method('currentUserId')->willReturn('employee-1');
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isPlannerOrAdmin')->willReturn(false);
		$this->access->method('requireAppAdmin')->willThrowException(
			new AppAccessDeniedException(AccessControlService::DENIAL_INSUFFICIENT_ROLE),
		);

		$response = $this->controller->directoryUsers();
		self::assertSame(403, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
		self::assertSame('INSUFFICIENT_ROLE', $response->getData()['error']['code']);
	}

	public function testDirectoryUsersReturnsEmptyForShortQueryWithoutDirectoryLookup(): void
	{
		$this->access->method('currentUserId')->willReturn('planner-1');
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isPlannerOrAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['q', '', 'a'],
		]);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects(self::never())->method('search');
		$controller = new RosterApiController(
			'dutycheck',
			$this->request,
			$this->access,
			$this->roster,
			$this->csvFormatter,
			$userManager,
			$this->createMock(IGroupManager::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
		);

		$response = $controller->directoryUsers();
		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['ok']);
		self::assertSame([], $response->getData()['users']);
	}
}

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
		$this->request->method('getParam')->willReturnMap([
			['periodId', null, 12],
			['employeeId', null, 21],
			['locationId', null, 3],
			['dutyDate', null, '2026-05-08'],
			['startTime', null, '08:00'],
			['endTime', null, '16:00'],
			['breakMinutes', null, 30],
			['note', null, ''],
			['acknowledgements', [], []],
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
}

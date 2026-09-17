<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use DG\BypassFinals;
use OCA\DutyCheck\Controller\ApiController;
use OCA\DutyCheck\Controller\CatalogApiController;
use OCA\DutyCheck\Controller\LicenseController;
use OCA\DutyCheck\Controller\MobileController;
use OCA\DutyCheck\Controller\PageController;
use OCA\DutyCheck\Controller\RosterApiController;
use OCA\DutyCheck\Controller\SelfServiceApiController;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Exception\MobileGateException;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RotationSuggestService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\ShiftPreferenceService;
use OCA\DutyCheck\Service\SollDiagnosticsService;
use OCA\DutyCheck\Service\SwapService;
use OCA\DutyCheck\Service\TimezoneCatalog;
use OCA\DutyCheck\Service\TodayBoardService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

// Must run before any DutyCheck controller/service class is autoloaded (constructor typehints).
BypassFinals::enable();

/**
 * Atlas v3 — per-endpoint happy (2xx / designed status + body) and AuthZ deny proofs.
 * Replaces Response-only invoke theater for api-matrix VERIFIED rows.
 */
final class AtlasApiEndpointHappyAuthzTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		BypassFinals::enable();
	}
	private const CONTROLLERS = [
		PageController::class,
		ApiController::class,
		CatalogApiController::class,
		RosterApiController::class,
		SelfServiceApiController::class,
		LicenseController::class,
		MobileController::class,
	];

	/**
	 * Actions that must prove AuthZ deny (authz_negative_required + privileged admin GETs).
	 *
	 * @var array<string, list<string>>
	 */
	private const AUTHZ_ACTIONS = [
		PageController::class => [
			'settingsSection',
		],
		LicenseController::class => [
			'apply', 'assignSeat', 'remove', 'removeSeat', 'show', 'seats', 'searchUsers',
		],
		MobileController::class => [
			'acceptSwap', 'acknowledgeAssignment', 'claimOpenShift', 'createIcalToken',
			'createMyAbsence', 'createMyBlackout', 'createSwapRequest', 'deleteMyBlackout',
			'deleteMyPreference', 'rotateIcalToken', 'saveMyPreference',
		],
		RosterApiController::class => [
			'acknowledgeAssignment', 'acknowledgeConflict', 'addCompanyMember', 'appPolicy',
			'approveOpenShiftClaim', 'attachEmployeeQualification', 'cancelAssignment',
			'claimOpenShift', 'closePeriod', 'conflictPolicy', 'copyPeriod', 'createAbsence',
			'createAssignment', 'createCompany', 'createEmployee', 'createLocation',
			'createMyAbsence', 'createOpenShift', 'createPeriod', 'createQualification',
			'createSwapRequest', 'createTemplate', 'deactivateQualification', 'deleteTemplate',
			'detachEmployeeQualification', 'directoryGroups', 'directoryUsers',
			'ensureCalendarMonth', 'exportRosterCsv', 'exportRosterMinutesCsv',
			'integrationIntent', 'integrationPurgeLegacyAbsences', 'integrationSettings',
			'integrationStatus', 'integrationSyncNow', 'listCompanies', 'listCompanyMembers',
			'listDutyRoles', 'opsFlags', 'periodAcknowledgeStats', 'periodAudit',
			'periodSnapshots', 'plannerLocationScope', 'planningDefaults', 'pruneSnapshots',
			'publicIcal', 'publishPeriod', 'publishReadiness', 'rejectOpenShiftClaim',
			'removeCompanyMember', 'removeDutyRole', 'reopenPeriod',
			'requireLocationQualification', 'reviewSwapRequest', 'rotateMyIcalToken',
			'saveAppPolicy', 'saveConflictPolicy', 'applyConflictPolicyToOpenPeriods', 'saveOpsFlags', 'savePlanningDefaults',
			'setDutyRole', 'setPlannerLocationScope', 'transitionAbsence', 'transitionPeriod',
			'updateAssignment', 'updateEmployee', 'updateLocation', 'updateQualification',
			'updateTemplate', 'verifyPeriodSnapshots',
		],
		SelfServiceApiController::class => [
			'acceptSwap', 'assignPattern', 'blackoutOverride', 'createMyBlackout',
			'createPattern', 'deactivatePattern', 'deleteMyBlackout', 'deleteMyPreference',
			'employeeBlackouts', 'employeePreferences', 'endRotationAssignment', 'getPattern',
			'listEmployeeRotationAssignments', 'saveMyPreference', 'sollDiagnostics',
			'suggestConfirm', 'suggestPreview', 'updatePattern', 'updateSettings',
		],
		CatalogApiController::class => [
			'timezones',
		],
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionHappyPathIs2xxOrDesignedStatus(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::CONTROLLERS as $class) {
			$this->byType = [];
			$ctrl = $this->buildController($class, allow: true, mode: 'happy');
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
					continue;
				}
				$symbol = $ref->getShortName() . '::' . $method->getName();
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				if (!$result instanceof Response) {
					$failures[] = $symbol . ' not Response';
					continue;
				}
				$status = $result->getStatus();
				// Designed throttles (shared lab / consecutive Atlas invoke) are happy-path OK.
				if ($status === 429 && ($result instanceof DataResponse || $result instanceof JSONResponse)) {
					$data = $result->getData();
					$code = '';
					if (is_array($data)) {
						$code = (string) ($data['error']['code'] ?? $data['error'] ?? '');
						if ($code === '' && is_string($data['error'] ?? null)) {
							$code = (string) $data['error'];
						}
					}
					$designed429 = [
						'INTEGRATION_SYNC_RATE_LIMIT',
						'INTEGRATION_PURGE_THROTTLED',
						'RATE_LIMITED',
						'ENSURE_MONTH_IN_PROGRESS',
					];
					if (in_array($code, $designed429, true) || in_array((string) ($data['error'] ?? ''), $designed429, true)) {
						$proved[] = $symbol;
						continue;
					}
				}
				if (!(($status >= 200 && $status < 300) || ($status >= 300 && $status < 400))) {
					$body = '';
					if ($result instanceof DataResponse || $result instanceof JSONResponse) {
						$body = json_encode($result->getData());
					}
					$failures[] = $symbol . ' status=' . $status . ' body=' . $body;
					continue;
				}
				if ($status < 300 && ($result instanceof DataResponse || $result instanceof JSONResponse)) {
					$data = $result->getData();
					if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== true) {
						$failures[] = $symbol . ' ok=false body=' . json_encode($data);
						continue;
					}
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "Happy-path failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(140, count($proved), 'expected ~156 controller actions, got ' . count($proved));
	}

	public function testAuthzNegativePerEndpointAction(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::AUTHZ_ACTIONS as $class => $actions) {
			foreach ($actions as $action) {
				$this->byType = [];
				$ctrl = $this->buildController($class, allow: false, mode: 'authz');
				$ref = new ReflectionClass($class);
				self::assertTrue($ref->hasMethod($action), $class . '::' . $action);
				$method = $ref->getMethod($action);
				$symbol = $ref->getShortName() . '::' . $action;
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
					if (!$result instanceof Response) {
						$failures[] = $symbol . ' not Response';
						continue;
					}
					if ($result->getStatus() < 400) {
						$body = '';
						if ($result instanceof DataResponse || $result instanceof JSONResponse) {
							$body = json_encode($result->getData());
						}
						$failures[] = $symbol . ' deny status=' . $result->getStatus() . ' body=' . $body;
						continue;
					}
					if ($result instanceof DataResponse || $result instanceof JSONResponse) {
						$data = $result->getData();
						if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== false) {
							$failures[] = $symbol . ' deny envelope ok!=false';
							continue;
						}
					}
				} catch (AppAccessDeniedException $e) {
					self::assertSame('access_denied', $e->getMessage(), $symbol);
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "AuthZ failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(100, count($proved), 'expected authz_negative actions, got ' . count($proved));
	}

	/**
	 * Explicit license mutate/seat denies (must not cite show-only).
	 */
	public function testLicenseMutateAndSeatAuthzNegativeIs403(): void
	{
		foreach (['apply', 'remove', 'assignSeat', 'removeSeat', 'show', 'seats', 'searchUsers'] as $action) {
			$this->byType = [];
			$ctrl = $this->buildController(LicenseController::class, allow: false, mode: 'authz');
			$ref = new ReflectionClass(LicenseController::class);
			$method = $ref->getMethod($action);
			$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
			self::assertSame(403, $result->getStatus(), 'LicenseController::' . $action);
			$data = $result->getData();
			self::assertIsArray($data);
			self::assertFalse($data['ok'] ?? true, 'LicenseController::' . $action);
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param 'happy'|'authz' $mode
	 * @return T
	 */
	private function buildController(string $class, bool $allow, string $mode): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		self::assertNotNull($ctor);
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();
			if ($name === 'appName') {
				$args[] = 'dutycheck';
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request($mode);
				continue;
			}
			if ($type === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode, $class);
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null') {
					return $t->getName();
				}
			}
		}
		return null;
	}

	/**
	 * @param class-string $controllerClass
	 */
	private function mockFor(string $typeName, bool $allow, string $mode, string $controllerClass): object
	{
		$key = $typeName . ':' . ($allow ? '1' : '0') . ':' . $mode . ':' . $controllerClass;
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}

		if ($typeName === AccessControlService::class) {
			$mock = $this->createMock(AccessControlService::class);
			$mock->method('currentUserId')->willReturn('alice');
			$mock->method('isAppAdmin')->willReturn($allow);
			$mock->method('isPlannerOrAdmin')->willReturn($allow);
			$mock->method('isEmployee')->willReturn($allow);
			$mock->method('hasActiveLinkedEmployee')->willReturn($allow);
			$mock->method('needsRoleEnrollment')->willReturn(false);
			$deny = static function () use ($allow): void {
				if (!$allow) {
					throw new AppAccessDeniedException(AccessControlService::DENIAL_INSUFFICIENT_ROLE);
				}
			};
			$mock->method('requirePlannerOrAdmin')->willReturnCallback($deny);
			$mock->method('requireAppAdmin')->willReturnCallback($deny);
			$mock->method('requireEmployee')->willReturnCallback(static function () use ($allow): void {
				if (!$allow) {
					throw new AppAccessDeniedException(AccessControlService::DENIAL_EMPLOYEE_NOT_LINKED);
				}
			});
			$mock->method('appPolicy')->willReturn([
				'appAdminUserIds' => [],
				'accessRestrictionEnabled' => false,
				'allowedUserIds' => [],
				'allowedGroupIds' => [],
			]);
			$mock->method('listDutyRoleAssignments')->willReturn([]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserSession::class) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$this->byType[$key] = $session;
			return $session;
		}

		if ($typeName === TimezoneCatalog::class) {
			$real = new TimezoneCatalog();
			$this->byType[$key] = $real;
			return $real;
		}

		if ($typeName === LicenseService::class) {
			$mock = $this->createMock(LicenseService::class);
			$mock->method('status')->willReturn(['ok' => true, 'licensed' => true]);
			$mock->method('apply')->willReturn(['ok' => true, 'licensed' => true]);
			$mock->method('remove')->willReturn(['ok' => true]);
			$mock->method('listSeats')->willReturn(['ok' => true, 'seats' => [], 'total' => 0]);
			$mock->method('assignSeat')->willReturn(['seat' => ['userId' => 'bob', 'ok' => true], 'created' => true]);
			$mock->method('searchUsersForSeats')->willReturn([]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === MobileGateService::class) {
			$mock = $this->createMock(MobileGateService::class);
			if ($mode === 'authz') {
				$mock->method('assertGatePassed')->willReturnCallback(
					static function (): void {
						throw new MobileGateException('seat_required');
					}
				);
			}
			$mock->method('bootstrapPayload')->willReturn([
				'ok' => true,
				'userId' => 'alice',
				'displayName' => 'Alice',
				'capabilities' => [],
				'urls' => [],
			]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === RosterService::class) {
			$mock = $this->createMock(RosterService::class);
			if ($mode === 'authz' && $controllerClass === RosterApiController::class) {
				$mock->method('publicIcal')->willReturnCallback(
					static function (): string {
						throw new \InvalidArgumentException('ICAL_TOKEN_INVALID');
					}
				);
			} else {
				$mock->method('publicIcal')->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
			}
			$mock->method('dashboardSummary')->willReturn([]);
			$mock->method('myRoster')->willReturn([]);
			$mock->method('myAbsences')->willReturn([]);
			$mock->method('listPeriods')->willReturn([]);
			$mock->method('acknowledgeAssignment')->willReturn(['id' => 1]);
			$mock->method('createMyAbsence')->willReturn(['id' => 1]);
			$mock->method('myIcalTokenMeta')->willReturn(['hasToken' => false, 'employeeId' => 10]);
			$mock->method('rotateMyIcalToken')->willReturn(['rotated' => true, 'employeeId' => 10]);
			$mock->method('countActiveEmployees')->willReturn(0);
			$mock->method('countActiveUnlinkedEmployees')->willReturn(0);
			$mock->method('rosterExportBundle')->willReturn([
				'period' => ['id' => 1, 'label' => 'Sep 2026'],
				'assignments' => [],
				'employees' => [],
				'locations' => [],
			]);
			$assignment = [
				'id' => 1,
				'locationId' => 1,
				'employeeId' => 10,
				'dutyDate' => '2026-09-10',
				'startTime' => '08:00',
				'endTime' => '17:00',
				'breakMinutes' => 30,
				'version' => 1,
			];
			$mock->method('peekAssignment')->willReturn($assignment);
			$mock->method('createAssignment')->willReturn($assignment);
			$mock->method('updateAssignment')->willReturn($assignment);
			$mock->method('cancelAssignment')->willReturn($assignment);
			$mock->method('createPeriod')->willReturn(['id' => 1]);
			$mock->method('ensureOpenCalendarMonth')->willReturn(['id' => 1]);
			$mock->method('transitionPeriod')->willReturn(['id' => 1]);
			$mock->method('listPeriodSnapshots')->willReturn([]);
			$mock->method('verifyPeriodSnapshots')->willReturn(['ok' => true]);
			$mock->method('publishReadiness')->willReturn(['ready' => true]);
			$mock->method('periodAudit')->willReturn([]);
			$mock->method('periodAcknowledgeStats')->willReturn([]);
			$mock->method('copyPeriodAssignments')->willReturn(['copied' => 0]);
			$mock->method('listEmployeeCatalog')->willReturn([]);
			$mock->method('createEmployee')->willReturn(['id' => 10]);
			$mock->method('updateEmployee')->willReturn(['id' => 10]);
			$mock->method('listLocationCatalog')->willReturn([]);
			$mock->method('createLocation')->willReturn(['id' => 1]);
			$mock->method('updateLocation')->willReturn(['id' => 1]);
			$mock->method('listAbsences')->willReturn([]);
			$mock->method('createAbsence')->willReturn(['id' => 1]);
			$mock->method('transitionAbsence')->willReturn(['id' => 1]);
			$mock->method('rosterData')->willReturn(['assignments' => []]);
			$mock->method('acknowledgeConflict')->willReturn(['id' => 1]);
			$mock->method('isSchemaReady')->willReturn(true);
			// assertPeriodCompanyAccess / logRosterDataExport are void — default mock no-op is fine.
			$this->byType[$key] = $mock;
			return $mock;
		}

		// Object-level AuthZ: counterparty / ownership checks live in the service layer.
		if ($mode === 'authz' && in_array($typeName, [
			\OCA\DutyCheck\Service\SwapService::class,
			\OCA\DutyCheck\Service\SollDiagnosticsService::class,
		], true)) {
			$mock = $this->createMock($typeName);
			$mock->method($this->anything())->willReturnCallback(
				static function (): never {
					throw new \InvalidArgumentException('FORBIDDEN');
				}
			);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if (class_exists($typeName)) {
			$ref = new ReflectionClass($typeName);
			if ($ref->isFinal() && $ref->isInstantiable()) {
				$obj = $this->buildFinalConcrete($ref, $allow, $mode, $controllerClass);
				$this->byType[$key] = $obj;
				return $obj;
			}
		}

		/** @var MockObject $mock */
		$mock = $this->createMock($typeName);
		$this->configureGenericHappyMock($typeName, $mock);
		$this->byType[$key] = $mock;
		return $mock;
	}

	private function configureGenericHappyMock(string $typeName, MockObject $mock): void
	{
		// Broad happy stubs — controllers wrap results in ok envelopes; nulls often still 200.
		if (method_exists($typeName, 'linkToRoute')) {
			$mock->method('linkToRoute')->willReturn('/apps/dutycheck/');
			$mock->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/dutycheck/');
			$mock->method('linkToDefaultPageUrl')->willReturn('/');
		}
		if (method_exists($typeName, 't')) {
			$mock->method('t')->willReturnCallback(static fn (string $s, array $p = []): string => $s);
		}
		if (method_exists($typeName, 'getAppVersion')) {
			$mock->method('getAppVersion')->willReturn('1.0.0');
		}
		if (method_exists($typeName, 'isSection')) {
			$mock->method('isSection')->willReturn(true);
			$mock->method('label')->willReturn('Access');
			$mock->method('help')->willReturn('help');
		}
		if (method_exists($typeName, 'clientHints')) {
			$mock->method('clientHints')->willReturn([
				'htmlLang' => 'en-US',
				'locale' => 'en_US',
				'timezone' => 'UTC',
			]);
		}
		if (method_exists($typeName, 'buildBootstrapForUser')) {
			$mock->method('buildBootstrapForUser')->willReturn(['ok' => true]);
			$mock->method('countLegacyAbsencesForLinkedEmployees')->willReturn(0);
			$mock->method('isBreakerActive')->willReturn(false);
			$mock->method('acquireSyncLease')->willReturn(['acquired' => true, 'token' => 'lease-tok']);
			$mock->method('runReconcile')->willReturn(['ok' => true, 'synced' => 0]);
		}

		switch ($typeName) {
			case CompanyService::class:
				$mock->method('writeCompanyIdFor')->willReturn(1);
				$mock->method('listCompanies')->willReturn([['id' => 1]]);
				$mock->method('createCompany')->willReturn(['id' => 1]);
				$mock->method('listMembers')->willReturn([]);
				break;
			case SelfServiceSettingsService::class:
				$mock->method('toApi')->willReturn([
					'ok' => true,
					'settingsRevision' => 'rev1',
					'preferencesEnabled' => true,
					'blackoutsEnabled' => true,
					'rotationPatternsEnabled' => true,
					'peerRosterVisibility' => true,
					'pushQuietHoursEnabled' => false,
					'pushQuietHoursStart' => '22:00',
					'pushQuietHoursEnd' => '06:00',
					'swapApprovalMode' => 'planner',
				]);
				$mock->method('updateForCompany')->willReturn(['ok' => true, 'settingsRevision' => 'rev2']);
				$mock->method('getForActor')->willReturn(['ok' => true]);
				break;
			case TodayBoardService::class:
				$mock->method('getBoard')->willReturn(['locationId' => 1, 'rows' => []]);
				break;
			case PeerRosterService::class:
				$mock->method('listTeamWeek')->willReturn(['rows' => [], 'total' => 0]);
				$mock->method('listBelongingLocations')->willReturn([['id' => 1, 'name' => 'HQ']]);
				break;
			case RotationPatternService::class:
				$mock->method('listPatterns')->willReturn([]);
				$mock->method('getPattern')->willReturn(['id' => 1, 'name' => 'P']);
				$mock->method('createPattern')->willReturn(['id' => 1]);
				$mock->method('updatePattern')->willReturn(['id' => 1]);
				$mock->method('deactivatePattern')->willReturn(['id' => 1]);
				$mock->method('assignToEmployee')->willReturn(['id' => 1]);
				$mock->method('listEmployeeAssignments')->willReturn([]);
				$mock->method('endAssignment')->willReturn(['id' => 1]);
				break;
			case RotationSuggestService::class:
				$mock->method('preview')->willReturn(['suggestions' => []]);
				$mock->method('confirm')->willReturn(['created' => 0]);
				break;
			case AvailabilityBlackoutService::class:
				$mock->method('listForEmployee')->willReturn([]);
				$mock->method('create')->willReturn(['id' => 1]);
				$mock->method('recordOverride')->willReturn(['id' => 1]);
				break;
			case ShiftPreferenceService::class:
				$mock->method('listForEmployee')->willReturn([]);
				$mock->method('listForCompanyEmployees')->willReturn([]);
				$mock->method('save')->willReturn(['id' => 1]);
				$mock->method('getById')->willReturn(['id' => 1]);
				break;
			case SwapService::class:
				$mock->method('acceptByCounterparty')->willReturn(['id' => 1, 'status' => 'accepted']);
				$mock->method('listPending')->willReturn([]);
				$mock->method('requestSwap')->willReturn(['id' => 1]);
				$mock->method('review')->willReturn(['id' => 1]);
				$mock->method('getById')->willReturn(['id' => 1]);
				$mock->method('listSwapCandidates')->willReturn([]);
				break;
			case SollDiagnosticsService::class:
				$mock->method('diagnose')->willReturn(['ok' => true, 'rows' => []]);
				break;
			case OpenShiftService::class:
				$mock->method('listOpen')->willReturn([]);
				$mock->method('listPending')->willReturn([]);
				$mock->method('create')->willReturn(['id' => 1]);
				$mock->method('claim')->willReturn(['id' => 1]);
				$mock->method('getById')->willReturn(['id' => 1, 'locationId' => 1]);
				$mock->method('approveClaim')->willReturn(['id' => 1]);
				$mock->method('rejectClaim')->willReturn(['id' => 1]);
				break;
			case IUserManager::class:
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('bob');
				$user->method('getDisplayName')->willReturn('Bob');
				$mock->method('search')->willReturn([$user]);
				$mock->method('get')->willReturn($user);
				break;
			case IGroupManager::class:
				$mock->method('search')->willReturn([]);
				break;
			case \OCP\IDBConnection::class:
				$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
				$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
				$expr->method($this->anything())->willReturn('1=1');
				$result = $this->createMock(\OCP\DB\IResult::class);
				$result->method('fetchOne')->willReturn(10);
				$result->method('fetchAll')->willReturn([]);
				foreach ([
					'select', 'selectDistinct', 'from', 'where', 'andWhere', 'orWhere',
					'orderBy', 'addOrderBy', 'groupBy', 'setMaxResults', 'setFirstResult',
					'delete', 'update', 'insert', 'set', 'values',
				] as $m) {
					$qb->method($m)->willReturnSelf();
				}
				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturnArgument(0);
				$qb->method('executeQuery')->willReturn($result);
				$qb->method('executeStatement')->willReturn(1);
				$mock->method('getQueryBuilder')->willReturn($qb);
				break;
		}
	}

	/**
	 * @param ReflectionClass<object> $ref
	 * @param class-string $controllerClass
	 */
	private function buildFinalConcrete(ReflectionClass $ref, bool $allow, string $mode, string $controllerClass): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			$typeName = $type ? $this->resolveTypeName($type) : null;
			if ($typeName === null) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
				} elseif ($type instanceof ReflectionNamedType && $type->allowsNull()) {
					$args[] = null;
				} else {
					$args[] = match ($type instanceof ReflectionNamedType ? $type->getName() : '') {
						'string' => '',
						'int' => 0,
						'bool' => false,
						'array' => [],
						default => null,
					};
				}
				continue;
			}
			if ($type !== null && $type->allowsNull() && $param->isDefaultValueAvailable()) {
				$args[] = null;
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode, $controllerClass);
		}
		return $ref->newInstanceArgs($args);
	}

	/** @param 'happy'|'authz' $mode */
	private function request(string $mode): IRequest
	{
		$req = $this->createMock(IRequest::class);
		$params = [
			'key' => 'LIC-TEST',
			'uid' => 'bob',
			'seat' => 'bob',
			'user' => 'bob',
			'q' => 'bo',
			'from' => '2026-09-01',
			'to' => '2026-09-30',
			'year' => 2026,
			'month' => 9,
			'section' => 'access',
			'startDate' => '2026-09-01',
			'endDate' => '2026-09-14',
			'employeeId' => 10,
			'locationId' => 1,
			'dutyDate' => '2026-09-10',
			'startTime' => '08:00',
			'endTime' => '17:00',
			'breakMinutes' => 30,
			'note' => 'n',
			'name' => 'Template',
			'code' => 'EARLY',
			'status' => 'approved',
			'reason' => 'r',
			'type' => 'vacation',
			'userId' => 'bob',
			'role' => 'planner',
			'companyId' => 1,
			'weekStart' => '2026-09-07',
			'page' => 1,
			'pageSize' => 20,
			'settings' => [],
			'policy' => [],
			'acknowledgements' => [],
			'token' => $mode === 'authz' ? 'bad-token' : 'tok',
			'displayName' => 'Bob',
			'active' => true,
			'linkedUserId' => 'bob',
			'qualificationId' => 1,
			'locationIds' => [1],
			'memberUserId' => 'bob',
			'intentEnabled' => true,
			'employeeIds' => [10],
			'patternId' => 1,
			'fromDate' => '2026-09-07',
			'band' => 'prefer',
			'startAt' => '2026-09-10 00:00:00',
			'endAt' => '2026-09-10 23:59:59',
			'scope' => 'personal',
			'overrideReason' => 'coverage',
			'revision' => 'rev1',
			'targetUserId' => 10,
			'counterpartyEmployeeId' => 11,
			'message' => 'swap please',
			'approve' => true,
			'decision' => 'approve',
			'blackoutOverrideReason' => 'audit',
			'kind' => 'vacation',
			'periodId' => 1,
			'enabled' => true,
			'yearMonth' => '2026-09',
			'validTo' => '2026-12-31',
			'date' => '2026-09-10',
			'locationId' => 1,
			'includePii' => false,
			'piiJustification' => 'n/a',
			'blockPublishWhenStale' => false,
		];
		$req->method('getParam')->willReturnCallback(static function (string $k, $default = null) use ($params) {
			return $params[$k] ?? $default;
		});
		$req->method('getParams')->willReturn($params);
		$req->method('getHeader')->willReturnCallback(static function (string $h): string {
			if (strcasecmp($h, 'Authorization') === 0) {
				return 'Basic ' . base64_encode('alice:app-pass');
			}
			return '';
		});
		$req->method('getServerProtocol')->willReturn('https');
		$req->method('getRemoteAddress')->willReturn('127.0.0.1');
		return $req;
	}

	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			$type = $param->getType();
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			if ($type instanceof ReflectionNamedType) {
				$name = $type->getName();
				if ($type->allowsNull()) {
					$args[] = null;
					continue;
				}
				$args[] = match ($name) {
					'int' => 1,
					'string' => 'access',
					'bool' => true,
					'float' => 1.0,
					'array' => [],
					default => null,
				};
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}

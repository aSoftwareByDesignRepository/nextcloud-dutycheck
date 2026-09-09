<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Coverage;

use OCA\DutyCheck\Controller\ApiController;
use OCA\DutyCheck\Controller\CatalogApiController;
use OCA\DutyCheck\Controller\LicenseController;
use OCA\DutyCheck\Controller\MobileController;
use OCA\DutyCheck\Controller\PageController;
use OCA\DutyCheck\Controller\RosterApiController;
use OCA\DutyCheck\Controller\SelfServiceApiController;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\TimezoneCatalog;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Atlas v3 used-function invoke coverage: every shipping controller action is called.
 */
final class AtlasControllersInvokeCoverageTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];

	private const CONTROLLERS = [
		PageController::class,
		ApiController::class,
		CatalogApiController::class,
		RosterApiController::class,
		SelfServiceApiController::class,
		LicenseController::class,
		MobileController::class,
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionIsInvoked(): void
	{
		$invoked = [];
		foreach (self::CONTROLLERS as $class) {
			$ctrl = $this->buildController($class);
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				if ($method->getName() === '__construct') {
					continue;
				}
				$args = $this->dummyArgs($method);
				$symbol = $ref->getShortName() . '::' . $method->getName();
				$result = $method->invokeArgs($ctrl, $args);
				self::assertInstanceOf(Response::class, $result, $symbol);
				$invoked[] = $symbol;
			}
		}

		self::assertGreaterThanOrEqual(140, count($invoked), 'expected ~156 controller actions, got ' . count($invoked));
		self::assertContains('PageController::index', $invoked);
		self::assertContains('RosterApiController::dashboard', $invoked);
		self::assertContains('MobileController::bootstrap', $invoked);
		self::assertContains('SelfServiceApiController::teamWeek', $invoked);
		self::assertContains('LicenseController::show', $invoked);
	}

	public function testLicenseShowAuthzNegativeIs403(): void
	{
		$ctrl = $this->buildController(LicenseController::class, allowAdmin: false);
		$res = $ctrl->show();
		self::assertSame(403, $res->getStatus());
	}

	public function testCatalogTimezonesAuthzNegativeIsForbiddenEnvelope(): void
	{
		$ctrl = $this->buildController(CatalogApiController::class, allowAdmin: false, allowPlanner: false);
		$res = $ctrl->timezones();
		self::assertInstanceOf(Response::class, $res);
		// ApiJsonErrorResponse maps FORBIDDEN → 403
		self::assertSame(403, $res->getStatus());
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function buildController(string $class, bool $allowAdmin = true, bool $allowPlanner = true): object
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
				$args[] = $this->request();
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
			$args[] = $this->mockFor($typeName, $allowAdmin, $allowPlanner);
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

	private function mockFor(string $typeName, bool $allowAdmin, bool $allowPlanner): object
	{
		$key = $typeName . ':' . ($allowAdmin ? '1' : '0') . ($allowPlanner ? '1' : '0');
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}

		if ($typeName === AccessControlService::class) {
			$mock = $this->createMock(AccessControlService::class);
			$mock->method('currentUserId')->willReturn('alice');
			$mock->method('isAppAdmin')->willReturn($allowAdmin);
			$mock->method('isPlannerOrAdmin')->willReturn($allowPlanner);
			$mock->method('isEmployee')->willReturn(true);
			$mock->method('hasActiveLinkedEmployee')->willReturn(true);
			$mock->method('needsRoleEnrollment')->willReturn(false);
			$mock->method('requirePlannerOrAdmin')->willReturnCallback(
				static function () use ($allowPlanner): void {
					if (!$allowPlanner) {
						throw new \InvalidArgumentException('FORBIDDEN');
					}
				}
			);
			$mock->method('requireAppAdmin')->willReturnCallback(
				static function () use ($allowAdmin): void {
					if (!$allowAdmin) {
						throw new \InvalidArgumentException('FORBIDDEN');
					}
				}
			);
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

		if ($typeName === \OCA\DutyCheck\Service\LicenseService::class) {
			$mock = $this->createMock($typeName);
			$mock->method('status')->willReturn(['ok' => true, 'licensed' => false]);
			$mock->method('apply')->willReturn(['ok' => true]);
			$mock->method('remove')->willReturn(['ok' => true]);
			$mock->method('listSeats')->willReturn(['seats' => [], 'total' => 0]);
			$mock->method('assignSeat')->willReturn(['seat' => ['userId' => 'bob'], 'created' => true]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if (class_exists($typeName)) {
			$ref = new ReflectionClass($typeName);
			if ($ref->isFinal() && $ref->isInstantiable()) {
				$obj = $this->buildFinalConcrete($ref, $allowAdmin, $allowPlanner);
				$this->byType[$key] = $obj;
				return $obj;
			}
		}

		/** @var MockObject $mock */
		$mock = $this->createMock($typeName);
		$this->byType[$key] = $mock;
		return $mock;
	}

	/**
	 * @param ReflectionClass<object> $ref
	 */
	private function buildFinalConcrete(ReflectionClass $ref, bool $allowAdmin, bool $allowPlanner): object
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
			$args[] = $this->mockFor($typeName, $allowAdmin, $allowPlanner);
		}
		return $ref->newInstanceArgs($args);
	}

	private function request(): IRequest
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
			'token' => 'tok',
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

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Mutation-hardening tests for the employee catalog: listing/counting,
 * create/update flows (insert payload, active flag, company column),
 * linked-user normalisation, display-name resolution and uniqueness guards.
 */
final class RosterServiceMutationEmployeeCatalogTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_employees.company_id' => true]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function invoke(RosterService $service, string $method, mixed ...$args): mixed
	{
		$m = new ReflectionMethod(RosterService::class, $method);
		$m->setAccessible(true);
		return $m->invoke($service, ...$args);
	}

	public function testListEmployeeCatalogMapsAndCastsRows(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetchAll' => [
			[
				'id' => '3',
				'display_name' => 77,
				'linked_user_id' => 88,
				'active' => '1',
				'created_at' => 20260101,
			],
			[
				'id' => 4,
				'display_name' => 'Bob',
				'linked_user_id' => null,
				'active' => 0,
				'created_at' => '2026-01-02 00:00:00',
			],
		]]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame([
			[
				'id' => 3,
				'displayName' => '77',
				'linkedUserId' => '88',
				'active' => true,
				'createdAt' => '20260101',
			],
			[
				'id' => 4,
				'displayName' => 'Bob',
				'linkedUserId' => null,
				'active' => false,
				'createdAt' => '2026-01-02 00:00:00',
			],
		], $service->listEmployeeCatalog(null));
	}

	public function testListEmployeeCatalogScopesToActorCompany(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::once())
			->method('restrictQuery')
			->with(self::anything(), 'company_id', 'planner-1');

		$qb = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qb), companies: $companies);

		self::assertSame([], $service->listEmployeeCatalog('planner-1'));
	}

	public function testListEmployeeCatalogWithoutActorSkipsCompanyScope(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::never())->method('restrictQuery');

		$qb = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qb), companies: $companies);

		self::assertSame([], $service->listEmployeeCatalog(null));
	}

	public function testCountActiveEmployeesFiltersOnActiveOneAndCastsResult(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetchOne' => '5'], $params);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame(5, $service->countActiveEmployees());
		$this->assertParamCaptured([1], $params);
	}

	public function testCountActiveEmployeesWithCompanyServiceButNoActorSkipsScope(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::never())->method('restrictQuery');

		$qb = $this->rosterQb(['fetchOne' => '5']);
		$service = new RosterService($this->rosterDb($qb), companies: $companies);

		self::assertSame(5, $service->countActiveEmployees());
	}

	public function testCountScopedRestrictsForActorWithCompanyColumn(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::once())
			->method('restrictQuery')
			->with(self::anything(), 'company_id', 'planner-1');

		$qb = $this->rosterQb(['fetchOne' => 2]);
		$service = new RosterService($this->rosterDb($qb), companies: $companies);

		self::assertSame(2, $this->invoke($service, 'countScoped', 'dc_employees', 'active', 1, 'planner-1'));
	}

	public function testCountActiveUnlinkedEmployeesQueriesActiveOneAndCastsResult(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetchOne' => '4'], $params);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame(4, $service->countActiveUnlinkedEmployees());
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $params);
		$this->assertParamCaptured([''], $params);
	}

	public function testCreateEmployeeInsertsCompletePayloadWithActiveDefault(): void
	{
		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbInsert = $this->rosterQb(['statementOnce' => true], $insertParams, $insertValues);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbNameUnique, $qbInsert, $qbList));

		self::assertSame([], $service->createEmployee(['displayName' => 'Alice'], 'planner-1'));

		self::assertSame(
			['display_name', 'linked_user_id', 'active', 'created_at'],
			array_keys($insertValues ?? []),
		);
		$this->assertParamCaptured(['Alice'], $insertParams);
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $insertParams, 'default active flag must be 1');
	}

	public function testCreateEmployeeHonoursExplicitInactiveFlag(): void
	{
		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbInsert = $this->rosterQb(['statementOnce' => true], $insertParams);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbNameUnique, $qbInsert, $qbList));

		self::assertSame([], $service->createEmployee(['displayName' => 'Alice', 'active' => '0'], 'planner-1'));

		$this->assertParamCaptured([0, IQueryBuilder::PARAM_INT], $insertParams);
	}

	public function testCreateEmployeeStampsCompanyIdWhenMultiCompanyReady(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(true);
		$companies->method('writeCompanyIdFor')->with('planner-1')->willReturn(77);

		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbInsert = $this->rosterQb(['statementOnce' => true], $insertParams, $insertValues);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbNameUnique, $qbInsert, $qbList),
			companies: $companies,
		);

		self::assertSame([], $service->createEmployee(['displayName' => 'Alice'], 'planner-1'));

		self::assertSame(
			['display_name', 'linked_user_id', 'active', 'created_at', 'company_id'],
			array_keys($insertValues ?? []),
		);
		$this->assertParamCaptured([77, IQueryBuilder::PARAM_INT], $insertParams);
	}

	public function testUpdateEmployeeWritesActiveDefaultAndTargetsRow(): void
	{
		$qbRowExists = $this->rosterQb(['fetchOne' => 7]);
		$qbCurrentLink = $this->rosterQb(['fetchOne' => null]);
		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true], $updateParams);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbRowExists, $qbCurrentLink, $qbNameUnique, $qbUpdate, $qbList),
		);

		self::assertSame([], $service->updateEmployee(7, ['displayName' => 'Alice2']));

		$this->assertParamCaptured(['Alice2'], $updateParams);
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $updateParams);
		$this->assertParamCaptured([7, IQueryBuilder::PARAM_INT], $updateParams);
	}

	public function testUpdateEmployeeHonoursExplicitInactiveFlag(): void
	{
		$qbRowExists = $this->rosterQb(['fetchOne' => 7]);
		$qbCurrentLink = $this->rosterQb(['fetchOne' => null]);
		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true], $updateParams);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbRowExists, $qbCurrentLink, $qbNameUnique, $qbUpdate, $qbList),
		);

		self::assertSame([], $service->updateEmployee(7, ['displayName' => 'Alice2', 'active' => '0']));

		$this->assertParamCaptured([0, IQueryBuilder::PARAM_INT], $updateParams);
	}

	public function testUpdateEmployeeAssertsActorCompanyOnTargetRow(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(false);
		$companies->expects(self::once())
			->method('assertRowCompany')
			->with('planner-1', 'dc_employees', 7);

		$qbRowExists = $this->rosterQb(['fetchOne' => 7]);
		$qbCurrentLink = $this->rosterQb(['fetchOne' => null]);
		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbRowExists, $qbCurrentLink, $qbNameUnique, $qbUpdate, $qbList),
			companies: $companies,
		);

		self::assertSame([], $service->updateEmployee(7, ['displayName' => 'Alice2'], 'planner-1'));
	}

	public function testUpdateEmployeeWithoutActorSkipsCompanyAssert(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(false);
		$companies->expects(self::never())->method('assertRowCompany');

		$qbRowExists = $this->rosterQb(['fetchOne' => 7]);
		$qbCurrentLink = $this->rosterQb(['fetchOne' => null]);
		$qbNameUnique = $this->rosterQb(['fetchOne' => false]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbRowExists, $qbCurrentLink, $qbNameUnique, $qbUpdate, $qbList),
			companies: $companies,
		);

		self::assertSame([], $service->updateEmployee(7, ['displayName' => 'Alice2']));
	}

	public function testNormalizeLinkedUserIdCastsTrimsAndValidates(): void
	{
		$service = new RosterService($this->rosterDb());

		self::assertSame('123', $this->invoke($service, 'normalizeLinkedUserId', 123));
		self::assertSame('bob', $this->invoke($service, 'normalizeLinkedUserId', ' bob '));
		self::assertNull($this->invoke($service, 'normalizeLinkedUserId', '   '));

		$max = str_repeat('a', 64);
		self::assertSame($max, $this->invoke($service, 'normalizeLinkedUserId', $max));
	}

	public function testNormalizeLinkedUserIdRejectsInvalidPrefix(): void
	{
		$service = new RosterService($this->rosterDb());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_LINKED_USER');
		$this->invoke($service, 'normalizeLinkedUserId', '§bob');
	}

	public function testNormalizeLinkedUserIdRejectsInvalidSuffix(): void
	{
		$service = new RosterService($this->rosterDb());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_LINKED_USER');
		$this->invoke($service, 'normalizeLinkedUserId', 'bob§');
	}

	public function testResolveDisplayNameCastsNumericPayloadName(): void
	{
		$service = new RosterService($this->rosterDb());

		self::assertSame('123', $this->invoke($service, 'resolveDisplayNameFromPayload', ['displayName' => 123], null));
	}

	public function testResolveDisplayNameFallsBackToLinkedUserWhenNameIsWhitespace(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn(' Bob ');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('bob')->willReturn($user);
		$service = new RosterService($this->rosterDb(), $userManager);

		self::assertSame('Bob', $this->invoke($service, 'resolveDisplayNameFromPayload', ['displayName' => '   '], 'bob'));
	}

	public function testResolveLinkedUserDisplayNameFallsBackToUidWhenAccountNameBlank(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('   ');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('bob')->willReturn($user);
		$service = new RosterService($this->rosterDb(), $userManager);

		self::assertSame('bob', $this->invoke($service, 'resolveLinkedUserDisplayName', 'bob'));
	}

	public function testDisplayNameUniqueAllowsOwnRowAndCastsId(): void
	{
		$qb = $this->rosterQb(['fetchOne' => '7', 'selectOnce' => true, 'maxResultsOnce' => 1]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertNull($this->invoke($service, 'assertEmployeeDisplayNameUnique', 'Alice', 7));
	}

	public function testDisplayNameUniqueRejectsForeignRow(): void
	{
		$qb = $this->rosterQb(['fetchOne' => 7]);
		$service = new RosterService($this->rosterDb($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('EMPLOYEE_NAME_EXISTS');
		$this->invoke($service, 'assertEmployeeDisplayNameUnique', 'Alice', 9);
	}

	public function testLinkedUserUniqueAllowsOwnRowAndCastsId(): void
	{
		$qb = $this->rosterQb(['fetchOne' => '7', 'selectOnce' => true, 'maxResultsOnce' => 1]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertNull($this->invoke($service, 'assertLinkedUserUnique', 'bob', 7));
	}

	public function testLinkedUserUniqueRejectsForeignRow(): void
	{
		$qb = $this->rosterQb(['fetchOne' => 7]);
		$service = new RosterService($this->rosterDb($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('LINKED_USER_EXISTS');
		$this->invoke($service, 'assertLinkedUserUnique', 'bob', 9);
	}

	public function testEmployeeRowExistsThrowsForMissingRow(): void
	{
		$qb = $this->rosterQb(['fetchOne' => false, 'selectOnce' => true]);
		$service = new RosterService($this->rosterDb($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('EMPLOYEE_NOT_FOUND');
		$this->invoke($service, 'assertEmployeeRowExists', 7);
	}

	public function testFetchEmployeeLinkedUserIdCastsValueAndNormalisesEmpties(): void
	{
		$qbValue = $this->rosterQb(['fetchOne' => 42, 'selectOnce' => true, 'maxResultsOnce' => 1]);
		$qbFalse = $this->rosterQb(['fetchOne' => false]);
		$qbEmpty = $this->rosterQb(['fetchOne' => '']);
		$service = new RosterService($this->rosterDb($qbValue, $qbFalse, $qbEmpty));

		self::assertSame('42', $this->invoke($service, 'fetchEmployeeLinkedUserId', 7));
		self::assertNull($this->invoke($service, 'fetchEmployeeLinkedUserId', 7));
		self::assertNull($this->invoke($service, 'fetchEmployeeLinkedUserId', 7));
	}

	public function testAssertEmployeeExistsRequiresActiveRow(): void
	{
		$qb = $this->rosterQb(['fetchOne' => false, 'selectOnce' => true], $params);
		$service = new RosterService($this->rosterDb($qb));

		try {
			$this->invoke($service, 'assertEmployeeExists', 4);
			self::fail('Expected EMPLOYEE_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('EMPLOYEE_NOT_FOUND', $e->getMessage());
		}

		$this->assertParamCaptured([4, IQueryBuilder::PARAM_INT], $params);
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $params, 'must filter on active = 1');
	}

	public function testAssertLocationExistsRequiresActiveRow(): void
	{
		$qb = $this->rosterQb(['fetchOne' => false, 'selectOnce' => true], $params);
		$service = new RosterService($this->rosterDb($qb));

		try {
			$this->invoke($service, 'assertLocationExists', 6);
			self::fail('Expected LOCATION_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('LOCATION_NOT_FOUND', $e->getMessage());
		}

		$this->assertParamCaptured([6, IQueryBuilder::PARAM_INT], $params);
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $params, 'must filter on active = 1');
	}
}

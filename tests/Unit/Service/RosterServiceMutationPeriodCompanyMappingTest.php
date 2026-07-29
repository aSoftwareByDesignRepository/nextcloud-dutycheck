<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Mutation-hardening tests for period row normalisation, row-company reads,
 * cross-entity company enforcement and assignment lookups.
 */
final class RosterServiceMutationPeriodCompanyMappingTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	private function invoke(RosterService $service, string $method, mixed ...$args): mixed
	{
		$m = new ReflectionMethod(RosterService::class, $method);
		$m->setAccessible(true);
		return $m->invoke($service, ...$args);
	}

	public function testPeriodByIdCastsRowValuesAndKeepsPublishedAt(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetch' => [
			'id' => '5',
			'start_date' => 20260701,
			'end_date' => 20260731,
			'status' => 123,
			'created_by' => 456,
			'created_at' => 789,
			'published_at' => 20260620,
			'closed_at' => null,
			'close_snapshot_id' => '9',
		]]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame([
			'id' => 5,
			'startDate' => '20260701',
			'endDate' => '20260731',
			'status' => '123',
			'createdBy' => '456',
			'createdAt' => '789',
			'publishedAt' => '20260620',
			'closedAt' => null,
			'closeSnapshotId' => 9,
		], $this->invoke($service, 'periodById', 5));
	}

	public function testPeriodByIdKeepsClosedAtAndNullSnapshot(): void
	{
		$qb = $this->rosterQb(['fetch' => [
			'id' => 6,
			'start_date' => '2026-08-01',
			'end_date' => '2026-08-31',
			'status' => 'closed',
			'created_by' => 'planner-1',
			'created_at' => '2026-07-01 00:00:00',
			'published_at' => null,
			'closed_at' => 20260901,
			'close_snapshot_id' => null,
		]]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame([
			'id' => 6,
			'startDate' => '2026-08-01',
			'endDate' => '2026-08-31',
			'status' => 'closed',
			'createdBy' => 'planner-1',
			'createdAt' => '2026-07-01 00:00:00',
			'publishedAt' => null,
			'closedAt' => '20260901',
			'closeSnapshotId' => null,
		], $this->invoke($service, 'periodById', 6));
	}

	public function testPeriodByIdThrowsForMissingRow(): void
	{
		$qb = $this->rosterQb(['fetch' => false]);
		$service = new RosterService($this->rosterDb($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_FOUND');
		$this->invoke($service, 'periodById', 999);
	}

	public function testReadRowCompanyIdCastsColumnValue(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetch' => ['company_id' => '5']]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame(5, $this->invoke($service, 'readRowCompanyId', 'dc_periods', 10));
	}

	public function testReadRowCompanyIdDefaultsWhenColumnIsNull(): void
	{
		$qb = $this->rosterQb(['fetch' => ['company_id' => null]]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame(
			CompanyService::DEFAULT_COMPANY_ID,
			$this->invoke($service, 'readRowCompanyId', 'dc_periods', 10),
		);
	}

	public function testReadRowCompanyIdThrowsForMissingRow(): void
	{
		$qb = $this->rosterQb(['fetch' => false]);
		$service = new RosterService($this->rosterDb($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('NOT_FOUND');
		$this->invoke($service, 'readRowCompanyId', 'dc_periods', 10);
	}

	public function testEntitiesSharePeriodCompanyIsNoOpWithoutCompanyService(): void
	{
		$service = new RosterService($this->rosterDb());

		self::assertNull($this->invoke($service, 'assertEntitiesSharePeriodCompany', 1, 2, 3));
	}

	public function testEntitiesSharePeriodCompanyRejectsLocationMismatch(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(true);
		$companies->method('isMultiCompanyActive')->willReturn(true);

		$qbPeriod = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$qbEmployee = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$qbLocation = $this->rosterQb(['fetch' => ['company_id' => 2]]);
		$service = new RosterService(
			$this->rosterDb($qbPeriod, $qbEmployee, $qbLocation),
			companies: $companies,
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MISMATCH');
		$this->invoke($service, 'assertEntitiesSharePeriodCompany', 10, 4, 6);
	}

	public function testEntitiesSharePeriodCompanyRejectsEmployeeMismatch(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(true);
		$companies->method('isMultiCompanyActive')->willReturn(true);

		$qbPeriod = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$qbEmployee = $this->rosterQb(['fetch' => ['company_id' => 2]]);
		$qbLocation = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$service = new RosterService(
			$this->rosterDb($qbPeriod, $qbEmployee, $qbLocation),
			companies: $companies,
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MISMATCH');
		$this->invoke($service, 'assertEntitiesSharePeriodCompany', 10, 4, 6);
	}

	public function testEntitiesSharePeriodCompanyAcceptsMatchingCompanies(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(true);
		$companies->method('isMultiCompanyActive')->willReturn(true);

		$qbPeriod = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$qbEmployee = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$qbLocation = $this->rosterQb(['fetch' => ['company_id' => 1]]);
		$service = new RosterService(
			$this->rosterDb($qbPeriod, $qbEmployee, $qbLocation),
			companies: $companies,
		);

		self::assertNull($this->invoke($service, 'assertEntitiesSharePeriodCompany', 10, 4, 6));
	}

	public function testPeekAssignmentThrowsForMissingRow(): void
	{
		$qb = $this->rosterQb(['fetch' => false]);
		$service = new RosterService($this->rosterDb($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ASSIGNMENT_NOT_FOUND');
		$service->peekAssignment(42);
	}

	public function testPeekAssignmentSelectsAndMapsRow(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetch' => [
			'id' => '42',
			'period_id' => '3',
			'employee_id' => '7',
			'location_id' => '2',
			'duty_date' => '2026-07-10',
			'status' => 'active',
		]]);
		$service = new RosterService($this->rosterDb($qb));

		self::assertSame([
			'id' => 42,
			'periodId' => 3,
			'employeeId' => 7,
			'locationId' => 2,
			'dutyDate' => '2026-07-10',
			'status' => 'active',
			'version' => 0,
		], $service->peekAssignment(42));
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class AcknowledgeConflictCompanyTest extends TestCase
{
	public function testAcknowledgeConflictAssertsPeriodCompanyBeforeUpdate(): void
	{
		$companies = $this->createMock(CompanyService::class);
		// Uniform-404: the conflict gate passes CONFLICT_NOT_FOUND so a
		// foreign-company period/conflict is indistinguishable from a missing one.
		$companies->expects($this->once())->method('assertRowCompany')
			->with('planner', 'dc_periods', 5, 'CONFLICT_NOT_FOUND')
			->willThrowException(new \InvalidArgumentException('CONFLICT_NOT_FOUND'));

		$conflictResult = $this->createMock(IResult::class);
		$conflictResult->method('fetch')->willReturn([
			'id' => 9,
			'period_id' => 5,
			'context_hash' => 'abc',
			'is_resolved' => 0,
		]);

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};
		$qbSelect = $this->createMock(IQueryBuilder::class);
		$qbSelect->method('select')->willReturnSelf();
		$qbSelect->method('from')->willReturnSelf();
		$qbSelect->method('where')->willReturnSelf();
		$qbSelect->method('expr')->willReturn($expr);
		$qbSelect->method('createNamedParameter')->willReturn('p');
		$qbSelect->method('executeQuery')->willReturn($conflictResult);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('getQueryBuilder')->willReturn($qbSelect);

		$svc = new RosterService(
			$db,
			$this->createMock(IUserManager::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$companies,
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CONFLICT_NOT_FOUND');
		$svc->acknowledgeConflict(9, 'planner', 'long enough reason for ack');
	}
}

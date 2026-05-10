<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Tests\Unit\Support\IntegrationQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class RosterServiceEmployeeCountTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testCountActiveUnlinkedEmployeesReturnsFetchOne(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchOne(4));

		$roster = new RosterService($db, null, null);
		self::assertSame(4, $roster->countActiveUnlinkedEmployees());
	}

	public function testCountActiveEmployeesUsesActiveFlag(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchOne(12));

		$roster = new RosterService($db, null, null);
		self::assertSame(12, $roster->countActiveEmployees());
	}
}

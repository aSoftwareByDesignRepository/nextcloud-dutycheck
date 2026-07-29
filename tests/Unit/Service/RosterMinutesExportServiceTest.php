<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterMinutesExportService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class RosterMinutesExportServiceTest extends TestCase
{
	public function testDisabledThrows(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$db = $this->createMock(IDBConnection::class);
		$svc = new RosterMinutesExportService($db, $config);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('HR_EXPORT_DISABLED');
		$svc->toCsv(1);
	}

	public function testCsvIncludesEffectiveMinutes(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('1');

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([
			[
				'duty_date' => '2099-01-02',
				'start_time' => '08:00:00',
				'end_time' => '12:00:00',
				'break_minutes' => 30,
				'display_name' => 'Alice',
				'linked_user_id' => 'alice',
				'employee_id' => 7,
				'status' => 'active',
			],
		]);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		// SchemaProbe may call getInner — stub tableExists
		$db->method('tableExists')->willReturn(true);

		$svc = new RosterMinutesExportService($db, $config);
		$csv = $svc->toCsv(1);
		self::assertStringContainsString('effectiveMinutes', $csv);
		self::assertStringContainsString('"210"', $csv); // 4h - 30m
		self::assertStringContainsString('Alice', $csv);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Repair;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Migration\DutyCheckTableCatalog;
use OCA\DutyCheck\Repair\EnsureDutyCheckSchema;
use OCA\DutyCheck\Repair\UninstallDropTables;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EnsureDutyCheckSchemaTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	public function testSucceedsWhenAllTablesColumnsAndIndexesExist(): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('closeCursor');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$connection->method('getQueryBuilder')->willReturn($qb);

		// Seed index probe cache (unit mocks lack SchemaWrapper getInner).
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('indexCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_assignments#dc_asg_skey_uidx' => true]);

		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('deleteAppValue')
			->with(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new EnsureDutyCheckSchema($connection, $config);
		$step->run($output);
		self::assertSame(UninstallDropTables::TABLES, DutyCheckTableCatalog::TABLES);
		self::assertGreaterThanOrEqual(12, count(DutyCheckTableCatalog::TABLES));
	}
}

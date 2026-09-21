<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\ApiRateLimitService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ApiRateLimitServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$ref = new ReflectionClass(SchemaProbe::class);
		foreach (['tableCache', 'columnCache', 'indexCache', 'schemaWrappers'] as $prop) {
			if (!$ref->hasProperty($prop)) {
				continue;
			}
			$p = $ref->getProperty($prop);
			$p->setValue(null, []);
		}
	}

	public function testFailsOpenWhenRateTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('dc_api_rate_limits')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');
		$svc = new ApiRateLimitService($db);
		$svc->assertAllowed('suggest-confirm:admin', 1);
		$this->addToAssertionCount(1);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Db;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SchemaProbeTest extends TestCase
{
	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	public function testResetCacheClearsWarmEntries(): void
	{
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_assignments.status' => true]);
		$idx = $ref->getProperty('indexCache');
		$idx->setAccessible(true);
		$idx->setValue(null, ['dc_assignments#dc_asg_skey_uidx' => true]);

		SchemaProbe::resetCache();
		self::assertSame([], $prop->getValue(null));
		self::assertSame([], $idx->getValue(null));
	}

	public function testMissingColumnIsCachedAsFalse(): void
	{
		SchemaProbe::resetCache();

		$db = $this->createMock(IDBConnection::class);
		// Prefer the QueryBuilder fallback path (no columnExists / getInner on this mock).
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('boom'));

		self::assertFalse(SchemaProbe::hasColumn($db, 'dc_assignments', 'missing_col'));

		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$cache = $prop->getValue(null);
		self::assertArrayHasKey('dc_assignments.missing_col', $cache);
		self::assertFalse($cache['dc_assignments.missing_col']);
	}

	public function testHasIndexWithoutGetInnerIsFalseAndCached(): void
	{
		SchemaProbe::resetCache();
		$db = $this->createMock(IDBConnection::class);

		self::assertFalse(SchemaProbe::hasIndex($db, 'dc_assignments', 'dc_asg_skey_uidx'));

		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('indexCache');
		$prop->setAccessible(true);
		$cache = $prop->getValue(null);
		self::assertArrayHasKey('dc_assignments#dc_asg_skey_uidx', $cache);
		self::assertFalse($cache['dc_assignments#dc_asg_skey_uidx']);
	}

	public function testHasIndexUsesWarmCacheWithoutTouchingDb(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('indexCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_assignments#dc_asg_skey_uidx' => true]);

		$db = $this->createMock(IDBConnection::class);
		self::assertTrue(SchemaProbe::hasIndex($db, 'dc_assignments', 'dc_asg_skey_uidx'));
	}
}

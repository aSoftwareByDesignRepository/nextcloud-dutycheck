<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\TimezoneCatalog;
use PHPUnit\Framework\TestCase;

class TimezoneCatalogTest extends TestCase
{
	public function testKnownTimezonesAreValid(): void
	{
		$catalog = new TimezoneCatalog();
		self::assertTrue($catalog->isValid('Europe/Berlin'));
		self::assertTrue($catalog->isValid('UTC'));
		self::assertFalse($catalog->isValid('Mars/Phobos'));
	}

	public function testPinnedZonesIncludeRequestedRegionsAndAreValidIana(): void
	{
		$catalog = new TimezoneCatalog();
		$pinned = $catalog->pinned();
		self::assertNotEmpty($pinned);
		foreach (['Europe/Moscow', 'Asia/Yekaterinburg', 'Asia/Tashkent'] as $required) {
			self::assertContains($required, $pinned, "Pinned list must include {$required}");
			self::assertTrue($catalog->isValid($required));
		}
	}

	public function testGroupedReturnsListOfLabelItemRecords(): void
	{
		$catalog = new TimezoneCatalog();
		$grouped = $catalog->grouped();
		self::assertNotEmpty($grouped);
		self::assertSame(0, array_key_first($grouped));
		$flatCount = 0;
		foreach ($grouped as $row) {
			self::assertIsArray($row);
			self::assertArrayHasKey('label', $row);
			self::assertArrayHasKey('items', $row);
			self::assertIsString($row['label']);
			self::assertIsArray($row['items']);
			self::assertNotEmpty($row['items']);
			foreach ($row['items'] as $tz) {
				self::assertIsString($tz);
				self::assertTrue($catalog->isValid($tz));
				$flatCount++;
			}
		}
		self::assertSame(count($catalog->all()), $flatCount);
		self::assertTrue($catalog->isValid('Pacific/Tarawa'));
	}

	public function testNormalizeOrThrowRejectsInvalid(): void
	{
		$catalog = new TimezoneCatalog();
		self::assertSame('Europe/Berlin', $catalog->normalizeOrThrow('  Europe/Berlin  '));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_TIMEZONE');
		$catalog->normalizeOrThrow('Not/A/Zone');
	}

	public function testForApiShape(): void
	{
		$catalog = new TimezoneCatalog();
		$api = $catalog->forApi();
		self::assertArrayHasKey('pinned', $api);
		self::assertArrayHasKey('groups', $api);
		self::assertIsArray($api['pinned']);
		self::assertIsArray($api['groups']);
	}
}

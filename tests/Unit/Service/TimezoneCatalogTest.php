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
}

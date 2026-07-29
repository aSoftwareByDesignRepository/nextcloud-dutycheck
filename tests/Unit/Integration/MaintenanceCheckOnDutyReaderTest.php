<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\Integration\MaintenanceCheckOnDutyReader;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MaintenanceCheckOnDutyReaderTest extends TestCase
{
	public function testDisabledFlagReturnsEmptyWithoutQuerying(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$reader = new MaintenanceCheckOnDutyReader(
			$db,
			$config,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
		);
		self::assertFalse($reader->isEffective());
		self::assertSame([], $reader->onDutyToday());
	}

	public function testEnabledButPeerMissingFailsClosed(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('1');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('maintenancecheck')->willReturn(false);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$reader = new MaintenanceCheckOnDutyReader(
			$db,
			$config,
			$apps,
			$this->createMock(LoggerInterface::class),
		);
		self::assertFalse($reader->isEffective());
		self::assertSame([], $reader->onDutyToday('2026-07-26'));
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SwapTransferErrorMapTest extends TestCase
{
	private function map(string $code): string
	{
		$svc = new SwapService($this->createMock(IDBConnection::class), $this->createMock(RosterService::class));
		$m = (new \ReflectionClass($svc))->getMethod('mapTransferError');
		return (string) $m->invoke($svc, $code);
	}

	public function testDistinctSwapCodesKeepTheirMapping(): void
	{
		self::assertSame('SWAP_ABSENCE_CONFLICT', $this->map('ABSENCE_CONFLICT'));
		self::assertSame('SWAP_QUALIFICATION', $this->map('QUALIFICATION_MISSING'));
	}

	public function testGenericConflictsCollapseToSwapConflict(): void
	{
		foreach (['ASSIGNMENT_OVERLAP', 'SWAP_CONFLICT', 'PERIOD_NOT_OPEN', 'COMPANY_MISMATCH', 'FORBIDDEN', 'ASSIGNMENT_TRANSFER_STALE', 'ASSIGNMENT_DUPLICATE_SLOT', 'SCHEMA_NOT_READY', 'ASSIGNMENT_CANCELLED'] as $code) {
			self::assertSame('SWAP_CONFLICT', $this->map($code), $code);
		}
	}

	public function testUnknownAndPrefixedCodesFallThrough(): void
	{
		self::assertSame('SWAP_CONFLICT', $this->map('SOMETHING_ELSE'));
		self::assertSame('SWAP_ALREADY', $this->map('SWAP_ALREADY'));
	}
}

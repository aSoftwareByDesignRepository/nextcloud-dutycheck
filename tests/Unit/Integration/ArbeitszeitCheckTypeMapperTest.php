<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\Integration\ArbeitszeitCheckTypeMapper;
use PHPUnit\Framework\TestCase;

class ArbeitszeitCheckTypeMapperTest extends TestCase
{
	public function testBlockingApprovedVacation(): void
	{
		self::assertTrue(ArbeitszeitCheckTypeMapper::isBlockingApproved('vacation', 'approved'));
	}

	public function testHomeOfficeApprovedIsNotHardBlocking(): void
	{
		self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved('home_office', 'approved'));
	}

	public function testVacationPendingIsNotBlockingForRoster(): void
	{
		self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved('vacation', 'pending'));
	}

	public function testUnknownTypeApprovedIsNotBlocking(): void
	{
		self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved('custom_unknown', 'approved'));
	}

	public function testAtStatusMapsToDutyOverlapSet(): void
	{
		self::assertTrue(ArbeitszeitCheckTypeMapper::atStatusOverlapsDutyStatuses('approved', ['pending', 'approved']));
		self::assertFalse(ArbeitszeitCheckTypeMapper::atStatusOverlapsDutyStatuses('rejected', ['pending', 'approved']));
	}

	public function testSubstitutePendingMapsToPendingDutyStatus(): void
	{
		self::assertSame('pending', ArbeitszeitCheckTypeMapper::toDutyStatus('substitute_pending'));
		self::assertTrue(ArbeitszeitCheckTypeMapper::atStatusOverlapsDutyStatuses('substitute_pending', ['pending']));
	}
}

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
		self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved('space_vacation', 'approved'));
		self::assertFalse(ArbeitszeitCheckTypeMapper::isKnownType('space_vacation'));
	}

	public function testNewAzcBuiltinsAreKnownAndBlocking(): void
	{
		// ArbeitszeitCheck ≥1.9 built-ins — both are real absences.
		foreach (['child_sick', 'overtime_comp'] as $type) {
			self::assertTrue(ArbeitszeitCheckTypeMapper::isKnownType($type), $type);
			self::assertTrue(ArbeitszeitCheckTypeMapper::isBlockingApproved($type, 'approved'), $type);
			self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved($type, 'pending'), $type);
		}
		self::assertSame('sick', ArbeitszeitCheckTypeMapper::toDutyKind('child_sick'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('overtime_comp'));
	}

	public function testCustomTypesAreKnownAndBlocking(): void
	{
		// AZC admin-defined types carry the reserved `custom_` namespace and
		// are absences by definition — approved ones must block rostering.
		self::assertTrue(ArbeitszeitCheckTypeMapper::isKnownType('custom_fortbildung'));
		self::assertTrue(ArbeitszeitCheckTypeMapper::isBlockingApproved('custom_fortbildung', 'approved'));
		self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved('custom_fortbildung', 'pending'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('custom_fortbildung'));
		// Prefix check is exact — a lookalike without the namespace stays unknown.
		self::assertFalse(ArbeitszeitCheckTypeMapper::isKnownType('customer_visit'));
		self::assertFalse(ArbeitszeitCheckTypeMapper::isBlockingApproved('customer_visit', 'approved'));
	}

	public function testUnknownStatusIsNonBlockingForOverlap(): void
	{
		self::assertFalse(ArbeitszeitCheckTypeMapper::isKnownStatus('weird_future_status'));
		self::assertFalse(ArbeitszeitCheckTypeMapper::atStatusOverlapsDutyStatuses('weird_future_status', ['pending', 'approved']));
		self::assertSame('cancelled', ArbeitszeitCheckTypeMapper::toDutyStatus('weird_future_status'));
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

	public function testEveryDutyStatusArmMapsDistinctly(): void
	{
		self::assertSame('pending', ArbeitszeitCheckTypeMapper::toDutyStatus('pending'));
		self::assertSame('approved', ArbeitszeitCheckTypeMapper::toDutyStatus('approved'));
		self::assertSame('rejected', ArbeitszeitCheckTypeMapper::toDutyStatus('rejected'));
		self::assertSame('cancelled', ArbeitszeitCheckTypeMapper::toDutyStatus('cancelled'));
		self::assertSame('cancelled', ArbeitszeitCheckTypeMapper::toDutyStatus('substitute_declined'));
	}

	public function testEveryDutyTypeArmMapsDistinctly(): void
	{
		self::assertSame('vacation', ArbeitszeitCheckTypeMapper::toDutyKind('vacation'));
		self::assertSame('sick', ArbeitszeitCheckTypeMapper::toDutyKind('sick_leave'));
		self::assertSame('unpaid', ArbeitszeitCheckTypeMapper::toDutyKind('unpaid_leave'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('personal_leave'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('parental_leave'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('special_leave'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('home_office'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('business_trip'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('totally_unknown'));
		self::assertSame('other', ArbeitszeitCheckTypeMapper::toDutyKind('custom_betriebsausflug'));
	}
}

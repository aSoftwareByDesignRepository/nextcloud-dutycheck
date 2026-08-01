<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\AssignmentSlotKey;
use PHPUnit\Framework\TestCase;

final class AssignmentSlotKeyTest extends TestCase
{
	public function testActiveKeyIsDeterministicAndStable(): void
	{
		$key = AssignmentSlotKey::forActive(3, 9, '2026-07-15', '08:00', '16:00');
		self::assertSame('a:3:9:2026-07-15:08:00:16:00', $key);
		self::assertSame($key, AssignmentSlotKey::forActive(3, 9, '2026-07-15', '08:00', '16:00'));
	}

	public function testCancelledKeyFreesLogicalSlot(): void
	{
		$active = AssignmentSlotKey::forActive(1, 2, '2026-07-15', '08:00', '12:00');
		$cancelled = AssignmentSlotKey::forCancelled(42);
		self::assertSame('c:42', $cancelled);
		self::assertNotSame($active, $cancelled);
		// A recreated active assignment reuses the logical key while cancelled keeps c:{id}.
		$recreated = AssignmentSlotKey::forActive(1, 2, '2026-07-15', '08:00', '12:00');
		self::assertSame($active, $recreated);
		self::assertNotSame($recreated, AssignmentSlotKey::forCancelled(99));
	}

	public function testDifferentSlotsProduceDifferentKeys(): void
	{
		$a = AssignmentSlotKey::forActive(1, 1, '2026-07-15', '08:00', '12:00');
		$b = AssignmentSlotKey::forActive(1, 1, '2026-07-15', '12:00', '16:00');
		$c = AssignmentSlotKey::forActive(1, 2, '2026-07-15', '08:00', '12:00');
		self::assertNotSame($a, $b);
		self::assertNotSame($a, $c);
	}
}

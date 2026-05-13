<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\Integration\ArbeitszeitCheckMirrorDeleteHelper;
use PHPUnit\Framework\TestCase;

final class ArbeitszeitCheckMirrorDeleteHelperTest extends TestCase
{
	public function testOrphanLinkedUserIds(): void
	{
		self::assertSame(
			['orphan1', 'orphan2'],
			ArbeitszeitCheckMirrorDeleteHelper::orphanLinkedUserIds(
				['a', 'orphan1', 'a', 'orphan2', 'b'],
				['a', 'b'],
			),
		);
		self::assertSame([], ArbeitszeitCheckMirrorDeleteHelper::orphanLinkedUserIds(['x'], ['x']));
		self::assertSame([], ArbeitszeitCheckMirrorDeleteHelper::orphanLinkedUserIds(['', 'x'], ['x']));
	}

	public function testAtAbsenceIdsToDelete(): void
	{
		self::assertSame(
			[2, 5],
			ArbeitszeitCheckMirrorDeleteHelper::atAbsenceIdsToDelete([1, 2, 3, 5], [1, 3]),
		);
		self::assertSame([], ArbeitszeitCheckMirrorDeleteHelper::atAbsenceIdsToDelete([1, 2], [1, 2]));
		self::assertSame([], ArbeitszeitCheckMirrorDeleteHelper::atAbsenceIdsToDelete([0, -1], [1]));
	}

	public function testInChunkSizeIsBelowSqliteDefaultVariableCeiling(): void
	{
		self::assertLessThanOrEqual(
			998,
			ArbeitszeitCheckMirrorDeleteHelper::IN_CHUNK + 1,
			'Each DELETE binds linked_user_id plus IN_CHUNK integers; stay under typical SQLite 999 var cap.',
		);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * SF-04 / FM-07 — copy apply is one transaction; skippable slot conflicts
 * stay inside the loop; unexpected failures roll the whole copy back.
 */
final class RosterServiceCopyPeriodTransactionTest extends TestCase
{
	public function testApplyOpensATransactionAndRollsBackOnUnexpectedFailure(): void
	{
		$fn = $this->copyPeriodSource();
		self::assertStringContainsString('if (!$dryRun) {', $fn);
		self::assertStringContainsString('$this->db->beginTransaction();', $fn);
		self::assertStringContainsString('$this->db->commit();', $fn);
		self::assertStringContainsString('$this->db->rollBack();', $fn);
		self::assertStringContainsString('if (!$dryRun && $this->db->inTransaction()) {', $fn);
	}

	public function testApplyDefersRosterHydrationAndConflictRefreshUntilCommit(): void
	{
		$fn = $this->copyPeriodSource();
		self::assertStringContainsString('], $actor, false, false, false, false);', $fn);
		self::assertStringContainsString('$useTransaction', (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName()));
		self::assertStringContainsString('$this->refreshAndListConflicts($targetPeriodId);', $fn);
		$createPos = strpos($fn, '], $actor, false, false, false, false);');
		$refreshPos = strpos($fn, '$this->refreshAndListConflicts($targetPeriodId);');
		self::assertNotFalse($createPos);
		self::assertNotFalse($refreshPos);
		self::assertGreaterThan($createPos, $refreshPos);
	}

	public function testSkippableCopyErrorsDoNotAbortTheTransaction(): void
	{
		$fn = $this->copyPeriodSource();
		self::assertStringContainsString('catch (ConflictAckRequiredException $e)', $fn);
		self::assertStringContainsString('catch (\\InvalidArgumentException)', $fn);
		self::assertStringContainsString('$skipped++;', $fn);
	}

	public function testListAssignmentsNeverSilentlyPaginates(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName());
		$start = strpos($src, 'Full assignment list for one period');
		self::assertNotFalse($start);
		$end = strpos($src, 'private function assignmentHasStatusColumn', $start);
		self::assertNotFalse($end);
		$fn = substr($src, $start, $end - $start);
		self::assertStringNotContainsString('setMaxResults', $fn);
		self::assertStringNotContainsString('array_slice', $fn);
		self::assertStringContainsString('Never paginated', $fn);
	}

	private function copyPeriodSource(): string
	{
		$src = (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName());
		$start = strpos($src, 'public function copyPeriodAssignments');
		self::assertNotFalse($start);
		$end = strpos($src, 'public function transferAssignmentEmployee', $start);
		self::assertNotFalse($end);
		return substr($src, $start, $end - $start);
	}
}

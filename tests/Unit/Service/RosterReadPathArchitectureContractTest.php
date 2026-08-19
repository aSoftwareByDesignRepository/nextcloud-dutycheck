<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * ADR-003 read vs write paths: roster GET stays correct and cheap-enough;
 * writes still recompute/materialize conflicts. Full assignment lists are
 * intentional (grid safety — SF-06).
 */
final class RosterReadPathArchitectureContractTest extends TestCase
{
	private function rosterSource(): string
	{
		return (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
	}

	private function extractFunction(string $signature): string
	{
		$src = $this->rosterSource();
		$start = strpos($src, $signature);
		self::assertNotFalse($start, $signature . ' not found');
		$brace = strpos($src, '{', $start);
		self::assertNotFalse($brace);
		$depth = 0;
		$len = strlen($src);
		for ($i = $brace; $i < $len; $i++) {
			$c = $src[$i];
			if ($c === '{') {
				$depth++;
			} elseif ($c === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($src, $start, $i - $start + 1);
				}
			}
		}
		self::fail('Unbalanced braces for ' . $signature);
	}

	public function testDashboardSummaryEmbedsConflictPulse(): void
	{
		$fn = $this->extractFunction('public function dashboardSummary');
		self::assertStringContainsString('dashboardConflictPulse($actorUserId)', $fn);
		self::assertStringNotContainsString('refreshAndListConflicts', $fn);
		self::assertStringNotContainsString('listAssignments', $fn);
	}

	public function testRosterGetLoadsFullAssignmentListForGrid(): void
	{
		$fn = $this->extractFunction('public function rosterData');
		self::assertStringContainsString('$this->listAssignments($selected)', $fn);
		self::assertStringContainsString('GET must not recompute/materialize conflicts', $fn);
	}

	public function testRosterGetReadsPersistedConflictsOnly(): void
	{
		$fn = $this->extractFunction('public function rosterData');
		self::assertStringContainsString('$this->listPersistedConflicts($selected)', $fn);
		self::assertStringNotContainsString('refreshAndListConflicts', $fn);
		self::assertStringNotContainsString('conflictsForPeriod', $fn);
	}

	public function testListAssignmentsIsNeverPaginated(): void
	{
		$src = $this->rosterSource();
		$fnStart = strpos($src, 'private function listAssignments');
		self::assertNotFalse($fnStart);
		$docStart = strrpos(substr($src, 0, $fnStart), '/**');
		self::assertNotFalse($docStart);
		$fn = $this->extractFunction('private function listAssignments');
		$block = substr($src, $docStart, strlen($fn) + ($fnStart - $docStart));
		self::assertStringContainsString('Never paginated', $block);
		self::assertStringNotContainsString('setMaxResults', $fn);
		self::assertStringNotContainsString(' LIMIT ', $fn);
	}

	public function testListPersistedConflictsDecodesJsonButSlimsBrowserPayload(): void
	{
		$fn = $this->extractFunction('private function listPersistedConflicts');
		self::assertStringContainsString('json_decode', $fn);
		self::assertStringContainsString("count(\$assignmentIds) >= 2", $fn);
		self::assertStringContainsString("'details' => []", $fn);
		self::assertStringNotContainsString("'details' => \$payload", $fn);
	}

	public function testPublishReadinessGetUsesSqlCountsNotFullRecompute(): void
	{
		$fn = $this->extractFunction('public function publishReadiness');
		self::assertStringContainsString('countUnresolvedConflictsBySeverity', $fn);
		self::assertStringContainsString('countUnacknowledgedSoftConflicts', $fn);
		self::assertStringNotContainsString('refreshAndListConflicts', $fn);
		self::assertStringNotContainsString('conflictsForPeriod', $fn);
		self::assertStringNotContainsString('listAssignments', $fn);
	}

	public function testPublishTransitionStillRecomputesConflictsBeforeFlip(): void
	{
		$fn = $this->extractFunction('public function transitionPeriod');
		self::assertStringContainsString('$this->conflictsForPeriod($periodId)', $fn);
		self::assertStringContainsString('PERIOD_HAS_HARD_CONFLICTS', $fn);
	}

	public function testWritePathsMaterializeConflictsAfterMutation(): void
	{
		$create = $this->extractFunction('public function createAssignment');
		self::assertStringContainsString('$this->refreshAndListConflicts($periodId)', $create);
		self::assertStringContainsString('$refreshConflicts', $create);

		$cancel = $this->extractFunction('public function cancelAssignment(int $assignmentId');
		self::assertStringContainsString('$this->refreshAndListConflicts($periodId)', $cancel);

		$update = $this->extractFunction('public function updateAssignment');
		self::assertStringContainsString('$this->refreshAndListConflicts($periodId)', $update);

		$refresh = $this->extractFunction('private function refreshAndListConflicts');
		self::assertStringContainsString('$this->conflictsForPeriod($periodId)', $refresh);
		self::assertStringContainsString('$this->materializeConflicts($periodId, $computed)', $refresh);
		self::assertStringContainsString('$this->listPersistedConflicts($periodId)', $refresh);
	}

	public function testDashboardPulseUsesSqlCountsAndSinglePeriodPick(): void
	{
		$fn = $this->extractFunction('public function dashboardConflictPulse');
		self::assertStringContainsString('countUnresolvedConflictsBySeverity', $fn);
		self::assertStringContainsString('countUnacknowledgedSoftConflicts', $fn);
		self::assertStringNotContainsString('refreshAndListConflicts', $fn);
		self::assertStringNotContainsString('listAssignments', $fn);
		self::assertStringNotContainsString('listPersistedConflicts', $fn);
		self::assertStringNotContainsString('conflictsForPeriod', $fn);

		$pick = $this->extractFunction('private function newestScopedPeriodId');
		self::assertStringContainsString('setMaxResults(1)', $pick);
		self::assertStringContainsString("orderBy('start_date', 'DESC')", $pick);
	}

	public function testRosterGetReusesLoadedPeriodRow(): void
	{
		$fn = $this->extractFunction('public function rosterData');
		self::assertStringNotContainsString('periodById', $fn);
		self::assertStringContainsString('listBlockingAbsenceSpansForPeriod($selected, $selectedPeriod)', $fn);
	}

	public function testNormalizePeriodToleratesListRowsWithoutCloseSnapshot(): void
	{
		$fn = $this->extractFunction('private function normalizePeriod');
		self::assertStringContainsString("\$r['close_snapshot_id'] ?? null", $fn);
	}
}

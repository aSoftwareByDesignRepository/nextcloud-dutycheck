<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * FM-08 — conflict acknowledgement is first-writer CAS, not last-writer-wins.
 */
final class RosterServiceAcknowledgeConflictCasTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	public function testLostRaceAgainstAnotherPlannerIsStaleNotOverwrite(): void
	{
		$select = $this->rosterQb(['fetch' => [
			'id' => 5,
			'period_id' => 3,
			'context_hash' => 'hash-a',
			'is_resolved' => 0,
			'ack_user_id' => null,
		]]);
		$updateParams = [];
		$update = $this->rosterQb(['andWhereExactly' => 3, 'statementOnce' => true, 'statementReturn' => 0], $updateParams);
		$again = $this->rosterQb(['fetch' => [
			'is_resolved' => 0,
			'ack_user_id' => 'bob',
			'context_hash' => 'hash-a',
		]]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($select, $update, $again);

		$svc = new RosterService($db);
		try {
			$svc->acknowledgeConflict(5, 'alice', 'a valid reason');
			self::fail('Expected CONFLICT_ACK_STALE');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('CONFLICT_ACK_STALE', $e->getMessage());
		}
		$this->assertParamCaptured([0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT], $updateParams);
	}

	public function testUpdateCasRequiresUnresolvedMatchingContext(): void
	{
		$src = (string) file_get_contents((new \ReflectionClass(RosterService::class))->getFileName());
		$start = strpos($src, 'public function acknowledgeConflict');
		self::assertNotFalse($start);
		$end = strpos($src, 'private function candidateSoftConflicts', $start);
		self::assertNotFalse($end);
		$fn = substr($src, $start, $end - $start);
		self::assertStringContainsString("eq('is_resolved'", $fn);
		self::assertStringContainsString("eq('context_hash'", $fn);
		self::assertStringContainsString('CONFLICT_ACK_STALE', $fn);
		self::assertStringContainsString("isNull('ack_user_id')", $fn);
		self::assertStringContainsString('executeStatement()', $fn);
	}
}

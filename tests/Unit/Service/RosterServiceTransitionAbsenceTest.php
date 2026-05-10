<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Tests\Unit\Support\IntegrationQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class RosterServiceTransitionAbsenceTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testTransitionAbsenceThrowsWhenIntegrationLocksLinkedEmployee(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(true);

		$qbAbsence = $this->qbFetchAssociative([
			'id' => 42,
			'employee_id' => 7,
			'start_date' => '2026-06-01',
			'end_date' => '2026-06-02',
			'status' => 'pending',
		]);
		$qbLinked = $this->qbFetchOne('linked-user-1');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbAbsence, $qbLinked);

		$roster = new RosterService($db, null, $at);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INTEGRATION_ABSENCE_READONLY');
		$roster->transitionAbsence(42, 'approved', '', 'planner-1');
	}

	public function testTransitionAbsenceProceedsWhenEmployeeNotLinkedUnderLock(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(true);

		$qbAbsence = $this->qbFetchAssociative([
			'id' => 42,
			'employee_id' => 7,
			'start_date' => '2026-06-01',
			'end_date' => '2026-06-02',
			'status' => 'pending',
		]);
		$qbNotLinked = $this->qbFetchOne(false);
		$qbOverlap = $this->qbFetchOne(false);
		$qbUpdate = $this->qbExecuteStatement();

		$qbList = $this->qbFetchAllAssociative([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbAbsence,
			$qbNotLinked,
			$qbOverlap,
			$qbUpdate,
			$qbList,
		);

		$roster = new RosterService($db, null, $at);
		$out = $roster->transitionAbsence(42, 'approved', '', 'planner-1');
		self::assertIsArray($out);
	}
}

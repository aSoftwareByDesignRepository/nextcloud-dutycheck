<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression (atlas avd_craft 2026-10-09): employee claim on a posted open shift
 * whose slot carries an inherent soft conflict (e.g. break_minutes below policy)
 * used to fail forever — the auto-apply path re-ran createAssignment's
 * acknowledgement gate an employee can never satisfy.
 *
 * Fix contract: claim() auto-apply must reach createAssignment with
 * $trustedMarketplaceApply=true so createAssignment skips the ack gate while
 * still recording the soft conflict for the planner.
 */
final class OpenShiftClaimAutoApplyFlagsTest extends TestCase
{
	public function testAutoApplyCallsCreateAssignmentTrusted(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('assertHardMarketplaceSlot');
		$roster->expects($this->once())
			->method('createAssignment')
			->with(
				$this->callback(static function (array $payload): bool {
					return $payload['acknowledgements'] === [];
				}),
				'alice',
				true, // allowPublishedMarketplace
				$this->anything(),
				$this->anything(),
				$this->anything(),
				true, // trustedMarketplaceApply — REQUIRED or soft-conflict slots can never be claimed
			)
			->willReturn([
				'createdAssignmentId' => 55,
				'assignments' => [[
					'id' => 55,
					'employeeId' => 9,
					'dutyDate' => '2099-03-01',
					'startTime' => '08:00:00',
				]],
			]);

		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('claimRequiresPlanner')->willReturn(false);

		$openRow = [
			'id' => 7,
			'period_id' => 1,
			'location_id' => 2,
			'template_id' => null,
			'duty_date' => '2099-03-01',
			'start_time' => '08:00:00',
			'end_time' => '12:00:00',
			'break_minutes' => 0,
			'status' => 'open',
			'claimed_by_emp' => null,
			'assignment_id' => null,
		];
		$pendingRow = $openRow;
		$pendingRow['status'] = 'pending';
		$pendingRow['claimed_by_emp'] = 9;
		$claimedRow = $pendingRow;
		$claimedRow['status'] = 'claimed';
		$claimedRow['assignment_id'] = 55;

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$select = static function (array $row) use ($expr): IQueryBuilder {
			$res = (new self)->createMock(IResult::class);
			$res->method('fetch')->willReturn($row);
			$qb = (new self)->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'groupBy'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($res);
			return $qb;
		};
		$update = static function () use ($expr): IQueryBuilder {
			$qb = (new self)->createMock(IQueryBuilder::class);
			foreach (['update', 'set', 'where', 'andWhere'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeStatement')->willReturn(1);
			return $qb;
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$select(['id' => 9]),            // linkedEmployeeId
			$select($openRow),               // getById (claim)
			$select(['status' => 'published']), // periodStatus
			$update(),                        // CAS open → pending
			$select(['company_id' => 1]),     // periodCompanyId
			$select($pendingRow),            // getById (applyClaimAsMarketplace)
			$update(),                        // link assignment_id
			$select($claimedRow),            // getById (return)
		);

		$svc = new OpenShiftService($db, $roster, null, $settings);
		$result = $svc->claim(7, 'alice');
		self::assertSame('claimed', $result['status']);
		self::assertSame(55, $result['assignmentId']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Tests\Unit\Support\IntegrationQueryBuilderTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class RosterServiceUpdateEmployeeLegacyTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testUpdateEmployeeThrowsWhenLinkingAccountWhileIntegrationEffectiveAndLegacyAbsencesExist(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('isEffective')->willReturn(true);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(false);
		$at->method('countLegacyAbsencesForEmployee')->with(7)->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(7),
			$this->qbFetchOne(false),
			$this->qbFetchOne(false),
		);

		$roster = new RosterService($db, null, $at);
		try {
			$roster->updateEmployee(7, [
				'displayName' => 'Patrol North',
				'linkedUserId' => 'nc-user-1',
				'active' => true,
			]);
			self::fail('expected IntegrationLegacyConflictException');
		} catch (IntegrationLegacyConflictException $e) {
			self::assertSame(2, $e->getLegacyAbsenceCount());
		}
	}

	public function testUpdateEmployeeAllowsLinkingWhenIntegrationEffectiveAndNoLegacyAbsences(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('isEffective')->willReturn(true);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(false);
		$at->method('countLegacyAbsencesForEmployee')->with(7)->willReturn(0);

		$expr = $this->qbExpr();
		$updateQb = $this->createMock(IQueryBuilder::class);
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('createNamedParameter')->willReturn('p');
		$updateQb->method('update')->willReturnSelf();
		$updateQb->method('set')->willReturnSelf();
		$updateQb->method('where')->willReturnSelf();
		$updateQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$catalogQb = $this->qbFetchAllAssociative([
			[
				'id' => 7,
				'display_name' => 'Patrol North',
				'linked_user_id' => 'nc-user-1',
				'active' => 1,
				'created_at' => '2026-01-01 00:00:00',
			],
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(7),
			$this->qbFetchOne(false),
			$this->qbFetchOne(false),
			$updateQb,
			$catalogQb,
		);

		$roster = new RosterService($db, null, $at);
		$out = $roster->updateEmployee(7, [
			'displayName' => 'Patrol North',
			'linkedUserId' => 'nc-user-1',
			'active' => true,
		]);
		self::assertCount(1, $out);
		self::assertSame('nc-user-1', $out[0]['linkedUserId']);
	}

	public function testUpdateEmployeeDoesNotCallLegacyCountWhenClearingLinkWhileIntegrationEffective(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('isEffective')->willReturn(true);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(false);
		$at->expects(self::never())->method('countLegacyAbsencesForEmployee');

		$expr = $this->qbExpr();
		$updateQb = $this->createMock(IQueryBuilder::class);
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('createNamedParameter')->willReturn('p');
		$updateQb->method('update')->willReturnSelf();
		$updateQb->method('set')->willReturnSelf();
		$updateQb->method('where')->willReturnSelf();
		$updateQb->method('executeStatement')->willReturn(1);

		$catalogQb = $this->qbFetchAllAssociative([
			[
				'id' => 7,
				'display_name' => 'Patrol North',
				'linked_user_id' => null,
				'active' => 1,
				'created_at' => '2026-01-01 00:00:00',
			],
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(7),
			$this->qbFetchOne(false),
			$updateQb,
			$catalogQb,
		);

		$roster = new RosterService($db, null, $at);
		$out = $roster->updateEmployee(7, [
			'displayName' => 'Patrol North',
			'linkedUserId' => null,
			'active' => true,
		]);
		self::assertNull($out[0]['linkedUserId']);
	}
}

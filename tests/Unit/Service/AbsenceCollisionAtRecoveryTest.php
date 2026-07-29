<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AbsenceCollisionAtRecoveryTest extends TestCase
{
	public function testAbsenceCollisionFromMirrorSourceIsArbeitszeitCheck(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('hasImportedBlockingAbsenceOnDate')->willReturn(true);
		$at->method('getPlannerOutboundUrl')->willReturn('/apps/arbeitszeitcheck/');

		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(false);
		$expr->method('eq')->willReturn('eq');
		$expr->method('lte')->willReturn('lte');
		$expr->method('gte')->willReturn('gte');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new RosterService($db, null, $at);
		$m = new ReflectionMethod(RosterService::class, 'absenceCollisionSource');
		$m->setAccessible(true);
		self::assertSame('arbeitszeitcheck', $m->invoke($svc, 7, '2026-07-27'));
	}

	public function testDutyCheckAbsenceTakesPrecedenceOverMirror(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->expects(self::never())->method('hasImportedBlockingAbsenceOnDate');

		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(42);
		$expr->method('eq')->willReturn('eq');
		$expr->method('lte')->willReturn('lte');
		$expr->method('gte')->willReturn('gte');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new RosterService($db, null, $at);
		$m = new ReflectionMethod(RosterService::class, 'absenceCollisionSource');
		$m->setAccessible(true);
		self::assertSame('dutycheck', $m->invoke($svc, 7, '2026-07-27'));
	}

	public function testConflictPayloadIncludesRecoveryUrlFieldsInSource(): void
	{
		$path = (new ReflectionClass(RosterService::class))->getFileName();
		self::assertNotFalse($path);
		$src = (string) file_get_contents($path);
		$start = strpos($src, "\$collisionSource === 'arbeitszeitcheck'");
		self::assertNotFalse($start);
		$slice = substr($src, $start, 900);
		self::assertStringContainsString("'recoveryUrl'", $slice);
		self::assertStringContainsString("'recoveryLabel'", $slice);
		self::assertStringContainsString('getPlannerOutboundUrl', $slice);
		self::assertStringContainsString('Employee assignment collides with an ArbeitszeitCheck absence', $src);
	}

	public function testAbsenceBlocksQueryScopesByPeriodCompanyInSource(): void
	{
		$path = (new ReflectionClass(RosterService::class))->getFileName();
		self::assertNotFalse($path);
		$src = (string) file_get_contents($path);
		$start = strpos($src, 'function listBlockingAbsenceSpansForPeriod');
		self::assertNotFalse($start);
		$slice = substr($src, $start, 3500);
		self::assertStringContainsString('readRowCompanyId', $slice);
		self::assertStringContainsString('e.company_id', $slice);
		self::assertStringContainsString('a.company_id', $slice);
	}
}

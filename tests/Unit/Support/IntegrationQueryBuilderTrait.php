<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Support;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\TestCase;

/**
 * Minimal IQueryBuilder stubs for integration service unit tests.
 *
 * @mixin TestCase
 */
trait IntegrationQueryBuilderTrait
{
	protected function qbExpr(): IExpressionBuilder
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('neq')->willReturn('neq');
		$expr->method('lte')->willReturn('lte');
		$expr->method('gte')->willReturn('gte');
		$expr->method('notIn')->willReturn('notin');
		$expr->method('in')->willReturn('in');
		$expr->method('isNotNull')->willReturn('isnotnull');
		$or = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
		$expr->method('orX')->willReturn($or);
		$or->method('add')->willReturnSelf();
		return $expr;
	}

	protected function qbFunc(): IFunctionBuilder
	{
		$countExpr = $this->createMock(IQueryFunction::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($countExpr);
		return $func;
	}

	/**
	 * @param array<string,mixed>|false|null $row Row for IResult::fetch(), or false when missing
	 */
	protected function qbFetchAssociative(array|false|null $row): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->qbExpr());
		$qb->method('func')->willReturn($this->qbFunc());
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'groupBy', 'setMaxResults', 'orderBy', 'addOrderBy', 'set', 'update'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetch')->willReturn($row === null ? false : $row);
		$res->method('fetchOne')->willReturn(false);
		$res->method('fetchAll')->willReturn([]);
		$qb->method('executeQuery')->willReturn($res);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	protected function qbFetchOne(mixed $value): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->qbExpr());
		$qb->method('func')->willReturn($this->qbFunc());
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'groupBy', 'setMaxResults', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchOne')->willReturn($value);
		$res->method('fetchAll')->willReturn([]);
		$qb->method('executeQuery')->willReturn($res);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	protected function qbFetchAllAssociative(array $rows): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->qbExpr());
		$qb->method('func')->willReturn($this->qbFunc());
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'groupBy', 'setMaxResults', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$res = $this->createMock(IResult::class);
		$res->method('fetchOne')->willReturn(false);
		$res->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($res);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	/**
	 * @param \PHPUnit\Framework\MockObject\Rule\InvocationOrder|null $expects
	 */
	protected function qbExecuteStatement(?\PHPUnit\Framework\MockObject\Rule\InvocationOrder $expects = null, int $affected = 1): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->qbExpr());
		$qb->method('func')->willReturn($this->qbFunc());
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'delete', 'set', 'values', 'update', 'insert'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		if ($expects !== null) {
			$qb->expects($expects)->method('executeStatement')->willReturn($affected);
		} else {
			$qb->method('executeStatement')->willReturn($affected);
		}
		return $qb;
	}
}

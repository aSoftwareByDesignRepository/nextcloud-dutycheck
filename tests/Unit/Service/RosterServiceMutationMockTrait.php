<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;

/**
 * Query-builder mocks for the RosterService mutation-hardening tests
 * (tests/Unit/Service/RosterServiceMutation*Test.php only).
 *
 * Compared to the generic IntegrationQueryBuilderTrait these stubs can:
 *  - capture every createNamedParameter() argument list (kills parameter-value
 *    mutants such as active flag 1 -> 0/2 or placeholder-name concat mutants),
 *  - capture the values() payload of INSERT builders (kills ArrayItem mutants),
 *  - enforce interaction expectations (select/setMaxResults/executeStatement),
 *    which kills MethodCallRemoval and setMaxResults integer mutants.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait RosterServiceMutationMockTrait
{
	/**
	 * @param array{
	 *     fetch?: mixed,
	 *     fetchOne?: mixed,
	 *     fetchAll?: array,
	 *     selectOnce?: bool,
	 *     maxResultsOnce?: int,
	 *     statementOnce?: bool,
	 *     statementReturn?: int,
	 *     andWhereExactly?: int,
	 *     composite?: ICompositeExpression
	 * } $config
	 * @param array<int, array<int, mixed>>|null $params captured createNamedParameter args
	 * @param array<string, mixed>|null $values captured values() payload
	 */
	protected function rosterQb(array $config = [], ?array &$params = null, ?array &$values = null): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn', 'isNull', 'isNotNull', 'like'] as $cmp) {
			$expr->method($cmp)->willReturn($cmp);
		}
		$composite = $config['composite'] ?? null;
		if ($composite === null) {
			$composite = $this->createMock(ICompositeExpression::class);
			$composite->method('add')->willReturnSelf();
		}
		$expr->method('orX')->willReturn($composite);
		$expr->method('andX')->willReturn($composite);
		$qb->method('expr')->willReturn($expr);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);

		$params = [];
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (...$args) use (&$params): string {
				$params[] = $args;
				return 'p';
			}
		);

		if ($config['selectOnce'] ?? false) {
			$qb->expects(self::once())->method('select')->willReturnSelf();
		} else {
			$qb->method('select')->willReturnSelf();
		}
		if (array_key_exists('maxResultsOnce', $config)) {
			$qb->expects(self::once())->method('setMaxResults')->with($config['maxResultsOnce'])->willReturnSelf();
		} else {
			$qb->method('setMaxResults')->willReturnSelf();
		}
		if (array_key_exists('andWhereExactly', $config)) {
			$qb->expects(self::exactly($config['andWhereExactly']))->method('andWhere')->willReturnSelf();
		} else {
			$qb->method('andWhere')->willReturnSelf();
		}

		foreach ([
			'from', 'where', 'orWhere', 'innerJoin', 'leftJoin', 'rightJoin',
			'orderBy', 'addOrderBy', 'groupBy', 'addGroupBy', 'addSelect',
			'selectAlias', 'setFirstResult',
			'update', 'insert', 'delete', 'set',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$values = null;
		$qb->method('values')->willReturnCallback(
			static function (array $v) use (&$values, $qb): IQueryBuilder {
				$values = $v;
				return $qb;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($config['fetch'] ?? false);
		$result->method('fetchOne')->willReturn($config['fetchOne'] ?? false);
		$result->method('fetchAll')->willReturn($config['fetchAll'] ?? []);
		$qb->method('executeQuery')->willReturn($result);

		$affected = $config['statementReturn'] ?? 1;
		if ($config['statementOnce'] ?? false) {
			$qb->expects(self::once())->method('executeStatement')->willReturn($affected);
		} else {
			$qb->method('executeStatement')->willReturn($affected);
		}

		return $qb;
	}

	/** Connection returning the given builders in order; exhaustion fails the test. */
	protected function rosterDb(IQueryBuilder ...$qbs): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$qbs);
		return $db;
	}

	/** Connection returning the same builder for every getQueryBuilder() call. */
	protected function rosterDbAlways(IQueryBuilder $qb): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	/**
	 * @param array<int, array<int, mixed>> $params
	 * @param array<int, mixed> $expected single createNamedParameter argument list
	 */
	protected function assertParamCaptured(array $expected, array $params, string $message = ''): void
	{
		// PHPUnit mock invocation recording may include trailing null placeholders;
		// compare on the leading expected arity only.
		$normalized = array_map(
			static fn(array $call): array => array_slice(array_values($call), 0, count($expected)),
			$params,
		);
		self::assertContains($expected, $normalized, $message !== '' ? $message : sprintf(
			'Expected createNamedParameter call %s not captured; got %s',
			var_export($expected, true),
			var_export($params, true),
		));
	}
}

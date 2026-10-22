<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\PeriodLockService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\RotationAnchorService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RotationSuggestService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the suggest-fill break precedence (customer report 2026-10-22:
 * "Pause global gesetzt" yet every pattern day filled with break 0 and all
 * >6h candidates died on break_too_short):
 *
 *   explicit week-day break > linked template break > organisation default.
 *
 * Without the last leg the pattern editor's breakMinutes:0 rows could never
 * satisfy the write-path verdict even though the planner configured the
 * global default.
 */
class RotationSuggestServiceBreakResolutionTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function service(?PlanningDefaultsService $defaults, ?IDBConnection $db = null): RotationSuggestService
	{
		$bare = static fn (string $class): object =>
			(new ReflectionClass($class))->newInstanceWithoutConstructor();

		return new RotationSuggestService(
			$db ?? $this->createMock(IDBConnection::class),
			$this->createMock(RosterService::class),
			$bare(CompanyService::class),
			$bare(SelfServiceSettingsService::class),
			$bare(RotationAnchorService::class),
			$bare(RotationPatternService::class),
			$bare(PeriodLockService::class),
			null,
			null,
			$defaults,
		);
	}

	private function defaults(int $minutes): PlanningDefaultsService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn((string) $minutes);
		return new PlanningDefaultsService($config);
	}

	private function resolve(RotationSuggestService $service, array $weekDay): ?array
	{
		$method = new ReflectionMethod(RotationSuggestService::class, 'resolveShiftTimes');
		$templateCache = [];
		return $method->invokeArgs($service, [$weekDay, &$templateCache]);
	}

	/** @var object|null recorded expr() spy of the last dbWithTemplate() */
	private ?object $lastExpr = null;

	private function dbWithTemplate(array $row): IDBConnection
	{
		$ref = new ReflectionClass(SchemaProbe::class);
		$ref->getProperty('tableCache')->setValue(null, ['dc_shift_templates' => true]);

		$expr = new class {
			/** @var list<string> first arg of every expr()->eq() call */
			public array $eqFields = [];

			public function eq(...$a)
			{
				$this->eqFields[] = (string) ($a[0] ?? '');
				return 'eq';
			}
		};
		$this->lastExpr = $expr;
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	public function testOrgDefaultAppliesWhenDayBreakIsZero(): void
	{
		$resolved = $this->resolve(
			$this->service($this->defaults(45)),
			['startLocal' => '06:00', 'endLocal' => '14:00', 'breakMinutes' => 0],
		);

		self::assertNotNull($resolved);
		self::assertSame(45, $resolved['breakMinutes']);
	}

	public function testExplicitDayBreakBeatsOrgDefault(): void
	{
		$resolved = $this->resolve(
			$this->service($this->defaults(45)),
			['startLocal' => '06:00', 'endLocal' => '14:00', 'breakMinutes' => 15],
		);

		self::assertSame(15, $resolved['breakMinutes']);
	}

	public function testZeroOrgDefaultKeepsZeroBreak(): void
	{
		$resolved = $this->resolve(
			$this->service($this->defaults(0)),
			['startLocal' => '06:00', 'endLocal' => '14:00', 'breakMinutes' => 0],
		);

		self::assertSame(0, $resolved['breakMinutes']);
	}

	public function testNoDefaultsServiceKeepsStoredBreak(): void
	{
		$resolved = $this->resolve(
			$this->service(null),
			['startLocal' => '06:00', 'endLocal' => '14:00', 'breakMinutes' => 0],
		);

		self::assertSame(0, $resolved['breakMinutes']);
	}

	public function testTemplateBreakBeatsOrgDefaultButNotExplicitDayBreak(): void
	{
		$db = $this->dbWithTemplate([
			'start_time' => '22:00',
			'end_time' => '06:00',
			'break_minutes' => 20,
			'location_id' => 3,
		]);
		$service = $this->service($this->defaults(45), $db);

		$inherited = $this->resolve($service, [
			'startLocal' => '22:00', 'endLocal' => '06:00',
			'breakMinutes' => 0, 'shiftTemplateId' => 7,
		]);
		self::assertSame(20, $inherited['breakMinutes']);

		$explicit = $this->resolve($service, [
			'startLocal' => '22:00', 'endLocal' => '06:00',
			'breakMinutes' => 10, 'shiftTemplateId' => 7,
		]);
		self::assertSame(10, $explicit['breakMinutes']);
	}

	public function testOrgDefaultAppliesAfterEmptyTemplateBreak(): void
	{
		$db = $this->dbWithTemplate([
			'start_time' => '06:00',
			'end_time' => '14:30',
			'break_minutes' => 0,
			'location_id' => 3,
		]);
		$resolved = $this->resolve(
			$this->service($this->defaults(45), $db),
			['breakMinutes' => 0, 'shiftTemplateId' => 7],
		);

		self::assertNotNull($resolved);
		self::assertSame('06:00', $resolved['startLocal']);
		self::assertSame('14:30', $resolved['endLocal']);
		self::assertSame(45, $resolved['breakMinutes']);
	}

	/**
	 * A deactivated shift template must stop driving linked pattern days —
	 * otherwise deactivation never reaches planning (same class as the
	 * inactive-employee notification leak). The query must filter
	 * dc_shift_templates.active = 1.
	 */
	public function testLoadTemplateRequiresActiveTemplate(): void
	{
		$db = $this->dbWithTemplate([
			'start_time' => '22:00',
			'end_time' => '06:00',
			'break_minutes' => 20,
			'location_id' => 3,
		]);
		$this->resolve($this->service(null, $db), [
			'breakMinutes' => 0, 'shiftTemplateId' => 7,
		]);

		self::assertNotNull($this->lastExpr);
		self::assertContains('id', $this->lastExpr->eqFields);
		self::assertContains('active', $this->lastExpr->eqFields);
	}
}

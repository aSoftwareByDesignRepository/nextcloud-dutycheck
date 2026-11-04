<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use DG\BypassFinals;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

BypassFinals::enable();

/**
 * my-roster renders `row.label || row.labelEnum` — the API must return a
 * translated `label` so the raw enum (personal/care/other) never leaks to the
 * UI in any locale (2026-11-04 intake regression).
 */
final class BlackoutLabelTranslationTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	public function testListForEmployeeEmitsTranslatedLabelAlongsideEnum(): void
	{
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function lt(...$a) { return 'lt'; }
			public function gt(...$a) { return 'gt'; }
		};

		$rows = [
			[
				'id' => 5, 'company_id' => 1, 'employee_id' => 9, 'location_id' => null,
				'start_at' => '2099-01-01 00:00:00', 'end_at' => '2099-01-02 00:00:00',
				'label_enum' => 'personal', 'created_by' => 'admin',
				'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00',
			],
			[
				'id' => 6, 'company_id' => 1, 'employee_id' => 9, 'location_id' => null,
				'start_at' => '2099-02-01 00:00:00', 'end_at' => '2099-02-02 00:00:00',
				'label_enum' => 'care', 'created_by' => 'admin',
				'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00',
			],
		];

		$resCompany = $this->createMock(IResult::class);
		$resCompany->method('fetch')->willReturn(['company_id' => 1]);
		$qbCompany = $this->stubSelect($expr, $resCompany);

		$resList = $this->createMock(IResult::class);
		$resList->method('fetchAll')->willReturn($rows);
		$qbList = $this->stubSelect($expr, $resList);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbCompany, $qbCompany, $qbList);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isPlannerOrAdmin')->willReturn(true);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');
		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('isBlackoutsEnabled')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'de:' . $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('dutycheck')->willReturn($l10n);

		$svc = new AvailabilityBlackoutService($db, $companies, $access, $settings, null, null, $factory);
		$out = $svc->listForEmployee(9, '2099-01-01', '2099-03-01', 'planner-actor');

		self::assertSame('personal', $out[0]['labelEnum']);
		self::assertSame('de:Personal', $out[0]['label']);
		self::assertSame('care', $out[1]['labelEnum']);
		self::assertSame('de:Care', $out[1]['label']);
	}

	public function testLabelFallsBackToEnumWithoutL10nFactory(): void
	{
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function lt(...$a) { return 'lt'; }
			public function gt(...$a) { return 'gt'; }
		};

		$resCompany = $this->createMock(IResult::class);
		$resCompany->method('fetch')->willReturn(['company_id' => 1]);
		$qbCompany = $this->stubSelect($expr, $resCompany);

		$resList = $this->createMock(IResult::class);
		$resList->method('fetchAll')->willReturn([[
			'id' => 7, 'company_id' => 1, 'employee_id' => 9, 'location_id' => null,
			'start_at' => '2099-01-01 00:00:00', 'end_at' => '2099-01-02 00:00:00',
			'label_enum' => 'other', 'created_by' => 'admin',
			'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00',
		]]);
		$qbList = $this->stubSelect($expr, $resList);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbCompany, $qbCompany, $qbList);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isPlannerOrAdmin')->willReturn(true);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');
		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('isBlackoutsEnabled')->willReturn(true);

		$svc = new AvailabilityBlackoutService($db, $companies, $access, $settings);
		$out = $svc->listForEmployee(9, '2099-01-01', '2099-03-01', 'planner-actor');

		self::assertSame('other', $out[0]['label']);
	}

	private function stubSelect(object $expr, IResult $result): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}
}

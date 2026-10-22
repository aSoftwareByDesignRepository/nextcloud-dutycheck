<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\PushQuietHoursService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Quiet-queue delivery gate: a notification queued while the employee was
 * still active must not reach them after deactivation/unlink — the same
 * "deactivated employees get plan mails" defect one drain cycle later.
 *
 * Behavioural (drainDue end-to-end over mocked query builders): a source
 * grep cannot catch a `false &&`-style bypass of the recipient check.
 */
final class PushQuietHoursRecipientGateTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$ref->getProperty('tableCache')->setValue(null, [
			'dc_push_quiet_queue' => true,
			'dc_employees' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function exprStub(): object
	{
		return new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function lt(...$a)
			{
				return 'lt';
			}
			public function lte(...$a)
			{
				return 'lte';
			}
			public function isNull(...$a)
			{
				return 'null';
			}
			public function isNotNull(...$a)
			{
				return 'nn';
			}
			public function in(...$a)
			{
				return 'in';
			}
		};
	}

	private function funcStub(): object
	{
		return new class {
			public function count(...$a)
			{
				return 'cnt';
			}
		};
	}

	private function qb(?IResult $result = null, int $affected = 1): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'delete', 'update', 'insert', 'from', 'where', 'andWhere', 'set', 'orderBy', 'addOrderBy', 'setMaxResults', 'values'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($this->exprStub());
		$qb->method('func')->willReturn($this->funcStub());
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeStatement')->willReturn($affected);
		if ($result !== null) {
			$qb->method('executeQuery')->willReturn($result);
		}
		return $qb;
	}

	private function result(array $rows, mixed $fetchOne = false): IResult
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetchOne')->willReturn($fetchOne);
		return $result;
	}

	private function queueRow(string $uid): array
	{
		return [
			'id' => 7,
			'user_id' => $uid,
			'company_id' => 1,
			'notif_type' => 'roster_published',
			'payload_json' => '{}',
			'attempts' => 0,
		];
	}

	private function service(IDBConnection $db, INotificationManager $notifications): PushQuietHoursService
	{
		$settings = (new ReflectionClass(SelfServiceSettingsService::class))
			->newInstanceWithoutConstructor();
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/my-roster');
		return new PushQuietHoursService($db, $settings, $notifications, $url);
	}

	public function testDrainDropsRecipientWhoseEmployeeWasDeactivated(): void
	{
		$qExpire = $this->qb();
		$qAbandon = $this->qb();
		$qSelect = $this->qb($this->result([$this->queueRow('leavers-uid')]));
		$qRecipient = $this->qb($this->result([], 0));
		$qDrop = $this->qb();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qExpire, $qAbandon, $qSelect, $qRecipient, $qDrop,
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');
		$notifications->expects($this->never())->method('createNotification');

		$delivered = $this->service($db, $notifications)->drainDue(10);
		self::assertSame(0, $delivered);
	}

	public function testDrainDeliversWhenRecipientStillActive(): void
	{
		$qExpire = $this->qb();
		$qAbandon = $this->qb();
		$qSelect = $this->qb($this->result([$this->queueRow('active-uid')]));
		$qRecipient = $this->qb($this->result([], 1));
		$qCas = $this->qb();
		$qDone = $this->qb();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qExpire, $qAbandon, $qSelect, $qRecipient, $qCas, $qDone,
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $m) {
			$notification->method($m)->willReturnSelf();
		}
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify')->with($notification);

		$delivered = $this->service($db, $notifications)->drainDue(10);
		self::assertSame(1, $delivered);
	}
}

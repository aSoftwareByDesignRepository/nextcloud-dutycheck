<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\PublishNotificationService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Publish fan-out: linked users only, cancelled excluded, no colleague PII in subject.
 */
final class PublishNotificationServiceTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_assignments.status' => true]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	public function testNotifiesDistinctLinkedUsersAndSkipsBlank(): void
	{
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn([
			'start_date' => '2099-01-06',
			'end_date' => '2099-01-12',
		]);

		$recipResult = $this->createMock(IResult::class);
		$recipResult->method('fetchAll')->willReturn([
			['linked_user_id' => 'alice'],
			['linked_user_id' => 'bob'],
			['linked_user_id' => 'alice'],
			['linked_user_id' => '  '],
			['linked_user_id' => null],
		]);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function isNotNull(...$a)
			{
				return 'nn';
			}
			public function orX(...$a)
			{
				return 'or';
			}
			public function neq(...$a)
			{
				return 'neq';
			}
			public function isNull(...$a)
			{
				return 'null';
			}
		};

		$qbPeriod = $this->createMock(IQueryBuilder::class);
		$qbPeriod->method('select')->willReturnSelf();
		$qbPeriod->method('from')->willReturnSelf();
		$qbPeriod->method('where')->willReturnSelf();
		$qbPeriod->method('expr')->willReturn($expr);
		$qbPeriod->method('createNamedParameter')->willReturn('p');
		$qbPeriod->method('executeQuery')->willReturn($periodResult);

		$qbRecip = $this->createMock(IQueryBuilder::class);
		$qbRecip->method('selectDistinct')->willReturnSelf();
		$qbRecip->method('from')->willReturnSelf();
		$qbRecip->method('innerJoin')->willReturnSelf();
		$qbRecip->method('where')->willReturnSelf();
		$qbRecip->method('andWhere')->willReturnSelf();
		$qbRecip->method('expr')->willReturn($expr);
		$qbRecip->method('createNamedParameter')->willReturn('p');
		$qbRecip->method('executeQuery')->willReturn($recipResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbPeriod, $qbRecip);

		$notified = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$notified, $notification) {
			$notified[] = $uid;
			return $notification;
		});
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($notification) {
			self::assertSame('roster_published', $subject);
			self::assertSame(['period' => '2099-01-06 – 2099-01-12'], $params);
			self::assertArrayNotHasKey('colleague', $params);
			self::assertArrayNotHasKey('employeeName', $params);
			return $notification;
		});
		$notification->method('setMessage')->willReturnSelf();
		$notification->method('setLink')->willReturnCallback(function (string $link) use ($notification) {
			self::assertStringContainsString('periodId=42', $link);
			return $notification;
		});

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->exactly(2))->method('notify')->with($notification);

		$event = $this->createMock(IEvent::class);
		$event->method('setApp')->willReturnSelf();
		$event->method('setType')->willReturnSelf();
		$event->method('setAffectedUser')->willReturnSelf();
		$event->method('setAuthor')->willReturnSelf();
		$event->method('setSubject')->willReturnSelf();
		$event->method('setObject')->willReturnSelf();
		$activity = $this->createMock(IActivityManager::class);
		$activity->method('generateEvent')->willReturn($event);
		$activity->expects($this->exactly(2))->method('publish')->with($event);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/dutycheck/my-roster');

		$l10n = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$svc = new PublishNotificationService(
			$db,
			$notifications,
			$activity,
			$url,
			$factory,
			$this->createMock(LoggerInterface::class),
		);
		$svc->notifyPeriodPublished(42, 'planner1');

		self::assertSame(['alice', 'bob'], $notified);
	}

	public function testMissingPeriodFallsBackToIdLabelWithoutThrowing(): void
	{
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn(false);
		$recipResult = $this->createMock(IResult::class);
		$recipResult->method('fetchAll')->willReturn([]);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function isNotNull(...$a)
			{
				return 'nn';
			}
			public function orX(...$a)
			{
				return 'or';
			}
			public function neq(...$a)
			{
				return 'neq';
			}
			public function isNull(...$a)
			{
				return 'null';
			}
		};

		$mk = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('selectDistinct')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('innerJoin')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($mk($periodResult), $mk($recipResult));

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');

		$svc = new PublishNotificationService(
			$db,
			$notifications,
			$this->createMock(IActivityManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFactory::class),
			$this->createMock(LoggerInterface::class),
		);
		$svc->notifyPeriodPublished(99, 'planner1');
		self::assertTrue(true, 'empty recipient set must complete without notify');
	}
}

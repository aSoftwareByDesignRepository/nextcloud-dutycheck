<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\LicenseStateMapper;
use OCA\DutyCheck\Db\MobileSeat;
use OCA\DutyCheck\Db\MobileSeatMapper;
use OCA\DutyCheck\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seat directory search must return the license-panel `items` contract and
 * merge uid + display-name hits (same shape as InvoiceCheck / TicketCheck).
 */
final class LicenseSeatSearchTest extends TestCase
{
	private function user(string $uid, string $display, bool $enabled = true): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($display);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function service(IUserManager $users, MobileSeatMapper $seats): LicenseService
	{
		return new LicenseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(LicenseStateMapper::class),
			$seats,
			$this->createMock(ITimeFactory::class),
			$users,
			$this->createMock(ILockingProvider::class),
		);
	}

	public function testShortQueryReturnsEmpty(): void
	{
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('search');
		$seats = $this->createMock(MobileSeatMapper::class);
		$svc = $this->service($users, $seats);
		self::assertSame([], $svc->searchUsersForSeats('a'));
		self::assertSame([], $svc->searchUsersForSeats(' '));
	}

	public function testMergesUidAndDisplayNameHitsMarksSeatsAndSkipsDisabled(): void
	{
		$alice = $this->user('alice', 'Alice Admin');
		$bob = $this->user('bob', 'Bob Builder');
		$ghost = $this->user('ghost', 'Ghost', false);

		$users = $this->createMock(IUserManager::class);
		$users->method('search')->with('al', 25, 0)->willReturn([$alice, $ghost]);
		$users->method('searchDisplayName')->with('al', 25, 0)->willReturn([$bob, $alice]);

		$seat = new MobileSeat();
		$seat->setUid('bob');
		$seat->setAssignedAt(1);
		$seat->setAssignedBy('admin');
		$seats = $this->createMock(MobileSeatMapper::class);
		$seats->method('findAllRanked')->willReturn([$seat]);

		$svc = $this->service($users, $seats);
		$out = $svc->searchUsersForSeats('al', 25);

		self::assertCount(2, $out);
		self::assertSame('alice', $out[0]['id']);
		self::assertSame('Alice Admin', $out[0]['displayName']);
		self::assertFalse($out[0]['hasSeat']);
		self::assertSame('bob', $out[1]['id']);
		self::assertTrue($out[1]['hasSeat']);
	}

	public function testRespectsLimit(): void
	{
		$u1 = $this->user('u1', 'One');
		$u2 = $this->user('u2', 'Two');
		$u3 = $this->user('u3', 'Three');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn([$u1, $u2, $u3]);
		$users->method('searchDisplayName')->willReturn([]);
		$seats = $this->createMock(MobileSeatMapper::class);
		$seats->method('findAllRanked')->willReturn([]);

		$out = $this->service($users, $seats)->searchUsersForSeats('user', 2);
		self::assertCount(2, $out);
		self::assertSame(['u1', 'u2'], array_column($out, 'id'));
	}
}

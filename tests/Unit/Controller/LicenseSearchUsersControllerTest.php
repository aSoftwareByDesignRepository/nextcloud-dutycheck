<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\LicenseController;
use OCA\DutyCheck\Exception\LicenseException;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\LicenseService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class LicenseSearchUsersControllerTest extends TestCase
{
	private function controller(
		LicenseService $license,
		AccessControlService $access,
		?IUser $user,
		array $params = [],
	): LicenseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new LicenseController('dutycheck', $request, $access, $license, $session);
	}

	public function testSearchUsersRequiresAppAdmin(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('alice')->willReturn(false);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('searchUsersForSeats');

		$res = $this->controller($license, $access, $user, ['q' => 'al'])->searchUsers();
		self::assertSame(403, $res->getStatus());
		$data = $res->getData();
		self::assertFalse($data['ok']);
		self::assertSame('access_denied', $data['error']);
	}

	public function testSearchUsersReturnsItemsContract(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('admin')->willReturn(true);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())
			->method('searchUsersForSeats')
			->with('al', 25)
			->willReturn([
				['id' => 'alice', 'displayName' => 'Alice', 'hasSeat' => false],
			]);

		$res = $this->controller($license, $access, $user, ['q' => 'al'])->searchUsers();
		self::assertSame(200, $res->getStatus());
		$data = $res->getData();
		self::assertTrue($data['ok']);
		self::assertArrayHasKey('items', $data);
		self::assertSame('alice', $data['items'][0]['id']);
		self::assertArrayNotHasKey('users', $data);
	}

	public function testSearchUsersAcceptsQueryAliasAndLimit(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())
			->method('searchUsersForSeats')
			->with('bob', 10)
			->willReturn([]);

		$res = $this->controller($license, $access, $user, [
			'query' => 'bob',
			'limit' => 10,
		])->searchUsers();
		self::assertSame(200, $res->getStatus());
		self::assertSame(['ok' => true, 'items' => []], $res->getData());
	}
}

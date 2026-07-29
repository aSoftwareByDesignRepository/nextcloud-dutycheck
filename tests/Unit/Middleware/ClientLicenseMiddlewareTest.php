<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Middleware;

use OCA\DutyCheck\Exception\PaymentRequiredException;
use OCA\DutyCheck\Middleware\ClientLicenseMiddleware;
use OCA\DutyCheck\Service\LicenseService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientLicenseMiddlewareTest extends TestCase
{
	public function testBrowserSessionNeverGates(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/my/roster');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');
		$license->expects($this->never())->method('gateState');

		$mw = new ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			new NullLogger(),
		);
		$mw->beforeController(new \stdClass(), 'myRoster');
	}

	public function testBootstrapExemptEvenWithBasicAuth(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic abc');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/bootstrap');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = new ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			new NullLogger(),
		);
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testGatedPathRequiresActivePlan(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic abc');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/my/roster');
		$request->method('getMethod')->willReturn('GET');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(false);

		$mw = new ClientLicenseMiddleware($request, $session, $license, new NullLogger());
		$this->expectException(PaymentRequiredException::class);
		$mw->beforeController(new \stdClass(), 'myRoster');
	}

	public function testWebApiPathsAreNotGatedEvenWithBasicAuth(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic abc');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/my/roster');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');
		$license->expects($this->never())->method('gateState');

		$mw = new ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			new NullLogger(),
		);
		$mw->beforeController(new \stdClass(), 'myRoster');
	}
}

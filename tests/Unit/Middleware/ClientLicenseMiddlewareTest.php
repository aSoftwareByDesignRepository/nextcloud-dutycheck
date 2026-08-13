<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Middleware;

use OCA\DutyCheck\Exception\MobileUnauthenticatedException;
use OCA\DutyCheck\Exception\PaymentRequiredException;
use OCA\DutyCheck\Middleware\ClientLicenseMiddleware;
use OCA\DutyCheck\Service\LicenseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientLicenseMiddlewareTest extends TestCase
{
	public function testCookieSessionOnMobilePathIsRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/my/roster');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = new ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			new NullLogger(),
		);
		$this->expectException(MobileUnauthenticatedException::class);
		$mw->beforeController(new \stdClass(), 'myRoster');
	}

	public function testCookieSessionOnBootstrapIsRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/bootstrap');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = new ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			new NullLogger(),
		);
		$this->expectException(MobileUnauthenticatedException::class);
		$mw->beforeController(new \stdClass(), 'bootstrap');
	}

	public function testAfterExceptionMapsUnauthenticatedTo401(): void
	{
		$mw = new ClientLicenseMiddleware(
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LicenseService::class),
			new NullLogger(),
		);
		$response = $mw->afterException(new \stdClass(), 'myRoster', new MobileUnauthenticatedException());
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok'] ?? true);
		self::assertSame('UNAUTHENTICATED', $data['error']['code'] ?? null);
		self::assertSame('UNAUTHENTICATED', $data['error']['message'] ?? null);
	}

	public function testAfterExceptionMapsPaymentRequiredTo402Not401(): void
	{
		$mw = new ClientLicenseMiddleware(
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LicenseService::class),
			new NullLogger(),
		);
		$response = $mw->afterException(
			new \stdClass(),
			'myRoster',
			new PaymentRequiredException('NO_MOBILE_SEAT'),
		);
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(Http::STATUS_PAYMENT_REQUIRED, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok'] ?? true);
		self::assertSame('NO_MOBILE_SEAT', $data['error']['code'] ?? null);
		self::assertSame('payment_required', $data['error']['type'] ?? null);
	}

	public function testAfterExceptionRethrowsUnknownExceptions(): void
	{
		$mw = new ClientLicenseMiddleware(
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LicenseService::class),
			new NullLogger(),
		);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not-a-gate');
		$mw->afterException(new \stdClass(), 'myRoster', new \RuntimeException('not-a-gate'));
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

	public function testBasicWithoutSessionUserIsRejectedOnGatedPath(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic abc');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/mobile/my/roster');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = new ClientLicenseMiddleware($request, $session, $license, new NullLogger());
		$this->expectException(MobileUnauthenticatedException::class);
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

	public function testWebApiPathsIgnoreMissingBasic(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/my/roster');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isMobilePlanActive');

		$mw = new ClientLicenseMiddleware(
			$request,
			$this->createMock(IUserSession::class),
			$license,
			new NullLogger(),
		);
		$mw->beforeController(new \stdClass(), 'myRoster');
	}
}

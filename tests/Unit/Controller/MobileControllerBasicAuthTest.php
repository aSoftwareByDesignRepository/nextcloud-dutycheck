<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\MobileController;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\RosterService;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Companion mobile API must not accept cookie/session callers: those routes are
 * NoCSRFRequired for Basic app-password clients. Cookie identity would open CSRF.
 */
final class MobileControllerBasicAuthTest extends TestCase
{
	/**
	 * @return list<array{0:string,1:callable(MobileController):mixed}>
	 */
	public function cookieRejectedRouteProvider(): array
	{
		return [
			['bootstrap', static fn (MobileController $c) => $c->bootstrap()],
			['myRoster', static fn (MobileController $c) => $c->myRoster()],
			['acknowledgeAssignment', static fn (MobileController $c) => $c->acknowledgeAssignment(42)],
			['myAbsences', static fn (MobileController $c) => $c->myAbsences()],
			['createMyAbsence', static fn (MobileController $c) => $c->createMyAbsence()],
			['listOpenShifts', static fn (MobileController $c) => $c->listOpenShifts()],
			['claimOpenShift', static fn (MobileController $c) => $c->claimOpenShift(7)],
			['createSwapRequest', static fn (MobileController $c) => $c->createSwapRequest()],
			['swapCandidates', static fn (MobileController $c) => $c->swapCandidates()],
		];
	}

	/**
	 * @dataProvider cookieRejectedRouteProvider
	 */
	public function testCookieSessionWithoutBasicIsRejectedOnEveryRoute(string $label, callable $invoke): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');

		$gate = $this->createMock(MobileGateService::class);
		$gate->expects($this->never())->method('bootstrapPayload');
		$gate->expects($this->never())->method('assertGatePassed');

		$roster = $this->createMock(RosterService::class);

		$controller = new MobileController(
			$request,
			$session,
			$gate,
			$roster,
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
		);

		$response = $invoke($controller);
		self::assertSame(401, $response->getStatus(), "route {$label} must reject cookie sessions");
		$data = $response->getData();
		self::assertSame('UNAUTHENTICATED', $data['error']['code'] ?? null, "route {$label}");
	}

	public function testBasicAuthAllowsBootstrap(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic YWxpY2U6eA==');

		$gate = $this->createMock(MobileGateService::class);
		$gate->expects($this->once())->method('bootstrapPayload')->with('alice', 'Alice', '1.0.0')->willReturn([
			'appId' => 'dutycheck',
			'urls' => [],
		]);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.0.0');

		$controller = new MobileController(
			$request,
			$session,
			$gate,
			$this->createMock(RosterService::class),
			$apps,
			$this->createMock(IURLGenerator::class),
		);

		$response = $controller->bootstrap();
		self::assertSame(200, $response->getStatus());
		self::assertSame('dutycheck', $response->getData()['appId'] ?? null);
	}

	public function testBearerAuthIsNotAcceptedAsBasic(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer sometoken');

		$gate = $this->createMock(MobileGateService::class);
		$gate->expects($this->never())->method('bootstrapPayload');

		$controller = new MobileController(
			$request,
			$session,
			$gate,
			$this->createMock(RosterService::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
		);

		$response = $controller->bootstrap();
		self::assertSame(401, $response->getStatus());
	}
}

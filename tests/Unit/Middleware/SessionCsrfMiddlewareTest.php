<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller {
	// Fixture: a controller that lives inside the guarded namespace. PHPUnit
	// mocks land in a Mock_* namespace and would be (correctly) skipped.
	class SessionCsrfProbeController extends \OCP\AppFramework\Controller
	{
		public function __construct()
		{
		}
	}
}

namespace OCA\DutyCheck\Tests\Unit\Middleware {

use OCA\DutyCheck\Exception\SessionCsrfException;
use OCA\DutyCheck\Middleware\SessionCsrfMiddleware;
use OCA\DutyCheck\Service\CsrfTokenValidator;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SessionCsrfMiddlewareTest extends TestCase
{
	private function request(array $opts): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($opts['method'] ?? 'POST');
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => match (strtolower($name)) {
				'authorization' => $opts['authorization'] ?? '',
				'requesttoken' => $opts['headerToken'] ?? '',
				default => '',
			},
		);
		$request->method('getParam')->willReturnCallback(
			fn (string $name) => $name === 'requesttoken' ? ($opts['paramToken'] ?? null) : null,
		);
		return $request;
	}

	private function session(bool $loggedIn): IUserSession
	{
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);
		return $session;
	}

	private function validator(bool $valid): CsrfTokenValidator
	{
		$validator = $this->createMock(CsrfTokenValidator::class);
		$validator->method('isValid')->willReturn($valid);
		return $validator;
	}

	private function controller(): object
	{
		return new \OCA\DutyCheck\Controller\SessionCsrfProbeController();
	}

	/** The whole point of the middleware: OCS-APIRequest must not open the door. */
	public function testSessionMutationWithOcsHeaderButNoTokenIsRejected(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['headerToken' => '']),
			$this->session(true),
			$this->validator(true),
		);
		$this->expectException(SessionCsrfException::class);
		$mw->beforeController($this->controller(), 'createPeriod');
	}

	public function testSessionMutationWithInvalidTokenIsRejected(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['headerToken' => 'bogus']),
			$this->session(true),
			$this->validator(false),
		);
		$this->expectException(SessionCsrfException::class);
		$mw->beforeController($this->controller(), 'createPeriod');
	}

	public function testSessionMutationWithValidTokenPasses(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['headerToken' => 'good']),
			$this->session(true),
			$this->validator(true),
		);
		$mw->beforeController($this->controller(), 'createPeriod');
		$this->addToAssertionCount(1);
	}

	public function testParamTokenIsAccepted(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['paramToken' => 'good']),
			$this->session(true),
			$this->validator(true),
		);
		$mw->beforeController($this->controller(), 'createPeriod');
		$this->addToAssertionCount(1);
	}

	public function testGetRequestIsNeverChecked(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['method' => 'GET']),
			$this->session(true),
			$this->validator(false),
		);
		$mw->beforeController($this->controller(), 'roster');
		$this->addToAssertionCount(1);
	}

	public function testBasicAuthRequestIsExempt(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['authorization' => 'Basic abc123']),
			$this->session(true),
			$this->validator(false),
		);
		$mw->beforeController($this->controller(), 'createMyAbsence');
		$this->addToAssertionCount(1);
	}

	public function testAnonymousRequestIsNotGuardedHere(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request([]),
			$this->session(false),
			$this->validator(false),
		);
		$mw->beforeController($this->controller(), 'createPeriod');
		$this->addToAssertionCount(1);
	}

	public function testNonDutyCheckControllerIsSkipped(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request([]),
			$this->session(true),
			$this->validator(false),
		);
		$mw->beforeController(new \stdClass(), 'anything');
		$this->addToAssertionCount(1);
	}

	public function testExceptionSerializesTo412WithStableCode(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request([]),
			$this->session(true),
			$this->validator(false),
		);
		$response = $mw->afterException($this->controller(), 'x', new SessionCsrfException());
		$this->assertSame(412, $response->getStatus());
		$this->assertSame('CSRF_FAILED', $response->getData()['error']['code']);
	}
}

}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Controller\ApiController;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Middleware\AppAccessMiddleware;
use OCA\DutyCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/** Directory-restriction gate against live app config and user sessions. */
final class AppAccessGateIntegrationTest extends TestCase
{
	private const ALLOWED = 'dc_gate_allowed';
	private const DENIED = 'dc_gate_denied';
	private const PASSWORD = 'dc-test-pass-9xK!';

	private ?string $prevRestriction = null;
	private ?string $prevAllowedUsers = null;
	private ?string $prevAllowedGroups = null;
	private ?string $prevAppAdmins = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prevRestriction = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$this->prevAllowedUsers = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$this->prevAllowedGroups = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');
		$this->prevAppAdmins = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');

		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		if ($this->prevRestriction !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, $this->prevRestriction);
		}
		if ($this->prevAllowedUsers !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $this->prevAllowedUsers);
		}
		if ($this->prevAllowedGroups !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, $this->prevAllowedGroups);
		}
		if ($this->prevAppAdmins !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, $this->prevAppAdmins);
		}
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
		/** @var IUserSession $session */
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser(null);
	}

	public function testDeniedUserBlockedByDirectoryRestriction(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');

		/** @var IUserSession $session */
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser($userManager->get(self::DENIED));

		/** @var ApiController $controller */
		$controller = \OC::$server->get(ApiController::class);
		$middleware = $this->middlewareWithMockRequest();

		try {
			$middleware->beforeController($controller, 'bootstrap');
			$this->fail('Expected AppAccessDeniedException for gated user');
		} catch (AppAccessDeniedException $exception) {
			$this->assertSame(AccessControlService::DENIAL_RESTRICTION, $exception->getDenialReason());
		}

		$response = $middleware->afterException(
			$controller,
			'bootstrap',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAppAdminPassesGateWhenRestrictionEnabled(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);

		/** @var IUserSession $session */
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser($userManager->get(self::ALLOWED));

		/** @var ApiController $controller */
		$controller = \OC::$server->get(ApiController::class);
		$this->middlewareWithMockRequest()->beforeController($controller, 'bootstrap');
		$this->addToAssertionCount(1);
	}

	private function middlewareWithMockRequest(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/dutycheck/api/bootstrap');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getHeader')->willReturn('');

		return new AppAccessMiddleware(
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			$request,
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
		);
	}
}

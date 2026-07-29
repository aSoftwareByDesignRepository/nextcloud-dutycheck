<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Middleware;

use OCA\DutyCheck\Exception\PaymentRequiredException;
use OCA\DutyCheck\Service\LicenseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * HTTP 402 gate for DutyCheck companion APIs under /api/mobile/* (Basic auth only).
 * Browser session traffic stays free. Bootstrap is never gated.
 */
class ClientLicenseMiddleware extends Middleware
{
	private const BOOTSTRAP_PATH = '/api/mobile/bootstrap';

	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LicenseService $licenseService,
		private readonly LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		if (!$this->usesBasicAppPassword()) {
			return;
		}

		$path = $this->normalizeApiPath((string)$this->request->getPathInfo());
		if (!$this->shouldGateMobileApiPath($path)) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$userId = $user->getUID();

		if (!$this->licenseService->isMobilePlanActive()) {
			$this->logger->info('DutyCheck mobile license gate: no active mobile plan', [
				'userId' => $userId,
				'path' => $path,
			]);
			throw new PaymentRequiredException('LICENSE_REQUIRED');
		}

		try {
			$this->licenseService->assertMobileAccess($userId);
		} catch (\OCA\DutyCheck\Exception\MobileGateException $e) {
			$code = match ($e->getErrorCode()) {
				'license_expired' => 'LICENSE_EXPIRED',
				'seat_limit_exceeded' => 'SEAT_LIMIT_EXCEEDED',
				'seat_required' => 'NO_MOBILE_SEAT',
				default => 'LICENSE_REQUIRED',
			};
			throw new PaymentRequiredException($code);
		}
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof PaymentRequiredException) {
			throw $exception;
		}

		$code = $exception->getErrorCode();
		return new DataResponse([
			'ok' => false,
			'error' => [
				'code' => $code,
				'type' => 'payment_required',
				'message' => $code,
			],
		], Http::STATUS_PAYMENT_REQUIRED);
	}

	private function shouldGateMobileApiPath(string $path): bool
	{
		if (!str_starts_with($path, '/api/mobile')) {
			return false;
		}
		if ($path === self::BOOTSTRAP_PATH) {
			return false;
		}
		return true;
	}

	private function usesBasicAppPassword(): bool
	{
		$auth = (string)$this->request->getHeader('Authorization');
		return str_starts_with(strtolower($auth), 'basic ');
	}

	private function normalizeApiPath(string $pathInfo): string
	{
		$path = $pathInfo;
		$prefix = '/apps/dutycheck';
		if (str_starts_with($path, $prefix)) {
			$path = substr($path, strlen($prefix));
		}
		if ($path === '') {
			return '/';
		}
		return $path;
	}
}

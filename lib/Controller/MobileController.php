<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\MobileGateException;
use OCA\DutyCheck\Http\ApiMutationParams;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Throwable;

/**
 * Companion API for DutyCheck Mobile (Basic app-password).
 *
 * Bootstrap is ungated so clients can render LicenseGate / UnofficialServer.
 * Roster + acknowledge + marketplace require a valid seat (assertGatePassed).
 * Browser session web routes are never gated by this controller.
 */
class MobileController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly MobileGateService $gate,
		private readonly RosterService $roster,
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?SwapService $swaps = null,
		private readonly ?OpenShiftService $openShifts = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$displayName = $this->userSession->getUser()?->getDisplayName() ?? $uid;
			$version = $this->appManager->getAppVersion(Application::APP_ID, false);
			$payload = $this->gate->bootstrapPayload($uid, $displayName, $version);
			$payload['urls'] = array_merge([
				'myRosterWeb' => $this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'),
				'azcAbsences' => null,
			], is_array($payload['urls'] ?? null) ? $payload['urls'] : []);
			return new JSONResponse($payload);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myRoster(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$from = $this->request->getParam('from');
			$to = $this->request->getParam('to');
			$data = $this->roster->myRoster(
				$uid,
				is_string($from) ? $from : null,
				is_string($to) ? $to : null,
			);
			return new JSONResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acknowledgeAssignment(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$result = $this->roster->acknowledgeAssignment($id, $uid);
			return new JSONResponse(['ok' => true, 'data' => $result]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myAbsences(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			return new JSONResponse(['ok' => true, 'data' => $this->roster->myAbsences($uid)]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createMyAbsence(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$params = ApiMutationParams::all($this->request);
			$data = $this->roster->createMyAbsence($uid, [
				'kind' => $params['kind'] ?? $this->request->getParam('kind'),
				'startDate' => $params['startDate'] ?? $this->request->getParam('startDate'),
				'endDate' => $params['endDate'] ?? $this->request->getParam('endDate'),
			]);
			return new JSONResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listOpenShifts(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->openShifts ?? throw new \RuntimeException('OPEN_SHIFTS_UNAVAILABLE');
			$periodId = $this->request->getParam('periodId');
			$data = $svc->listOpen(
				$periodId !== null && $periodId !== '' ? (int) $periodId : null,
				$uid,
			);
			return new JSONResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function claimOpenShift(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->openShifts ?? throw new \RuntimeException('OPEN_SHIFTS_UNAVAILABLE');
			$data = $svc->claim($id, $uid);
			return new JSONResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createSwapRequest(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->swaps ?? throw new \RuntimeException('SWAPS_UNAVAILABLE');
			$params = ApiMutationParams::all($this->request);
			$to = $params['toEmployeeId'] ?? null;
			$data = $svc->requestSwap(
				(int) ($params['assignmentId'] ?? 0),
				$uid,
				$to !== null && $to !== '' ? (int) $to : null,
				(string) ($params['reason'] ?? ''),
			);
			return new JSONResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function swapCandidates(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->swaps ?? throw new \RuntimeException('SWAPS_UNAVAILABLE');
			return new JSONResponse(['ok' => true, 'data' => $svc->listSwapCandidates($uid)]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Companion routes are Basic app-password only.
	 *
	 * These endpoints are #[NoCSRFRequired] so mobile clients can authenticate
	 * without a request token. Accepting cookie/session identity here would
	 * create a CSRF surface for seat-gated mutations (ack / claim / swap /
	 * absence). Browser callers must use the CSRF-protected web API instead.
	 */
	private function requireUid(): string
	{
		if (!$this->usesBasicAppPassword()) {
			throw new \RuntimeException('UNAUTHENTICATED');
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('UNAUTHENTICATED');
		}
		return $user->getUID();
	}

	private function usesBasicAppPassword(): bool
	{
		$auth = (string) $this->request->getHeader('Authorization');
		return str_starts_with(strtolower($auth), 'basic ');
	}

	private function fromThrowable(Throwable $e): JSONResponse
	{
		if ($e instanceof MobileGateException) {
			$code = $e->getErrorCode();
			$http = match ($code) {
				'license_expired' => Http::STATUS_PAYMENT_REQUIRED,
				'license_missing' => Http::STATUS_PAYMENT_REQUIRED,
				'seat_required', 'seat_limit_exceeded' => Http::STATUS_PAYMENT_REQUIRED,
				default => Http::STATUS_PAYMENT_REQUIRED,
			};
			$wire = match ($code) {
				'license_missing' => 'LICENSE_REQUIRED',
				'license_expired' => 'LICENSE_EXPIRED',
				'seat_limit_exceeded' => 'SEAT_LIMIT_EXCEEDED',
				'seat_required' => 'NO_MOBILE_SEAT',
				default => 'LICENSE_REQUIRED',
			};
			return new JSONResponse([
				'ok' => false,
				'error' => ['code' => $wire, 'type' => 'payment_required', 'message' => $wire],
			], $http);
		}
		if ($e->getMessage() === 'UNAUTHENTICATED') {
			return new JSONResponse(['ok' => false, 'error' => ['code' => 'UNAUTHENTICATED']], 401);
		}
		$mapped = ApiJsonErrorResponse::fromThrowable($e);
		return new JSONResponse($mapped->getData(), $mapped->getStatus());
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\MobileGateException;
use OCA\DutyCheck\Http\ApiMutationParams;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\Contract\EffectiveTargetHoursFacade;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\ShiftPreferenceService;
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
use DateTimeImmutable;
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
		private readonly ?PeerRosterService $peers = null,
		private readonly ?ShiftPreferenceService $preferences = null,
		private readonly ?AvailabilityBlackoutService $blackouts = null,
		private readonly ?SelfServiceSettingsService $selfService = null,
		private readonly ?CompanyService $companies = null,
		private readonly ?EffectiveTargetHoursFacade $facade = null,
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
			$caps = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
			$snapshot = $this->selfServiceSnapshot($uid);
			$payload['capabilities'] = array_merge($caps, [
				// Additive GA capabilities — do not bump dutycheck.companion.min.
				'dutycheck.peerRoster' => (bool) ($snapshot['peerRosterVisibility'] ?? false),
				'dutycheck.preferences' => (bool) ($snapshot['preferencesEnabled'] ?? false),
				'dutycheck.blackouts' => (bool) ($snapshot['blackoutsEnabled'] ?? false),
				'dutycheck.ical' => true,
				'dutycheck.quietHours' => (bool) ($snapshot['pushQuietHoursEnabled'] ?? false),
				'dutycheck.planWeekLabel' => (bool) ($snapshot['rotationPatternsEnabled'] ?? false),
				'dutycheck.swapBilateral' => (($snapshot['swapApprovalMode'] ?? '') === SelfServiceSettingsService::SWAP_BILATERAL_AUTO),
			]);
			if ($snapshot !== null) {
				$payload['selfService'] = $snapshot;
			}
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
			$data = $this->enrichRosterWithPlanWeek($uid, $data);
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
	public function acceptSwap(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->swaps ?? throw new \RuntimeException('SWAPS_UNAVAILABLE');
			return new JSONResponse(['ok' => true, 'data' => $svc->acceptByCounterparty($id, $uid)]);
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function teamWeek(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->peers ?? throw new \RuntimeException('PEER_ROSTER_UNAVAILABLE');
			$locationId = (int) $this->request->getParam('locationId', 0);
			$weekStart = (string) $this->request->getParam('weekStart', '');
			$page = (int) $this->request->getParam('page', 1);
			$pageSize = (int) $this->request->getParam('pageSize', 50);
			return new JSONResponse(['ok' => true, 'data' => $svc->listTeamWeek($uid, $locationId, $weekStart, $page, $pageSize)]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myPreferences(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->preferences ?? throw new \RuntimeException('PREFERENCES_UNAVAILABLE');
			$employeeId = (int) $this->roster->myIcalTokenMeta($uid)['employeeId'];
			return new JSONResponse(['ok' => true, 'data' => $svc->listForEmployee($employeeId, $uid)]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function saveMyPreference(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->preferences ?? throw new \RuntimeException('PREFERENCES_UNAVAILABLE');
			$employeeId = (int) $this->roster->myIcalTokenMeta($uid)['employeeId'];
			$params = ApiMutationParams::all($this->request);
			$data = $svc->save($employeeId, $params, $uid);
			$status = isset($params['id']) && (int) $params['id'] > 0 ? 200 : 201;
			return new JSONResponse(['ok' => true, 'data' => $data], $status);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteMyPreference(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->preferences ?? throw new \RuntimeException('PREFERENCES_UNAVAILABLE');
			$svc->delete($id, $uid);
			return new JSONResponse(['ok' => true, 'data' => ['id' => $id]]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myBlackouts(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->blackouts ?? throw new \RuntimeException('BLACKOUTS_UNAVAILABLE');
			$employeeId = (int) $this->roster->myIcalTokenMeta($uid)['employeeId'];
			$from = (string) $this->request->getParam('from', '');
			$to = (string) $this->request->getParam('to', '');
			return new JSONResponse(['ok' => true, 'data' => $svc->listForEmployee($employeeId, $from, $to, $uid)]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createMyBlackout(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->blackouts ?? throw new \RuntimeException('BLACKOUTS_UNAVAILABLE');
			$employeeId = (int) $this->roster->myIcalTokenMeta($uid)['employeeId'];
			$params = ApiMutationParams::all($this->request);
			$locationRaw = $params['locationId'] ?? $params['location_id'] ?? null;
			$locationId = $locationRaw !== null && $locationRaw !== '' ? (int) $locationRaw : null;
			$data = $svc->create(
				$employeeId,
				(string) ($params['startAt'] ?? $params['start_at'] ?? ''),
				(string) ($params['endAt'] ?? $params['end_at'] ?? ''),
				(string) ($params['label'] ?? $params['labelEnum'] ?? 'other'),
				$locationId,
				$uid,
			);
			return new JSONResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteMyBlackout(int $id): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			$svc = $this->blackouts ?? throw new \RuntimeException('BLACKOUTS_UNAVAILABLE');
			$svc->delete($id, $uid);
			return new JSONResponse(['ok' => true, 'data' => ['id' => $id]]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function icalTokenMeta(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			return new JSONResponse(['ok' => true, 'data' => $this->roster->myIcalTokenMeta($uid)]);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createIcalToken(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			return new JSONResponse(['ok' => true, 'data' => $this->roster->rotateMyIcalToken($uid)], 201);
		} catch (Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rotateIcalToken(): JSONResponse
	{
		try {
			$uid = $this->requireUid();
			$this->gate->assertGatePassed($uid);
			return new JSONResponse(['ok' => true, 'data' => $this->roster->rotateMyIcalToken($uid)]);
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

	/**
	 * Companion-only slice of self-service settings (no admin policy dump).
	 *
	 * @return array<string, mixed>|null
	 */
	private function selfServiceSnapshot(string $uid): ?array
	{
		if ($this->selfService === null || $this->companies === null) {
			return null;
		}
		try {
			$companyId = $this->companies->writeCompanyIdFor($uid);
			$full = $this->selfService->toApi($companyId);
		} catch (Throwable) {
			try {
				$full = $this->selfService->toApi(CompanyService::DEFAULT_COMPANY_ID);
			} catch (Throwable) {
				return null;
			}
		}
		// Settings UI needs quiet window labels; do not expose swap/peer/admin knobs.
		return [
			'pushQuietHoursEnabled' => (bool) ($full['pushQuietHoursEnabled'] ?? false),
			'pushQuietHoursStart' => (string) ($full['pushQuietHoursStart'] ?? '22:00'),
			'pushQuietHoursEnd' => (string) ($full['pushQuietHoursEnd'] ?? '06:00'),
		];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function enrichRosterWithPlanWeek(string $uid, array $rows): array
	{
		if ($this->facade === null || $rows === []) {
			return $rows;
		}
		$out = [];
		foreach ($rows as $row) {
			$dutyDate = (string) ($row['dutyDate'] ?? '');
			if ($dutyDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate) === 1) {
				try {
					$dto = $this->facade->getDayTarget($uid, new DateTimeImmutable($dutyDate . ' 00:00:00'));
					if ($dto !== null) {
						$row['rotationWeekIndex'] = $dto->weekIndex;
						$row['rotationWeekLabel'] = $dto->weekLabel;
					}
				} catch (Throwable) {
					// Additive fields only — never fail the roster payload.
				}
			}
			$out[] = $row;
		}
		return $out;
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

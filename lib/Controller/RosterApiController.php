<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Http\ApiMutationParams;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use Throwable;

class RosterApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private RosterService $roster,
		private RosterCsvFormatter $csvFormatter,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IArbeitszeitCheckIntegration $arbeitszeitCheckIntegration,
		private IConfig $config,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function dashboard(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $this->roster->dashboardSummary()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function roster(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$periodId = $this->request->getParam('periodId');
			$data = $this->roster->rosterData($periodId !== null ? (int) $periodId : null);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createPeriod(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$created = $this->roster->createPeriod(
				(string) $this->request->getParam('startDate', ''),
				(string) $this->request->getParam('endDate', ''),
				$this->access->currentUserId(),
			);
			return new DataResponse(['ok' => true, 'data' => $created]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 400);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function transitionPeriod(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$targetStatus = trim((string) $this->request->getParam('status', ''));
			if ($targetStatus === 'open') {
				$this->access->requireAppAdmin($userId);
			}
			$updated = $this->roster->transitionPeriod(
				$id,
				$targetStatus,
				$userId,
				(string) $this->request->getParam('reason', '')
			);
			return new DataResponse(['ok' => true, 'data' => $updated]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function publishPeriod(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$updated = $this->roster->transitionPeriod($id, 'published', $userId);
			return new DataResponse(['ok' => true, 'data' => $updated]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function closePeriod(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$updated = $this->roster->transitionPeriod($id, 'closed', $userId);
			return new DataResponse(['ok' => true, 'data' => $updated]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function reopenPeriod(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$updated = $this->roster->transitionPeriod(
				$id,
				'open',
				$userId,
				(string) $this->request->getParam('reason', '')
			);
			return new DataResponse(['ok' => true, 'data' => $updated]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function periodSnapshots(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->listPeriodSnapshots($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function verifyPeriodSnapshots(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->verifyPeriodSnapshots($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function publishReadiness(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->publishReadiness($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function periodAudit(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->periodAudit($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	/**
	 * UTF-8 CSV export of all assignments in a period. DutyCheck / Nextcloud administrators only.
	 */
	#[NoAdminRequired]
	public function exportRosterCsv(): DataDownloadResponse|DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$raw = $this->request->getParam('periodId');
			$periodId = is_numeric($raw) ? (int) $raw : 0;
			if ($periodId <= 0) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'PERIOD_ID_REQUIRED']], 400);
			}
			$bundle = $this->roster->rosterExportBundle($periodId);
			$exportedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
			$csv = $this->csvFormatter->buildDutyRosterCsv(
				$bundle['period'],
				$bundle['assignments'],
				$userId,
				$exportedAt,
			);
			$this->roster->logRosterDataExport($periodId, $userId, 'csv', [
				'assignmentCount' => count($bundle['assignments']),
			]);
			$filename = $this->safeRosterExportFilename($bundle['period']);

			return new DataDownloadResponse($csv, $filename, 'text/csv; charset=UTF-8');
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	/**
	 * @param array<string, mixed> $period
	 */
	private function safeRosterExportFilename(array $period): string
	{
		$id = max(0, (int) ($period['id'] ?? 0));
		$start = (string) preg_replace('/[^0-9-]+/', '', (string) ($period['startDate'] ?? ''));
		$end = (string) preg_replace('/[^0-9-]+/', '', (string) ($period['endDate'] ?? ''));
		$base = 'dutycheck-roster-' . $id;
		if ($start !== '' && $end !== '') {
			$base .= '_' . $start . '_' . $end;
		}
		if (preg_match('/^[a-zA-Z0-9._-]+$/', $base) !== 1) {
			$base = 'dutycheck-roster-' . $id;
		}

		return $base . '.csv';
	}

	#[NoAdminRequired]
	public function createAssignment(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$params = ApiMutationParams::all($this->request);
			$data = $this->roster->createAssignment([
				'periodId' => $params['periodId'] ?? null,
				'employeeId' => $params['employeeId'] ?? null,
				'locationId' => $params['locationId'] ?? null,
				'dutyDate' => $params['dutyDate'] ?? null,
				'startTime' => $params['startTime'] ?? null,
				'endTime' => $params['endTime'] ?? null,
				'breakMinutes' => $params['breakMinutes'] ?? null,
				'note' => $params['note'] ?? null,
				'acknowledgements' => ApiMutationParams::acknowledgements($this->request),
			], $this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], ApiJsonErrorResponse::statusForInvalidArgument($e->getMessage()));
		} catch (ConflictAckRequiredException $e) {
			return new DataResponse([
				'ok' => false,
				'error' => [
					'code' => 'CONFLICT_ACK_REQUIRED',
					'conflicts' => $e->getConflicts(),
				],
			], 409);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function acknowledgeConflict(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$reason = (string) $this->request->getParam('reason', '');
			$data = $this->roster->acknowledgeConflict($id, $userId, $reason);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function absences(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $this->roster->listAbsences()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createAbsence(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$data = $this->roster->createAbsence([
				'employeeId' => $this->request->getParam('employeeId'),
				'kind' => $this->request->getParam('kind'),
				'startDate' => $this->request->getParam('startDate'),
				'endDate' => $this->request->getParam('endDate'),
			], $this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function transitionAbsence(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$data = $this->roster->transitionAbsence(
				$id,
				(string) $this->request->getParam('status', ''),
				(string) $this->request->getParam('reviewReason', ''),
				$userId,
			);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function employees(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $this->roster->listEmployeeCatalog()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createEmployee(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			if (!$this->roster->isSchemaReady()) {
				return ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException('SCHEMA_NOT_READY'));
			}
			$params = ApiMutationParams::all($this->request);
			$data = $this->roster->createEmployee([
				'displayName' => $params['displayName'] ?? null,
				'linkedUserId' => $params['linkedUserId'] ?? null,
				'active' => $params['active'] ?? null,
			]);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateEmployee(int $id): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			if (!$this->roster->isSchemaReady()) {
				return ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException('SCHEMA_NOT_READY'));
			}
			$params = ApiMutationParams::all($this->request);
			$data = $this->roster->updateEmployee($id, [
				'displayName' => $params['displayName'] ?? null,
				'linkedUserId' => $params['linkedUserId'] ?? null,
				'active' => $params['active'] ?? null,
			]);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function directoryUsers(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			if (!$this->access->isAppAdmin($userId) && !$this->access->isPlannerOrAdmin($userId)) {
				$this->access->requireAppAdmin($userId);
			}
			$query = trim((string) $this->request->getParam('q', ''));
			if (mb_strlen($query) < 2) {
				return new DataResponse(['ok' => true, 'users' => []]);
			}
			$users = $this->userManager->search($query);
			$rows = [];
			$count = 0;
			foreach ($users as $user) {
				if ($user === null) {
					continue;
				}
				$uid = (string) $user->getUID();
				if ($uid === '') {
					continue;
				}
				$rows[] = [
					'id' => $uid,
					'displayName' => (string) $user->getDisplayName(),
					'enabled' => method_exists($user, 'isEnabled') ? (bool) $user->isEnabled() : true,
				];
				$count++;
				if ($count >= 100) {
					break;
				}
			}
			return new DataResponse(['ok' => true, 'users' => $rows]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function directoryGroups(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$query = trim((string) $this->request->getParam('q', ''));
			$groups = $this->groupManager->search($query, 100, 0);
			$rows = [];
			foreach ($groups as $group) {
				if ($group === null) {
					continue;
				}
				$id = (string) $group->getGID();
				if ($id === '') {
					continue;
				}
				$rows[] = [
					'id' => $id,
					'displayName' => (string) ($group->getDisplayName() ?? $id),
				];
			}
			return new DataResponse(['ok' => true, 'groups' => $rows]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function appPolicy(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'policy' => $this->access->appPolicy()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveAppPolicy(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$params = ApiMutationParams::all($this->request);
			$policy = $this->access->saveAppPolicy([
				'appAdminUserIds' => $params['appAdminUserIds'] ?? [],
				'accessRestrictionEnabled' => $params['accessRestrictionEnabled'] ?? false,
				'allowedUserIds' => $params['allowedUserIds'] ?? [],
				'allowedGroupIds' => $params['allowedGroupIds'] ?? [],
			]);
			return new DataResponse(['ok' => true, 'policy' => $policy]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 400);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function locations(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $this->roster->listLocationCatalog()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createLocation(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$data = $this->roster->createLocation([
				'name' => $this->request->getParam('name'),
				'timezone' => $this->request->getParam('timezone'),
				'active' => $this->request->getParam('active'),
			]);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 400);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateLocation(int $id): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$data = $this->roster->updateLocation($id, [
				'name' => $this->request->getParam('name'),
				'timezone' => $this->request->getParam('timezone'),
				'active' => $this->request->getParam('active'),
			]);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 400);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myRoster(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$from = $this->request->getParam('from');
			$to = $this->request->getParam('to');
			$fromString = is_string($from) && $from !== '' ? $from : null;
			$toString = is_string($to) && $to !== '' ? $to : null;
			return new DataResponse([
				'ok' => true,
				'data' => $this->roster->myRoster($userId, $fromString, $toString),
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 404);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myAbsences(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->myAbsences($userId)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 404);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createMyAbsence(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$data = $this->roster->createMyAbsence($userId, [
				'kind' => $this->request->getParam('kind'),
				'startDate' => $this->request->getParam('startDate'),
				'endDate' => $this->request->getParam('endDate'),
			]);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myIcalToken(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->myIcalTokenMeta($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function rotateMyIcalToken(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->rotateMyIcalToken($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function publicIcal(int $employeeId): DataDisplayResponse
	{
		try {
			if (!$this->isSecureRequest()) {
				return new DataDisplayResponse('HTTPS_REQUIRED', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
			}
			$token = trim((string) $this->request->getParam('token', ''));
			$remoteAddr = method_exists($this->request, 'getRemoteAddress') ? (string) $this->request->getRemoteAddress() : '';
			$calendar = $this->roster->publicIcal($employeeId, $token, $remoteAddr);
			return new DataDisplayResponse($calendar, 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
		} catch (\InvalidArgumentException $e) {
			$status = match ($e->getMessage()) {
				'ICAL_TOKEN_INVALID' => 403,
				'RATE_LIMITED' => 429,
				default => 404,
			};
			return new DataDisplayResponse($e->getMessage(), $status, ['Content-Type' => 'text/plain; charset=utf-8']);
		} catch (Throwable $e) {
			return new DataDisplayResponse('INTERNAL_ERROR', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
	}

	private function isSecureRequest(): bool
	{
		// Use Nextcloud's protocol resolution (honours trusted reverse proxies); avoid trusting raw client headers alone.
		return strtolower($this->request->getServerProtocol()) === 'https';
	}

	private const INTEGRATION_MANUAL_SYNC_COOLDOWN_SEC = 45;

	/** Cooldown after a legacy-absence purge (destructive; mitigates double-submit / abuse). */
	private const INTEGRATION_PURGE_COOLDOWN_SEC = 60;

	/**
	 * Extra fields merged into admin integration payloads (settings UI).
	 *
	 * @return array{legacyDcAbsencesOnLinkedEmployees:int, activeEmployeesTotal:int, activeEmployeesUnlinked:int}
	 */
	private function integrationAdminContextExtras(): array
	{
		return [
			'legacyDcAbsencesOnLinkedEmployees' => $this->arbeitszeitCheckIntegration->countLegacyAbsencesForLinkedEmployees(),
			'activeEmployeesTotal' => $this->roster->countActiveEmployees(),
			'activeEmployeesUnlinked' => $this->roster->countActiveUnlinkedEmployees(),
		];
	}

	#[NoAdminRequired]
	public function integrationStatus(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$hasLink = $this->access->hasActiveLinkedEmployee($userId);
			return new DataResponse([
				'ok' => true,
				'data' => array_merge(
					$this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLink),
					$this->integrationAdminContextExtras(),
				),
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function integrationIntent(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$params = $this->request->getParams();
			if (!\array_key_exists('enabled', $params)) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'INVALID_PARAMETER']], 400);
			}
			$enabled = filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			if ($enabled === null) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'INVALID_PARAMETER']], 400);
			}
			$this->arbeitszeitCheckIntegration->setIntentEnabled($enabled, $userId);
			$hasLink = $this->access->hasActiveLinkedEmployee($userId);
			return new DataResponse([
				'ok' => true,
				'data' => array_merge(
					$this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLink),
					$this->integrationAdminContextExtras(),
				),
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function integrationSyncNow(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$now = $this->timeFactory->getTime();
			$last = (int) $this->config->getAppValue(Application::APP_ID, 'integration_at_manual_sync_cooldown', '0');
			if ($last > 0 && ($now - $last) < self::INTEGRATION_MANUAL_SYNC_COOLDOWN_SEC) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'INTEGRATION_SYNC_THROTTLED']], 429);
			}

			$lease = $this->arbeitszeitCheckIntegration->acquireSyncLease(600);
			if (!$lease['acquired']) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'INTEGRATION_SYNC_ALREADY_RUNNING']], 409);
			}

			$result = $this->arbeitszeitCheckIntegration->runReconcile($lease['token'], 120);
			if ($result['ok'] === false) {
				$code = (string) ($result['code'] ?? 'INTEGRATION_SYNC_FAILED');
				$status = match ($code) {
					'INTEGRATION_SYNC_BREAKER_TRIPPED', 'INTEGRATION_SYNC_FAILED' => 503,
					default => 500,
				};
				return new DataResponse(['ok' => false, 'error' => ['code' => $code]], $status);
			}

			$this->config->setAppValue(Application::APP_ID, 'integration_at_manual_sync_cooldown', (string) $now);

			$hasLink = $this->access->hasActiveLinkedEmployee($userId);
			return new DataResponse([
				'ok' => true,
				'data' => [
					'reconcile' => $result,
					'integration' => array_merge(
						$this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLink),
						$this->integrationAdminContextExtras(),
					),
				],
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function integrationPurgeLegacyAbsences(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$now = $this->timeFactory->getTime();
			$lastPurge = (int) $this->config->getAppValue(Application::APP_ID, 'integration_at_purge_legacy_cooldown', '0');
			if ($lastPurge > 0 && ($now - $lastPurge) < self::INTEGRATION_PURGE_COOLDOWN_SEC) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'INTEGRATION_PURGE_THROTTLED']], 429);
			}
			$deleted = $this->arbeitszeitCheckIntegration->purgeLegacyAbsencesForLinkedEmployees($userId);
			if ($deleted > 0) {
				$this->config->setAppValue(Application::APP_ID, 'integration_at_purge_legacy_cooldown', (string) $now);
			}
			$hasLink = $this->access->hasActiveLinkedEmployee($userId);
			return new DataResponse([
				'ok' => true,
				'data' => [
					'deleted' => $deleted,
					'integration' => array_merge(
						$this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLink),
						$this->integrationAdminContextExtras(),
					),
				],
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

}

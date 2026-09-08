<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\Http\ApiMutationParams;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RotationSuggestService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\ShiftPreferenceService;
use OCA\DutyCheck\Service\SollDiagnosticsService;
use OCA\DutyCheck\Service\SwapService;
use OCA\DutyCheck\Service\TodayBoardService;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use Throwable;

/**
 * HTTP surface for Roster Self-Service GA (settings, today, patterns, suggest, prefs, blackouts).
 */
class SelfServiceApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private CompanyService $companies,
		private SelfServiceSettingsService $settings,
		private TodayBoardService $todayBoard,
		private RotationPatternService $patterns,
		private RotationSuggestService $suggest,
		private AvailabilityBlackoutService $blackouts,
		private ShiftPreferenceService $preferences,
		private PeerRosterService $peerRoster,
		private SwapService $swaps,
		private SollDiagnosticsService $sollDiagnostics,
		private IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function getSettings(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$companyId = $this->companies->writeCompanyIdFor($userId);
			$data = $this->settings->toApi($companyId);
			$data['companyId'] = $companyId;
			$data['sollFromDutyWarning'] = $this->sollLinkWarning($companyId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateSettings(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$companyId = $this->companies->writeCompanyIdFor($userId);
			$patch = ApiMutationParams::all($this->request);
			unset($patch['requesttoken'], $patch['_route']);
			$expectedRevision = null;
			if (isset($patch['settingsRevision']) && is_string($patch['settingsRevision'])) {
				$expectedRevision = trim($patch['settingsRevision']);
			}
			unset($patch['settingsRevision'], $patch['expectedRevision']);
			$this->settings->updateForCompany(
				$companyId,
				$patch,
				$userId,
				($expectedRevision !== null && $expectedRevision !== '') ? $expectedRevision : null,
			);
			$data = $this->settings->toApi($companyId);
			$data['companyId'] = $companyId;
			$data['sollFromDutyWarning'] = $this->sollLinkWarning($companyId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function acceptSwap(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$data = $this->swaps->acceptByCounterparty($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function teamWeek(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$locationId = (int) $this->request->getParam('locationId', 0);
			$weekStart = (string) $this->request->getParam('weekStart', '');
			$page = max(1, (int) $this->request->getParam('page', 1));
			$pageSize = max(1, min(50, (int) $this->request->getParam('pageSize', 50)));
			if ($locationId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
				throw new \InvalidArgumentException('INVALID_TEAM_WEEK_QUERY');
			}
			$data = $this->peerRoster->listTeamWeek($userId, $locationId, $weekStart, $page, $pageSize);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function teamLocations(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$locations = $this->peerRoster->listBelongingLocations($userId);
			return new DataResponse(['ok' => true, 'data' => ['locations' => $locations]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listEmployeeRotationAssignments(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$rows = $this->patterns->listEmployeeAssignments($id, $userId);
			return new DataResponse(['ok' => true, 'data' => ['assignments' => $rows]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function endRotationAssignment(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$validTo = (string) ($params['validTo'] ?? $params['valid_to'] ?? '');
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo)) {
				throw new \InvalidArgumentException('INVALID_VALID_TO');
			}
			$data = $this->patterns->endAssignment($id, $validTo, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function blackoutOverride(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$employeeId = (int) ($params['employeeId'] ?? $params['employee_id'] ?? 0);
			$blackoutId = isset($params['blackoutId']) ? (int) $params['blackoutId'] : (isset($params['blackout_id']) ? (int) $params['blackout_id'] : null);
			$reason = (string) ($params['reason'] ?? '');
			if ($employeeId < 1) {
				throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
			}
			$data = $this->blackouts->recordOverride($id, $blackoutId, $employeeId, $reason, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function sollDiagnostics(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$targetUser = $this->request->getParam('userId');
			$employeeIdRaw = $this->request->getParam('employeeId');
			$employeeId = is_numeric($employeeIdRaw) ? (int) $employeeIdRaw : null;
			$data = $this->sollDiagnostics->diagnose(
				$userId,
				is_string($targetUser) ? $targetUser : null,
				$employeeId,
			);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function todayBoard(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$locationId = (int) $this->request->getParam('locationId', 0);
			$date = (string) $this->request->getParam('date', '');
			if ($date === '') {
				$date = (new \DateTimeImmutable('today'))->format('Y-m-d');
			}
			$page = (int) $this->request->getParam('page', 1);
			$pageSize = (int) $this->request->getParam('pageSize', 200);
			$data = $this->todayBoard->getBoard($userId, $locationId, $date, $page, $pageSize);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listPatterns(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$companyId = $this->companies->writeCompanyIdFor($userId);
			return new DataResponse([
				'ok' => true,
				'data' => [
					'patterns' => $this->patterns->listPatterns($companyId, $userId),
					'allowedCycleWeeks' => $this->settings->allowedCycleWeeks($companyId),
					'rotationPatternsEnabled' => $this->settings->isRotationEnabled($companyId),
				],
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getPattern(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->patterns->getPattern($id, $userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createPattern(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$payload = ApiMutationParams::all($this->request);
			unset($payload['requesttoken'], $payload['_route']);
			if (!isset($payload['companyId']) && !isset($payload['company_id'])) {
				$payload['companyId'] = $this->companies->writeCompanyIdFor($userId);
			}
			$created = $this->patterns->createPattern($payload, $userId);
			return new DataResponse(['ok' => true, 'data' => $created]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updatePattern(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$payload = ApiMutationParams::all($this->request);
			unset($payload['requesttoken'], $payload['_route']);
			$updated = $this->patterns->updatePattern($id, $payload, $userId);
			return new DataResponse(['ok' => true, 'data' => $updated]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function assignPattern(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$employeeId = (int) ($params['employeeId'] ?? $params['employee_id'] ?? 0);
			$validFrom = (string) ($params['validFrom'] ?? $params['valid_from'] ?? '');
			$validToRaw = $params['validTo'] ?? $params['valid_to'] ?? null;
			$validTo = is_string($validToRaw) && $validToRaw !== '' ? $validToRaw : null;
			$supersede = filter_var($params['supersede'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$assignment = $this->patterns->assignToEmployee(
				$employeeId,
				$id,
				$validFrom,
				$validTo,
				$userId,
				$supersede,
			);
			return new DataResponse(['ok' => true, 'data' => $assignment]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function suggestPreview(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$employeeIds = $this->optionalIntList($params['employeeIds'] ?? $params['employee_ids'] ?? null);
			$locationId = isset($params['locationId']) || isset($params['location_id'])
				? (int) ($params['locationId'] ?? $params['location_id'])
				: null;
			if ($locationId !== null && $locationId < 1) {
				$locationId = null;
			}
			$data = $this->suggest->preview($id, $userId, $employeeIds, $locationId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function suggestConfirm(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$employeeIds = $this->optionalIntList($params['employeeIds'] ?? $params['employee_ids'] ?? null);
			$locationId = isset($params['locationId']) || isset($params['location_id'])
				? (int) ($params['locationId'] ?? $params['location_id'])
				: null;
			if ($locationId !== null && $locationId < 1) {
				$locationId = null;
			}
			$data = $this->suggest->confirm($id, $userId, $employeeIds, $locationId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myBlackouts(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$employeeId = $this->linkedEmployeeId($userId);
			$from = (string) $this->request->getParam('from', (new \DateTimeImmutable('today'))->format('Y-m-d'));
			$to = (string) $this->request->getParam('to', (new \DateTimeImmutable('today'))->modify('+90 days')->format('Y-m-d'));
			$rows = $this->blackouts->listForEmployee($employeeId, $from, $to, $userId);
			return new DataResponse(['ok' => true, 'data' => ['blackouts' => $rows, 'employeeId' => $employeeId]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createMyBlackout(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$employeeId = $this->linkedEmployeeId($userId);
			$params = ApiMutationParams::all($this->request);
			$startAt = (string) ($params['startAt'] ?? $params['start_at'] ?? '');
			$endAt = (string) ($params['endAt'] ?? $params['end_at'] ?? '');
			$label = (string) ($params['label'] ?? $params['labelEnum'] ?? 'personal');
			$locationRaw = $params['locationId'] ?? $params['location_id'] ?? null;
			$locationId = $locationRaw === null || $locationRaw === '' ? null : (int) $locationRaw;
			$row = $this->blackouts->create($employeeId, $startAt, $endAt, $label, $locationId, $userId);
			return new DataResponse(['ok' => true, 'data' => $row]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteMyBlackout(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$this->blackouts->delete($id, $userId);
			return new DataResponse(['ok' => true, 'data' => ['deleted' => true]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myPreferences(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$employeeId = $this->linkedEmployeeId($userId);
			$rows = $this->preferences->listForEmployee($employeeId, $userId);
			return new DataResponse(['ok' => true, 'data' => ['preferences' => $rows, 'employeeId' => $employeeId]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveMyPreference(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$employeeId = $this->linkedEmployeeId($userId);
			$payload = ApiMutationParams::all($this->request);
			unset($payload['requesttoken'], $payload['_route']);
			$row = $this->preferences->save($employeeId, $payload, $userId);
			return new DataResponse(['ok' => true, 'data' => $row]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteMyPreference(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$this->preferences->delete($id, $userId);
			return new DataResponse(['ok' => true, 'data' => ['deleted' => true]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deactivatePattern(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->patterns->deactivatePattern($id, $userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function employeePreferences(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$rows = $this->preferences->listForEmployee($id, $userId);
			return new DataResponse(['ok' => true, 'data' => ['preferences' => $rows]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function employeeBlackouts(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$from = (string) $this->request->getParam('from', (new \DateTimeImmutable('today'))->format('Y-m-d'));
			$to = (string) $this->request->getParam('to', (new \DateTimeImmutable('today'))->modify('+31 days')->format('Y-m-d'));
			$rows = $this->blackouts->listForEmployee($id, $from, $to, $userId);
			return new DataResponse(['ok' => true, 'data' => ['blackouts' => $rows]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	/**
	 * Planner roster enrichment: preference chips + blackout markers for visible employees.
	 */
	#[NoAdminRequired]
	public function rosterSignals(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$companyId = $this->companies->writeCompanyIdFor($userId);
			$rawIds = $this->request->getParam('employeeIds', []);
			$employeeIds = $this->optionalIntList($rawIds) ?? [];
			if (count($employeeIds) > 200) {
				$employeeIds = array_slice($employeeIds, 0, 200);
			}
			$from = (string) $this->request->getParam('from', '');
			$to = (string) $this->request->getParam('to', '');
			$prefs = [];
			$blackouts = [];
			if ($employeeIds !== [] && $this->settings->isPreferencesEnabled($companyId)) {
				try {
					$prefs = $this->preferences->listForCompanyEmployees($companyId, $employeeIds, $userId);
				} catch (Throwable) {
					$prefs = [];
				}
			}
			if ($employeeIds !== [] && $from !== '' && $to !== ''
				&& $this->settings->isBlackoutsEnabled($companyId)) {
				foreach ($employeeIds as $eid) {
					try {
						foreach ($this->blackouts->listForEmployee($eid, $from, $to, $userId) as $row) {
							$blackouts[] = $row;
						}
					} catch (Throwable) {
						// Feature off / no access — skip quietly.
					}
				}
			}
			return new DataResponse([
				'ok' => true,
				'data' => [
					'preferences' => $prefs,
					'blackouts' => $blackouts,
				],
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	private function sollLinkWarning(int $companyId): bool
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_employees')) {
			return true;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('linked_user_id'))
			->andWhere($qb->expr()->neq('linked_user_id', $qb->createNamedParameter('')));
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		return (int) $qb->executeQuery()->fetchOne() < 1;
	}

	private function linkedEmployeeId(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$id = $qb->executeQuery()->fetchOne();
		if ($id === false) {
			throw new \InvalidArgumentException('EMPLOYEE_LINK_NOT_FOUND');
		}
		return (int) $id;
	}

	/**
	 * @param mixed $raw
	 * @return list<int>|null
	 */
	private function optionalIntList(mixed $raw): ?array
	{
		if ($raw === null || $raw === '' || $raw === []) {
			return null;
		}
		if (!is_array($raw)) {
			$raw = preg_split('/\s*,\s*/', (string) $raw) ?: [];
		}
		$out = [];
		foreach ($raw as $v) {
			$n = (int) $v;
			if ($n > 0) {
				$out[] = $n;
			}
		}
		return $out === [] ? null : array_values(array_unique($out));
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Http\ApiMutationParams;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Integration\IntegrationOpsConstants;
use OCA\DutyCheck\Integration\MaintenanceCheckOnDutyReader;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\PlannerLocationScopeService;
use OCA\DutyCheck\Service\QualificationService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RosterMinutesExportService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCA\DutyCheck\Service\SnapshotRetentionService;
use OCA\DutyCheck\Service\SwapService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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
		private PlanningDefaultsService $planningDefaults,
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private ?ShiftTemplateService $templates = null,
		private ?ConflictPolicyService $conflictPolicy = null,
		private ?QualificationService $qualifications = null,
		private ?SwapService $swaps = null,
		private ?OpenShiftService $openShifts = null,
		private ?PlannerLocationScopeService $plannerScope = null,
		private ?SnapshotRetentionService $snapshotRetention = null,
		private ?MaintenanceCheckOnDutyReader $onDutyReader = null,
		private ?RosterMinutesExportService $rosterMinutesExport = null,
		private ?CompanyService $companies = null,
	) {
		parent::__construct($appName, $request);
	}

	private function templates(): ShiftTemplateService
	{
		return $this->templates ?? throw new \RuntimeException('TEMPLATES_UNAVAILABLE');
	}

	private function conflictPolicyService(): ConflictPolicyService
	{
		return $this->conflictPolicy ?? throw new \RuntimeException('POLICY_UNAVAILABLE');
	}

	private function qualificationsService(): QualificationService
	{
		return $this->qualifications ?? throw new \RuntimeException('QUALS_UNAVAILABLE');
	}

	private function swapsService(): SwapService
	{
		return $this->swaps ?? throw new \RuntimeException('SWAPS_UNAVAILABLE');
	}

	private function openShiftsService(): OpenShiftService
	{
		return $this->openShifts ?? throw new \RuntimeException('OPEN_SHIFTS_UNAVAILABLE');
	}

	#[NoAdminRequired]
	public function dashboard(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$userId = $this->access->currentUserId();
			return new DataResponse(['ok' => true, 'data' => $this->roster->dashboardSummary($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function roster(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$periodId = $this->request->getParam('periodId');
			$data = $this->roster->rosterData($periodId !== null ? (int) $periodId : null, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listPeriods(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => ['periods' => $this->roster->listPeriods($userId)]]);
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
			$this->roster->assertPeriodCompanyAccess($userId, $id);
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
			$this->roster->assertPeriodCompanyAccess($userId, $id);
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
			$this->roster->assertPeriodCompanyAccess($userId, $id);
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
			$this->roster->assertPeriodCompanyAccess($userId, $id);
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
			$this->roster->assertPeriodCompanyAccess($userId, $periodId);
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
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$locationId = (int) ($params['locationId'] ?? 0);
			if ($this->plannerScope !== null && $locationId > 0) {
				$this->plannerScope->assertCanPlanLocation($userId, $locationId);
			}
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
			], $userId);
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
	public function updateAssignment(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$existing = $this->roster->peekAssignment($id, $userId);
			$targetLocationId = isset($params['locationId']) && (int) $params['locationId'] > 0
				? (int) $params['locationId']
				: (int) $existing['locationId'];
			if ($this->plannerScope !== null) {
				$this->plannerScope->assertCanPlanLocation($userId, (int) $existing['locationId']);
				$this->plannerScope->assertCanPlanLocation($userId, $targetLocationId);
			}
			$data = $this->roster->updateAssignment($id, [
				'employeeId' => $params['employeeId'] ?? null,
				'locationId' => $params['locationId'] ?? null,
				'dutyDate' => $params['dutyDate'] ?? null,
				'startTime' => $params['startTime'] ?? null,
				'endTime' => $params['endTime'] ?? null,
				'breakMinutes' => $params['breakMinutes'] ?? null,
				'note' => $params['note'] ?? null,
				'expectedVersion' => $params['expectedVersion'] ?? ($params['version'] ?? null),
				'acknowledgements' => ApiMutationParams::acknowledgements($this->request),
			], $userId);
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
	public function cancelAssignment(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$row = $this->roster->peekAssignment($id, $userId);
			if ($this->plannerScope !== null) {
				$this->plannerScope->assertCanPlanLocation($userId, (int) $row['locationId']);
			}
			$data = $this->roster->cancelAssignment($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function acknowledgeAssignment(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$data = $this->roster->acknowledgeAssignment($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function periodAcknowledgeStats(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$this->roster->assertPeriodCompanyAccess($userId, $id);
			return new DataResponse(['ok' => true, 'data' => $this->roster->periodAcknowledgeStats($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function copyPeriod(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$sourceId = (int) ($params['sourcePeriodId'] ?? 0);
			$dryRun = filter_var($params['dryRun'] ?? true, FILTER_VALIDATE_BOOLEAN);
			$data = $this->roster->copyPeriodAssignments($sourceId, $id, $userId, $dryRun);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listTemplates(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$locationId = $this->request->getParam('locationId');
			$data = $this->templates()->list(
				$locationId !== null && $locationId !== '' ? (int) $locationId : null,
				true,
				$userId,
			);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createTemplate(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$data = $this->templates()->create($params, $userId);
			return new DataResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateTemplate(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$data = $this->templates()->update($id, $params, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteTemplate(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$this->templates()->delete($id, $userId);
			return new DataResponse(['ok' => true]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function conflictPolicy(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'data' => $this->conflictPolicyService()->get()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveConflictPolicy(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$data = $this->conflictPolicyService()->save($params, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listQualifications(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->qualificationsService()->listCatalog(true, $userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createQualification(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$data = $this->qualificationsService()->create($params, $userId);
			return new DataResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateQualification(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$data = $this->qualificationsService()->update($id, $params, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deactivateQualification(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$data = $this->qualificationsService()->deactivate($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listOpenShifts(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			// Middleware already requires canUseApp; employees claim, planners review.
			$periodId = $this->request->getParam('periodId');
			$data = $this->openShiftsService()->listOpen(
				$periodId !== null && $periodId !== '' ? (int) $periodId : null,
				$userId,
			);
			if ($this->plannerScope !== null && $this->access->isPlannerOrAdmin($userId) && !$this->access->isAppAdmin($userId)) {
				$allowed = $this->plannerScope->locationIdsFor($userId);
				if ($allowed !== []) {
					$data = array_values(array_filter(
						$data,
						static fn (array $row): bool => in_array((int) $row['locationId'], $allowed, true),
					));
				}
			}
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listPendingOpenShifts(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$periodId = $this->request->getParam('periodId');
			$data = $this->openShiftsService()->listPending(
				$periodId !== null && $periodId !== '' ? (int) $periodId : null,
				$userId,
			);
			if ($this->plannerScope !== null) {
				$allowed = $this->plannerScope->locationIdsFor($userId);
				if ($allowed !== []) {
					$data = array_values(array_filter(
						$data,
						static fn (array $row): bool => in_array((int) $row['locationId'], $allowed, true),
					));
				}
			}
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createOpenShift(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$locationId = (int) ($params['locationId'] ?? 0);
			if ($this->plannerScope !== null && $locationId > 0) {
				$this->plannerScope->assertCanPlanLocation($userId, $locationId);
			}
			$data = $this->openShiftsService()->create($params, $userId);
			return new DataResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function claimOpenShift(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$data = $this->openShiftsService()->claim($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function approveOpenShiftClaim(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$open = $this->openShiftsService()->getById($id);
			if ($this->plannerScope !== null) {
				$this->plannerScope->assertCanPlanLocation($userId, (int) $open['locationId']);
			}
			$data = $this->openShiftsService()->approveClaim(
				$id,
				$userId,
				ApiMutationParams::acknowledgements($this->request),
			);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function rejectOpenShiftClaim(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$open = $this->openShiftsService()->getById($id);
			if ($this->plannerScope !== null) {
				$this->plannerScope->assertCanPlanLocation($userId, (int) $open['locationId']);
			}
			$data = $this->openShiftsService()->rejectClaim($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $data]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listSwapRequests(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->swapsService()->listPending($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createSwapRequest(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			$params = ApiMutationParams::all($this->request);
			$to = $params['toEmployeeId'] ?? null;
			$data = $this->swapsService()->requestSwap(
				(int) ($params['assignmentId'] ?? 0),
				$userId,
				$to !== null && $to !== '' ? (int) $to : null,
				(string) ($params['reason'] ?? ''),
			);
			return new DataResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	/**
	 * Staff-safe colleague list for A↔B swap UI (names only; excludes self; company-scoped).
	 */
	#[NoAdminRequired]
	public function swapCandidates(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireEmployee($userId);
			return new DataResponse(['ok' => true, 'data' => $this->swapsService()->listSwapCandidates($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function reviewSwapRequest(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$data = $this->swapsService()->review(
				$id,
				$userId,
				(string) ($params['decision'] ?? ''),
				(string) ($params['reviewReason'] ?? ''),
			);
			return new DataResponse(['ok' => true, 'data' => $data]);
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
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->listAbsences($userId)]);
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
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->listEmployeeCatalog($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createEmployee(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			if (!$this->roster->isSchemaReady()) {
				return ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException('SCHEMA_NOT_READY'));
			}
			$params = ApiMutationParams::all($this->request);
			$data = $this->roster->createEmployee([
				'displayName' => $params['displayName'] ?? null,
				'linkedUserId' => $params['linkedUserId'] ?? null,
				'active' => $params['active'] ?? null,
			], $userId);
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
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			if (!$this->roster->isSchemaReady()) {
				return ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException('SCHEMA_NOT_READY'));
			}
			$params = ApiMutationParams::all($this->request);
			$data = $this->roster->updateEmployee($id, [
				'displayName' => $params['displayName'] ?? null,
				'linkedUserId' => $params['linkedUserId'] ?? null,
				'active' => $params['active'] ?? null,
			], $userId);
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
	public function listDutyRoles(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			return new DataResponse(['ok' => true, 'assignments' => $this->access->listDutyRoleAssignments()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function setDutyRole(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$params = ApiMutationParams::all($this->request);
			$assignments = $this->access->setDutyRole(
				(string)($params['userId'] ?? ''),
				(string)($params['role'] ?? ''),
			);
			return new DataResponse(['ok' => true, 'assignments' => $assignments]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 400);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function removeDutyRole(string $userId): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$assignments = $this->access->removeDutyRole($userId);
			return new DataResponse(['ok' => true, 'assignments' => $assignments]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['ok' => false, 'error' => ['code' => $e->getMessage()]], 400);
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
	public function planningDefaults(): DataResponse
	{
		try {
			// Planners need the org default for roster pre-fill; only saving requires app admin.
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());

			return new DataResponse(['ok' => true, 'planning' => $this->planningDefaults->toApi()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function savePlanningDefaults(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$params = ApiMutationParams::all($this->request);
			$this->planningDefaults->setFromPayload($params['defaultBreakMinutes'] ?? null);

			return new DataResponse(['ok' => true, 'planning' => $this->planningDefaults->toApi()]);
		} catch (\InvalidArgumentException $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function locations(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			return new DataResponse(['ok' => true, 'data' => $this->roster->listLocationCatalog($userId)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createLocation(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$data = $this->roster->createLocation([
				'name' => $this->request->getParam('name'),
				'timezone' => $this->request->getParam('timezone'),
				'active' => $this->request->getParam('active'),
			], $userId);
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
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$data = $this->roster->updateLocation($id, [
				'name' => $this->request->getParam('name'),
				'timezone' => $this->request->getParam('timezone'),
				'active' => $this->request->getParam('active'),
			], $userId);
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
	#[PublicPage]
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
			// Never distinguish missing employee vs bad token (enumeration oracle).
			$status = match ($e->getMessage()) {
				'RATE_LIMITED' => 429,
				default => 403,
			};
			$body = $status === 429 ? 'RATE_LIMITED' : 'ICAL_TOKEN_INVALID';
			return new DataDisplayResponse($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
		} catch (Throwable $e) {
			return new DataDisplayResponse('INTERNAL_ERROR', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
	}

	private function isSecureRequest(): bool
	{
		// Use Nextcloud's protocol resolution (honours trusted reverse proxies); avoid trusting raw client headers alone.
		return strtolower($this->request->getServerProtocol()) === 'https';
	}

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
			$reason = isset($params['reason']) ? (string) $params['reason'] : '';
			$this->arbeitszeitCheckIntegration->setIntentEnabled($enabled, $userId, $reason);
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

			if ($this->arbeitszeitCheckIntegration->isBreakerActive()) {
				$retryAfter = max(1, $this->arbeitszeitCheckIntegration->getBreakerRetryAfterSeconds());
				return new DataResponse(
					['ok' => false, 'error' => ['code' => 'INTEGRATION_SYNC_BREAKER_TRIPPED', 'retryAfter' => $retryAfter]],
					503,
					['Retry-After' => (string) $retryAfter],
				);
			}

			$rateLimiter = \OCP\Server::get(\OCA\DutyCheck\Integration\IntegrationSyncRateLimiter::class);
			$preRl = $rateLimiter->check($userId);
			if ($preRl['allowed'] !== true) {
				$retryAfter = max(1, (int) ($preRl['retryAfter'] ?? IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL));
				return new DataResponse(
					['ok' => false, 'error' => ['code' => 'INTEGRATION_SYNC_RATE_LIMIT', 'retryAfter' => $retryAfter]],
					429,
					['Retry-After' => (string) $retryAfter],
				);
			}

			$lease = $this->arbeitszeitCheckIntegration->acquireSyncLease(600);
			if (!$lease['acquired']) {
				return new DataResponse([
					'ok' => false,
					'error' => [
						'code' => 'INTEGRATION_SYNC_ALREADY_RUNNING',
						'startedAt' => $lease['startedAt'] ?? null,
					],
				], 202);
			}

			$rl = $rateLimiter->tryConsume($userId);
			if ($rl['allowed'] !== true) {
				$this->arbeitszeitCheckIntegration->releaseSyncLease((string) ($lease['token'] ?? ''));
				$retryAfter = max(1, (int) ($rl['retryAfter'] ?? IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL));
				return new DataResponse(
					['ok' => false, 'error' => ['code' => 'INTEGRATION_SYNC_RATE_LIMIT', 'retryAfter' => $retryAfter]],
					429,
					['Retry-After' => (string) $retryAfter],
				);
			}

			$result = $this->arbeitszeitCheckIntegration->runReconcile($lease['token'], 120);
			if ($result['ok'] === false) {
				$code = (string) ($result['code'] ?? 'INTEGRATION_SYNC_FAILED');
				$status = match ($code) {
					'INTEGRATION_SYNC_BREAKER_TRIPPED', 'INTEGRATION_SYNC_FAILED' => 503,
					default => 500,
				};
				$headers = [];
				if ($code === 'INTEGRATION_SYNC_BREAKER_TRIPPED') {
					$retryAfter = max(1, $this->arbeitszeitCheckIntegration->getBreakerRetryAfterSeconds());
					$headers['Retry-After'] = (string) $retryAfter;
				}
				return new DataResponse(['ok' => false, 'error' => ['code' => $code]], $status, $headers);
			}

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
	public function integrationSettings(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$params = $this->request->getParams();
			$changed = false;

			if (\array_key_exists('includePii', $params)) {
				$includePii = filter_var($params['includePii'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if ($includePii === null) {
					return new DataResponse(['ok' => false, 'error' => ['code' => 'INVALID_PARAMETER']], 400);
				}
				$justification = isset($params['piiJustification']) ? (string) $params['piiJustification'] : '';
				$this->arbeitszeitCheckIntegration->setIncludePii($includePii, $userId, $justification);
				$changed = true;
			}
			if (\array_key_exists('blockPublishWhenStale', $params)) {
				$block = filter_var($params['blockPublishWhenStale'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if ($block === null) {
					return new DataResponse(['ok' => false, 'error' => ['code' => 'INVALID_PARAMETER']], 400);
				}
				$this->arbeitszeitCheckIntegration->setBlockPublishWhenStale($block, $userId);
				$changed = true;
			}
			if (!$changed) {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'INVALID_PARAMETER']], 400);
			}

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

	#[NoAdminRequired]
	public function attachEmployeeQualification(int $id): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$params = ApiMutationParams::all($this->request);
			$qualId = (int) ($params['qualificationId'] ?? 0);
			$expires = isset($params['expiresOn']) ? (string) $params['expiresOn'] : null;
			if ($qualId <= 0) {
				throw new \InvalidArgumentException('QUALIFICATION_ID_REQUIRED');
			}
			$this->qualificationsService()->attachToEmployee($id, $qualId, $expires, $this->access->currentUserId());
			return new DataResponse(['ok' => true]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function detachEmployeeQualification(int $id, int $qualificationId): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			$this->qualificationsService()->detachFromEmployee(
				$id,
				$qualificationId,
				$this->access->currentUserId(),
			);
			return new DataResponse(['ok' => true]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function requireLocationQualification(int $id): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$params = ApiMutationParams::all($this->request);
			$qualId = (int) ($params['qualificationId'] ?? 0);
			if ($qualId <= 0) {
				throw new \InvalidArgumentException('QUALIFICATION_ID_REQUIRED');
			}
			$this->qualificationsService()->requireForLocation($id, $qualId, $userId);
			return new DataResponse(['ok' => true]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function plannerLocationScope(string $userId): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$scope = $this->plannerScope ?? throw new \RuntimeException('SCOPE_UNAVAILABLE');
			return new DataResponse(['ok' => true, 'data' => ['locationIds' => $scope->locationIdsFor($userId)]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function setPlannerLocationScope(string $userId): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$scope = $this->plannerScope ?? throw new \RuntimeException('SCOPE_UNAVAILABLE');
			$params = ApiMutationParams::all($this->request);
			$ids = $params['locationIds'] ?? [];
			if (!is_array($ids)) {
				throw new \InvalidArgumentException('INVALID_PARAMETER');
			}
			$scope->setScope($userId, array_map('intval', $ids));
			return new DataResponse(['ok' => true, 'data' => ['locationIds' => $scope->locationIdsFor($userId)]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function opsFlags(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$retention = $this->snapshotRetention ?? throw new \RuntimeException('RETENTION_UNAVAILABLE');
			return new DataResponse(['ok' => true, 'data' => [
				'thresholdApproachNotify' => $this->config->getAppValue(Application::APP_ID, 'threshold_approach_notify', '0') === '1',
				'thresholdApproachRatio' => (float) $this->config->getAppValue(Application::APP_ID, 'threshold_approach_ratio', '0.9'),
				'mcOnDutyHookEnabled' => $this->config->getAppValue(Application::APP_ID, 'mc_onduty_hook_enabled', '0') === '1',
				'snapshotRetentionDays' => $retention->retentionDays(),
				'hrRosterMinutesExportEnabled' => $this->config->getAppValue(Application::APP_ID, RosterMinutesExportService::CONFIG_ENABLED, '0') === '1',
			]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveOpsFlags(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$params = ApiMutationParams::all($this->request);
			if (array_key_exists('thresholdApproachNotify', $params)) {
				$on = filter_var($params['thresholdApproachNotify'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				$this->config->setAppValue(Application::APP_ID, 'threshold_approach_notify', $on ? '1' : '0');
			}
			if (array_key_exists('thresholdApproachRatio', $params)) {
				$ratio = (float) $params['thresholdApproachRatio'];
				$ratio = max(0.5, min(0.99, $ratio));
				$this->config->setAppValue(Application::APP_ID, 'threshold_approach_ratio', (string) $ratio);
			}
			if (array_key_exists('mcOnDutyHookEnabled', $params)) {
				$on = filter_var($params['mcOnDutyHookEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				$this->config->setAppValue(Application::APP_ID, 'mc_onduty_hook_enabled', $on ? '1' : '0');
			}
			if (array_key_exists('snapshotRetentionDays', $params)) {
				$days = max(0, min(3650, (int) $params['snapshotRetentionDays']));
				$this->config->setAppValue(Application::APP_ID, 'snapshot_retention_days', (string) $days);
			}
			if (array_key_exists('hrRosterMinutesExportEnabled', $params)) {
				$on = filter_var($params['hrRosterMinutesExportEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				$export = $this->rosterMinutesExport ?? throw new \RuntimeException('HR_EXPORT_UNAVAILABLE');
				$export->setEnabled($on === true, $this->access->currentUserId());
			}
			return $this->opsFlags();
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function exportRosterMinutesCsv(int $periodId): DataDownloadResponse|DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$this->roster->assertPeriodCompanyAccess($userId, $periodId);
			$export = $this->rosterMinutesExport ?? throw new \RuntimeException('HR_EXPORT_UNAVAILABLE');
			$csv = $export->toCsv($periodId);
			return new DataDownloadResponse($csv, 'dutycheck-roster-minutes-' . $periodId . '.csv', 'text/csv');
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function pruneSnapshots(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$retention = $this->snapshotRetention ?? throw new \RuntimeException('RETENTION_UNAVAILABLE');
			return new DataResponse(['ok' => true, 'data' => $retention->pruneExpired()]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function onDutyToday(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requirePlannerOrAdmin($userId);
			$reader = $this->onDutyReader ?? throw new \RuntimeException('ONDUTY_UNAVAILABLE');
			$day = $this->request->getParam('day');
			$dayStr = is_string($day) && $day !== '' ? $day : null;
			return new DataResponse(['ok' => true, 'data' => [
				'effective' => $reader->isEffective(),
				'assignments' => $reader->onDutyToday($dayStr, $userId),
			]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listCompanies(): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$svc = $this->companies ?? throw new \RuntimeException('COMPANIES_UNAVAILABLE');
			return new DataResponse(['ok' => true, 'data' => [
				'companies' => $svc->listCompanies(),
				'multiCompanyActive' => $svc->isMultiCompanyActive(),
			]]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createCompany(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->access->requireAppAdmin($userId);
			$svc = $this->companies ?? throw new \RuntimeException('COMPANIES_UNAVAILABLE');
			$params = ApiMutationParams::all($this->request);
			$data = $svc->createCompany((string) ($params['name'] ?? ''), $userId);
			return new DataResponse(['ok' => true, 'data' => $data], 201);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listCompanyMembers(int $id): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$svc = $this->companies ?? throw new \RuntimeException('COMPANIES_UNAVAILABLE');
			return new DataResponse(['ok' => true, 'data' => $svc->listMembers($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function addCompanyMember(int $id): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$svc = $this->companies ?? throw new \RuntimeException('COMPANIES_UNAVAILABLE');
			$params = ApiMutationParams::all($this->request);
			$userId = trim((string) ($params['userId'] ?? ''));
			$role = (string) ($params['role'] ?? 'member');
			if ($userId === '') {
				throw new \InvalidArgumentException('USER_ID_REQUIRED');
			}
			if ($id <= 0) {
				throw new \InvalidArgumentException('COMPANY_NOT_FOUND');
			}
			$svc->addMember($id, $userId, $role);
			return new DataResponse(['ok' => true, 'data' => $svc->listMembers($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function removeCompanyMember(int $id, string $userId): DataResponse
	{
		try {
			$this->access->requireAppAdmin($this->access->currentUserId());
			$svc = $this->companies ?? throw new \RuntimeException('COMPANIES_UNAVAILABLE');
			if ($id <= 0) {
				throw new \InvalidArgumentException('COMPANY_NOT_FOUND');
			}
			$svc->removeMember($id, $userId);
			return new DataResponse(['ok' => true, 'data' => $svc->listMembers($id)]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}

}

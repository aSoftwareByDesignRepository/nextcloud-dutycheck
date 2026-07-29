<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Throwable;

class ApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private RosterService $roster,
		private IArbeitszeitCheckIntegration $arbeitszeitCheckIntegration,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function bootstrap(): DataResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$includePlanner = $this->access->isPlannerOrAdmin($userId);
			$catalog = [
				'dashboard' => null,
				'roster' => null,
				'absences' => null,
				'myRoster' => null,
				'myAbsences' => null,
			];
			if ($includePlanner) {
				$catalog['dashboard'] = $this->roster->dashboardSummary($userId);
				$catalog['roster'] = $this->roster->rosterData(null, $userId);
				$catalog['absences'] = $this->roster->listAbsences($userId);
			}
			if ($this->access->hasActiveLinkedEmployee($userId)) {
				try {
					$catalog['myRoster'] = $this->roster->myRoster($userId);
					$catalog['myAbsences'] = $this->roster->myAbsences($userId);
				} catch (\InvalidArgumentException) {
					// No linked employee row — self-service APIs would fail the same way.
				}
			}

			$hasLink = $this->access->hasActiveLinkedEmployee($userId);

			return new DataResponse([
				'ok' => true,
				'data' => [
					'userId' => $userId,
					'isAppAdmin' => $this->access->isAppAdmin($userId),
					'isEmployee' => $this->access->isEmployee($userId),
					'isPlannerOrAdmin' => $includePlanner,
					'catalog' => $catalog,
					'arbeitszeitCheckIntegration' => $this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLink),
				],
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}

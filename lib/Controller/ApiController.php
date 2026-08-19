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
			$hasLink = $this->access->hasActiveLinkedEmployee($userId);
			// Identity + integration flags only. Pages load their own lists
			// (/api/dashboard, /api/roster, /api/my-roster). Hydrating a year of
			// assignments here made every absences tab-focus poll expensive.

			return new DataResponse([
				'ok' => true,
				'data' => [
					'userId' => $userId,
					'isAppAdmin' => $this->access->isAppAdmin($userId),
					'isEmployee' => $this->access->isEmployee($userId),
					'isPlannerOrAdmin' => $includePlanner,
					'catalog' => [
						'dashboard' => null,
						'roster' => null,
						'absences' => null,
						'myRoster' => null,
						'myAbsences' => null,
					],
					'arbeitszeitCheckIntegration' => $this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLink),
				],
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}

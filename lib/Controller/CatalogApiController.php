<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\TimezoneCatalog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Throwable;

class CatalogApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private TimezoneCatalog $timezoneCatalog,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function timezones(): DataResponse
	{
		try {
			$this->access->requirePlannerOrAdmin($this->access->currentUserId());
			return new DataResponse([
				'ok' => true,
				'data' => $this->timezoneCatalog->forApi(),
			]);
		} catch (Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCA\DutyCheck\Service\AccessControlService;
use OCP\AppFramework\Http\DataResponse;
use Throwable;

/**
 * Maps exceptions from access checks and roster validation to JSON {@see DataResponse} bodies.
 */
final class ApiJsonErrorResponse
{
	public static function fromThrowable(Throwable $e): DataResponse
	{
		if ($e instanceof AppAccessDeniedException) {
			$reason = $e->getDenialReason();
			$code = match ($reason) {
				AccessControlService::DENIAL_INSUFFICIENT_ROLE => 'INSUFFICIENT_ROLE',
				AccessControlService::DENIAL_EMPLOYEE_NOT_LINKED => 'EMPLOYEE_RECORD_LINK_REQUIRED',
				default => 'access_denied',
			};
			return new DataResponse(['ok' => false, 'error' => ['code' => $code]], 403);
		}
		if ($e instanceof ConflictAckRequiredException) {
			return new DataResponse([
				'ok' => false,
				'error' => [
					'code' => 'CONFLICT_ACK_REQUIRED',
					'conflicts' => $e->getConflicts(),
				],
			], 409);
		}
		if ($e instanceof IntegrationLegacyConflictException) {
			return new DataResponse([
				'ok' => false,
				'error' => [
					'code' => 'INTEGRATION_LEGACY_CONFLICT',
					'legacyAbsenceCount' => $e->getLegacyAbsenceCount(),
				],
			], 409);
		}
		if ($e instanceof \RuntimeException) {
			$msg = $e->getMessage();
			if ($msg === 'not_authenticated') {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'NOT_AUTHENTICATED']], 401);
			}
			if ($msg === 'access_denied') {
				return new DataResponse(['ok' => false, 'error' => ['code' => 'access_denied']], 403);
			}
			// Do not map arbitrary RuntimeExceptions to a role error — that masks server faults.
			return new DataResponse(['ok' => false, 'error' => ['code' => 'INTERNAL_ERROR']], 500);
		}
		if ($e instanceof \InvalidArgumentException) {
			$errorCode = $e->getMessage();
			$status = match ($errorCode) {
				'PERIOD_NOT_FOUND', 'ABSENCE_NOT_FOUND', 'EMPLOYEE_LINK_NOT_FOUND', 'CONFLICT_NOT_FOUND' => 404,
				'SNAPSHOT_HASH_MISMATCH' => 500,
				'PERIOD_NOT_OPEN', 'ASSIGNMENT_OVERLAP', 'ASSIGNMENT_DUPLICATE_SLOT', 'ABSENCE_CONFLICT', 'PERIOD_HAS_HARD_CONFLICTS', 'ABSENCE_OVERLAP' => 422,
				'REASON_TOO_SHORT', 'CONFLICT_RESOLVED' => 422,
				'INTEGRATION_ABSENCE_READONLY' => 403,
				'INTEGRATION_LEGACY_CONFLICT' => 409,
				'INTEGRATION_PURGE_BLOCKED' => 409,
				'INTEGRATION_PEER_NOT_INSTALLED', 'INTEGRATION_PEER_DISABLED', 'INTEGRATION_PEER_VERSION' => 400,
				default => 400,
			};
			return new DataResponse(['ok' => false, 'error' => ['code' => $errorCode]], $status);
		}

		return new DataResponse(['ok' => false, 'error' => ['code' => 'INTERNAL_ERROR']], 500);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCA\DutyCheck\Service\AccessControlService;
use OCP\AppFramework\Http\DataResponse;
use OCP\Server;
use Psr\Log\LoggerInterface;
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
			], 422);
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
			self::logUnexpected($e);
			return new DataResponse(['ok' => false, 'error' => ['code' => 'INTERNAL_ERROR']], 500);
		}
		if ($e instanceof \InvalidArgumentException) {
			$errorCode = $e->getMessage();
			return new DataResponse(
				['ok' => false, 'error' => ['code' => $errorCode]],
				self::statusForInvalidArgument($errorCode),
			);
		}

		self::logUnexpected($e);
		return new DataResponse(['ok' => false, 'error' => ['code' => 'INTERNAL_ERROR']], 500);
	}

	public static function statusForInvalidArgument(string $errorCode): int
	{
		return match ($errorCode) {
			'SCHEMA_NOT_READY' => 503,
			'PERIOD_NOT_FOUND', 'ABSENCE_NOT_FOUND', 'EMPLOYEE_LINK_NOT_FOUND', 'CONFLICT_NOT_FOUND', 'EMPLOYEE_NOT_FOUND', 'ASSIGNMENT_NOT_FOUND', 'TEMPLATE_NOT_FOUND', 'SWAP_NOT_FOUND', 'OPEN_SHIFT_NOT_FOUND' => 404,
			'SNAPSHOT_HASH_MISMATCH' => 500,
			'PERIOD_NOT_OPEN', 'PERIOD_NOT_PUBLISHED', 'ASSIGNMENT_CANCELLED', 'ASSIGNMENT_OVERLAP', 'SWAP_CONFLICT', 'SWAP_ALREADY_PENDING', 'OPEN_SHIFT_CONFLICT', 'OPEN_SHIFT_NOT_OPEN', 'ASSIGNMENT_DUPLICATE_SLOT', 'ABSENCE_CONFLICT', 'PERIOD_HAS_HARD_CONFLICTS', 'ABSENCE_OVERLAP', 'EQUAL_DUTY_TIMES', 'INVALID_SHIFT_LENGTH', 'DATE_OUTSIDE_PERIOD', 'QUALIFICATION_MISSING', 'QUALIFICATION_NAME_INVALID', 'QUALIFICATION_NAME_EXISTS', 'QUALIFICATION_NOT_FOUND', 'EMPLOYEE_QUALIFICATION_NOT_FOUND', 'INVALID_EXPIRY_DATE', 'INVALID_CONFLICT_POLICY', 'TEMPLATE_NAME_INVALID' => 422,
			'REASON_TOO_SHORT', 'CONFLICT_RESOLVED' => 422,
			'PERIOD_STATUS_CONFLICT', 'ABSENCE_STATUS_CONFLICT', 'STALE_VERSION', 'ASSIGNMENT_TRANSFER_STALE' => 409,
			'EXPECTED_VERSION_REQUIRED' => 422,
			'FORBIDDEN', 'COMPANY_MISMATCH' => 403,
			'INTEGRATION_ABSENCE_READONLY' => 403,
			'INTEGRATION_LEGACY_CONFLICT' => 422,
			'INTEGRATION_AT_UNKNOWN_ENUM' => 422,
			'INTEGRATION_PURGE_BLOCKED' => 409,
			'INTEGRATION_DETECTION_FLAPPING' => 409,
			'INTEGRATION_PII_JUSTIFICATION_REQUIRED' => 400,
			'INTEGRATION_PEER_NOT_INSTALLED', 'INTEGRATION_PEER_DISABLED', 'INTEGRATION_PEER_VERSION' => 400,
			'INTEGRATION_PUBLISH_STALE' => 409,
			default => 400,
		};
	}

	private static function logUnexpected(Throwable $e): void
	{
		try {
			$logger = Server::get(LoggerInterface::class);
			$logger->error(
				'DutyCheck API unhandled error: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'exception' => $e,
				],
			);
		} catch (Throwable) {
			// Logging must never break the JSON error response.
		}
	}
}

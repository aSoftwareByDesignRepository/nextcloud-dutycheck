<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use DateTimeImmutable;

/**
 * Peer detection, mirror reconcile, bootstrap JSON, and conflict helpers.
 * Constants (peer app id, routes, min version) live on {@see ArbeitszeitCheckIntegrationService}.
 */
interface IArbeitszeitCheckIntegration
{
	public function getIntentEnabled(): bool;

	public function setIntentEnabled(bool $enabled, string $actorUid): void;

	public function getPeerInstalled(): bool;

	public function getPeerEnabled(): bool;

	public function getPeerVersionString(): ?string;

	public function getPeerVersionOk(): bool;

	public function isEffective(): bool;

	/**
	 * When true, DutyCheck must not create absences for employees with a linked account
	 * (ArbeitszeitCheck is the source of truth). True while integration is fully effective,
	 * or while integration intent is on and the peer app is ready — including breaker or stale mirror windows.
	 */
	public function integrationLocksLinkedDutyCheckAbsences(): bool;

	public function isBreakerActive(): bool;

	public function getTStaleSeconds(): int;

	public function getLastReconcileAt(): ?DateTimeImmutable;

	public function isStale(): bool;

	/**
	 * @return array{active: bool, startedAt: mixed}
	 */
	public function getSyncLeaseInfo(): array;

	/**
	 * @return array{acquired: bool, token?: string, message?: string}
	 */
	public function acquireSyncLease(int $ttlSeconds = 600): array;

	public function releaseSyncLease(string $token): void;

	/**
	 * @return array{ok: bool, code: string, rows?: int}
	 */
	public function runReconcile(string $leaseToken, ?int $maxWallSeconds = null): array;

	public function countLegacyAbsencesForLinkedEmployees(): int;

	public function countLegacyAbsencesForEmployee(int $employeeId): int;

	/**
	 * Hard-delete legacy DutyCheck absence rows for active linked employees so integration can be enabled.
	 * Refused while integration intent is on or integration is effective.
	 *
	 * @throws \InvalidArgumentException message `INTEGRATION_PURGE_BLOCKED` when not allowed
	 */
	public function purgeLegacyAbsencesForLinkedEmployees(string $actorUid): int;

	public function getPlannerOutboundUrl(): ?string;

	public function getEmployeeOutboundUrl(): ?string;

	/**
	 * @return array<string, mixed>
	 */
	public function buildBootstrapForUser(string $_userId, bool $readonlyAbsencesForCurrentUser): array;

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForPlanner(): array;

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForEmployee(string $linkedUserId): array;

	public function hasImportedBlockingAbsenceOnDate(int $employeeId, string $dateYmd): bool;

	/**
	 * @param list<string> $dutyStatuses
	 */
	public function mirrorOverlapsEmployeeRange(int $employeeId, string $startDate, string $endDate, array $dutyStatuses, ?int $ignoreAtAbsenceId = null): bool;

	/**
	 * @return array<string, mixed>
	 */
	public function getAdminIntegrationStatus(): array;
}

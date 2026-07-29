<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use DateTimeImmutable;

/**
 * Peer detection, mirror reconcile, bootstrap JSON, and conflict helpers.
 * Constants (peer app id, routes, min version) live on {@see ArbeitszeitCheckIntegrationService}
 * and {@see IntegrationOpsConstants}.
 */
interface IArbeitszeitCheckIntegration
{
	public function getIntentEnabled(): bool;

	public function setIntentEnabled(bool $enabled, string $actorUid, string $disableReason = ''): void;

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

	/**
	 * Seconds until the circuit breaker opens for Sync again (0 when not tripped).
	 */
	public function getBreakerRetryAfterSeconds(): int;

	public function getTStaleSeconds(): int;

	public function getTStalePublishBlockSeconds(): int;

	public function getLastReconcileAt(): ?DateTimeImmutable;

	public function isStale(): bool;

	public function getIncludePii(): bool;

	public function setIncludePii(bool $enabled, string $actorUid, string $justification = ''): void;

	public function getBlockPublishWhenStale(): bool;

	public function setBlockPublishWhenStale(bool $enabled, string $actorUid): void;

	public function shouldBlockPublishForStale(): bool;

	/**
	 * @return array{active: bool, startedAt: mixed}
	 */
	public function getSyncLeaseInfo(): array;

	/**
	 * @return array{acquired: bool, token?: string, message?: string, startedAt?: mixed}
	 */
	public function acquireSyncLease(int $ttlSeconds = 600): array;

	public function releaseSyncLease(string $token): void;

	/**
	 * @return array{ok: bool, code: string, rows?: int, complete?: bool}
	 */
	public function runReconcile(string $leaseToken, ?int $maxWallSeconds = null, ?string $sinceYmd = null, ?string $onlyUserId = null): array;

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
	 * @param list<int>|null $allowedCompanyIds null = unrestricted; non-empty = SQL company filter
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForPlanner(?array $allowedCompanyIds = null): array;

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForEmployee(string $linkedUserId): array;

	/**
	 * T1/T2 mirror rows overlapping a period window for WF-23 publish snapshots (never T3).
	 *
	 * @param int|null $companyId when set, only employees in that company
	 * @return list<array{atAbsenceId:int,employeeId:int,type:string,status:string,startDate:string,endDate:string,source:string}>
	 */
	public function listImportedAbsencesForPeriodSnapshot(string $periodStartYmd, string $periodEndYmd, ?int $companyId = null): array;

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

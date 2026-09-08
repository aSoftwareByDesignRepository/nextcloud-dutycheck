<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service\Contract;

use DateTimeImmutable;

/**
 * Read-only Soll resolution for ArbeitszeitCheck (in-process only).
 *
 * Registered as `dutycheck.effective_target_hours_facade`.
 * AZC must never SQL-read dc_* tables (COMPOSABILITY / Argus).
 */
interface EffectiveTargetHoursFacade
{
	public function getFacadeVersion(): int;

	/** G1 — rotation patterns master switch for the org of the linked employee. */
	public function isRotationEnabledForOrg(?string $ncUserId = null): bool;

	/**
	 * Duty `soll_from_duty` + G1 — AZC must also have its own G2 flag before consuming.
	 */
	public function isSollFromDutyEnabledForUser(string $ncUserId): bool;

	public function getWeekTarget(string $ncUserId, DateTimeImmutable $isoWeekStart): ?TargetHoursWeekDto;

	public function getDayTarget(string $ncUserId, DateTimeImmutable $date): ?TargetHoursDayDto;

	/**
	 * Sum of day targets Mon–Sun; null when v2 not effective for user.
	 */
	public function getWeekTargetMinutes(string $ncUserId, DateTimeImmutable $isoWeekStart): ?int;
}

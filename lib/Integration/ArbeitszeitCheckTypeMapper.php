<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

/**
 * Maps ArbeitszeitCheck absence types/statuses to DutyCheck semantics.
 *
 * @see planning/app-ideas/dutycheck/arbeitszeitcheck-integration.md
 */
final class ArbeitszeitCheckTypeMapper
{
	/** @var list<string> */
	public const AT_BLOCKING_TYPES = [
		'vacation',
		'sick_leave',
		'unpaid_leave',
		'personal_leave',
		'parental_leave',
		'special_leave',
	];

	/** @var list<string> */
	public const AT_SOFT_TYPES_DEFAULT = [
		'home_office',
		'business_trip',
	];

	/** @var list<string> */
	public const AT_KNOWN_STATUSES = [
		'pending',
		'substitute_pending',
		'approved',
		'rejected',
		'cancelled',
		'substitute_declined',
	];

	public static function isKnownType(string $atType): bool
	{
		return in_array($atType, self::AT_BLOCKING_TYPES, true)
			|| in_array($atType, self::AT_SOFT_TYPES_DEFAULT, true);
	}

	public static function isKnownStatus(string $atStatus): bool
	{
		return in_array($atStatus, self::AT_KNOWN_STATUSES, true);
	}

	/**
	 * DutyCheck `kind` for planner UI (matches existing absence kinds where possible).
	 */
	public static function toDutyKind(string $atType): string
	{
		return match ($atType) {
			'vacation' => 'vacation',
			'sick_leave' => 'sick',
			'unpaid_leave' => 'unpaid',
			'personal_leave', 'parental_leave', 'special_leave' => 'other',
			'home_office', 'business_trip' => 'other',
			default => 'other',
		};
	}

	/**
	 * DutyCheck workflow status for display (pending/approved/rejected/cancelled).
	 * Unknown AT statuses map to cancelled for display so they never look like active leave.
	 */
	public static function toDutyStatus(string $atStatus): string
	{
		return match ($atStatus) {
			'pending', 'substitute_pending' => 'pending',
			'approved' => 'approved',
			'rejected' => 'rejected',
			'cancelled', 'substitute_declined' => 'cancelled',
			// Unknown: non-blocking display — never pretend this is pending/approved leave.
			default => 'cancelled',
		};
	}

	/**
	 * Hard roster conflict: approved absence that blocks assignments on a calendar day.
	 */
	public static function isBlockingApproved(string $atType, string $atStatus): bool
	{
		if ($atStatus !== 'approved') {
			return false;
		}
		if (in_array($atType, self::AT_SOFT_TYPES_DEFAULT, true)) {
			return false;
		}
		if (in_array($atType, self::AT_BLOCKING_TYPES, true)) {
			return true;
		}
		// Unknown type: non-blocking (caller must log INTEGRATION_AT_UNKNOWN_ENUM).
		return false;
	}

	/**
	 * Overlap check against dc_absences statuses pending|approved — mirror equivalents.
	 * Unknown AT statuses never overlap (non-blocking).
	 *
	 * @param list<string> $dutyStatuses
	 */
	public static function atStatusOverlapsDutyStatuses(string $atStatus, array $dutyStatuses): bool
	{
		if (!self::isKnownStatus($atStatus)) {
			return false;
		}
		$duty = self::toDutyStatus($atStatus);
		foreach ($dutyStatuses as $s) {
			if ($duty === $s) {
				return true;
			}
		}
		return false;
	}
}

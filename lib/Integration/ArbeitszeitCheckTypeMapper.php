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
	/**
	 * AZC reserves the `custom_` namespace for admin-defined absence types
	 * (ArbeitszeitCheck `CustomAbsenceType::CODE_PREFIX`; duplicated here —
	 * no cross-app PHP dependency per §15.2-style decoupling).
	 */
	public const AT_CUSTOM_TYPE_PREFIX = 'custom_';

	/** @var list<string> */
	public const AT_BLOCKING_TYPES = [
		'vacation',
		'sick_leave',
		'unpaid_leave',
		'personal_leave',
		'parental_leave',
		'special_leave',
		// AZC ≥1.9 built-ins — both are real absences (employee away).
		'child_sick',
		'overtime_comp',
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
			|| in_array($atType, self::AT_SOFT_TYPES_DEFAULT, true)
			|| str_starts_with($atType, self::AT_CUSTOM_TYPE_PREFIX);
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
			// Child sick leave is carer's absence, not the employee's own
			// illness — but for planning the nearest DC kind is still `sick`.
			'sick_leave', 'child_sick' => 'sick',
			'unpaid_leave' => 'unpaid',
			'personal_leave', 'parental_leave', 'special_leave' => 'other',
			'home_office', 'business_trip', 'overtime_comp' => 'other',
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
		// Admin-defined custom absence types are absences by definition — the
		// only "working remotely" semantics in AZC are the built-in soft types
		// and the per-entry work_location field (not part of at_absences).
		// Under-blocking would silently roster a person who is away, so the
		// reserved `custom_*` namespace blocks when approved.
		if (str_starts_with($atType, self::AT_CUSTOM_TYPE_PREFIX)) {
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

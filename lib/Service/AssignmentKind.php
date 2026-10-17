<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

/**
 * Canonical assignment kind vocabulary.
 *
 * `shift` is a worked duty that produces working-time minutes;
 * `standby` (Rufbereitschaft) is planning context only and must never feed
 * minute totals, rest-time rules, payroll exports, or target-hour math.
 */
final class AssignmentKind
{
	public const SHIFT = 'shift';
	public const STANDBY = 'standby';

	public const ALL = [self::SHIFT, self::STANDBY];

	public static function isValid(mixed $kind): bool
	{
		return is_string($kind) && in_array($kind, self::ALL, true);
	}

	/**
	 * Normalize an API payload value. Absent/null/empty defaults to shift;
	 * anything else must validate via {@see isValid()} first.
	 */
	public static function normalize(mixed $raw): string
	{
		if ($raw === null || $raw === '') {
			return self::SHIFT;
		}
		if (!is_string($raw) || !self::isValid(trim($raw))) {
			throw new \InvalidArgumentException('INVALID_KIND');
		}
		return trim($raw);
	}

	public static function isStandby(mixed $kind): bool
	{
		return $kind === self::STANDBY;
	}
}

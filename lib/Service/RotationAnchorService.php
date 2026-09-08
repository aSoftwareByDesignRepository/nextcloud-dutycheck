<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;

/**
 * Maps calendar dates → pattern week_index using ISO parity or fixed-date anchors.
 * Single shared implementation for suggest-fill, facade, and Planwoche labels.
 */
final class RotationAnchorService
{
	public const ANCHOR_ISO_WEEK_PARITY = 'iso_week_parity';
	public const ANCHOR_FIXED_DATE = 'fixed_date';

	/**
	 * @param array{
	 *   cycle_weeks?:int,
	 *   cycleWeeks?:int,
	 *   anchor_type?:string,
	 *   anchorType?:string,
	 *   anchor_iso_week_index?:?int,
	 *   anchorIsoWeekIndex?:?int,
	 *   anchor_date?:?string,
	 *   anchorDate?:?string,
	 *   anchor_effective_from?:?string,
	 *   anchorEffectiveFrom?:?string
	 * } $pattern
	 */
	public function weekIndexForDate(array $pattern, DateTimeImmutable $date): int
	{
		$cycle = max(1, (int) ($pattern['cycle_weeks'] ?? $pattern['cycleWeeks'] ?? 2));
		$anchorType = (string) ($pattern['anchor_type'] ?? $pattern['anchorType'] ?? self::ANCHOR_ISO_WEEK_PARITY);
		$effectiveFrom = $pattern['anchor_effective_from'] ?? $pattern['anchorEffectiveFrom'] ?? null;

		// Forward-only: before effective_from, treat as prior mapping (iso parity default).
		if (is_string($effectiveFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) === 1) {
			$from = new DateTimeImmutable($effectiveFrom . ' 00:00:00');
			if ($date < $from && $anchorType === self::ANCHOR_FIXED_DATE) {
				$anchorType = self::ANCHOR_ISO_WEEK_PARITY;
			}
		}

		if ($anchorType === self::ANCHOR_FIXED_DATE) {
			$anchorDateRaw = $pattern['anchor_date'] ?? $pattern['anchorDate'] ?? null;
			if (!is_string($anchorDateRaw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorDateRaw) !== 1) {
				throw new \InvalidArgumentException('INVALID_ANCHOR_DATE');
			}
			$anchor = new DateTimeImmutable($anchorDateRaw . ' 00:00:00');
			$anchorMonday = $this->isoMonday($anchor);
			$dateMonday = $this->isoMonday($date);
			$diffDays = (int) $anchorMonday->diff($dateMonday)->format('%r%a');
			$weeks = intdiv($diffDays, 7);
			// PHP % can be negative; normalize into [0, cycle).
			return (($weeks % $cycle) + $cycle) % $cycle;
		}

		// ISO week parity (default): ISO week number mod N.
		// anchor_iso_week_index: which residue maps to week_index 0 (default 1 = odd KW → 0 for N=2).
		$isoWeek = (int) $date->format('W');
		$anchorResidue = $pattern['anchor_iso_week_index'] ?? $pattern['anchorIsoWeekIndex'] ?? null;
		if ($anchorResidue === null) {
			$anchorResidue = ($cycle === 2) ? 1 : 0; // odd→0 for biweekly default
		}
		$anchorResidue = ((int) $anchorResidue % $cycle + $cycle) % $cycle;
		$residue = (($isoWeek % $cycle) + $cycle) % $cycle;
		return (($residue - $anchorResidue) % $cycle + $cycle) % $cycle;
	}

	/**
	 * Human Planwoche label (DE/EN-agnostic structure; callers translate wrapper).
	 */
	public function weekLabel(int $weekIndex, int $cycleWeeks, ?int $isoWeek = null): string
	{
		$n = max(1, $cycleWeeks);
		$idx = max(0, $weekIndex);
		$letter = chr(ord('A') + ($idx % 26));
		if ($n === 2 && $isoWeek !== null) {
			$parity = ($isoWeek % 2 === 1) ? 'odd' : 'even';
			return sprintf('Planwoche %s (%s KW)', $letter, $parity);
		}
		return sprintf('Planwoche %s (%d/%d)', $letter, $idx + 1, $n);
	}

	public function isoMonday(DateTimeImmutable $date): DateTimeImmutable
	{
		$dow = (int) $date->format('N'); // 1=Mon .. 7=Sun
		return $date->setTime(0, 0, 0)->modify('-' . ($dow - 1) . ' days');
	}

	public function assertCycleWeeksAllowed(int $cycleWeeks, array $allowed): void
	{
		if (!in_array($cycleWeeks, $allowed, true)) {
			throw new \InvalidArgumentException('CYCLE_WEEKS_UNSUPPORTED');
		}
		if ($cycleWeeks < 1 || $cycleWeeks > 8) {
			throw new \InvalidArgumentException('CYCLE_WEEKS_UNSUPPORTED');
		}
	}
}

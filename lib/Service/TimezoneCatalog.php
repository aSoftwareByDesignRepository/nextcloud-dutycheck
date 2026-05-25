<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

/**
 * Single source of truth for IANA timezone identifiers in DutyCheck.
 *
 * The UI loads {@see grouped()} and {@see pinned()} via the catalog API so planners
 * can search the full PHP timezone database (~430 zones) without embedding every
 * option in HTML. {@see isValid()} uses the same underlying list as location
 * create/update validation.
 */
class TimezoneCatalog
{
	/**
	 * Frequently chosen zones surfaced at the top of the searchable picker.
	 * Every entry must be a valid IANA name (enforced in unit tests).
	 *
	 * @var list<string>
	 */
	private const PINNED = [
		'UTC',
		'Europe/Berlin',
		'Europe/London',
		'Europe/Paris',
		'Europe/Moscow',
		'America/New_York',
		'America/Chicago',
		'America/Los_Angeles',
		'Asia/Dubai',
		'Asia/Kolkata',
		'Asia/Singapore',
		'Asia/Tashkent',
		'Asia/Tokyo',
		'Asia/Yekaterinburg',
		'Australia/Sydney',
	];

	/** @var list<string>|null */
	private ?array $allCache = null;

	/** @var list<array{label:string, items:list<string>}>|null */
	private ?array $groupedCache = null;

	/**
	 * @return list<string>
	 */
	public function all(): array
	{
		if ($this->allCache === null) {
			$this->allCache = \DateTimeZone::listIdentifiers();
		}
		return $this->allCache;
	}

	/**
	 * Pinned identifiers for quick selection (subset of {@see all()}).
	 *
	 * @return list<string>
	 */
	public function pinned(): array
	{
		$valid = array_fill_keys($this->all(), true);
		$out = [];
		foreach (self::PINNED as $tz) {
			if (isset($valid[$tz])) {
				$out[] = $tz;
			}
		}
		return $out;
	}

	/**
	 * Full IANA list grouped by region prefix for optgroup-style UI sections.
	 *
	 * Returns a numerically indexed list so JSON encodes as `[{label,items},…]`
	 * (associative maps would become `{}` and break the frontend).
	 *
	 * @return list<array{label:string, items:list<string>}>
	 */
	public function grouped(): array
	{
		if ($this->groupedCache !== null) {
			return $this->groupedCache;
		}

		/** @var array<string, list<string>> $map */
		$map = [];
		foreach ($this->all() as $identifier) {
			$label = $this->regionLabel($identifier);
			$map[$label][] = $identifier;
		}
		ksort($map, SORT_STRING);
		$out = [];
		foreach ($map as $label => $items) {
			sort($items, SORT_STRING);
			$out[] = ['label' => $label, 'items' => $items];
		}
		$this->groupedCache = $out;
		return $out;
	}

	/**
	 * Payload for GET /api/catalog/timezones.
	 *
	 * @return array{pinned:list<string>, groups:list<array{label:string, items:list<string>}>}
	 */
	public function forApi(): array
	{
		return [
			'pinned' => $this->pinned(),
			'groups' => $this->grouped(),
		];
	}

	public function isValid(string $timezone): bool
	{
		$trimmed = trim($timezone);
		if ($trimmed === '') {
			return false;
		}
		return in_array($trimmed, $this->all(), true);
	}

	public function normalizeOrThrow(string $timezone): string
	{
		$trimmed = trim($timezone);
		if (!$this->isValid($trimmed)) {
			throw new \InvalidArgumentException('INVALID_TIMEZONE');
		}
		return $trimmed;
	}

	private function regionLabel(string $identifier): string
	{
		if ($identifier === 'UTC') {
			return 'UTC';
		}
		$slash = strpos($identifier, '/');
		if ($slash === false) {
			return 'Other';
		}
		return substr($identifier, 0, $slash);
	}
}

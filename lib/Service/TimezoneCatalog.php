<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

class TimezoneCatalog
{
	/**
	 * @return list<string>
	 */
	public function all(): array
	{
		return \DateTimeZone::listIdentifiers();
	}

	public function isValid(string $timezone): bool
	{
		return in_array($timezone, $this->all(), true);
	}
}

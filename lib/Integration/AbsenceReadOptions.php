<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

/**
 * Options for {@see IArbeitszeitCheckAbsenceReader::listAbsencesOverlapping}.
 */
final class AbsenceReadOptions
{
	public function __construct(
		public readonly bool $includePii = false,
	) {
	}
}

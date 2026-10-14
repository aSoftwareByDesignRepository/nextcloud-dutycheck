<?php

declare(strict_types=1);

namespace OCA\DutyCheck;

use OCP\Capabilities\IPublicCapability;
use OCP\IURLGenerator;

/**
 * Suite flange (F17): compatible Check-family apps capability-detect
 * `check.on-duty` before pulling the read-only on-duty feed — same
 * pattern as tourcheck's `check.drive-segments`. Versioned so the
 * contract can evolve without silent consumer breakage.
 */
class Capabilities implements IPublicCapability
{
	public function __construct(private IURLGenerator $urlGenerator)
	{
	}

	public function getCapabilities(): array
	{
		return [
			'check' => [
				'on-duty' => [
					'version' => 1,
					'endpoint' => $this->urlGenerator->linkToRoute('dutycheck.rosterApi.onDutyToday'),
				],
			],
		];
	}
}

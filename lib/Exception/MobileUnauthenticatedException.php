<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Exception;

/**
 * HTTP 401 — companion /api/mobile/* requires Basic app-password (no cookie CSRF).
 */
class MobileUnauthenticatedException extends \RuntimeException
{
	public function __construct()
	{
		parent::__construct('UNAUTHENTICATED');
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Exception;

/**
 * HTTP 402 — companion seat / license required (Basic /api/mobile/* only).
 * Cookie callers never reach this gate — they are rejected as 401 first.
 */
class PaymentRequiredException extends \RuntimeException
{
	public function __construct(string $code = 'LICENSE_REQUIRED')
	{
		parent::__construct($code);
	}

	public function getErrorCode(): string
	{
		return $this->getMessage() !== '' ? $this->getMessage() : 'LICENSE_REQUIRED';
	}
}

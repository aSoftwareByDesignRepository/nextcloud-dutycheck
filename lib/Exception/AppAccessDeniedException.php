<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Exception;

use OCP\AppFramework\Http;

class AppAccessDeniedException extends \RuntimeException
{
	public function __construct(private string $denialReason = 'no_access')
	{
		parent::__construct('access_denied', Http::STATUS_FORBIDDEN);
	}

	public function getDenialReason(): string
	{
		return $this->denialReason;
	}
}

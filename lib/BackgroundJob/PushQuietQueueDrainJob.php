<?php

declare(strict_types=1);

namespace OCA\DutyCheck\BackgroundJob;

use OCA\DutyCheck\Service\PushQuietHoursService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains deferred Duty push notifications after quiet hours end (every 5 minutes).
 */
class PushQuietQueueDrainJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly PushQuietHoursService $quiet,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		try {
			$this->quiet->drainDue(50);
		} catch (Throwable $e) {
			$this->logger->warning('DutyCheck quiet queue drain failed', [
				'app' => 'dutycheck',
				'exception' => $e,
			]);
		}
	}
}

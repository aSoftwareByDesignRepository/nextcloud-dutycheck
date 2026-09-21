<?php

declare(strict_types=1);

namespace OCA\DutyCheck\BackgroundJob;

use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains dc_periods.conflicts_dirty for open periods (every 5 minutes).
 *
 * Sync Save/Apply rematerializes a budget immediately; this job finishes the
 * remainder. Poor-man's cron still benefits when someone is active; correctness
 * also has lazy roster GET for a dirty selected period.
 */
class ConflictDirtyRematerializeJob extends TimedJob
{
	public const DRAIN_BATCH = 20;

	public function __construct(
		ITimeFactory $time,
		private readonly RosterService $roster,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		try {
			$this->roster->drainDirtyOpenPeriodConflicts(self::DRAIN_BATCH);
		} catch (Throwable $e) {
			$this->logger->warning('DutyCheck conflict dirty rematerialize failed', [
				'app' => 'dutycheck',
				'exception' => $e,
			]);
		}
	}
}

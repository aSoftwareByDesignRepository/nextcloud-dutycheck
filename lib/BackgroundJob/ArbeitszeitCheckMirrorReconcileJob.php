<?php

declare(strict_types=1);

namespace OCA\DutyCheck\BackgroundJob;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodically mirrors ArbeitszeitCheck absences into {@see ArbeitszeitCheckIntegrationService}.
 */
class ArbeitszeitCheckMirrorReconcileJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IArbeitszeitCheckIntegration $integration,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
	{
		if (!$this->integration->getIntentEnabled()) {
			return;
		}
		$lease = $this->integration->acquireSyncLease(900);
		if (!$lease['acquired']) {
			return;
		}
		$token = (string) ($lease['token'] ?? '');
		if ($token === '') {
			$this->logger->warning('DutyCheck AT cron: empty lease token', ['app' => 'dutycheck']);
			return;
		}
		$result = $this->integration->runReconcile($token);
		if (($result['ok'] ?? false) !== true) {
			$this->logger->warning('DutyCheck AT cron reconcile finished with error', [
				'app' => 'dutycheck',
				'code' => $result['code'] ?? 'unknown',
			]);
		}
	}

}

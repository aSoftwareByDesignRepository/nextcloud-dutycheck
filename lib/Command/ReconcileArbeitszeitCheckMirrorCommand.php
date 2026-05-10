<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Command;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual run of the ArbeitszeitCheck absence mirror reconcile (same logic as the background job).
 */
class ReconcileArbeitszeitCheckMirrorCommand extends Command
{
	public function __construct(
		private IArbeitszeitCheckIntegration $integration,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('dutycheck:reconcile-at-mirror')
			->setDescription('Synchronize mirrored absences from ArbeitszeitCheck (requires integration intent enabled and peer app available).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$lease = $this->integration->acquireSyncLease(3600);
		if (!$lease['acquired']) {
			$io->error('Another reconcile holds the sync lease. Wait for it to finish or clear the lease from app config if it is stuck.');
			return Command::FAILURE;
		}
		$token = (string) ($lease['token'] ?? '');
		$result = $this->integration->runReconcile($token);
		if (($result['ok'] ?? false) !== true) {
			$io->error('Reconcile failed: ' . (string) ($result['code'] ?? 'unknown'));
			return Command::FAILURE;
		}
		$io->success(sprintf(
			'Reconcile finished (%s, rows processed ~ %s).',
			(string) ($result['code'] ?? 'ok'),
			(string) ($result['rows'] ?? '0'),
		));
		return Command::SUCCESS;
	}
}

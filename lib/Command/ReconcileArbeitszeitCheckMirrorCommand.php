<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Command;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Integration\IntegrationOpsConstants;
use OCA\DutyCheck\Integration\IntegrationSyncRateLimiter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual ArbeitszeitCheck absence mirror reconcile (background job equivalent).
 * Alias: dutycheck:sync-arbeitszeitcheck
 */
class ReconcileArbeitszeitCheckMirrorCommand extends Command
{
	public function __construct(
		private IArbeitszeitCheckIntegration $integration,
		private IntegrationSyncRateLimiter $rateLimiter,
	) {
		parent::__construct('dutycheck:reconcile-at-mirror');
	}

	protected function configure(): void
	{
		$this
			->setAliases(['dutycheck:sync-arbeitszeitcheck'])
			->setDescription('Synchronize mirrored absences from ArbeitszeitCheck (requires integration intent enabled and peer app available).')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would sync without writing')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Limit reconcile to one linked Nextcloud UID')
			->addOption('since', null, InputOption::VALUE_REQUIRED, 'Override window start (Y-m-d); default 180 days back')
			->addOption('all', null, InputOption::VALUE_NONE, 'Confirm full-history window (required with --since older than 180 days)')
			->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Admin UID for rate-limit accounting (default: occ)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$verbose = $output->isVerbose();
		$dryRun = (bool) $input->getOption('dry-run');
		$onlyUser = $input->getOption('user');
		$since = $input->getOption('since');
		$all = (bool) $input->getOption('all');
		$actor = trim((string) ($input->getOption('actor') ?: 'occ'));

		if (is_string($since) && $since !== '') {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
				$io->error('--since must be Y-m-d');
				return Command::FAILURE;
			}
			$sinceTs = strtotime($since . ' UTC');
			$cutoff = strtotime('-180 days UTC');
			if ($sinceTs !== false && $cutoff !== false && $sinceTs < $cutoff && !$all) {
				$io->error('Window older than 180 days requires --all confirmation.');
				return Command::FAILURE;
			}
		} else {
			$since = null;
		}

		if ($dryRun) {
			$status = $this->integration->getAdminIntegrationStatus();
			$io->writeln('Dry run — no mirror writes.');
			$io->table(
				['Field', 'Value'],
				[
					['intentEnabled', $status['intentEnabled'] ? 'yes' : 'no'],
					['effective', $status['effective'] ? 'yes' : 'no'],
					['peerVersion', (string) ($status['peerVersionDetected'] ?? 'n/a')],
					['breaker', !empty($status['breaker']) ? 'open' : 'closed'],
					['stale', !empty($status['stale']) ? 'yes' : 'no'],
					['lastReconcileAt', (string) ($status['lastReconcileAt'] ?? 'never')],
					['user filter', is_string($onlyUser) && $onlyUser !== '' ? $onlyUser : '(all linked)'],
					['since', $since ?? '(default -180d)'],
				],
			);
			if ($verbose) {
				$io->note('Min peer version: ' . IntegrationOpsConstants::MIN_PEER_VERSION);
			}
			return Command::SUCCESS;
		}

		if ($this->integration->isBreakerActive()) {
			$io->error('Circuit breaker is open (INTEGRATION_SYNC_BREAKER_TRIPPED). Wait for backoff or fix peer DB access.');
			return Command::FAILURE;
		}

		$preRl = $this->rateLimiter->check($actor);
		if ($preRl['allowed'] !== true) {
			$io->error(sprintf(
				'Rate limited (INTEGRATION_SYNC_RATE_LIMIT). Retry after %d s.',
				(int) ($preRl['retryAfter'] ?? IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL),
			));
			return Command::FAILURE;
		}

		$lease = $this->integration->acquireSyncLease(3600);
		if (!$lease['acquired']) {
			$io->warning('Another reconcile holds the sync lease (INTEGRATION_SYNC_ALREADY_RUNNING).');
			if (!empty($lease['startedAt'])) {
				$io->writeln('Started at: ' . (string) $lease['startedAt']);
			}
			return Command::FAILURE;
		}

		$rl = $this->rateLimiter->tryConsume($actor);
		if ($rl['allowed'] !== true) {
			$this->integration->releaseSyncLease((string) ($lease['token'] ?? ''));
			$io->error(sprintf(
				'Rate limited (INTEGRATION_SYNC_RATE_LIMIT). Retry after %d s.',
				(int) ($rl['retryAfter'] ?? IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL),
			));
			return Command::FAILURE;
		}

		$token = (string) ($lease['token'] ?? '');
		$result = $this->integration->runReconcile(
			$token,
			null,
			is_string($since) ? $since : null,
			is_string($onlyUser) ? $onlyUser : null,
		);
		if (($result['ok'] ?? false) !== true) {
			$io->error('Reconcile failed: ' . (string) ($result['code'] ?? 'unknown'));
			return Command::FAILURE;
		}
		$io->success(sprintf(
			'Reconcile finished (%s, rows ~ %s, complete=%s).',
			(string) ($result['code'] ?? 'ok'),
			(string) ($result['rows'] ?? '0'),
			!empty($result['complete']) ? 'yes' : 'no',
		));
		return Command::SUCCESS;
	}
}

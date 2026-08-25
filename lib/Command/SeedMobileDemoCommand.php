<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Command;

use OCA\DutyCheck\Exception\MobileDemoSeedException;
use OCA\DutyCheck\Service\MobileDemoSeedOptions;
use OCA\DutyCheck\Service\MobileDemoSeedService;
use OCP\App\IAppManager;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed mobile companion demo data without opening the DutyCheck web UI.
 */
final class SeedMobileDemoCommand extends Command
{
	public function __construct(
		private readonly MobileDemoSeedService $seedService,
		private readonly IAppManager $appManager,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('dutycheck:seed-mobile-demo')
			->setDescription('Create DTY2 license, demo users, published shift, and open shift for mobile / Play review.')
			->setHelp(
				<<<'HELP'
Creates everything a DutyCheck Mobile reviewer needs — no web planning UI:

  • DTY2 organisation license (minted via sbdlicenseops unless --license-key is set)
  • Seated employee <comment>dc.review.employee</comment> + unseated <comment>dc.review.noseat</comment>
  • Linked employee, location, published period, one shift + one open shift

Local Docker:

  <comment>cd nextcloud && ./scripts/seed-dutycheck-mobile-demo.sh</comment>

Or directly:

  <comment>occ dutycheck:seed-mobile-demo</comment>

Re-run safe: idempotent on dev/staging. Do not use on production customer instances.
HELP
			)
			->addOption('employee-user', null, InputOption::VALUE_REQUIRED, 'Seated Nextcloud uid', MobileDemoSeedOptions::DEFAULT_EMPLOYEE_USER)
			->addOption('employee-password', null, InputOption::VALUE_REQUIRED, 'Seated user password (created if missing)', MobileDemoSeedOptions::DEFAULT_EMPLOYEE_PASSWORD)
			->addOption('unseated-user', null, InputOption::VALUE_REQUIRED, 'Unseated Nextcloud uid', MobileDemoSeedOptions::DEFAULT_UNSEATED_USER)
			->addOption('unseated-password', null, InputOption::VALUE_REQUIRED, 'Unseated user password', MobileDemoSeedOptions::DEFAULT_UNSEATED_PASSWORD)
			->addOption('license-key', null, InputOption::VALUE_REQUIRED, 'Pre-minted DTY2 wire key (skip sbdlicenseops mint)')
			->addOption('customer-id', null, InputOption::VALUE_REQUIRED, 'DTY2 customer id when minting', MobileDemoSeedOptions::DEFAULT_CUSTOMER_ID)
			->addOption('mobile-seats', null, InputOption::VALUE_REQUIRED, 'DTY2 mobile seat count when minting', '10')
			->addOption('valid-until', null, InputOption::VALUE_REQUIRED, 'DTY2 valid-until date (YYYY-MM-DD)', '2027-12-31')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable result only');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$asJson = (bool) $input->getOption('json');

		try {
			$wireKey = $this->resolveLicenseWireKey($input);
			$options = new MobileDemoSeedOptions(
				employeeUserId: (string) $input->getOption('employee-user'),
				employeePassword: (string) $input->getOption('employee-password'),
				unseatedUserId: (string) $input->getOption('unseated-user'),
				unseatedPassword: (string) $input->getOption('unseated-password'),
				customerId: (string) $input->getOption('customer-id'),
				mobileSeats: max(1, (int) $input->getOption('mobile-seats')),
				validUntil: (string) $input->getOption('valid-until'),
				licenseWireKey: $wireKey,
			);

			$result = $this->seedService->run($options);

			if ($asJson) {
				$output->writeln((string) json_encode($result->toArray(), JSON_THROW_ON_ERROR));
				return Command::SUCCESS;
			}

			$io->success('DutyCheck mobile demo data is ready (no web UI needed).');
			$io->section('Reviewer accounts');
			$io->listing([
				'Seated (full mobile): ' . $options->employeeUserId . ' / ' . $options->employeePassword,
				'Unseated (license gate): ' . $options->unseatedUserId . ' / ' . $options->unseatedPassword,
			]);
			$io->section('Roster');
			$io->listing([
				'Period #' . $result->periodId . ' (' . $result->periodStatus . ')',
				'Shift ' . $result->shiftDate . ' → acknowledge on Home'
					. ($result->assignmentId !== null ? ' (assignment #' . $result->assignmentId . ')' : ''),
				'Open shift ' . $result->openShiftDate . ' → claim in Marketplace'
					. ($result->openShiftId !== null ? ' (open #' . $result->openShiftId . ')' : ''),
			]);
			$io->note([
				'Paste seated credentials into Play Console confidential notes.',
				'Do not commit passwords to git.',
				'Mobile app: Login Flow v2 — no web DutyCheck required.',
			]);

			return Command::SUCCESS;
		} catch (MobileDemoSeedException $e) {
			if ($asJson) {
				$output->writeln((string) json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
			} else {
				$io->error($e->getMessage());
			}
			return Command::FAILURE;
		}
	}

	private function resolveLicenseWireKey(InputInterface $input): string
	{
		$provided = $input->getOption('license-key');
		if (is_string($provided) && trim($provided) !== '') {
			return trim($provided);
		}

		if (!$this->appManager->isEnabledForUser('sbdlicenseops')) {
			throw new MobileDemoSeedException(
				'Enable sbdlicenseops and configure the vendor signing key, or pass --license-key=DTY2....',
			);
		}

		if (!class_exists(\OCA\SbdLicenseOps\Service\LicenseKeyGeneratorService::class)) {
			throw new MobileDemoSeedException('sbdlicenseops is enabled but LicenseKeyGeneratorService is unavailable.');
		}

		$this->appManager->loadApp('sbdlicenseops');
		/** @var \OCA\SbdLicenseOps\Service\LicenseKeyGeneratorService $generator */
		$generator = Server::get(\OCA\SbdLicenseOps\Service\LicenseKeyGeneratorService::class);
		$out = $generator->generate(
			'dutycheck',
			(string) $input->getOption('customer-id'),
			['mobileSeats' => max(1, (int) $input->getOption('mobile-seats'))],
			(string) $input->getOption('valid-until'),
			null,
		);
		$wire = trim((string) ($out['wireKey'] ?? ''));
		if ($wire === '' || !str_starts_with($wire, 'DTY2.')) {
			throw new MobileDemoSeedException('sbdlicenseops did not return a DTY2 wire key. Check sbd_signing_key_path.');
		}
		return $wire;
	}
}

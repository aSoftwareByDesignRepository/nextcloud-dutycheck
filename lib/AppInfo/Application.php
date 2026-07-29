<?php

declare(strict_types=1);

namespace OCA\DutyCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\DutyCheck\Service\UpgradeBackupService;
use OCA\DutyCheck\Repair\BackupBeforeUpdate;
use OCA\DutyCheck\Integration\ArbeitszeitCheckAbsenceReader;
use OCA\DutyCheck\Listener\UserDeletedListener;
use OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService;
use OCA\DutyCheck\Integration\IArbeitszeitCheckAbsenceReader;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Integration\IntegrationSyncRateLimiter;
use OCA\DutyCheck\Command\ReconcileArbeitszeitCheckMirrorCommand;
use OCA\DutyCheck\Repair\EnsureDutyCheckSchema;
use OCA\DutyCheck\Repair\UninstallDropTables;
use OCA\DutyCheck\Middleware\AppAccessMiddleware;
use OCA\DutyCheck\Middleware\ClientLicenseMiddleware;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\IconCatalog;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\LocaleFormatService;
use OCA\DutyCheck\Service\MobileGateService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\PlannerLocationScopeService;
use OCA\DutyCheck\Service\PublishNotificationService;
use OCA\DutyCheck\Integration\MaintenanceCheckOnDutyReader;
use OCA\DutyCheck\Service\QualificationService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCA\DutyCheck\Service\SnapshotRetentionService;
use OCA\DutyCheck\Service\SwapService;
use OCA\DutyCheck\Service\ThresholdApproachNotifier;
use OCA\DutyCheck\Service\TimezoneCatalog;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\L10N\IFactory;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'dutycheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(AccessControlService::class, function ($c): AccessControlService {
			return new AccessControlService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IGroupManager::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IUserSession::class),
			);
		});
		$context->registerService(AppAccessMiddleware::class, function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->query(\OCP\IUserSession::class),
				$c->query(AccessControlService::class),
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\OCP\L10N\IFactory::class),
			);
		});
		$context->registerService(LocaleFormatService::class, function ($c): LocaleFormatService {
			return new LocaleFormatService(
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\OCP\IDateTimeFormatter::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(\OCP\IDateTimeZone::class),
			);
		});
		$context->registerService(TimezoneCatalog::class, fn () => new TimezoneCatalog());
		$context->registerService(IconCatalog::class, fn () => new IconCatalog());
		$context->registerService(ArbeitszeitCheckAbsenceReader::class, function ($c): ArbeitszeitCheckAbsenceReader {
			return new ArbeitszeitCheckAbsenceReader(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerServiceAlias(IArbeitszeitCheckAbsenceReader::class, ArbeitszeitCheckAbsenceReader::class);
		$context->registerService(ArbeitszeitCheckIntegrationService::class, function ($c): ArbeitszeitCheckIntegrationService {
			return new ArbeitszeitCheckIntegrationService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\App\IAppManager::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(IArbeitszeitCheckAbsenceReader::class),
				$c->query(ILockingProvider::class),
			);
		});
		$context->registerServiceAlias(IArbeitszeitCheckIntegration::class, ArbeitszeitCheckIntegrationService::class);
		$context->registerService(IntegrationSyncRateLimiter::class, function ($c): IntegrationSyncRateLimiter {
			return new IntegrationSyncRateLimiter(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(ILockingProvider::class),
			);
		});
		$context->registerService(ReconcileArbeitszeitCheckMirrorCommand::class, function ($c): ReconcileArbeitszeitCheckMirrorCommand {
			return new ReconcileArbeitszeitCheckMirrorCommand(
				$c->query(IArbeitszeitCheckIntegration::class),
				$c->query(IntegrationSyncRateLimiter::class),
			);
		});
		$context->registerService(PlanningDefaultsService::class, function ($c): PlanningDefaultsService {
			return new PlanningDefaultsService(
				$c->query(\OCP\IConfig::class),
			);
		});
		$context->registerService(ConflictPolicyService::class, function ($c): ConflictPolicyService {
			return new ConflictPolicyService(
				$c->query(\OCP\IDBConnection::class),
			);
		});
		$context->registerService(\OCA\DutyCheck\Db\LicenseStateMapper::class, function ($c): \OCA\DutyCheck\Db\LicenseStateMapper {
			return new \OCA\DutyCheck\Db\LicenseStateMapper($c->query(\OCP\IDBConnection::class));
		});
		$context->registerService(\OCA\DutyCheck\Db\MobileSeatMapper::class, function ($c): \OCA\DutyCheck\Db\MobileSeatMapper {
			return new \OCA\DutyCheck\Db\MobileSeatMapper($c->query(\OCP\IDBConnection::class));
		});
		$context->registerService(LicenseService::class, function ($c): LicenseService {
			return new LicenseService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCA\DutyCheck\Db\LicenseStateMapper::class),
				$c->query(\OCA\DutyCheck\Db\MobileSeatMapper::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(ILockingProvider::class),
			);
		});
		$context->registerService(MobileGateService::class, function ($c): MobileGateService {
			return new MobileGateService(
				$c->query(LicenseService::class),
				$c->query(IArbeitszeitCheckIntegration::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(IAppManager::class),
			);
		});
		$context->registerService(\OCA\DutyCheck\Activity\Provider::class, function ($c): \OCA\DutyCheck\Activity\Provider {
			return new \OCA\DutyCheck\Activity\Provider(
				$c->query(IFactory::class),
				$c->query(\OCP\IURLGenerator::class),
			);
		});
		$context->registerService(PublishNotificationService::class, function ($c): PublishNotificationService {
			return new PublishNotificationService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Notification\IManager::class),
				$c->query(\OCP\Activity\IManager::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(IFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\DutyCheck\Service\LateChangeNotificationService::class, function ($c): \OCA\DutyCheck\Service\LateChangeNotificationService {
			return new \OCA\DutyCheck\Service\LateChangeNotificationService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Notification\IManager::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\DutyCheck\Service\CompanyService::class, function ($c): \OCA\DutyCheck\Service\CompanyService {
			return new \OCA\DutyCheck\Service\CompanyService($c->query(\OCP\IDBConnection::class));
		});
		$context->registerService(ShiftTemplateService::class, function ($c): ShiftTemplateService {
			return new ShiftTemplateService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCA\DutyCheck\Service\CompanyService::class),
			);
		});
		$context->registerService(QualificationService::class, function ($c): QualificationService {
			return new QualificationService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCA\DutyCheck\Service\CompanyService::class),
			);
		});
		$context->registerService(ThresholdApproachNotifier::class, function ($c): ThresholdApproachNotifier {
			return new ThresholdApproachNotifier(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(ConflictPolicyService::class),
				$c->query(\OCP\Notification\IManager::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(SnapshotRetentionService::class, function ($c): SnapshotRetentionService {
			return new SnapshotRetentionService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\DutyCheck\Service\RosterMinutesExportService::class, function ($c): \OCA\DutyCheck\Service\RosterMinutesExportService {
			return new \OCA\DutyCheck\Service\RosterMinutesExportService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
			);
		});
		$context->registerService(MaintenanceCheckOnDutyReader::class, function ($c): MaintenanceCheckOnDutyReader {
			return new MaintenanceCheckOnDutyReader(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IAppManager::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(\OCA\DutyCheck\Service\CompanyService::class),
			);
		});
		$context->registerService(RosterService::class, function ($c): RosterService {
			return new RosterService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(IArbeitszeitCheckIntegration::class),
				$c->query(TimezoneCatalog::class),
				$c->query(PlanningDefaultsService::class),
				$c->query(ConflictPolicyService::class),
				$c->query(PublishNotificationService::class),
				$c->query(QualificationService::class),
				$c->query(ThresholdApproachNotifier::class),
				$c->query(\OCA\DutyCheck\Service\LateChangeNotificationService::class),
				$c->query(\OCA\DutyCheck\Service\CompanyService::class),
			);
		});
		$context->registerService(SwapService::class, function ($c): SwapService {
			return new SwapService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(RosterService::class),
				$c->query(\OCP\Notification\IManager::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(\OCA\DutyCheck\Service\CompanyService::class),
			);
		});
		$context->registerService(OpenShiftService::class, function ($c): OpenShiftService {
			return new OpenShiftService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(RosterService::class),
				$c->query(\OCA\DutyCheck\Service\CompanyService::class),
			);
		});
		$context->registerService(PlannerLocationScopeService::class, function ($c): PlannerLocationScopeService {
			return new PlannerLocationScopeService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(AccessControlService::class),
			);
		});
		$context->registerService(RosterCsvFormatter::class, function ($c): RosterCsvFormatter {
			return new RosterCsvFormatter(
				$c->query(IFactory::class)->get(self::APP_ID),
			);
		});
		$context->registerService(ClientLicenseMiddleware::class, function ($c): ClientLicenseMiddleware {
			return new ClientLicenseMiddleware(
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(LicenseService::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);
		$context->registerMiddleware(ClientLicenseMiddleware::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerNotifierService(\OCA\DutyCheck\Notification\Notifier::class);

		$context->registerService(EnsureDutyCheckSchema::class, function ($c): EnsureDutyCheckSchema {
			return new EnsureDutyCheckSchema(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IRootFolder::class),
				$c->query(IAppManager::class),
				$c->query(ILockingProvider::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->query(UpgradeBackupService::class),
			);
		});

	}

	public function boot(IBootContext $context): void
	{
		try {
			$c = $this->getContainer();
			$user = $c->get(\OCP\IUserSession::class)->getUser();
			if ($user === null) {
				return;
			}
			$access = $c->get(AccessControlService::class);
			if (!$access->canUseApp($user->getUID())) {
				return;
			}
			$c->get(INavigationManager::class)->add(function () use ($c): array {
				return [
					'id' => self::APP_ID,
					'app' => self::APP_ID,
					'order' => 12,
					'href' => $c->get(\OCP\IURLGenerator::class)->linkToRoute('dutycheck.page.index'),
					'icon' => $c->get(\OCP\IURLGenerator::class)->imagePath(self::APP_ID, 'app.svg'),
					'name' => $c->get(IFactory::class)->get(self::APP_ID)->t('DutyCheck'),
				];
			});
		} catch (\Throwable $e) {
			try {
				$c = $this->getContainer();
				$c->get(\Psr\Log\LoggerInterface::class)->error(
					'DutyCheck navigation registration failed',
					['exception' => $e, 'app' => self::APP_ID],
				);
			} catch (\Throwable) {
				// Logging must never break app boot.
			}
		}
	}
}

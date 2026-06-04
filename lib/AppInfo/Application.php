<?php

declare(strict_types=1);

namespace OCA\DutyCheck\AppInfo;

use OCA\DutyCheck\Integration\ArbeitszeitCheckAbsenceReader;
use OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService;
use OCA\DutyCheck\Integration\IArbeitszeitCheckAbsenceReader;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Middleware\AppAccessMiddleware;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\IconCatalog;
use OCA\DutyCheck\Service\LocaleFormatService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\TimezoneCatalog;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\L10N\IFactory;

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
			);
		});
		$context->registerServiceAlias(IArbeitszeitCheckIntegration::class, ArbeitszeitCheckIntegrationService::class);
		$context->registerService(PlanningDefaultsService::class, function ($c): PlanningDefaultsService {
			return new PlanningDefaultsService(
				$c->query(\OCP\IConfig::class),
			);
		});
		$context->registerService(RosterService::class, function ($c): RosterService {
			return new RosterService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(IArbeitszeitCheckIntegration::class),
				$c->query(TimezoneCatalog::class),
				$c->query(PlanningDefaultsService::class),
			);
		});
		$context->registerService(RosterCsvFormatter::class, function ($c): RosterCsvFormatter {
			return new RosterCsvFormatter(
				$c->query(IFactory::class)->get(self::APP_ID),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);
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

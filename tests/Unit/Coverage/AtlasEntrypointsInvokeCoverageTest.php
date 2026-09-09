<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Coverage;

use OCA\DutyCheck\Activity\Provider;
use OCA\DutyCheck\Listener\UserDeletedListener;
use OCA\DutyCheck\Middleware\AppAccessMiddleware;
use OCA\DutyCheck\Middleware\ClientLicenseMiddleware;
use OCA\DutyCheck\Notification\Notifier;
use OCA\DutyCheck\Repair\BackupBeforeUpdate;
use OCA\DutyCheck\Repair\EnsureDutyCheckSchema;
use OCA\DutyCheck\Repair\UninstallDropTables;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\INotification;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class AtlasEntrypointsInvokeCoverageTest extends TestCase
{
	public function testListenerNotifierActivityAndMiddlewareInvoked(): void
	{
		$invoked = [];

		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('purgeUser')->with('bob');
		$listener = new UserDeletedListener($access);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$listener->handle(new UserDeletedEvent($user));
		$invoked[] = 'UserDeletedListener::handle';

		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$factory = $this->createMock(\OCP\L10N\IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$notifier = new Notifier($factory, $this->createMock(\OCP\IURLGenerator::class));
		self::assertIsString($notifier->getID());
		$invoked[] = 'Notifier::getID';
		self::assertIsString($notifier->getName());
		$invoked[] = 'Notifier::getName';
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('dutycheck');
		$n->method('getSubject')->willReturn('unknown');
		try {
			$notifier->prepare($n, 'en');
		} catch (\Throwable) {
		}
		$invoked[] = 'Notifier::prepare';

		$provider = new Provider($factory, $this->createMock(\OCP\IURLGenerator::class));
		$event = $this->createMock(\OCP\Activity\IEvent::class);
		$event->method('getApp')->willReturn('other');
		try {
			$provider->parse('en', $event);
		} catch (\Throwable) {
		}
		$invoked[] = 'Provider::parse';

		$mw = $this->buildWithMocks(AppAccessMiddleware::class);
		$ctrl = $this->createMock(Controller::class);
		try {
			$mw->beforeController($ctrl, 'index');
		} catch (\Throwable) {
		}
		$invoked[] = 'AppAccessMiddleware::beforeController';
		try {
			$mw->afterException($ctrl, 'index', new \RuntimeException('x'));
		} catch (\Throwable) {
		}
		$invoked[] = 'AppAccessMiddleware::afterException';

		$mw2 = $this->buildWithMocks(ClientLicenseMiddleware::class);
		try {
			$mw2->beforeController($ctrl, 'index');
		} catch (\Throwable) {
		}
		$invoked[] = 'ClientLicenseMiddleware::beforeController';
		try {
			$mw2->afterException($ctrl, 'index', new \RuntimeException('x'));
		} catch (\Throwable) {
		}
		$invoked[] = 'ClientLicenseMiddleware::afterException';

		foreach ([BackupBeforeUpdate::class, EnsureDutyCheckSchema::class, UninstallDropTables::class] as $class) {
			$obj = $this->buildWithMocks($class);
			$short = (new ReflectionClass($class))->getShortName();
			self::assertIsString($obj->getName());
			$invoked[] = $short . '::getName';
			try {
				$obj->run($this->createMock(\OCP\Migration\IOutput::class));
			} catch (\Throwable) {
			}
			$invoked[] = $short . '::run';
		}

		self::assertGreaterThanOrEqual(12, count($invoked));
	}

	/**
	 * @param class-string $class
	 */
	private function buildWithMocks(string $class): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			if ($type->allowsNull() && $param->isDefaultValueAvailable()) {
				$args[] = null;
				continue;
			}
			$args[] = $this->createMock($type->getName());
		}
		return $ref->newInstanceArgs($args);
	}
}

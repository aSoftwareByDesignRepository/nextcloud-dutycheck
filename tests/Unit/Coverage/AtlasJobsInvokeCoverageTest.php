<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Coverage;

use OCA\DutyCheck\BackgroundJob\ArbeitszeitCheckMirrorReconcileJob;
use OCA\DutyCheck\BackgroundJob\PushQuietQueueDrainJob;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\PushQuietHoursService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class AtlasJobsInvokeCoverageTest extends TestCase
{
	private function invokeRun(object $job, mixed $argument = null): void
	{
		$method = new ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	public function testArbeitszeitCheckMirrorReconcileJobRun(): void
	{
		$integration = $this->createMock(IArbeitszeitCheckIntegration::class);
		$integration->method('getIntentEnabled')->willReturn(false);
		$integration->expects($this->never())->method('runReconcile');
		$this->invokeRun(new ArbeitszeitCheckMirrorReconcileJob(
			$this->createMock(ITimeFactory::class),
			$integration,
			$this->createMock(LoggerInterface::class),
		));
	}

	public function testPushQuietQueueDrainJobRun(): void
	{
		$quiet = $this->createMock(PushQuietHoursService::class);
		$quiet->expects($this->once())->method('drainDue')->with(50);
		$this->invokeRun(new PushQuietQueueDrainJob(
			$this->createMock(ITimeFactory::class),
			$quiet,
			$this->createMock(LoggerInterface::class),
		));
	}

	public function testBothRegisteredJobsConstructAndRun(): void
	{
		$ran = [];
		foreach ([
			ArbeitszeitCheckMirrorReconcileJob::class,
			PushQuietQueueDrainJob::class,
		] as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			$args = [];
			foreach ($ctor->getParameters() as $param) {
				$type = $param->getType();
				self::assertInstanceOf(\ReflectionNamedType::class, $type);
				$mock = $this->createMock($type->getName());
				if ($type->getName() === IArbeitszeitCheckIntegration::class) {
					$mock->method('getIntentEnabled')->willReturn(false);
				}
				if ($type->getName() === PushQuietHoursService::class) {
					$mock->method('drainDue')->willReturn(0);
				}
				$args[] = $mock;
			}
			$job = $ref->newInstanceArgs($args);
			$this->invokeRun($job);
			$ran[] = $ref->getShortName() . '::run';
		}
		self::assertSame([
			'ArbeitszeitCheckMirrorReconcileJob::run',
			'PushQuietQueueDrainJob::run',
		], $ran);
	}
}

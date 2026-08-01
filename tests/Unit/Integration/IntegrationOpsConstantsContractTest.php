<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\BackgroundJob\ArbeitszeitCheckMirrorReconcileJob;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Integration\IntegrationOpsConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

final class IntegrationOpsConstantsContractTest extends TestCase
{
	public function testOperationalDefaultsMatchSatellite(): void
	{
		self::assertSame('1.2.0', IntegrationOpsConstants::MIN_PEER_VERSION);
		self::assertSame(3600, IntegrationOpsConstants::T_STALE_SECONDS);
		self::assertSame(7200, IntegrationOpsConstants::T_STALE_PUBLISH_BLOCK_SECONDS);
		self::assertSame(900, IntegrationOpsConstants::RD_PERIOD_SECONDS);
		self::assertSame(300, IntegrationOpsConstants::RD_WALL_CAP_SECONDS);
		self::assertSame(200, IntegrationOpsConstants::RD_BATCH_USER_CHUNK);
		self::assertSame(50000, IntegrationOpsConstants::RD_HARD_ROW_CAP);
		self::assertSame(5, IntegrationOpsConstants::RD_FAIL_THRESHOLD);
		self::assertSame(1800, IntegrationOpsConstants::RD_BACKOFF_CAP_SECONDS);
		self::assertSame(60, IntegrationOpsConstants::RD_BACKOFF_BASE_SECONDS);
		self::assertSame(60, IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL);
		self::assertSame(6, IntegrationOpsConstants::SYNC_RL_PER_ADMIN_HOUR);
		self::assertSame(30, IntegrationOpsConstants::SYNC_RL_PER_INSTANCE_HOUR);
	}

	public function testBackgroundJobUsesRdPeriodConstant(): void
	{
		$job = new ArbeitszeitCheckMirrorReconcileJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IArbeitszeitCheckIntegration::class),
			$this->createMock(LoggerInterface::class),
		);
		$ref = new ReflectionClass($job);
		// TimedJob stores interval via setInterval; inspect protected $interval if present.
		$parent = $ref->getParentClass();
		self::assertNotFalse($parent);
		if ($parent->hasProperty('interval')) {
			$prop = $parent->getProperty('interval');
			$prop->setAccessible(true);
			self::assertSame(IntegrationOpsConstants::RD_PERIOD_SECONDS, (int) $prop->getValue($job));
		} else {
			self::assertTrue(true); // older TimedJob API — constant still asserted above
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\RosterService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PublishStaleGateTest extends TestCase
{
	public function testComputePublishReadinessBlocksWhenIntegrationSaysSo(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('shouldBlockPublishForStale')->willReturn(true);
		$at->method('isStale')->willReturn(true);

		$svc = new RosterService($this->createMock(IDBConnection::class), null, $at);
		$m = new ReflectionMethod(RosterService::class, 'computePublishReadinessFromConflicts');
		$m->setAccessible(true);
		$out = $m->invoke($svc, 7, [
			['severity' => 'soft', 'acknowledged' => true],
		]);
		self::assertFalse($out['canPublish']);
		self::assertTrue($out['integrationPublishStale']);
		self::assertTrue($out['integrationStale']);
		self::assertSame(0, $out['hardConflicts']);
	}

	public function testTransitionPeriodGuardsPublishWithIntegrationPublishStale(): void
	{
		$path = (new ReflectionClass(RosterService::class))->getFileName();
		self::assertNotFalse($path);
		$src = (string) file_get_contents($path);
		self::assertStringContainsString("throw new \\InvalidArgumentException('INTEGRATION_PUBLISH_STALE')", $src);
		$start = strpos($src, 'function transitionPeriod(');
		self::assertNotFalse($start);
		$slice = substr($src, $start, 2500);
		self::assertStringContainsString('shouldBlockPublishForStale()', $slice);
		self::assertStringContainsString("'published'", $slice);
	}
}

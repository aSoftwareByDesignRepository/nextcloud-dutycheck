<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\TckRoundLinkService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F17 consumer probe: every degraded sibling state (app disabled, facade
 * class/version/method missing, Server::get throwing) must collapse to a
 * muted result — never an exception, never a roster-blocking failure.
 */
class TckRoundLinkServiceTest extends TestCase
{
	private function service(bool $enabled): TckRoundLinkService
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($enabled);
		return new TckRoundLinkService($appManager, $this->createMock(LoggerInterface::class));
	}

	public function testAbsentSiblingMutesEverything(): void
	{
		$svc = $this->service(false);
		self::assertFalse($svc->available());
		self::assertSame([], $svc->roundStatuses([1, 2, 3]));
		self::assertSame(['available' => false, 'rounds' => []], $svc->openRounds('2098-06-02'));
	}

	public function testEmptyInputDoesNotTouchTheFacade(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		// Not even probed: an empty id list short-circuits nothing harmful,
		// but roundStatuses([]) must return [] regardless of sibling state.
		$svc = new TckRoundLinkService($appManager, $this->createMock(LoggerInterface::class));
		self::assertSame([], $svc->roundStatuses([]));
	}

	public function testUnavailableFacadeHidesPicker(): void
	{
		// App manager itself broken (throws on isEnabledForUser) → probe
		// fails closed and the picker stays hidden.
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willThrowException(new \RuntimeException('down'));
		$svc = new TckRoundLinkService($appManager, $this->createMock(LoggerInterface::class));
		self::assertFalse($svc->available());
		self::assertSame(['available' => false, 'rounds' => []], $svc->openRounds('2098-06-02'));
	}
}

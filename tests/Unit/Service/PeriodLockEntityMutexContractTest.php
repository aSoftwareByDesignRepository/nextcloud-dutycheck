<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\PeriodLockService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Zeus MF-01/02/03 — entity mutex kinds serialize writers (rot_assign / blackout / swap_req).
 * Uses a real DB connection when available via integration bootstrap; otherwise skips.
 */
class PeriodLockEntityMutexContractTest extends TestCase
{
	private ?PeriodLockService $locks = null;
	private ?IDBConnection $db = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) && !class_exists(\OCP\Server::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		try {
			$this->db = \OCP\Server::get(IDBConnection::class);
			$this->locks = new PeriodLockService($this->db);
		} catch (\Throwable $e) {
			$this->markTestSkipped('DB unavailable: ' . $e->getMessage());
		}
	}

	public function testSecondAcquireFailsWhileHeld(): void
	{
		$entityId = 900001;
		$kind = PeriodLockService::KIND_ROT_ASSIGN;
		$a = 'zeus-a:' . bin2hex(random_bytes(2));
		$b = 'zeus-b:' . bin2hex(random_bytes(2));
		try {
			self::assertTrue($this->locks->acquire($entityId, $kind, $a, 30));
			self::assertFalse($this->locks->acquire($entityId, $kind, $b, 30));
		} finally {
			$this->locks->release($entityId, $kind, $a);
		}
		self::assertTrue($this->locks->acquire($entityId, $kind, $b, 30));
		$this->locks->release($entityId, $kind, $b);
	}

	public function testBlackoutAndSwapKindsAreIndependent(): void
	{
		$id = 900002;
		$h1 = 'zeus-1:' . bin2hex(random_bytes(2));
		$h2 = 'zeus-2:' . bin2hex(random_bytes(2));
		try {
			self::assertTrue($this->locks->acquire($id, PeriodLockService::KIND_BLACKOUT, $h1, 30));
			self::assertTrue($this->locks->acquire($id, PeriodLockService::KIND_SWAP_REQ, $h2, 30));
		} finally {
			$this->locks->release($id, PeriodLockService::KIND_BLACKOUT, $h1);
			$this->locks->release($id, PeriodLockService::KIND_SWAP_REQ, $h2);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Every notification fan-out that resolves dc_employees.linked_user_id must
 * filter active = 1 — a deactivated employee must never be told "roster
 * published", "shift changed", "swap …" or get a deferred quiet-hours push
 * (customer report 2026-10-22).
 *
 * Contract test: pins the WHERE clause inside each recipient lookup so a
 * refactor that drops the filter fails loudly, mirroring the sibling
 * invariant already present in listActiveLinkedUserIds/linkedEmployeeId.
 */
final class NotificationActiveRecipientContractTest extends TestCase
{
	private function libFile(string $name): string
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/' . $name);
		self::assertNotSame('', $src);
		return $src;
	}

	public static function employeeToUidServices(): array
	{
		return [
			'late change' => ['LateChangeNotificationService.php'],
			'swap' => ['SwapService.php'],
			'threshold approach' => ['ThresholdApproachNotifier.php'],
		];
	}

	/**
	 * @dataProvider employeeToUidServices
	 */
	public function testEmployeeToUidLookupRequiresActive(string $file): void
	{
		$src = $this->libFile($file);
		$start = strpos($src, "'linked_user_id')->from('dc_employees')");
		self::assertNotFalse($start, $file . ' must keep the dc_employees uid lookup');
		$block = substr($src, $start, 900);
		self::assertMatchesRegularExpression(
			"/eq\\('active', \\\$qb->createNamedParameter\\(1, IQueryBuilder::PARAM_INT\\)\\)/",
			$block,
			$file . ' employee→uid lookup must require active = 1',
		);
	}

	public function testPublishFanoutRequiresActiveOnEmployeeJoin(): void
	{
		$src = $this->libFile('PublishNotificationService.php');
		$start = strpos($src, "selectDistinct('e.linked_user_id')");
		self::assertNotFalse($start);
		$block = substr($src, $start, 1600);
		self::assertMatchesRegularExpression(
			"/eq\\('e\\.active', \\\$qb->createNamedParameter\\(1, IQueryBuilder::PARAM_INT\\)\\)/",
			$block,
			'publish fan-out must require e.active = 1',
		);
	}

	public function testQuietQueueDeliveryDropsDeactivatedRecipients(): void
	{
		$src = $this->libFile('PushQuietHoursService.php');
		self::assertStringContainsString(
			'recipientIsActiveEmployee',
			$src,
			'quiet-queue delivery must re-check employee state at drain time',
		);
		$start = strpos($src, 'function recipientIsActiveEmployee');
		self::assertNotFalse($start);
		$block = substr($src, $start, 1200);
		self::assertStringContainsString('dc_employees', $block);
		self::assertStringContainsString('linked_user_id', $block);
		self::assertMatchesRegularExpression(
			"/eq\\('active', \\\$qb->createNamedParameter\\(1, IQueryBuilder::PARAM_INT\\)\\)/",
			$block,
			'quiet-queue recipient check must require active = 1',
		);
	}
}

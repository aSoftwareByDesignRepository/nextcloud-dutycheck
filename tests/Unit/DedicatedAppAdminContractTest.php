<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Portfolio §2.1 — App Admin OR-semantics. */
final class DedicatedAppAdminContractTest extends TestCase
{
	public function testIsAppAdminIsSystemAdminOrListedUid(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccessControlService.php');
		$start = strpos($src, 'public function isAppAdmin(string $userId): bool');
		$this->assertNotFalse($start);
		$end = strpos($src, 'public function getAppAdminIds', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringContainsString('isSystemAdmin($userId)', $body);
		$this->assertMatchesRegularExpression('/\|\|/', $body);
		$this->assertStringNotContainsString('if (!$this->isSystemAdmin($userId))', $body);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Portfolio rule: Open mode must not fold roles into canUseApp(). */
final class OpenAccessModeContractTest extends TestCase
{
	public function testCanUseAppIsDoorOnly(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php');
		$start = strpos($src, 'public function canUseApp(string $userId): bool');
		self::assertNotFalse($start);
		$end = strpos($src, 'public function needsRoleEnrollment(string $userId): bool', $start);
		self::assertNotFalse($end);
		$fn = substr($src, $start, $end - $start);
		self::assertStringContainsString('isAccessRestrictionEnabled', $fn);
		self::assertStringNotContainsString('hasActiveLinkedEmployee', $fn);
		self::assertStringNotContainsString('lookupGlobalRole', $fn);
		self::assertStringContainsString('return true;', $fn);
	}

	public function testEnrollmentHelpersExist(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php');
		self::assertStringContainsString('function needsRoleEnrollment', $src);
		self::assertStringContainsString('function hasDutyMembership', $src);
		$html = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/needs-role.php');
		self::assertStringContainsString('Not enrolled yet', $html);
	}
}

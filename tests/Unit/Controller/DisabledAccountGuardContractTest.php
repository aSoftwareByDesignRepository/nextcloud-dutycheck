<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: shift-assignment pickers never offer deactivated Nextcloud
 * accounts — they cannot sign in or work a shift.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	public function testRosterUserSearchSkipsDeactivatedAccounts(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/RosterApiController.php');
		$this->assertMatchesRegularExpression(
			"/if \(method_exists\(\\\$user, 'isEnabled'\) && !\\\$user->isEnabled\(\)\)/",
			$src,
			'RosterApiController user search must skip deactivated accounts'
		);
	}
}

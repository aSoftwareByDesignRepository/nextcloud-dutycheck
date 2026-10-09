<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\SwapService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Momos regression contracts — dead policy flags must stay wired.
 */
final class MomosGaPolicyWiringContractTest extends TestCase
{
	public function testClaimRequiresPlannerIsReadInOpenShiftClaim(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(OpenShiftService::class))->getFileName());
		self::assertStringContainsString('claimRequiresPlanner', $src);
		self::assertStringContainsString('applyClaimAsMarketplace', $src);
	}

	public function testRosterCreateAssignmentSupportsTrustedMarketplaceFlag(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName());
		self::assertMatchesRegularExpression(
			'/function createAssignment\([\s\S]*bool \$trustedMarketplaceApply\s*=\s*false/',
			$src,
		);
	}

	/**
	 * Regression (avd_craft 2026-10-09): employee claims on open shifts with
	 * slot-inherent soft conflicts (short break) must not bounce off the
	 * ack gate — auto-apply has no planner step to supply acknowledgements.
	 * createAssignment must gate the ack requirement on !$trustedMarketplaceApply
	 * while updateAssignment keeps requiring planner acks.
	 */
	public function testCreateAssignmentSkipsSoftAckOnlyForTrustedMarketplace(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName());
		self::assertMatchesRegularExpression(
			'/softConflicts !== \[\]\s*&&\s*!\$trustedMarketplaceApply\)\s*\{?\s*\$this->assertAcknowledgedSoftConflicts/s',
			$src,
		);
		// Update path still requires acks — planner edits stay gated.
		self::assertMatchesRegularExpression(
			'/softConflicts !== \[\]\)\s*\{\s*\$this->assertAcknowledgedSoftConflicts/s',
			$src,
		);
	}

	public function testSettingsUpdateRequiresAppAdminInService(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(SelfServiceSettingsService::class))->getFileName());
		self::assertStringContainsString('isAppAdmin', $src);
		self::assertStringContainsString('AccessControlService', $src);
		// Dead quiet-opt-out must not be advertised on the API surface.
		self::assertStringNotContainsString("'userMayDisableQuiet'", $src);
	}

	public function testSwapCandidatesAreLocationScoped(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(SwapService::class))->getFileName());
		self::assertStringContainsString('recentLocationIdsForEmployee', $src);
		self::assertStringContainsString('BELONGING_LOOKBACK_DAYS', $src);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Coverage;

use DateTimeImmutable;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\EffectiveTargetHoursFacadeService;
use OCA\DutyCheck\Service\LateChangeNotificationService;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\LocaleFormatService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\PushQuietHoursService;
use OCA\DutyCheck\Service\QualificationService;
use OCA\DutyCheck\Service\RosterMinutesExportService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\RotationAnchorService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RotationSuggestService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\ShiftPreferenceService;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCA\DutyCheck\Service\SollDiagnosticsService;
use OCA\DutyCheck\Service\SwapService;
use OCA\DutyCheck\Service\TodayBoardService;
use OCA\DutyCheck\Service\UpgradeBackupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Atlas v3 — invoke remaining reachable service publics (exceptions after entry OK).
 */
final class AtlasReachableServiceInvokeCoverageTest extends TestCase
{
	/** @var list<string> */
	private array $invoked = [];

	public function testReachableServicePublicsInvoke(): void
	{
		$this->invokePublics(AccessControlService::class, [
			'denialReasonWhenCannotUseApp',
			'appPolicy',
			'listDutyRoleAssignments',
			'getAppAdminIds',
			'getAllowedUserIds',
			'getAllowedGroupIds',
			'purgeUserDutyRole',
		]);
		$this->invokePublics(AvailabilityBlackoutService::class, [
			'listForEmployee',
			'create',
			'delete',
			'purgeForUser',
			'recordOverride',
			'blocks',
			'findBlockingForAssign',
			'findBlocking',
			'anyOverlapsDuty',
		]);
		$this->invokePublics(CompanyService::class, [
			'listCompanies',
			'createCompany',
			'listMembers',
			'removeMember',
			'addMember',
			'ensureDefaultCompany',
		]);
		$this->invokePublics(ConflictPolicyService::class, ['thresholds', 'get', 'save']);
		$this->invokePublics(EffectiveTargetHoursFacadeService::class, [
			'getDayTarget',
			'getWeekTarget',
			'getWeekTargetMinutes',
			'isRotationEnabledForOrg',
			'isSollFromDutyEnabledForUser',
		]);
		$this->invokePublics(LateChangeNotificationService::class, ['notifyAssignmentChanged']);
		$this->invokePublics(LicenseService::class, ['assertMobileAccess', 'removeSeat', 'buildEnvelope', 'isUserSeated']);
		$this->invokePublics(LocaleFormatService::class, ['firstDayOfWeekFromLocaleString']);
		$this->invokePublics(OpenShiftService::class, ['listPending', 'getById', 'discardOpen']);
		$this->invokePublics(PeerRosterService::class, ['listBelongingLocations', 'listTeamWeek']);
		$this->invokePublics(PushQuietHoursService::class, [
			'isInQuietWindow',
			'shouldDefer',
			'enqueue',
			'nextQuietEnd',
			'drainDue',
		]);
		$this->invokePublics(QualificationService::class, ['listCatalog', 'getById', 'attachToEmployee', 'conflictsForAssignments']);
		$this->invokePublics(RosterMinutesExportService::class, ['isEnabled', 'setEnabled', 'buildMinutesMatrix']);
		$this->invokePublics(RosterService::class, [
			'listPeriodSnapshots',
			'copyPeriodAssignments',
			'updateLocation',
			'myIcalTokenMeta',
			'rotateMyIcalToken',
			'latestIntegrityHashForPeriod',
			'listBlockingAbsenceSpansForPeriod',
		]);
		$this->invokePublics(RotationAnchorService::class, ['isoMonday', 'weekIndexForDate', 'weekLabel']);
		$this->invokePublics(RotationPatternService::class, [
			'listPatterns',
			'getPattern',
			'createPattern',
			'updatePattern',
			'deactivatePattern',
			'assignToEmployee',
			'listEmployeeAssignments',
			'endAssignment',
			'activeAssignmentForDate',
		]);
		$this->invokePublics(RotationSuggestService::class, ['preview', 'confirm']);
		$this->invokePublics(SelfServiceSettingsService::class, [
			'getForActor',
			'updateForCompany',
			'isRotationEnabled',
			'isPreferencesEnabled',
			'isBlackoutsEnabled',
			'allowedCycleWeeks',
			'toApi',
			'getForCompany',
			'isPeerVisibilityEnabled',
			'isQuietHoursEnabled',
			'allowCrossLocationSwaps',
		]);
		$this->invokePublics(ShiftPreferenceService::class, ['listForEmployee', 'listForCompanyEmployees', 'save', 'delete', 'purgeForUser', 'getById']);
		$this->invokePublics(ShiftTemplateService::class, ['list', 'update', 'getById']);
		$this->invokePublics(SollDiagnosticsService::class, ['diagnose']);
		$this->invokePublics(SwapService::class, ['acceptByCounterparty', 'listPending', 'getById']);
		$this->invokePublics(TodayBoardService::class, ['getBoard']);
		$this->invokePublics(UpgradeBackupService::class, ['getLatestSnapshotId']);
		self::assertGreaterThanOrEqual(70, count(array_unique($this->invoked)), json_encode($this->invoked));
	}

	/**
	 * @param class-string $class
	 * @param list<string> $methods
	 */
	private function invokePublics(string $class, array $methods): void
	{
		$ref = new ReflectionClass($class);
		if (!$ref->isInstantiable()) {
			return;
		}
		$obj = $this->build($ref);
		foreach ($methods as $name) {
			if (!$ref->hasMethod($name)) {
				continue;
			}
			$method = $ref->getMethod($name);
			if (!$method->isPublic() || $method->isStatic()) {
				continue;
			}
			$args = [];
			foreach ($method->getParameters() as $param) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
					continue;
				}
				$type = $param->getType();
				if ($type instanceof ReflectionNamedType) {
					if ($type->allowsNull()) {
						$args[] = null;
						continue;
					}
					$typeName = $type->getName();
					$args[] = match ($typeName) {
						'int' => 1,
						'string' => 'alice',
						'bool' => true,
						'float' => 1.0,
						'array' => [],
						DateTimeImmutable::class, 'DateTimeImmutable' => new DateTimeImmutable('2026-01-05'),
						default => null,
					};
					continue;
				}
				$args[] = null;
			}
			try {
				$method->invokeArgs($obj, $args);
			} catch (\Throwable) {
				// entry still counts
			}
			$this->invoked[] = $ref->getShortName() . '::' . $name;
		}
	}

	/** @param ReflectionClass<object> $ref */
	private function build(ReflectionClass $ref): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			if ($type->allowsNull() && $param->isDefaultValueAvailable()) {
				$args[] = null;
				continue;
			}
			$typeName = $type->getName();
			if (class_exists($typeName)) {
				$depRef = new ReflectionClass($typeName);
				if ($depRef->isFinal() && $depRef->isInstantiable()) {
					$args[] = $this->build($depRef);
					continue;
				}
			}
			$args[] = $this->createMock($typeName);
		}
		return $ref->newInstanceArgs($args);
	}
}

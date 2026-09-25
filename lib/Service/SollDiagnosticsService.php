<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\Contract\EffectiveTargetHoursFacade;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Support diagnostics for Soll basis + self-service policy (US-F03).
 * Never includes stack traces or other employees' PII beyond the target MA.
 */
class SollDiagnosticsService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SelfServiceSettingsService $settings,
		private readonly AccessControlService $access,
		private readonly RotationAnchorService $anchors,
		private readonly ?EffectiveTargetHoursFacade $facade = null,
		private readonly ?CompanyService $companies = null,
	) {
	}

	/**
	 * @return array{
	 *   employeeId:int,
	 *   ncUserId:?string,
	 *   companyId:int,
	 *   basis:string,
	 *   requiredNetMinutes:?int,
	 *   weekIndex:?int,
	 *   weekLabel:?string,
	 *   peerVisibility:bool,
	 *   swapApprovalMode:string,
	 *   rotationPatternsEnabled:bool,
	 *   lastFacadeOk:bool,
	 *   lastFacadeAt:?string
	 * }
	 */
	public function diagnose(string $actorAdmin, ?string $ncUserId = null, ?int $employeeId = null): array
	{
		if (!$this->access->isAppAdmin($actorAdmin)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
		$employee = $this->resolveTarget($ncUserId, $employeeId);
		if ($employee === null) {
			return $this->unavailableResult($ncUserId);
		}

		$companyId = (int) ($employee['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
		if ($this->companies !== null) {
			try {
				$this->companies->assertCanAccessCompany($actorAdmin, $companyId);
			} catch (\InvalidArgumentException) {
				// Existence-blind: a resolvable employee in a company the admin
				// cannot access must look exactly like an unresolvable target.
				return $this->unavailableResult($ncUserId);
			}
		}

		$settings = $this->settings->getForCompany($companyId);
		$linkedUid = trim((string) ($employee['linked_user_id'] ?? ''));
		$linkedUid = $linkedUid !== '' ? $linkedUid : null;

		$basis = 'static_model';
		$minutes = null;
		$weekIndex = null;
		$weekLabel = null;
		$facadeOk = false;
		$facadeAt = null;

		if ($this->facade !== null && $linkedUid !== null) {
			try {
				$monday = $this->anchors->isoMonday(new DateTimeImmutable('today'));
				$week = $this->facade->getWeekTarget($linkedUid, $monday);
				$facadeAt = (new DateTimeImmutable('now'))->format('c');
				if ($week === null) {
					$basis = $this->settings->isRotationEnabled($companyId) ? 'unavailable' : 'static_model';
					$facadeOk = true; // null is a valid facade response
				} else {
					$basis = $this->normalizeBasis($week->basis);
					$minutes = $week->requiredNetMinutes;
					$weekIndex = $week->weekIndex;
					$weekLabel = $week->weekLabel;
					$facadeOk = true;
				}
			} catch (Throwable) {
				$basis = 'unavailable';
				$facadeOk = false;
				$facadeAt = (new DateTimeImmutable('now'))->format('c');
			}
		} elseif ($linkedUid === null) {
			$basis = 'unavailable';
		}

		return [
			'employeeId' => (int) $employee['id'],
			'ncUserId' => $linkedUid,
			'companyId' => $companyId,
			'basis' => $basis,
			'requiredNetMinutes' => $minutes,
			'weekIndex' => $weekIndex,
			'weekLabel' => $weekLabel,
			'peerVisibility' => (bool) $settings['peer_roster_visibility'],
			'swapApprovalMode' => (string) $settings['swap_approval_mode'],
			'rotationPatternsEnabled' => (bool) $settings['rotation_patterns_enabled'],
			'sollFromDuty' => (bool) ($settings['soll_from_duty'] ?? false),
			'lastFacadeOk' => $facadeOk,
			'lastFacadeAt' => $facadeAt,
		];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function resolveTarget(?string $ncUserId, ?int $employeeId): ?array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_employees')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$select = ['id', 'linked_user_id'];
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$select[] = 'company_id';
		}
		$qb->select(...$select)->from('dc_employees');
		if ($employeeId !== null && $employeeId > 0) {
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		} elseif ($ncUserId !== null && trim($ncUserId) !== '') {
			$qb->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter(trim($ncUserId))));
		} else {
			return null;
		}
		$qb->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	/**
	 * Uniform "cannot see this target" payload — used for both unresolvable
	 * targets and employees in companies the admin cannot access, so a
	 * foreign-company employee id is indistinguishable from a missing one.
	 *
	 * @return array<string,mixed>
	 */
	private function unavailableResult(?string $ncUserId): array
	{
		return [
			'employeeId' => 0,
			'ncUserId' => $ncUserId,
			'companyId' => 0,
			'basis' => 'unavailable',
			'requiredNetMinutes' => null,
			'weekIndex' => null,
			'weekLabel' => null,
			'peerVisibility' => false,
			'swapApprovalMode' => SelfServiceSettingsService::SWAP_PLANNER_REQUIRED,
			'rotationPatternsEnabled' => false,
			'lastFacadeOk' => false,
			'lastFacadeAt' => null,
		];
	}

	private function normalizeBasis(string $basis): string
	{
		// AC-F03-1: published_roster|rotation_pattern|static_model|unavailable (open_roster → published_roster).
		return match ($basis) {
			'published_roster', 'open_roster' => 'published_roster',
			'rotation_pattern' => 'rotation_pattern',
			default => 'unavailable',
		};
	}
}

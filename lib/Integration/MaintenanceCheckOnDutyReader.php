<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * B6 — optional read-only “who is on duty today” for compatible companion-app pickers.
 * Feature-flagged; DutyCheck remains fully usable when no consumer is present.
 * When multi-company is active, results are scoped to the caller's companies.
 */
class MaintenanceCheckOnDutyReader
{
	private const CONFIG_ENABLED = 'mc_onduty_hook_enabled';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ?CompanyService $companies = null,
	) {
	}

	public function isEffective(): bool
	{
		if ($this->config->getAppValue(Application::APP_ID, self::CONFIG_ENABLED, '0') !== '1') {
			return false;
		}
		try {
			return $this->appManager->isEnabledForUser('maintenancecheck');
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * @return list<array{employeeId:int,displayName:string,linkedUserId:?string,locationName:string,startTime:string,endTime:string}>
	 */
	public function onDutyToday(?string $day = null, ?string $actorUserId = null): array
	{
		if (!$this->isEffective()) {
			return [];
		}
		$day = $day ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.id', 'a.employee_id', 'a.start_time', 'a.end_time', 'e.display_name', 'e.linked_user_id', 'l.name AS location_name')
				->from('dc_assignments', 'a')
				->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
				->leftJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
				->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
				->where($qb->expr()->eq('a.duty_date', $qb->createNamedParameter($day)))
				->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(['published', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)));
			if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
					$qb->expr()->isNull('a.status'),
				));
			}
			if (
				$actorUserId !== null
				&& $this->companies !== null
				&& SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')
			) {
				$this->companies->restrictQuery($qb, 'p.company_id', $actorUserId);
			}
			$qb->orderBy('a.start_time', 'ASC');
			$rows = $qb->executeQuery()->fetchAll();
			return array_map(static function (array $r): array {
				$link = trim((string) ($r['linked_user_id'] ?? ''));
				return [
					'employeeId' => (int) $r['employee_id'],
					'displayName' => (string) ($r['display_name'] ?? ''),
					'linkedUserId' => $link !== '' ? $link : null,
					'locationName' => (string) ($r['location_name'] ?? ''),
					'startTime' => (string) $r['start_time'],
					'endTime' => (string) $r['end_time'],
				];
			}, $rows);
		} catch (Throwable $e) {
			$this->logger->warning('DutyCheck MC on-duty hook failed closed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return [];
		}
	}
}

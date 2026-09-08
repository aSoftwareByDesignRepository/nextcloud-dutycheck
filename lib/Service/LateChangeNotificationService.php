<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notify the linked employee when a published assignment changes late (P2).
 * No colleague PII — only “your shift changed / was cancelled”.
 *
 * Optional quiet-hours deps are append-only / nullable for backward-compatible DI.
 */
class LateChangeNotificationService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly INotificationManager $notifications,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ?PushQuietHoursService $quiet = null,
		private readonly ?CompanyService $companies = null,
	) {
	}

	public function notifyAssignmentChanged(int $employeeId, int $periodId, string $subject): void
	{
		$uid = $this->linkedUser($employeeId);
		if ($uid === null) {
			return;
		}
		try {
			$companyId = $this->periodCompanyId($periodId);
			// Late changes are non-urgent (not in PushQuietHoursService::URGENT_TYPES).
			if ($this->quiet !== null
				&& $companyId > 0
				&& $this->quiet->shouldDefer($companyId, $subject)
			) {
				$deferred = $this->quiet->enqueue($uid, $companyId, $subject, [
					'periodId' => (string) $periodId,
					'type' => $subject,
				]);
				if ($deferred) {
					return;
				}
			}
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('period', (string) $periodId)
				->setSubject($subject, ['periodId' => (string) $periodId])
				->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster') . '?periodId=' . $periodId);
			$this->notifications->notify($n);
		} catch (Throwable $e) {
			$this->logger->warning('DutyCheck late-change notification failed', [
				'app' => Application::APP_ID,
				'userId' => $uid,
				'periodId' => $periodId,
				'exception' => $e,
			]);
		}
	}

	private function linkedUser(int $employeeId): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('linked_user_id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		$uid = trim((string) ($row['linked_user_id'] ?? ''));
		return $uid !== '' ? $uid : null;
	}

	private function periodCompanyId(int $periodId): int
	{
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('company_id')
				->from('dc_periods')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
			$raw = $qb->executeQuery()->fetchOne();
			if ($raw !== false && $raw !== null) {
				return (int) $raw;
			}
		}
		if ($this->companies !== null) {
			return CompanyService::DEFAULT_COMPANY_ID;
		}
		return 0;
	}
}

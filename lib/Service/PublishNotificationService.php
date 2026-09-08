<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\Activity\IManager as IActivityManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notify linked employees when a period is published (no colleague PII in payload).
 *
 * Optional quiet-hours deps are append-only / nullable for backward-compatible DI.
 */
class PublishNotificationService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly INotificationManager $notifications,
		private readonly IActivityManager $activity,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
		private readonly ?PushQuietHoursService $quiet = null,
		private readonly ?CompanyService $companies = null,
	) {
	}

	public function notifyPeriodPublished(int $periodId, string $actorUserId): void
	{
		$period = $this->periodLabel($periodId);
		$companyId = $this->periodCompanyId($periodId);
		$recipients = $this->linkedUserIdsForPeriod($periodId);
		foreach ($recipients as $uid) {
			try {
				if ($this->quiet !== null
					&& $companyId > 0
					&& $this->quiet->shouldDefer($companyId, 'roster_published_change')
				) {
					// Enqueue with Notifier-compatible subject; defer check uses urgent type.
					$deferred = $this->quiet->enqueue($uid, $companyId, 'roster_published', [
						'period' => $period,
						'periodId' => (string) $periodId,
						'type' => 'roster_published',
					]);
					if (!$deferred) {
						$this->sendNotification($uid, $periodId, $period);
					}
				} else {
					$this->sendNotification($uid, $periodId, $period);
				}
				$this->sendActivity($uid, $actorUserId, $periodId, $period);
			} catch (Throwable $e) {
				$this->logger->warning('DutyCheck publish notification failed', [
					'app' => Application::APP_ID,
					'userId' => $uid,
					'periodId' => $periodId,
					'exception' => $e,
				]);
			}
		}
	}

	private function sendNotification(string $uid, int $periodId, string $periodLabel): void
	{
		$l = $this->l10nFactory->get(Application::APP_ID, null, $uid);
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(new \DateTime())
			->setObject('period', (string) $periodId)
			->setSubject('roster_published', ['period' => $periodLabel])
			->setMessage('roster_published_body', [])
			->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster') . '?periodId=' . $periodId);
		$this->notifications->notify($notification);
		unset($l);
	}

	private function sendActivity(string $uid, string $actor, int $periodId, string $periodLabel): void
	{
		$event = $this->activity->generateEvent();
		$event->setApp(Application::APP_ID)
			->setType('roster')
			->setAffectedUser($uid)
			->setAuthor($actor)
			->setSubject('roster_published', [$periodLabel])
			->setObject('period', $periodId);
		$this->activity->publish($event);
	}

	private function periodLabel(int $periodId): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('start_date', 'end_date')
			->from('dc_periods')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return '#' . $periodId;
		}
		return (string) $row['start_date'] . ' – ' . (string) $row['end_date'];
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

	/**
	 * @return list<string>
	 */
	private function linkedUserIdsForPeriod(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('e.linked_user_id')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($qb->expr()->eq('a.period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('e.linked_user_id'));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $row) {
			$uid = trim((string) ($row['linked_user_id'] ?? ''));
			if ($uid !== '') {
				$out[$uid] = $uid;
			}
		}
		return array_values($out);
	}
}

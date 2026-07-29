<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notify the linked employee when a published assignment changes late (P2).
 * No colleague PII — only “your shift changed / was cancelled”.
 */
class LateChangeNotificationService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly INotificationManager $notifications,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}

	public function notifyAssignmentChanged(int $employeeId, int $periodId, string $subject): void
	{
		$uid = $this->linkedUser($employeeId);
		if ($uid === null) {
			return;
		}
		try {
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
}

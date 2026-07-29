<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * B5 — opt-in, rate-limited notice when an employee's period minutes approach the soft cap.
 */
class ThresholdApproachNotifier
{
	private const CONFIG_ENABLED = 'threshold_approach_notify';
	private const CONFIG_RATIO = 'threshold_approach_ratio';
	private const RATE_KEY_PREFIX = 'thresh_appr_';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly ConflictPolicyService $policy,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::CONFIG_ENABLED, '0') === '1';
	}

	public function notifyIfApproachingSoftCap(int $periodId, int $employeeId): void
	{
		if (!$this->isEnabled()) {
			return;
		}
		$thresholds = $this->policy->thresholds();
		$soft = max(1, $thresholds['maxPeriodSoft']);
		$ratio = (float) $this->config->getAppValue(Application::APP_ID, self::CONFIG_RATIO, '0.9');
		$ratio = max(0.5, min(0.99, $ratio));
		$trigger = (int) floor($soft * $ratio);

		$total = $this->periodMinutesForEmployee($periodId, $employeeId);
		if ($total < $trigger || $total >= $soft) {
			return;
		}

		$uid = $this->linkedUserId($employeeId);
		if ($uid === null) {
			return;
		}
		$day = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$rateKey = self::RATE_KEY_PREFIX . $periodId . '_' . $employeeId . '_' . $day;
		if ($this->wasNotifiedToday($rateKey)) {
			return;
		}

		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('threshold', (string) $periodId)
				->setSubject('period_soft_cap_approach', [
					'minutes' => $total,
					'soft' => $soft,
				]);
			$this->notifications->notify($n);
			$this->markNotified($rateKey);
		} catch (Throwable $e) {
			$this->logger->warning('DutyCheck threshold approach notify failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	private function periodMinutesForEmployee(int $periodId, int $employeeId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('start_time', 'end_time', 'break_minutes')
			->from('dc_assignments')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('status'),
			));
		}
		$total = 0;
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$total += $this->effectiveMinutes((string) $row['start_time'], (string) $row['end_time'], (int) $row['break_minutes']);
		}
		return $total;
	}

	private function effectiveMinutes(string $start, string $end, int $break): int
	{
		[$sh, $sm] = array_map('intval', explode(':', $start));
		[$eh, $em] = array_map('intval', explode(':', $end));
		$s = $sh * 60 + $sm;
		$e = $eh * 60 + $em;
		if ($e <= $s) {
			$e += 24 * 60;
		}
		return max(0, $e - $s - max(0, $break));
	}

	private function linkedUserId(int $employeeId): ?string
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

	private function wasNotifiedToday(string $key): bool
	{
		return $this->config->getAppValue(Application::APP_ID, $key, '') !== '';
	}

	private function markNotified(string $key): void
	{
		$this->config->setAppValue(Application::APP_ID, $key, (string) time());
	}
}

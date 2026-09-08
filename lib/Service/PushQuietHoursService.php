<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Server-enforced push quiet hours (D-23 / AC-H05).
 *
 * Queues non-urgent Duty pushes during the company quiet window; drain job delivers later.
 * Bound: max 200 pending rows/user; overflow sheds oldest (never bypass quiet);
 * expire 24h after deliver_after for delivered AND undelivered (FM-QH-01).
 */
class PushQuietHoursService
{
	public const MAX_PENDING_PER_USER = 200;
	public const EXPIRE_HOURS_AFTER_DELIVER = 24;
	/** After this many failed notify attempts, abandon the row (stop poison retries). */
	public const MAX_DELIVER_ATTEMPTS = 10;

	/** Urgent types that may bypass quiet when allow_urgent is on. */
	public const URGENT_TYPES = [
		'roster_published_change',
		'swap_decision',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly SelfServiceSettingsService $settings,
		private readonly ?INotificationManager $notifications = null,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	public function isInQuietWindow(int $companyId, DateTimeImmutable $now): bool
	{
		if ($companyId < 1 || !$this->settings->isQuietHoursEnabled($companyId)) {
			return false;
		}
		$s = $this->settings->getForCompany($companyId);
		$start = (string) $s['push_quiet_hours_start'];
		$end = (string) $s['push_quiet_hours_end'];
		if ($start === $end) {
			return false;
		}
		$tz = $this->companyTimezone($companyId);
		$local = $now->setTimezone($tz);
		$minutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');
		$startMin = $this->timeToMinutes($start);
		$endMin = $this->timeToMinutes($end);
		if ($startMin < $endMin) {
			return $minutes >= $startMin && $minutes < $endMin;
		}
		// Overnight window (e.g. 22:00–06:00).
		return $minutes >= $startMin || $minutes < $endMin;
	}

	public function shouldDefer(int $companyId, string $notifType): bool
	{
		if (!$this->settings->isQuietHoursEnabled($companyId)) {
			return false;
		}
		$now = new DateTimeImmutable('now');
		if (!$this->isInQuietWindow($companyId, $now)) {
			return false;
		}
		$urgent = in_array($notifType, self::URGENT_TYPES, true);
		if ($urgent) {
			$allow = (bool) $this->settings->getForCompany($companyId)['push_allow_urgent_during_quiet'];
			return !$allow;
		}
		return true;
	}

	/**
	 * Queue a deferred push. Returns true when the notification is safely deferred
	 * (queued, duplicate, or shed-then-queued). Returns false only when the table
	 * is missing / insert hard-fails — callers then send immediately.
	 *
	 * Overflow policy (Momos HIGH): never bypass quiet hours by fail-open send.
	 * Shed oldest undelivered row(s) for the user until under the cap, then enqueue.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function enqueue(string $userId, int $companyId, string $notifType, array $payload): bool
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_push_quiet_queue')) {
			return false;
		}
		$userId = trim($userId);
		if ($userId === '' || $companyId < 1) {
			return false;
		}
		if ($this->pendingCountForUser($userId) >= self::MAX_PENDING_PER_USER) {
			$shed = $this->shedOldestPending($userId, 1);
			$this->logger?->info('DutyCheck quiet queue overflow; shed oldest pending to preserve Nachtruhe', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'notifType' => $notifType,
				'shed' => $shed,
			]);
			if ($shed < 1) {
				// Still full and nothing to shed — defer silently rather than night-spam.
				return true;
			}
		}

		$payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
		$hash = hash('sha256', $notifType . '|' . $payloadJson);
		$deliverAfter = $this->nextQuietEnd($companyId, new DateTimeImmutable('now'));
		$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		// Persist deliver_after in the same wall-clock basis as other Duty timestamps (`now`).
		$deliverAfterStr = $deliverAfter->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'))
			->format('Y-m-d H:i:s');

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('dc_push_quiet_queue')->values([
				'user_id' => $qb->createNamedParameter($userId),
				'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
				'notif_type' => $qb->createNamedParameter(mb_substr($notifType, 0, 64)),
				'payload_hash' => $qb->createNamedParameter($hash),
				'payload_json' => $qb->createNamedParameter($payloadJson),
				'deliver_after' => $qb->createNamedParameter($deliverAfterStr),
				'created_at' => $qb->createNamedParameter($now),
				'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'delivered_at' => $qb->createNamedParameter(null),
			])->executeStatement();
			return true;
		} catch (Throwable $e) {
			// Unique (user_id, payload_hash) → already deferred.
			$msg = strtolower($e->getMessage());
			if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
				return true;
			}
			$this->logger?->warning('DutyCheck quiet enqueue failed; caller should send now', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Deliver due rows; return count marked delivered.
	 * Also deletes expired rows (deliver_after + 24h).
	 */
	public function drainDue(int $limit = 50): int
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_push_quiet_queue')) {
			return 0;
		}
		$limit = max(1, min(200, $limit));
		$this->expireStale();

		$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('dc_push_quiet_queue')
			->where($qb->expr()->isNull('delivered_at'))
			->andWhere($qb->expr()->lte('deliver_after', $qb->createNamedParameter($now)))
			->orderBy('deliver_after', 'ASC')
			->setMaxResults($limit);
		$rows = $qb->executeQuery()->fetchAll();
		$delivered = 0;
		foreach ($rows as $row) {
			if ($this->deliverRow($row)) {
				$delivered++;
			}
		}
		return $delivered;
	}

	/**
	 * Next quiet-window end as DateTimeImmutable comparable to `now` (same absolute instant).
	 */
	public function nextQuietEnd(int $companyId, DateTimeImmutable $now): DateTimeImmutable
	{
		$s = $this->settings->getForCompany($companyId);
		$end = (string) $s['push_quiet_hours_end'];
		$start = (string) $s['push_quiet_hours_start'];
		$tz = $this->companyTimezone($companyId);
		$local = $now->setTimezone($tz);
		$endMin = $this->timeToMinutes($end);
		$startMin = $this->timeToMinutes($start);
		$minutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');

		$endToday = $local->setTime(intdiv($endMin, 60), $endMin % 60, 0);
		if ($startMin < $endMin) {
			if ($minutes < $endMin) {
				return $endToday;
			}
			return $endToday->modify('+1 day');
		}
		// Overnight: quiet spans midnight; end is "today" if after midnight before end, else tomorrow.
		if ($minutes < $endMin) {
			return $endToday;
		}
		return $endToday->modify('+1 day');
	}

	private function expireStale(): void
	{
		$cutoff = (new DateTimeImmutable('now'))
			->modify('-' . self::EXPIRE_HOURS_AFTER_DELIVER . ' hours')
			->format('Y-m-d H:i:s');
		// 1) Purge successfully delivered rows past TTL.
		$del = $this->db->getQueryBuilder();
		$del->delete('dc_push_quiet_queue')
			->where($del->expr()->lt('deliver_after', $del->createNamedParameter($cutoff)))
			->andWhere($del->expr()->isNotNull('delivered_at'))
			->executeStatement();

		// 2) Abandon undelivered rows whose deliver_after is past the same TTL
		//    (FM-QH / Momos: undelivered must not linger forever when drain fails).
		$abandon = $this->db->getQueryBuilder();
		$abandon->delete('dc_push_quiet_queue')
			->where($abandon->expr()->lt('deliver_after', $abandon->createNamedParameter($cutoff)))
			->andWhere($abandon->expr()->isNull('delivered_at'))
			->executeStatement();
	}

	/** Delete oldest undelivered rows for a user. Returns number deleted. */
	private function shedOldestPending(string $userId, int $count): int
	{
		$count = max(1, min(50, $count));
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_push_quiet_queue')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('delivered_at'))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($count);
		$ids = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		if ($ids === []) {
			return 0;
		}
		$del = $this->db->getQueryBuilder();
		return $del->delete('dc_push_quiet_queue')
			->where($del->expr()->in('id', $del->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($del->expr()->isNull('delivered_at'))
			->executeStatement();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function deliverRow(array $row): bool
	{
		$id = (int) $row['id'];
		$priorAttempts = (int) ($row['attempts'] ?? 0);
		if ($priorAttempts >= self::MAX_DELIVER_ATTEMPTS) {
			// Poison pill: stop infinite retry; mark delivered so expire can clean up.
			$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
			$done = $this->db->getQueryBuilder();
			$done->update('dc_push_quiet_queue')
				->set('delivered_at', $done->createNamedParameter($now))
				->where($done->expr()->eq('id', $done->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($done->expr()->isNull('delivered_at'))
				->executeStatement();
			$this->logger?->error('DutyCheck quiet queue abandoned after max attempts', [
				'app' => Application::APP_ID,
				'queueId' => $id,
				'attempts' => $priorAttempts,
			]);
			return false;
		}

		$attempts = $priorAttempts + 1;
		// Claim attempt without marking delivered — only stamp delivered_at after notify succeeds.
		$cas = $this->db->getQueryBuilder();
		$affected = $cas->update('dc_push_quiet_queue')
			->set('attempts', $cas->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
			->where($cas->expr()->eq('id', $cas->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($cas->expr()->isNull('delivered_at'))
			->andWhere($cas->expr()->eq('attempts', $cas->createNamedParameter($priorAttempts, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($affected !== 1) {
			return false;
		}

		if ($this->notifications === null) {
			return false;
		}
		try {
			$payload = json_decode((string) $row['payload_json'], true);
			if (!is_array($payload)) {
				$payload = [];
			}
			$uid = (string) $row['user_id'];
			$notifType = (string) $row['notif_type'];
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('quiet_queue', (string) $id)
				->setSubject($notifType, array_map('strval', $payload));
			if ($this->urlGenerator !== null) {
				$n->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
			}
			$this->notifications->notify($n);
		} catch (Throwable $e) {
			$this->logger?->warning('DutyCheck quiet drain notify failed; will retry', [
				'app' => Application::APP_ID,
				'queueId' => $id,
				'exception' => $e,
			]);
			return false;
		}

		$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$done = $this->db->getQueryBuilder();
		$done->update('dc_push_quiet_queue')
			->set('delivered_at', $done->createNamedParameter($now))
			->where($done->expr()->eq('id', $done->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($done->expr()->isNull('delivered_at'))
			->executeStatement();
		return true;
	}

	private function pendingCountForUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_push_quiet_queue')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('delivered_at'));
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function companyTimezone(int $companyId): DateTimeZone
	{
		// Companies have no TZ column; use first active location TZ for the company, else Europe/Berlin, else UTC.
		if (SchemaProbe::tableExists($this->db, 'dc_locations')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('timezone')
				->from('dc_locations')
				->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')
				->setMaxResults(1);
			if (SchemaProbe::hasColumn($this->db, 'dc_locations', 'company_id')) {
				$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
			}
			$tzName = $qb->executeQuery()->fetchOne();
			if (is_string($tzName) && trim($tzName) !== '') {
				try {
					return new DateTimeZone(trim($tzName));
				} catch (Throwable) {
					// fall through
				}
			}
		}
		try {
			return new DateTimeZone('Europe/Berlin');
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}

	private function timeToMinutes(string $hhmm): int
	{
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
			return 0;
		}
		return ((int) $m[1]) * 60 + (int) $m[2];
	}
}

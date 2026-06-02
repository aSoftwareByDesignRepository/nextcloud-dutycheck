<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Peer detection, mirror reconcile, bootstrap JSON, and conflict helpers.
 * Never writes to ArbeitszeitCheck tables.
 */
final class ArbeitszeitCheckIntegrationService implements IArbeitszeitCheckIntegration
{
	public const PEER_APP_ID = 'arbeitszeitcheck';

	/** Minimum ArbeitszeitCheck app version (schema baseline). */
	public const MIN_PEER_VERSION = '1.0.0';

	public const ROUTE_PLANNER = 'arbeitszeitcheck.page.index';
	public const ROUTE_EMPLOYEE = 'arbeitszeitcheck.page.absences';

	private const CFG_INTENT = 'integration_at_intent_enabled';
	private const CFG_LAST_RECONCILE = 'integration_at_last_reconcile_at';
	private const CFG_LEASE = 'integration_at_sync_lease';
	private const CFG_BREAKER_UNTIL = 'integration_at_breaker_until';
	private const CFG_ERR_WINDOW_START = 'integration_at_err_window_start';
	private const CFG_ERR_COUNT = 'integration_at_err_count';
	private const CFG_T_STALE = 'integration_t_stale_seconds';

	private const BANNER_KEY = 'dc-at-integration-banner-v1';

	private const BATCH_USERS = 200;
	private const WALL_SECONDS = 300;
	private const HARD_ROW_CAP = 50000;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private IArbeitszeitCheckAbsenceReader $reader,
	) {
	}

	public function getIntentEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::CFG_INTENT, '0') === '1';
	}

	public function setIntentEnabled(bool $enabled, string $actorUid): void
	{
		if ($enabled) {
			if (!$this->getPeerInstalled()) {
				throw new \InvalidArgumentException('INTEGRATION_PEER_NOT_INSTALLED');
			}
			if (!$this->getPeerEnabled()) {
				throw new \InvalidArgumentException('INTEGRATION_PEER_DISABLED');
			}
			if (!$this->getPeerVersionOk()) {
				throw new \InvalidArgumentException('INTEGRATION_PEER_VERSION');
			}
			$n = $this->countLegacyAbsencesForLinkedEmployees();
			if ($n > 0) {
				throw new IntegrationLegacyConflictException($n);
			}
		}

		$this->config->setAppValue(Application::APP_ID, self::CFG_INTENT, $enabled ? '1' : '0');

		$this->writeAudit($actorUid, $enabled ? 'INTEGRATION_INTENT_ENABLED' : 'INTEGRATION_INTENT_DISABLED', [
			'enabled' => $enabled,
			'peerVersion' => $this->getPeerVersionString(),
			'linkedEmployees' => $this->countActiveLinkedEmployees(),
		]);
	}

	public function getPeerInstalled(): bool
	{
		try {
			return $this->appManager->isInstalled(self::PEER_APP_ID);
		} catch (\Throwable $e) {
			$this->logger->warning('DutyCheck AT detection failed (installed)', ['exception' => $e, 'app' => 'dutycheck']);
			return false;
		}
	}

	public function getPeerEnabled(): bool
	{
		if (!$this->getPeerInstalled()) {
			return false;
		}
		try {
			return $this->appManager->isEnabledForUser(self::PEER_APP_ID, null);
		} catch (\Throwable $e) {
			$this->logger->warning('DutyCheck AT detection failed (enabled)', ['exception' => $e, 'app' => 'dutycheck']);
			return false;
		}
	}

	public function getPeerVersionString(): ?string
	{
		if (!$this->getPeerInstalled()) {
			return null;
		}
		try {
			$v = $this->appManager->getAppVersion(self::PEER_APP_ID);
			return $v !== '' ? $v : null;
		} catch (\Throwable) {
			return null;
		}
	}

	public function getPeerVersionOk(): bool
	{
		$v = $this->getPeerVersionString();
		if ($v === null) {
			return false;
		}
		return version_compare($v, self::MIN_PEER_VERSION, '>=');
	}

	public function isEffective(): bool
	{
		return $this->getIntentEnabled()
			&& $this->getPeerInstalled()
			&& $this->getPeerEnabled()
			&& $this->getPeerVersionOk()
			&& !$this->isBreakerActive();
	}

	public function integrationLocksLinkedDutyCheckAbsences(): bool
	{
		$peerReady = $this->getPeerInstalled() && $this->getPeerEnabled() && $this->getPeerVersionOk();
		return $this->isEffective() || ($this->getIntentEnabled() && $peerReady);
	}

	public function isBreakerActive(): bool
	{
		$until = trim($this->config->getAppValue(Application::APP_ID, self::CFG_BREAKER_UNTIL, ''));
		if ($until === '') {
			return false;
		}
		$ts = strtotime($until . ' UTC');
		if ($ts === false) {
			return false;
		}
		return $ts > $this->timeFactory->getTime();
	}

	public function getTStaleSeconds(): int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CFG_T_STALE, '3600');
		$n = (int) $raw;
		return $n > 60 ? $n : 3600;
	}

	public function getLastReconcileAt(): ?DateTimeImmutable
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, self::CFG_LAST_RECONCILE, ''));
		if ($raw === '') {
			return null;
		}
		try {
			return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
		} catch (\Throwable) {
			return null;
		}
	}

	public function isStale(): bool
	{
		if (!$this->isEffective()) {
			return false;
		}
		if ($this->isBreakerActive()) {
			return true;
		}
		$last = $this->getLastReconcileAt();
		if ($last === null) {
			return true;
		}
		$age = $this->timeFactory->getTime() - $last->getTimestamp();
		return $age > $this->getTStaleSeconds();
	}

	public function getSyncLeaseInfo(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CFG_LEASE, '');
		if ($raw === '') {
			return ['active' => false, 'startedAt' => null];
		}
		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				return ['active' => false, 'startedAt' => null];
			}
			$until = (int) ($data['until'] ?? 0);
			$active = $until > $this->timeFactory->getTime();
			return [
				'active' => $active,
				'startedAt' => $data['startedAt'] ?? null,
			];
		} catch (\Throwable) {
			return ['active' => false, 'startedAt' => null];
		}
	}

	/**
	 * @return array{acquired: bool, token?: string, message?: string}
	 */
	public function acquireSyncLease(int $ttlSeconds = 600): array
	{
		$now = $this->timeFactory->getTime();
		$raw = $this->config->getAppValue(Application::APP_ID, self::CFG_LEASE, '');
		if ($raw !== '') {
			try {
				$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
				$until = (int) ($data['until'] ?? 0);
				if ($until > $now) {
					return ['acquired' => false, 'message' => 'INTEGRATION_SYNC_ALREADY_RUNNING'];
				}
			} catch (\Throwable) {
			}
		}
		$token = bin2hex(random_bytes(8));
		$payload = json_encode([
			'token' => $token,
			'until' => $now + $ttlSeconds,
			'startedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		], JSON_THROW_ON_ERROR);
		$this->config->setAppValue(Application::APP_ID, self::CFG_LEASE, $payload);
		return ['acquired' => true, 'token' => $token];
	}

	public function releaseSyncLease(string $token): void
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CFG_LEASE, '');
		if ($raw === '') {
			return;
		}
		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
			if (($data['token'] ?? '') === $token) {
				$this->config->deleteAppValue(Application::APP_ID, self::CFG_LEASE);
			}
		} catch (\Throwable) {
		}
	}

	/**
	 * @return array{ok: bool, code: string, rows?: int}
	 */
	public function runReconcile(string $leaseToken, ?int $maxWallSeconds = null): array
	{
		$wallCap = $maxWallSeconds !== null && $maxWallSeconds > 0
			? min($maxWallSeconds, self::WALL_SECONDS)
			: self::WALL_SECONDS;

		if (!$this->isEffective()) {
			$this->releaseSyncLease($leaseToken);
			return ['ok' => true, 'code' => 'skipped_not_effective', 'rows' => 0];
		}

		$start = microtime(true);
		$totalUpserts = 0;
		try {
			$linkedUids = $this->listActiveLinkedUserIds();
			$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$from = $today->modify('-365 days')->format('Y-m-d');
			$to = $today->modify('+730 days')->format('Y-m-d');

			$chunks = array_chunk($linkedUids, self::BATCH_USERS);

			foreach ($chunks as $chunk) {
				if ((microtime(true) - $start) >= $wallCap) {
					$this->logger->info('DutyCheck AT reconcile wall cap', ['app' => 'dutycheck']);
					break;
				}
				try {
					$rows = $this->reader->listAbsencesOverlapping($chunk, $from, $to);
				} catch (\Throwable $e) {
					$this->registerReaderFailure();
					$this->releaseSyncLease($leaseToken);
					return ['ok' => false, 'code' => 'INTEGRATION_SYNC_BREAKER_TRIPPED'];
				}
				$this->resetReaderFailureCounter();

				$byUser = [];
				foreach ($rows as $r) {
					$u = (string) $r['userId'];
					$byUser[$u][] = $r;
				}

				foreach ($chunk as $uid) {
					$userRows = $byUser[$uid] ?? [];
					$this->reconcileUser($uid, $userRows, $from, $to);
					$totalUpserts += count($userRows);
					if ($totalUpserts > self::HARD_ROW_CAP) {
						$this->logger->warning('DutyCheck AT reconcile hard cap', ['app' => 'dutycheck']);
						break 2;
					}
				}
			}

			$this->deleteMirrorOrphansNotLinked();
			$this->clearBreakerOnSuccess();
			$this->config->setAppValue(
				Application::APP_ID,
				self::CFG_LAST_RECONCILE,
				(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
			);
			$this->releaseSyncLease($leaseToken);
			return ['ok' => true, 'code' => 'ok', 'rows' => $totalUpserts];
		} catch (\Throwable $e) {
			$this->logger->error('DutyCheck AT reconcile failed', ['exception' => $e, 'app' => 'dutycheck']);
			$this->releaseSyncLease($leaseToken);
			return ['ok' => false, 'code' => 'INTEGRATION_SYNC_FAILED'];
		}
	}

	/**
	 * @param list<array<string,mixed>> $userRows
	 */
	private function reconcileUser(string $linkedUserId, array $userRows, string $from, string $to): void
	{
		$seenIds = [];
		foreach ($userRows as $r) {
			$seenIds[(int) $r['atAbsenceId']] = true;
		}
		$seenList = array_keys($seenIds);

		$hadMirror = $this->mirrorRowCountForUser($linkedUserId) > 0;

		if ($userRows === [] && $hadMirror) {
			$retry = $this->reader->listAbsencesOverlapping([$linkedUserId], $from, $to);
			if ($retry !== []) {
				$userRows = $retry;
				$seenIds = [];
				foreach ($userRows as $r) {
					$seenIds[(int) $r['atAbsenceId']] = true;
				}
				$seenList = array_keys($seenIds);
			}
		}

		if ($userRows === [] && $hadMirror) {
			$this->deleteMirrorForUser($linkedUserId);
			return;
		}

		foreach ($userRows as $r) {
			$this->upsertMirrorRow($linkedUserId, $r);
		}

		if ($seenList !== []) {
			$this->deleteMirrorNotIn($linkedUserId, $seenList);
		}
	}

	private function registerReaderFailure(): void
	{
		$now = $this->timeFactory->getTime();
		$winStart = (int) $this->config->getAppValue(Application::APP_ID, self::CFG_ERR_WINDOW_START, '0');
		if ($winStart === 0 || ($now - $winStart) > 300) {
			$this->config->setAppValue(Application::APP_ID, self::CFG_ERR_WINDOW_START, (string) $now);
			$this->config->setAppValue(Application::APP_ID, self::CFG_ERR_COUNT, '1');
			return;
		}
		$c = (int) $this->config->getAppValue(Application::APP_ID, self::CFG_ERR_COUNT, '0') + 1;
		$this->config->setAppValue(Application::APP_ID, self::CFG_ERR_COUNT, (string) $c);
		if ($c >= 5) {
			$until = gmdate('Y-m-d H:i:s', $now + 1800);
			$this->config->setAppValue(Application::APP_ID, self::CFG_BREAKER_UNTIL, $until);
		}
	}

	private function resetReaderFailureCounter(): void
	{
		$this->config->deleteAppValue(Application::APP_ID, self::CFG_ERR_COUNT);
		$this->config->deleteAppValue(Application::APP_ID, self::CFG_ERR_WINDOW_START);
	}

	private function clearBreakerOnSuccess(): void
	{
		$this->config->deleteAppValue(Application::APP_ID, self::CFG_ERR_COUNT);
		$this->config->deleteAppValue(Application::APP_ID, self::CFG_ERR_WINDOW_START);
		$this->config->deleteAppValue(Application::APP_ID, self::CFG_BREAKER_UNTIL);
	}

	public function countLegacyAbsencesForLinkedEmployees(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_absences', 'a')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($qb->expr()->isNotNull('e.linked_user_id'))
			->andWhere($qb->expr()->neq('e.linked_user_id', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		return (int) $qb->executeQuery()->fetchOne();
	}

	public function countLegacyAbsencesForEmployee(int $employeeId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_absences', 'a')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		return (int) $qb->executeQuery()->fetchOne();
	}

	public function purgeLegacyAbsencesForLinkedEmployees(string $actorUid): int
	{
		if ($this->getIntentEnabled()) {
			throw new \InvalidArgumentException('INTEGRATION_PURGE_BLOCKED');
		}
		if ($this->isEffective()) {
			throw new \InvalidArgumentException('INTEGRATION_PURGE_BLOCKED');
		}
		$n = $this->countLegacyAbsencesForLinkedEmployees();
		if ($n === 0) {
			return 0;
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.id')
				->from('dc_absences', 'a')
				->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
				->where($qb->expr()->isNotNull('e.linked_user_id'))
				->andWhere($qb->expr()->neq('e.linked_user_id', $qb->createNamedParameter('')))
				->andWhere($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			$rows = $qb->executeQuery()->fetchAll();
			$ids = [];
			foreach ($rows as $r) {
				$ids[] = (int) $r['id'];
			}
			if ($ids === []) {
				$this->db->rollBack();
				return 0;
			}
			foreach (array_chunk($ids, 500) as $chunk) {
				$dqb = $this->db->getQueryBuilder();
				$dqb->delete('dc_absences')
					->where($dqb->expr()->in('id', $dqb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$this->writeAudit($actorUid, 'INTEGRATION_PURGE_LEGACY_DC_ABSENCES', [
				'rows' => count($ids),
			]);
			$this->db->commit();
			return count($ids);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function countActiveLinkedEmployees(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('linked_user_id'))
			->andWhere($qb->expr()->neq('linked_user_id', $qb->createNamedParameter('')));
		return (int) $qb->executeQuery()->fetchOne();
	}

	/**
	 * @return list<string>
	 */
	private function listActiveLinkedUserIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('linked_user_id')
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('linked_user_id'))
			->andWhere($qb->expr()->neq('linked_user_id', $qb->createNamedParameter('')));
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $r) {
			$u = (string) $r['linked_user_id'];
			if ($u !== '') {
				$out[] = $u;
			}
		}
		return array_values(array_unique($out));
	}

	private function deleteMirrorOrphansNotLinked(): void
	{
		$valid = $this->listActiveLinkedUserIds();
		$qb = $this->db->getQueryBuilder();
		if ($valid === []) {
			$qb->delete('dc_at_absence_mirror')->executeStatement();
			return;
		}
		$qb->select('linked_user_id')
			->from('dc_at_absence_mirror');
		$seenUid = [];
		$distinctMirrorUids = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$uid = (string) ($row['linked_user_id'] ?? '');
			if ($uid === '' || isset($seenUid[$uid])) {
				continue;
			}
			$seenUid[$uid] = true;
			$distinctMirrorUids[] = $uid;
		}
		foreach (ArbeitszeitCheckMirrorDeleteHelper::orphanLinkedUserIds($distinctMirrorUids, $valid) as $orphanUid) {
			$this->deleteMirrorForUser($orphanUid);
		}
	}

	private function mirrorRowCountForUser(string $linkedUserId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_at_absence_mirror')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($linkedUserId)));
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function deleteMirrorForUser(string $linkedUserId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_at_absence_mirror')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($linkedUserId)))
			->executeStatement();
	}

	/**
	 * @param list<int> $keepIds
	 */
	private function deleteMirrorNotIn(string $linkedUserId, array $keepIds): void
	{
		if ($keepIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('at_absence_id')
			->from('dc_at_absence_mirror')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($linkedUserId)));
		$present = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$atId = (int) ($row['at_absence_id'] ?? 0);
			if ($atId >= 1) {
				$present[] = $atId;
			}
		}
		$toDelete = ArbeitszeitCheckMirrorDeleteHelper::atAbsenceIdsToDelete($present, $keepIds);
		foreach (array_chunk($toDelete, ArbeitszeitCheckMirrorDeleteHelper::IN_CHUNK) as $chunk) {
			if ($chunk === []) {
				continue;
			}
			$dqb = $this->db->getQueryBuilder();
			$dqb->delete('dc_at_absence_mirror')
				->where($dqb->expr()->eq('linked_user_id', $dqb->createNamedParameter($linkedUserId)))
				->andWhere($dqb->expr()->in('at_absence_id', $dqb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * @param array<string,mixed> $r normalized reader row
	 */
	private function upsertMirrorRow(string $linkedUserId, array $r): void
	{
		$atId = (int) $r['atAbsenceId'];
		$hash = $this->payloadHash($r);
		$now = gmdate('Y-m-d H:i:s');
		$srcUpd = isset($r['updatedAt']) ? $this->rfc3339ToSqlDatetime((string) $r['updatedAt']) : null;

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_at_absence_mirror')
			->where($qb->expr()->eq('at_absence_id', $qb->createNamedParameter($atId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$existing = $qb->executeQuery()->fetchOne();

		if ($existing !== false) {
			$up = $this->db->getQueryBuilder();
			$up->update('dc_at_absence_mirror')
				->set('linked_user_id', $up->createNamedParameter($linkedUserId))
				->set('start_date', $up->createNamedParameter($r['startDate']))
				->set('end_date', $up->createNamedParameter($r['endDate']))
				->set('type', $up->createNamedParameter($r['type']))
				->set('status', $up->createNamedParameter($r['status']))
				->set('payload_hash', $up->createNamedParameter($hash))
				->set('last_seen_at', $up->createNamedParameter($now))
				->set('source_updated_at', $up->createNamedParameter($srcUpd))
				->where($up->expr()->eq('at_absence_id', $up->createNamedParameter($atId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return;
		}

		$ins = $this->db->getQueryBuilder();
		$ins->insert('dc_at_absence_mirror')
			->values([
				'linked_user_id' => $ins->createNamedParameter($linkedUserId),
				'at_absence_id' => $ins->createNamedParameter($atId, IQueryBuilder::PARAM_INT),
				'start_date' => $ins->createNamedParameter($r['startDate']),
				'end_date' => $ins->createNamedParameter($r['endDate']),
				'type' => $ins->createNamedParameter($r['type']),
				'status' => $ins->createNamedParameter($r['status']),
				'payload_hash' => $ins->createNamedParameter($hash),
				'last_seen_at' => $ins->createNamedParameter($now),
				'source_updated_at' => $ins->createNamedParameter($srcUpd),
			])->executeStatement();
	}

	/**
	 * @param array<string,mixed> $r
	 */
	private function payloadHash(array $r): string
	{
		$days = $r['days'];
		$payload = [
			'atAbsenceId' => (int) $r['atAbsenceId'],
			'createdAt' => (string) $r['createdAt'],
			'days' => $days === null ? null : number_format((float) $days, 2, '.', ''),
			'endDate' => (string) $r['endDate'],
			'startDate' => (string) $r['startDate'],
			'status' => (string) $r['status'],
			'type' => (string) $r['type'],
			'updatedAt' => (string) $r['updatedAt'],
			'userId' => (string) $r['userId'],
		];
		ksort($payload);
		return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function rfc3339ToSqlDatetime(string $rfc): ?string
	{
		try {
			$dt = new DateTimeImmutable($rfc);
			return $dt->format('Y-m-d H:i:s');
		} catch (\Throwable) {
			return null;
		}
	}

	private function writeAudit(string $actorUid, string $action, array $payload): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_period_audit_log')
			->values([
				'period_id' => $qb->createNamedParameter(null),
				'actor_user_id' => $qb->createNamedParameter($actorUid),
				'action' => $qb->createNamedParameter($action),
				'target_kind' => $qb->createNamedParameter('integration'),
				'target_id' => $qb->createNamedParameter(null),
				'payload_json' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
				'created_at' => $qb->createNamedParameter((new DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
			])->executeStatement();
	}

	public function getPlannerOutboundUrl(): ?string
	{
		if (!$this->getPeerInstalled() || !$this->getPeerEnabled()) {
			return null;
		}
		try {
			return $this->urlGenerator->linkToRouteAbsolute(self::ROUTE_PLANNER);
		} catch (\Throwable) {
			return null;
		}
	}

	public function getEmployeeOutboundUrl(): ?string
	{
		if (!$this->getPeerInstalled() || !$this->getPeerEnabled()) {
			return null;
		}
		try {
			return $this->urlGenerator->linkToRouteAbsolute(self::ROUTE_EMPLOYEE);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function buildBootstrapForUser(string $_userId, bool $readonlyAbsencesForCurrentUser): array
	{
		$installed = $this->getPeerInstalled();
		$enabled = $this->getPeerEnabled();
		$versionOk = $this->getPeerVersionOk();
		$effective = $this->isEffective();
		$lease = $this->getSyncLeaseInfo();

		$canLink = $installed && $enabled && $versionOk;
		$plannerUrl = $canLink ? $this->getPlannerOutboundUrl() : null;
		$employeeUrl = $canLink ? $this->getEmployeeOutboundUrl() : null;

		$last = $this->getLastReconcileAt();
		$locksLinked = $this->integrationLocksLinkedDutyCheckAbsences();

		return [
			'effective' => $effective,
			'intentEnabled' => $this->getIntentEnabled(),
			'integrationLocksLinkedDutyCheckAbsences' => $locksLinked,
			'readonlyAbsencesForCurrentUser' => $readonlyAbsencesForCurrentUser && $locksLinked,
			'peerInstalled' => $installed,
			'peerEnabled' => $enabled,
			'peerVersionOk' => $versionOk,
			'peerVersionDetected' => $this->getPeerVersionString(),
			'peerVersionRange' => ['min' => self::MIN_PEER_VERSION, 'max' => null],
			'integrationStale' => $this->isStale(),
			'integrationLastReconcileAt' => $last?->format('Y-m-d\TH:i:s\Z'),
			'integrationReconcileInProgress' => $lease['active'],
			'integrationBreakerTripped' => $this->isBreakerActive(),
			'peerPlannerOutboundUrl' => $plannerUrl,
			'peerEmployeeOutboundUrl' => $employeeUrl,
			'bannerDismissKey' => self::BANNER_KEY,
			'tStaleSeconds' => $this->getTStaleSeconds(),
		];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForPlanner(): array
	{
		if (!$this->isEffective()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'm.at_absence_id',
			'm.start_date',
			'm.end_date',
			'm.type',
			'm.status',
			'e.id AS employee_id',
			'e.display_name',
		)
			->from('dc_at_absence_mirror', 'm')
			->innerJoin('m', 'dc_employees', 'e', 'm.linked_user_id = e.linked_user_id')
			->where($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('m.start_date', 'DESC')
			->addOrderBy('m.at_absence_id', 'DESC');
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $r) {
			$atStatus = (string) $r['status'];
			$atType = (string) $r['type'];
			$out[] = [
				'id' => null,
				'source' => 'arbeitszeitcheck',
				'atAbsenceId' => (int) $r['at_absence_id'],
				'employeeId' => (int) $r['employee_id'],
				'employeeName' => (string) $r['display_name'],
				'kind' => ArbeitszeitCheckTypeMapper::toDutyKind($atType),
				'atType' => $atType,
				'startDate' => (string) $r['start_date'],
				'endDate' => (string) $r['end_date'],
				'status' => ArbeitszeitCheckTypeMapper::toDutyStatus($atStatus),
				'reviewReason' => '',
				'piiHidden' => true,
			];
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForEmployee(string $linkedUserId): array
	{
		if (!$this->isEffective()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('at_absence_id', 'start_date', 'end_date', 'type', 'status')
			->from('dc_at_absence_mirror')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($linkedUserId)))
			->orderBy('start_date', 'DESC')
			->addOrderBy('at_absence_id', 'DESC');
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $r) {
			$atStatus = (string) $r['status'];
			$atType = (string) $r['type'];
			$out[] = [
				'id' => null,
				'source' => 'arbeitszeitcheck',
				'atAbsenceId' => (int) $r['at_absence_id'],
				'kind' => ArbeitszeitCheckTypeMapper::toDutyKind($atType),
				'atType' => $atType,
				'startDate' => (string) $r['start_date'],
				'endDate' => (string) $r['end_date'],
				'status' => ArbeitszeitCheckTypeMapper::toDutyStatus($atStatus),
				'reviewReason' => '',
				'piiHidden' => true,
			];
		}
		return $out;
	}

	public function hasImportedBlockingAbsenceOnDate(int $employeeId, string $dateYmd): bool
	{
		if (!$this->isEffective()) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.type', 'm.status')
			->from('dc_at_absence_mirror', 'm')
			->innerJoin('m', 'dc_employees', 'e', 'm.linked_user_id = e.linked_user_id')
			->where($qb->expr()->eq('e.id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('m.start_date', $qb->createNamedParameter($dateYmd)))
			->andWhere($qb->expr()->gte('m.end_date', $qb->createNamedParameter($dateYmd)))
			->setMaxResults(50);
		$rows = $qb->executeQuery()->fetchAll();
		foreach ($rows as $r) {
			if (ArbeitszeitCheckTypeMapper::isBlockingApproved((string) $r['type'], (string) $r['status'])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mirror overlap for dc_absences validation (pending/approved windows).
	 *
	 * @param list<string> $dutyStatuses e.g. ['pending','approved']
	 */
	public function mirrorOverlapsEmployeeRange(int $employeeId, string $startDate, string $endDate, array $dutyStatuses, ?int $ignoreAtAbsenceId = null): bool
	{
		if (!$this->isEffective()) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.at_absence_id', 'm.start_date', 'm.end_date', 'm.status')
			->from('dc_at_absence_mirror', 'm')
			->innerJoin('m', 'dc_employees', 'e', 'm.linked_user_id = e.linked_user_id')
			->where($qb->expr()->eq('e.id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('m.start_date', $qb->createNamedParameter($endDate)))
			->andWhere($qb->expr()->gte('m.end_date', $qb->createNamedParameter($startDate)));
		if ($ignoreAtAbsenceId !== null) {
			$qb->andWhere($qb->expr()->neq('m.at_absence_id', $qb->createNamedParameter($ignoreAtAbsenceId, IQueryBuilder::PARAM_INT)));
		}
		$rows = $qb->executeQuery()->fetchAll();
		foreach ($rows as $r) {
			if (ArbeitszeitCheckTypeMapper::atStatusOverlapsDutyStatuses((string) $r['status'], $dutyStatuses)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getAdminIntegrationStatus(): array
	{
		return [
			'intentEnabled' => $this->getIntentEnabled(),
			'peerInstalled' => $this->getPeerInstalled(),
			'peerEnabled' => $this->getPeerEnabled(),
			'peerVersionOk' => $this->getPeerVersionOk(),
			'peerVersionDetected' => $this->getPeerVersionString(),
			'effective' => $this->isEffective(),
			'legacyAbsenceCount' => $this->countLegacyAbsencesForLinkedEmployees(),
			'lastReconcileAt' => $this->getLastReconcileAt()?->format('Y-m-d\TH:i:s\Z'),
			'stale' => $this->isStale(),
			'breaker' => $this->isBreakerActive(),
			'lease' => $this->getSyncLeaseInfo(),
			'minPeerVersion' => self::MIN_PEER_VERSION,
		];
	}
}

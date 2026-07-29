<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Peer detection, mirror reconcile, bootstrap JSON, and conflict helpers.
 * Never writes to ArbeitszeitCheck tables.
 */
final class ArbeitszeitCheckIntegrationService implements IArbeitszeitCheckIntegration
{
	public const PEER_APP_ID = 'arbeitszeitcheck';

	/** @deprecated Use IntegrationOpsConstants::MIN_PEER_VERSION */
	public const MIN_PEER_VERSION = IntegrationOpsConstants::MIN_PEER_VERSION;

	public const ROUTE_PLANNER = 'arbeitszeitcheck.page.index';
	public const ROUTE_EMPLOYEE = 'arbeitszeitcheck.page.absences';

	/** Short key (historical); satellite also documents integration_arbeitszeitcheck_enabled. */
	private const CFG_INTENT = 'integration_at_intent_enabled';
	private const CFG_INTENT_ALIAS = 'integration_arbeitszeitcheck_enabled';
	private const CFG_INCLUDE_PII = 'integration_arbeitszeitcheck_include_pii';
	private const CFG_BLOCK_PUBLISH_STALE = 'block_publish_when_stale';
	private const CFG_LAST_RECONCILE = 'integration_at_last_reconcile_at';
	private const CFG_LEASE = 'integration_at_sync_lease';
	private const CFG_BREAKER_UNTIL = 'integration_at_breaker_until';
	private const CFG_ERR_WINDOW_START = 'integration_at_err_window_start';
	private const CFG_ERR_COUNT = 'integration_at_err_count';
	private const CFG_T_STALE = 'integration_t_stale_seconds';
	private const CFG_T_STALE_PUBLISH = 'integration_t_stale_publish_block_seconds';
	private const CFG_DETECTION_FAIL_AT = 'integration_at_detection_fail_at';
	private const CFG_BREAKER_TRIPS = 'integration_at_breaker_trips';

	/** @var array<string, true> Debounce INTEGRATION_AT_UNKNOWN_ENUM within one reconcile run. */
	private array $unknownEnumSeen = [];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private IArbeitszeitCheckAbsenceReader $reader,
		private ?ILockingProvider $locking = null,
	) {
	}

	public function getIntentEnabled(): bool
	{
		if ($this->config->getAppValue(Application::APP_ID, self::CFG_INTENT, '0') === '1') {
			return true;
		}
		return $this->config->getAppValue(Application::APP_ID, self::CFG_INTENT_ALIAS, '0') === '1';
	}

	public function setIntentEnabled(bool $enabled, string $actorUid, string $disableReason = ''): void
	{
		if ($enabled) {
			if ($this->isDetectionGraceActive()) {
				throw new \InvalidArgumentException('INTEGRATION_DETECTION_FLAPPING');
			}
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

		$reason = mb_substr(trim($disableReason), 0, 500);
		$this->config->setAppValue(Application::APP_ID, self::CFG_INTENT, $enabled ? '1' : '0');
		$this->config->setAppValue(Application::APP_ID, self::CFG_INTENT_ALIAS, $enabled ? '1' : '0');

		$payload = [
			'enabled' => $enabled,
			'peerVersion' => $this->getPeerVersionString(),
			'linkedEmployees' => $this->countActiveLinkedEmployees(),
		];
		if (!$enabled && $reason !== '') {
			$payload['reason'] = $reason;
		}
		$this->writeAudit($actorUid, $enabled ? 'INTEGRATION_INTENT_ENABLED' : 'INTEGRATION_INTENT_DISABLED', $payload);
	}

	public function getPeerInstalled(): bool
	{
		try {
			return $this->appManager->isInstalled(self::PEER_APP_ID);
		} catch (\Throwable $e) {
			$this->recordDetectionFailure();
			$this->logger->warning('DutyCheck AT detection failed (installed)', [
				'exception' => $e,
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_DETECTION_FAILED',
			]);
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
			$this->recordDetectionFailure();
			$this->logger->warning('DutyCheck AT detection failed (enabled)', [
				'exception' => $e,
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_DETECTION_FAILED',
			]);
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
		return version_compare($v, IntegrationOpsConstants::MIN_PEER_VERSION, '>=');
	}

	/**
	 * Effective = intent ∧ installed ∧ enabled ∧ version_ok.
	 * Circuit breaker does NOT clear effective (stale-while-effective per REQ-RD-11).
	 */
	public function isEffective(): bool
	{
		return $this->getIntentEnabled()
			&& $this->getPeerInstalled()
			&& $this->getPeerEnabled()
			&& $this->getPeerVersionOk();
	}

	public function integrationLocksLinkedDutyCheckAbsences(): bool
	{
		$peerReady = $this->getPeerInstalled() && $this->getPeerEnabled() && $this->getPeerVersionOk();
		return $this->isEffective() || ($this->getIntentEnabled() && $peerReady);
	}

	public function getIncludePii(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::CFG_INCLUDE_PII, '0') === '1';
	}

	public function setIncludePii(bool $enabled, string $actorUid, string $justification = ''): void
	{
		$prev = $this->getIncludePii();
		$justification = mb_substr(trim($justification), 0, 500);
		if ($enabled && $justification === '') {
			throw new \InvalidArgumentException('INTEGRATION_PII_JUSTIFICATION_REQUIRED');
		}
		$this->config->setAppValue(Application::APP_ID, self::CFG_INCLUDE_PII, $enabled ? '1' : '0');
		$this->writeAudit($actorUid, $enabled ? 'INTEGRATION_PII_ENABLED' : 'INTEGRATION_PII_DISABLED', [
			'from' => $prev,
			'to' => $enabled,
			'justification' => $enabled ? $justification : null,
		]);
		if (!$enabled && $prev) {
			$this->scrubMirrorPiiColumns();
		}
	}

	public function getBlockPublishWhenStale(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::CFG_BLOCK_PUBLISH_STALE, '0') === '1';
	}

	public function setBlockPublishWhenStale(bool $enabled, string $actorUid): void
	{
		$prev = $this->getBlockPublishWhenStale();
		$this->config->setAppValue(Application::APP_ID, self::CFG_BLOCK_PUBLISH_STALE, $enabled ? '1' : '0');
		$this->writeAudit($actorUid, 'INTEGRATION_BLOCK_PUBLISH_STALE_CHANGED', [
			'from' => $prev,
			'to' => $enabled,
		]);
	}

	/**
	 * WF-7: block publish only when effective AND (breaker OR age beyond T_stale_publish_block)
	 * and block_publish_when_stale is on.
	 */
	public function shouldBlockPublishForStale(): bool
	{
		if (!$this->getBlockPublishWhenStale() || !$this->isEffective()) {
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
		return $age > $this->getTStalePublishBlockSeconds();
	}

	public function getTStalePublishBlockSeconds(): int
	{
		$raw = $this->config->getAppValue(
			Application::APP_ID,
			self::CFG_T_STALE_PUBLISH,
			(string) IntegrationOpsConstants::T_STALE_PUBLISH_BLOCK_SECONDS,
		);
		$n = (int) $raw;
		return $n > 60 ? $n : IntegrationOpsConstants::T_STALE_PUBLISH_BLOCK_SECONDS;
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

	public function getBreakerRetryAfterSeconds(): int
	{
		$until = trim($this->config->getAppValue(Application::APP_ID, self::CFG_BREAKER_UNTIL, ''));
		if ($until === '') {
			return 0;
		}
		$ts = strtotime($until . ' UTC');
		if ($ts === false) {
			return 0;
		}
		$remaining = $ts - $this->timeFactory->getTime();
		return $remaining > 0 ? $remaining : 0;
	}

	public function getTStaleSeconds(): int
	{
		$raw = $this->config->getAppValue(
			Application::APP_ID,
			self::CFG_T_STALE,
			(string) IntegrationOpsConstants::T_STALE_SECONDS,
		);
		$n = (int) $raw;
		return $n > 60 ? $n : IntegrationOpsConstants::T_STALE_SECONDS;
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
	 * @return array{acquired: bool, token?: string, message?: string, startedAt?: mixed}
	 */
	public function acquireSyncLease(int $ttlSeconds = 600): array
	{
		$lockKey = 'dutycheck/integration_at_sync_lease';
		$held = false;
		if ($this->locking !== null) {
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				$held = true;
			} catch (LockedException) {
				return [
					'acquired' => false,
					'message' => 'INTEGRATION_SYNC_ALREADY_RUNNING',
				];
			}
		}
		try {
			$now = $this->timeFactory->getTime();
			$raw = $this->config->getAppValue(Application::APP_ID, self::CFG_LEASE, '');
			if ($raw !== '') {
				try {
					$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
					$until = (int) ($data['until'] ?? 0);
					if ($until > $now) {
						return [
							'acquired' => false,
							'message' => 'INTEGRATION_SYNC_ALREADY_RUNNING',
							'startedAt' => $data['startedAt'] ?? null,
						];
					}
				} catch (\Throwable) {
				}
			}
			$token = bin2hex(random_bytes(8));
			$startedAt = gmdate('Y-m-d\TH:i:s\Z');
			$payload = json_encode([
				'token' => $token,
				'until' => $now + $ttlSeconds,
				'startedAt' => $startedAt,
			], JSON_THROW_ON_ERROR);
			$this->config->setAppValue(Application::APP_ID, self::CFG_LEASE, $payload);

			// TOCTOU mitigation: re-read and confirm our token won.
			$verify = $this->config->getAppValue(Application::APP_ID, self::CFG_LEASE, '');
			try {
				$vdata = json_decode($verify, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($vdata) || ($vdata['token'] ?? '') !== $token) {
					return [
						'acquired' => false,
						'message' => 'INTEGRATION_SYNC_ALREADY_RUNNING',
						'startedAt' => is_array($vdata) ? ($vdata['startedAt'] ?? null) : null,
					];
				}
			} catch (\Throwable) {
				return ['acquired' => false, 'message' => 'INTEGRATION_SYNC_ALREADY_RUNNING'];
			}

			// REQ-RD-10: release lease if the process dies before runReconcile finishes.
			$svc = $this;
			register_shutdown_function(static function () use ($svc, $token): void {
				try {
					$svc->releaseSyncLease($token);
				} catch (\Throwable) {
					// best-effort
				}
			});

			return ['acquired' => true, 'token' => $token, 'startedAt' => $startedAt];
		} finally {
			if ($held && $this->locking !== null) {
				$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
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
	 * @return array{ok: bool, code: string, rows?: int, complete?: bool}
	 */
	public function runReconcile(string $leaseToken, ?int $maxWallSeconds = null, ?string $sinceYmd = null, ?string $onlyUserId = null): array
	{
		$wallCap = $maxWallSeconds !== null && $maxWallSeconds > 0
			? min($maxWallSeconds, IntegrationOpsConstants::RD_WALL_CAP_SECONDS)
			: IntegrationOpsConstants::RD_WALL_CAP_SECONDS;

		if ($this->isBreakerActive()) {
			$this->releaseSyncLease($leaseToken);
			return ['ok' => false, 'code' => 'INTEGRATION_SYNC_BREAKER_TRIPPED', 'complete' => false];
		}

		if (!$this->isEffective()) {
			$this->releaseSyncLease($leaseToken);
			return ['ok' => true, 'code' => 'skipped_not_effective', 'rows' => 0, 'complete' => true];
		}

		$start = microtime(true);
		$totalUpserts = 0;
		$incomplete = false;
		$includePii = $this->getIncludePii();
		$readOpts = new AbsenceReadOptions($includePii);
		$this->unknownEnumSeen = [];

		try {
			$linkedUids = $this->listActiveLinkedUserIds();
			if ($onlyUserId !== null && $onlyUserId !== '') {
				$onlyUserId = trim($onlyUserId);
				$linkedUids = in_array($onlyUserId, $linkedUids, true) ? [$onlyUserId] : [];
			}
			$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$from = $sinceYmd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceYmd) === 1
				? $sinceYmd
				: $today->modify('-180 days')->format('Y-m-d');
			$to = $today->modify('+730 days')->format('Y-m-d');

			$chunks = array_chunk($linkedUids, IntegrationOpsConstants::RD_BATCH_USER_CHUNK);

			foreach ($chunks as $chunk) {
				// WF-13: abort if intent flipped / peer no longer effective mid-run.
				if (!$this->isEffective()) {
					$this->logger->info('DutyCheck AT reconcile aborted: integration no longer effective', [
						'app' => 'dutycheck',
						'code' => 'INTEGRATION_RECONCILE_ABORTED_INTENT',
					]);
					$incomplete = true;
					break;
				}
				if ((microtime(true) - $start) >= $wallCap) {
					$this->logger->info('DutyCheck AT reconcile wall cap', [
						'app' => 'dutycheck',
						'code' => 'INTEGRATION_AT_WALL_CAP',
					]);
					$incomplete = true;
					break;
				}
				try {
					$rows = $this->reader->listAbsencesOverlapping($chunk, $from, $to, $readOpts);
				} catch (\Throwable $e) {
					$this->registerReaderFailure();
					$this->releaseSyncLease($leaseToken);
					$code = $this->isBreakerActive()
						? 'INTEGRATION_SYNC_BREAKER_TRIPPED'
						: 'INTEGRATION_SYNC_FAILED';
					return ['ok' => false, 'code' => $code, 'complete' => false];
				}
				$this->resetReaderFailureCounter();

				$byUser = [];
				foreach ($rows as $r) {
					$u = (string) $r['userId'];
					$byUser[$u][] = $r;
				}

				foreach ($chunk as $uid) {
					$userRows = $byUser[$uid] ?? [];
					$this->reconcileUser($uid, $userRows, $from, $to, $readOpts);
					$totalUpserts += count($userRows);
					if ($totalUpserts > IntegrationOpsConstants::RD_HARD_ROW_CAP) {
						$this->logger->warning('DutyCheck AT reconcile hard cap', [
							'app' => 'dutycheck',
							'code' => 'INTEGRATION_AT_HARD_CAP_HIT',
						]);
						$incomplete = true;
						break 2;
					}
				}
			}

			if ($onlyUserId === null || $onlyUserId === '') {
				$this->deleteMirrorOrphansNotLinked();
			}
			$this->clearBreakerOnSuccess();

			// REQ-RD-10: only advance last_reconcile when the run completed its work.
			if (!$incomplete) {
				$this->config->setAppValue(
					Application::APP_ID,
					self::CFG_LAST_RECONCILE,
					(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
				);
			}

			$this->releaseSyncLease($leaseToken);
			return [
				'ok' => true,
				'code' => $incomplete ? 'ok_incomplete' : 'ok',
				'rows' => $totalUpserts,
				'complete' => !$incomplete,
			];
		} catch (\Throwable $e) {
			$this->logger->error('DutyCheck AT reconcile failed', ['exception' => $e, 'app' => 'dutycheck']);
			$this->releaseSyncLease($leaseToken);
			return ['ok' => false, 'code' => 'INTEGRATION_SYNC_FAILED', 'complete' => false];
		}
	}

	/**
	 * @param list<array<string,mixed>> $userRows
	 */
	private function reconcileUser(string $linkedUserId, array $userRows, string $from, string $to, AbsenceReadOptions $readOpts): void
	{
		$seenIds = [];
		foreach ($userRows as $r) {
			$seenIds[(int) $r['atAbsenceId']] = true;
		}
		$seenList = array_keys($seenIds);

		$hadMirror = $this->mirrorRowCountForUser($linkedUserId) > 0;

		if ($userRows === [] && $hadMirror) {
			$retry = $this->reader->listAbsencesOverlapping([$linkedUserId], $from, $to, $readOpts);
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
		if ($winStart === 0 || ($now - $winStart) > IntegrationOpsConstants::RD_FAIL_WINDOW_SECONDS) {
			$this->config->setAppValue(Application::APP_ID, self::CFG_ERR_WINDOW_START, (string) $now);
			$this->config->setAppValue(Application::APP_ID, self::CFG_ERR_COUNT, '1');
			return;
		}
		$c = (int) $this->config->getAppValue(Application::APP_ID, self::CFG_ERR_COUNT, '0') + 1;
		$this->config->setAppValue(Application::APP_ID, self::CFG_ERR_COUNT, (string) $c);
		if ($c >= IntegrationOpsConstants::RD_FAIL_THRESHOLD) {
			$trips = (int) $this->config->getAppValue(Application::APP_ID, self::CFG_BREAKER_TRIPS, '0') + 1;
			$this->config->setAppValue(Application::APP_ID, self::CFG_BREAKER_TRIPS, (string) $trips);
			$exponent = max(0, $trips - 1);
			$backoff = IntegrationOpsConstants::RD_BACKOFF_BASE_SECONDS * (2 ** $exponent);
			if ($backoff > IntegrationOpsConstants::RD_BACKOFF_CAP_SECONDS) {
				$backoff = IntegrationOpsConstants::RD_BACKOFF_CAP_SECONDS;
			}
			$until = gmdate('Y-m-d H:i:s', $now + $backoff);
			$this->config->setAppValue(Application::APP_ID, self::CFG_BREAKER_UNTIL, $until);
			$this->logger->warning('DutyCheck AT reader circuit breaker tripped', [
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_AT_READER_TRIPPED',
				'trips' => $trips,
				'backoffSeconds' => $backoff,
			]);
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
		$this->config->deleteAppValue(Application::APP_ID, self::CFG_BREAKER_TRIPS);
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
		$type = (string) ($r['type'] ?? '');
		$status = (string) ($r['status'] ?? '');
		if (!ArbeitszeitCheckTypeMapper::isKnownType($type)) {
			$this->noteUnknownEnum('type', $type, $atId);
		}
		if (!ArbeitszeitCheckTypeMapper::isKnownStatus($status)) {
			$this->noteUnknownEnum('status', $status, $atId);
		}
		$hash = $this->payloadHash($r);
		$now = gmdate('Y-m-d H:i:s');
		$srcUpd = isset($r['updatedAt']) ? $this->rfc3339ToSqlDatetime((string) $r['updatedAt']) : null;
		$includePii = $this->getIncludePii();
		$reason = $includePii && array_key_exists('reason', $r) ? $r['reason'] : null;
		$approverComment = $includePii && array_key_exists('approverComment', $r) ? $r['approverComment'] : null;
		if (!$includePii) {
			$reason = null;
			$approverComment = null;
		}

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
				->set('reason', $up->createNamedParameter($reason))
				->set('approver_comment', $up->createNamedParameter($approverComment))
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
				'reason' => $ins->createNamedParameter($reason),
				'approver_comment' => $ins->createNamedParameter($approverComment),
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
		if ($this->getIncludePii()) {
			$payload['reason'] = isset($r['reason']) ? (string) $r['reason'] : '';
			$payload['approverComment'] = isset($r['approverComment']) ? (string) $r['approverComment'] : '';
		}
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

	/**
	 * REQ mapping / REQ-TST-05: unknown AT enums stay non-blocking but are observable.
	 */
	private function noteUnknownEnum(string $field, string $value, int $atAbsenceId): void
	{
		$key = $field . "\0" . $value;
		if (isset($this->unknownEnumSeen[$key])) {
			return;
		}
		$this->unknownEnumSeen[$key] = true;
		$safeValue = mb_substr($value, 0, 64);
		$this->logger->warning('DutyCheck AT unknown enum (non-blocking)', [
			'app' => 'dutycheck',
			'code' => 'INTEGRATION_AT_UNKNOWN_ENUM',
			'field' => $field,
			'value' => $safeValue,
			'atAbsenceId' => $atAbsenceId,
		]);
		try {
			$this->writeAudit('system', 'INTEGRATION_AT_UNKNOWN_ENUM', [
				'field' => $field,
				'value' => $safeValue,
				'atAbsenceId' => $atAbsenceId,
			]);
		} catch (\Throwable) {
			// Audit must never abort reconcile.
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
		$includePii = $this->getIncludePii();

		return [
			'effective' => $effective,
			'intentEnabled' => $this->getIntentEnabled(),
			'integrationLocksLinkedDutyCheckAbsences' => $locksLinked,
			'readonlyAbsencesForCurrentUser' => $readonlyAbsencesForCurrentUser && $locksLinked,
			'peerInstalled' => $installed,
			'peerEnabled' => $enabled,
			'peerVersionOk' => $versionOk,
			'peerVersionDetected' => $this->getPeerVersionString(),
			'peerVersionRange' => ['min' => IntegrationOpsConstants::MIN_PEER_VERSION, 'max' => null],
			'integrationStale' => $this->isStale(),
			'integrationLastReconcileAt' => $last?->format('Y-m-d\TH:i:s\Z'),
			'integrationReconcileInProgress' => $lease['active'],
			'integrationBreakerTripped' => $this->isBreakerActive(),
			'peerPlannerOutboundUrl' => $plannerUrl,
			'peerEmployeeOutboundUrl' => $employeeUrl,
			'bannerDismissKey' => IntegrationOpsConstants::BANNER_DISMISS_KEY,
			'tStaleSeconds' => $this->getTStaleSeconds(),
			'tStalePublishBlockSeconds' => $this->getTStalePublishBlockSeconds(),
			'blockPublishWhenStale' => $this->getBlockPublishWhenStale(),
			'includePii' => $includePii,
			'ops' => [
				'rdPeriodSeconds' => IntegrationOpsConstants::RD_PERIOD_SECONDS,
				'rdWallCapSeconds' => IntegrationOpsConstants::RD_WALL_CAP_SECONDS,
				'rdBatchUserChunk' => IntegrationOpsConstants::RD_BATCH_USER_CHUNK,
				'rdHardRowCap' => IntegrationOpsConstants::RD_HARD_ROW_CAP,
				'rdFailThreshold' => IntegrationOpsConstants::RD_FAIL_THRESHOLD,
				'syncRateLimitPerAdminInterval' => IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL,
				'syncRateLimitPerAdminHour' => IntegrationOpsConstants::SYNC_RL_PER_ADMIN_HOUR,
				'syncRateLimitPerInstanceHour' => IntegrationOpsConstants::SYNC_RL_PER_INSTANCE_HOUR,
			],
		];
	}

	/**
	 * @param list<int>|null $allowedCompanyIds
	 * @return list<array<string,mixed>>
	 */
	public function listMirrorRowsForPlanner(?array $allowedCompanyIds = null): array
	{
		if (!$this->isEffective()) {
			return [];
		}
		// Non-null empty = multi-company actor with no membership → deny-all (fail closed).
		if ($allowedCompanyIds !== null && $allowedCompanyIds === []) {
			return [];
		}
		$includePii = $this->getIncludePii();
		$qb = $this->db->getQueryBuilder();
		$select = [
			'm.at_absence_id',
			'm.start_date',
			'm.end_date',
			'm.type',
			'm.status',
			'e.id AS employee_id',
			'e.display_name',
		];
		if ($includePii) {
			$select[] = 'm.reason';
			$select[] = 'm.approver_comment';
		}
		$qb->select(...$select)
			->from('dc_at_absence_mirror', 'm')
			->innerJoin('m', 'dc_employees', 'e', 'm.linked_user_id = e.linked_user_id')
			->where($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('m.start_date', 'DESC')
			->addOrderBy('m.at_absence_id', 'DESC');
		if ($allowedCompanyIds !== null
			&& SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$qb->andWhere($qb->expr()->in(
				'e.company_id',
				$qb->createNamedParameter($allowedCompanyIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		}
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $r) {
			$atStatus = (string) $r['status'];
			$atType = (string) $r['type'];
			$row = [
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
				'piiHidden' => !$includePii,
			];
			if ($includePii) {
				$row['reason'] = isset($r['reason']) ? (string) $r['reason'] : '';
				$row['approverComment'] = isset($r['approver_comment']) ? (string) $r['approver_comment'] : '';
			}
			$out[] = $row;
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
		$includePii = $this->getIncludePii();
		$qb = $this->db->getQueryBuilder();
		$select = ['at_absence_id', 'start_date', 'end_date', 'type', 'status'];
		if ($includePii) {
			$select[] = 'reason';
			$select[] = 'approver_comment';
		}
		$qb->select(...$select)
			->from('dc_at_absence_mirror')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($linkedUserId)))
			->orderBy('start_date', 'DESC')
			->addOrderBy('at_absence_id', 'DESC');
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $r) {
			$atStatus = (string) $r['status'];
			$atType = (string) $r['type'];
			$row = [
				'id' => null,
				'source' => 'arbeitszeitcheck',
				'atAbsenceId' => (int) $r['at_absence_id'],
				'kind' => ArbeitszeitCheckTypeMapper::toDutyKind($atType),
				'atType' => $atType,
				'startDate' => (string) $r['start_date'],
				'endDate' => (string) $r['end_date'],
				'status' => ArbeitszeitCheckTypeMapper::toDutyStatus($atStatus),
				'reviewReason' => '',
				'piiHidden' => !$includePii,
			];
			if ($includePii) {
				$row['reason'] = isset($r['reason']) ? (string) $r['reason'] : '';
				$row['approverComment'] = isset($r['approver_comment']) ? (string) $r['approver_comment'] : '';
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * WF-23: immutable T1/T2 slice for publish/close snapshots. Never includes T3 columns.
	 *
	 * @return list<array{atAbsenceId:int,employeeId:int,type:string,status:string,startDate:string,endDate:string,source:string}>
	 */
	public function listImportedAbsencesForPeriodSnapshot(string $periodStartYmd, string $periodEndYmd, ?int $companyId = null): array
	{
		if (!$this->isEffective()) {
			return [];
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStartYmd) !== 1
			|| preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEndYmd) !== 1
			|| $periodStartYmd > $periodEndYmd) {
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
		)
			->from('dc_at_absence_mirror', 'm')
			->innerJoin('m', 'dc_employees', 'e', 'm.linked_user_id = e.linked_user_id')
			->where($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('m.start_date', $qb->createNamedParameter($periodEndYmd)))
			->andWhere($qb->expr()->gte('m.end_date', $qb->createNamedParameter($periodStartYmd)))
			->orderBy('m.start_date', 'ASC')
			->addOrderBy('m.at_absence_id', 'ASC');
		if ($companyId !== null && $companyId > 0
			&& SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$qb->andWhere($qb->expr()->eq(
				'e.company_id',
				$qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
			));
		}
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'atAbsenceId' => (int) $r['at_absence_id'],
				'employeeId' => (int) $r['employee_id'],
				'type' => (string) $r['type'],
				'status' => (string) $r['status'],
				'startDate' => (string) $r['start_date'],
				'endDate' => (string) $r['end_date'],
				'source' => 'arbeitszeitcheck',
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
			'minPeerVersion' => IntegrationOpsConstants::MIN_PEER_VERSION,
			'includePii' => $this->getIncludePii(),
			'blockPublishWhenStale' => $this->getBlockPublishWhenStale(),
			'tStalePublishBlockSeconds' => $this->getTStalePublishBlockSeconds(),
			'shouldBlockPublishForStale' => $this->shouldBlockPublishForStale(),
		];
	}

	private function recordDetectionFailure(): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			self::CFG_DETECTION_FAIL_AT,
			(string) $this->timeFactory->getTime(),
		);
	}

	private function isDetectionGraceActive(): bool
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, self::CFG_DETECTION_FAIL_AT, ''));
		if ($raw === '' || !ctype_digit($raw)) {
			return false;
		}
		$ts = (int) $raw;
		return ($this->timeFactory->getTime() - $ts) < IntegrationOpsConstants::SET_DETECTION_GRACE_SECONDS;
	}

	private function scrubMirrorPiiColumns(): void
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_at_absence_mirror')
				->set('reason', $qb->createNamedParameter(null))
				->set('approver_comment', $qb->createNamedParameter(null))
				->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->warning('DutyCheck could not scrub mirror PII columns', [
				'app' => 'dutycheck',
				'exception' => $e,
			]);
		}
	}
}

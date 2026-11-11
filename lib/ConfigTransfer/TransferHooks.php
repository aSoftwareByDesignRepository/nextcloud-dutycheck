<?php

declare(strict_types=1);

/**
 * DutyCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessControlService::requireAppAdmin — system admins + configured
 *    app admins).
 *  - validateValue/normalizeValue delegate to the SAME normalisers the
 *    settings surfaces use — AccessControlService::normalizeForWrite for
 *    the access-door keys, PlanningDefaultsService::normalizeForWrite for
 *    the planning default, and per-key semantics mirroring the ops-flag /
 *    integration write paths (validator parity, NN-08).
 *  - writeMirrored runs the access policy through
 *    AccessControlService::saveAppPolicy in ONE atomic call — never raw
 *    setAppValue (NN-02; the restricted-door-requires-allowlist invariant
 *    is cross-key).
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\DutyCheck\ConfigTransfer;

use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\UpgradeBackupService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const ACCESS_KEYS = [
		'access_restriction_enabled',
		'access_allowed_user_ids',
		'access_allowed_group_ids',
		'app_admin_user_ids',
	];

	/** Boolean appconfig flags — stored canonically as '0'/'1'. */
	private const FLAG_KEYS = [
		'threshold_approach_notify',
		'mc_onduty_hook_enabled',
		'hr_roster_minutes_export_enabled',
		'integration_at_intent_enabled',
		'integration_arbeitszeitcheck_enabled',
		'integration_arbeitszeitcheck_include_pii',
		'block_publish_when_stale',
	];

	/** Second-granularity tuning keys (reader floors: n > 60 else default). */
	private const STALE_SECONDS_KEYS = [
		'integration_t_stale_seconds',
		'integration_t_stale_publish_block_seconds',
	];

	public function __construct(
		private readonly AccessControlService $access,
		private readonly PlanningDefaultsService $planningDefaults,
		private readonly UpgradeBackupService $backups,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		$this->access->requireAppAdmin($uid);
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		if (in_array($key, self::ACCESS_KEYS, true)) {
			return $this->access->normalizeForWrite($key, $value);
		}
		return match (true) {
			$key === 'default_break_minutes' => $this->planningDefaults->normalizeForWrite($value),
			// saveOpsFlags parses via FILTER_VALIDATE_BOOLEAN and stores
			// '1'/'0' — the wire value is already canonical, so accept the
			// stored form only.
			in_array($key, self::FLAG_KEYS, true) => in_array($value, ['0', '1'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_FLAG'),
			// saveOpsFlags: (float) then clamp 0.5–0.99 — '' casts to 0.0
			// like the API path (legacy installs store '' verbatim).
			$key === 'threshold_approach_ratio' => $this->normalizeRatio($value),
			// saveOpsFlags: (int) then clamp 0–3650 — '' casts to 0.
			$key === 'snapshot_retention_days' => $this->normalizeIntRange($value, 0, 3650, 'INVALID_SNAPSHOT_RETENTION'),
			// Staleness tuning seconds: read via (int) with a >60 floor —
			// canonical digits for real values; '' passes through verbatim
			// (the reader treats it as "use default" like the stored value).
			in_array($key, self::STALE_SECONDS_KEYS, true) => $this->normalizeStaleSeconds($value),
			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	private function normalizeRatio(string $value): string
	{
		$trimmed = trim($value);
		if ($trimmed === '') {
			$trimmed = '0';
		}
		if (!is_numeric($trimmed)) {
			throw new \InvalidArgumentException('INVALID_THRESHOLD_RATIO');
		}
		$ratio = max(0.5, min(0.99, (float) $trimmed));
		return (string) $ratio;
	}

	private function normalizeIntRange(string $value, int $min, int $max, string $error): string
	{
		$trimmed = trim($value);
		if ($trimmed === '') {
			$trimmed = '0';
		}
		if (!preg_match('/^-?\d+$/', $trimmed)) {
			throw new \InvalidArgumentException($error);
		}
		$n = max($min, min($max, (int) $trimmed));
		return (string) $n;
	}

	private function normalizeStaleSeconds(string $value): string
	{
		$trimmed = trim($value);
		if ($trimmed === '') {
			return '';
		}
		if (!preg_match('/^\d+$/', $trimmed)) {
			throw new \InvalidArgumentException('INVALID_STALE_SECONDS');
		}
		return (string) (int) $trimmed;
	}

	public function validateMirrored(array $keyValues): ?string
	{
		// Decode the mirrored policy values and run the same invariants
		// saveAppPolicy enforces — but WITHOUT writing.
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			return 'INVALID_POLICY_JSON';
		}
		[$admins, $users, $groups, $restricted] = $decoded;
		foreach ($admins as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_APP_ADMIN_USER';
			}
		}
		foreach ($users as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_ALLOWED_USER';
			}
		}
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				return 'INVALID_ALLOWED_GROUP';
			}
		}
		if ($restricted && $users === [] && $groups === []) {
			return 'ACCESS_LIST_REQUIRED';
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		[$admins, $users, $groups, $restricted] = $decoded;
		// One atomic policy write through the app's central writer — the
		// payload carries all four fields, so a partial transfer file never
		// wipes the sections it does not mention (decodePolicy overlays).
		$this->access->saveAppPolicy([
			'appAdminUserIds' => $admins,
			'allowedUserIds' => $users,
			'allowedGroupIds' => $groups,
			'accessRestrictionEnabled' => $restricted,
		]);
	}

	/**
	 * Overlay the transferred mirrored keys onto the current policy, so a
	 * file that carries only a subset never blanks the rest.
	 *
	 * @return array{0:list<string>,1:list<string>,2:list<string>,3:bool}|null
	 */
	private function decodePolicy(array $kv): ?array
	{
		$current = $this->access->appPolicy();
		$admins = $current['appAdminUserIds'];
		$users = $current['allowedUserIds'];
		$groups = $current['allowedGroupIds'];
		$restricted = $current['accessRestrictionEnabled'];
		if (isset($kv['app_admin_user_ids'])) {
			$admins = json_decode($kv['app_admin_user_ids'], true);
			if (!is_array($admins)) {
				return null;
			}
		}
		if (isset($kv['access_allowed_user_ids'])) {
			$users = json_decode($kv['access_allowed_user_ids'], true);
			if (!is_array($users)) {
				return null;
			}
		}
		if (isset($kv['access_allowed_group_ids'])) {
			$groups = json_decode($kv['access_allowed_group_ids'], true);
			if (!is_array($groups)) {
				return null;
			}
		}
		if (isset($kv['access_restriction_enabled'])) {
			$restricted = $kv['access_restriction_enabled'] === '1';
		}
		return [
			array_values(array_map('strval', $admins)),
			array_values(array_map('strval', $users)),
			array_values(array_map('strval', $groups)),
			(bool)$restricted,
		];
	}

	public function securityRelevantKeys(): array
	{
		return [
			'access_restriction_enabled',
			'access_allowed_user_ids',
			'access_allowed_group_ids',
			'app_admin_user_ids',
			'hr_roster_minutes_export_enabled',
			'integration_arbeitszeitcheck_include_pii',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			str_starts_with($key, 'access_'), $key === 'app_admin_user_ids' => 'access',
			$key === 'default_break_minutes' => 'planning',
			str_starts_with($key, 'integration_'), $key === 'block_publish_when_stale' => 'integration',
			default => 'operations',
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		$this->logger->info('dutycheck: config transfer ' . $action, [
			'app' => 'dutycheck',
			'actor' => $actor === '' ? 'cli' : $actor,
			'details' => $details,
		]);
	}
}

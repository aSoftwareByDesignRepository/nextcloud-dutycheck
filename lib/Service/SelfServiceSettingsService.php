<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Per-company Roster Self-Service GA settings.
 *
 * Defaults are legacy-safe (D-17 / D-23): behavioural flags off for existing
 * companies; new companies seed quiet hours ON via {@see defaultsForNewCompany()}.
 */
final class SelfServiceSettingsService
{
	public const ALLOWED_CYCLE_WEEKS = [1, 2, 3, 4];

	public const SWAP_PLANNER_REQUIRED = 'planner_required';
	public const SWAP_BILATERAL_AUTO = 'bilateral_auto';

	/** @var array<string, mixed> */
	private const SAFE_DEFAULTS = [
		'rotation_patterns_enabled' => false,
		'soll_from_duty' => false,
		'peer_roster_visibility' => false,
		'swap_approval_mode' => self::SWAP_PLANNER_REQUIRED,
		'allow_cross_location_swaps' => false,
		'claim_requires_planner' => false,
		'preferences_enabled' => false,
		'preference_ranking_on_suggest' => false,
		'rotation_allowed_cycle_weeks' => self::ALLOWED_CYCLE_WEEKS,
		'shift_terminal_plan_strip' => false,
		'today_board_enabled' => false,
		'blackouts_enabled' => false,
		'early_end_latest' => '12:00',
		'late_start_earliest' => '14:00',
		'push_quiet_hours_enabled' => false,
		'push_quiet_hours_start' => '22:00',
		'push_quiet_hours_end' => '06:00',
		'push_allow_urgent_during_quiet' => false,
		'user_may_disable_quiet' => false,
	];

	/** @var array<int, array<string, mixed>> */
	private array $cache = [];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly ?AccessControlService $access = null,
	) {
	}

	/**
	 * Defaults stamped when a company is created on/after GA (quiet ON).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaultsForNewCompany(): array
	{
		$defaults = self::SAFE_DEFAULTS;
		$defaults['push_quiet_hours_enabled'] = true;
		$defaults['_ga_quiet_seeded'] = true;
		return $defaults;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getForCompany(int $companyId): array
	{
		if ($companyId < 1) {
			return self::SAFE_DEFAULTS;
		}
		if (isset($this->cache[$companyId])) {
			return $this->cache[$companyId];
		}
		$stored = $this->readStored($companyId);
		$merged = array_merge(self::SAFE_DEFAULTS, $stored);
		$merged = $this->normalize($merged);
		return $this->cache[$companyId] = $merged;
	}

	/**
	 * Resolve settings for the actor's writable company (first membership / default).
	 *
	 * @return array<string, mixed>
	 */
	public function getForActor(string $userId): array
	{
		return $this->getForCompany($this->companies->writeCompanyIdFor($userId));
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	public function updateForCompany(int $companyId, array $patch, string $actor, ?string $expectedRevision = null): array
	{
		if ($companyId < 1) {
			throw new \InvalidArgumentException('COMPANY_NOT_FOUND');
		}
		// Defense in depth: HTTP layer also requireAppAdmin — never trust service callers.
		if ($this->access !== null && !$this->access->isAppAdmin($actor)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
		$this->companies->assertCanAccessCompany($actor, $companyId);
		unset($this->cache[$companyId]);

		// Optimistic CAS on the exact stored JSON string — prevents last-write-wins clobber.
		$expectedRaw = $this->readRawJsonString($companyId);
		if ($expectedRevision !== null && $expectedRevision !== '') {
			$actual = $this->revisionForRaw($expectedRaw);
			if (!hash_equals($actual, $expectedRevision)) {
				throw new \InvalidArgumentException('SETTINGS_CONFLICT');
			}
		}
		$current = $this->normalize(array_merge(
			self::SAFE_DEFAULTS,
			$this->decodeRaw($expectedRaw),
		));
		$merged = $this->normalize(array_merge($current, $this->filterPatch($patch)));
		$this->writeStoredCas($companyId, $expectedRaw, $merged);
		unset($this->cache[$companyId]);
		return $this->getForCompany($companyId);
	}

	/**
	 * Seed settings_json for a newly created company (quiet hours ON).
	 */
	public function seedNewCompany(int $companyId): void
	{
		if ($companyId < 1 || !SchemaProbe::hasColumn($this->db, 'dc_companies', 'settings_json')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_companies')
			->set('settings_json', $qb->createNamedParameter(
				json_encode(self::defaultsForNewCompany(), JSON_THROW_ON_ERROR),
			))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		unset($this->cache[$companyId]);
	}

	public function isRotationEnabled(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['rotation_patterns_enabled'];
	}

	public function isPeerVisibilityEnabled(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['peer_roster_visibility'];
	}

	public function isPreferencesEnabled(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['preferences_enabled'];
	}

	public function isBlackoutsEnabled(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['blackouts_enabled'];
	}

	public function isQuietHoursEnabled(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['push_quiet_hours_enabled'];
	}

	public function swapApprovalMode(int $companyId): string
	{
		$mode = (string) $this->getForCompany($companyId)['swap_approval_mode'];
		return $mode === self::SWAP_BILATERAL_AUTO ? self::SWAP_BILATERAL_AUTO : self::SWAP_PLANNER_REQUIRED;
	}

	public function allowCrossLocationSwaps(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['allow_cross_location_swaps'];
	}

	public function claimRequiresPlanner(int $companyId): bool
	{
		return (bool) $this->getForCompany($companyId)['claim_requires_planner'];
	}

	/**
	 * @return list<int>
	 */
	public function allowedCycleWeeks(int $companyId): array
	{
		$raw = $this->getForCompany($companyId)['rotation_allowed_cycle_weeks'] ?? self::ALLOWED_CYCLE_WEEKS;
		if (!is_array($raw)) {
			return self::ALLOWED_CYCLE_WEEKS;
		}
		$out = [];
		foreach ($raw as $n) {
			$n = (int) $n;
			if (in_array($n, self::ALLOWED_CYCLE_WEEKS, true)) {
				$out[] = $n;
			}
		}
		return $out !== [] ? array_values(array_unique($out)) : self::ALLOWED_CYCLE_WEEKS;
	}

	/**
	 * API-facing camelCase snapshot for bootstrap / settings UI.
	 *
	 * @return array<string, mixed>
	 */
	public function toApi(int $companyId): array
	{
		$s = $this->getForCompany($companyId);
		$raw = $this->readRawJsonString($companyId);
		return [
			'rotationPatternsEnabled' => (bool) $s['rotation_patterns_enabled'],
			'sollFromDuty' => (bool) $s['soll_from_duty'],
			'peerRosterVisibility' => (bool) $s['peer_roster_visibility'],
			'swapApprovalMode' => (string) $s['swap_approval_mode'],
			'allowCrossLocationSwaps' => (bool) $s['allow_cross_location_swaps'],
			'claimRequiresPlanner' => (bool) $s['claim_requires_planner'],
			'preferencesEnabled' => (bool) $s['preferences_enabled'],
			'preferenceRankingOnSuggest' => (bool) $s['preference_ranking_on_suggest'],
			'rotationAllowedCycleWeeks' => $this->allowedCycleWeeks($companyId),
			'shiftTerminalPlanStrip' => (bool) $s['shift_terminal_plan_strip'],
			'todayBoardEnabled' => (bool) $s['today_board_enabled'],
			'blackoutsEnabled' => (bool) $s['blackouts_enabled'],
			'earlyEndLatest' => (string) $s['early_end_latest'],
			'lateStartEarliest' => (string) $s['late_start_earliest'],
			'pushQuietHoursEnabled' => (bool) $s['push_quiet_hours_enabled'],
			'pushQuietHoursStart' => (string) $s['push_quiet_hours_start'],
			'pushQuietHoursEnd' => (string) $s['push_quiet_hours_end'],
			'pushAllowUrgentDuringQuiet' => (bool) $s['push_allow_urgent_during_quiet'],
			// Reserved / not enforced in GA — omit from API so clients cannot pretend employees may opt out.
			'settingsRevision' => $this->revisionForRaw($raw),
		];
	}

	private function revisionForRaw(?string $raw): string
	{
		return hash('sha256', $raw ?? '');
	}

	/**
	 * Exact JSON string as stored (null when column empty) — used for CAS.
	 */
	private function readRawJsonString(int $companyId): ?string
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_companies', 'settings_json')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('settings_json')->from('dc_companies')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		$raw = $qb->executeQuery()->fetchOne();
		if ($raw === false || $raw === null) {
			return null;
		}
		$str = trim((string) $raw);
		return $str === '' ? null : (string) $raw;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeRaw(?string $raw): array
	{
		if ($raw === null || trim($raw) === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return [];
		}
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function writeStoredCas(int $companyId, ?string $expectedRaw, array $settings): void
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_companies', 'settings_json')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$newJson = json_encode($settings, JSON_THROW_ON_ERROR);
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_companies')
			->set('settings_json', $qb->createNamedParameter($newJson))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		if ($expectedRaw === null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('settings_json'),
				$qb->expr()->eq('settings_json', $qb->createNamedParameter('')),
			));
		} else {
			$qb->andWhere($qb->expr()->eq('settings_json', $qb->createNamedParameter($expectedRaw)));
		}
		$affected = $qb->executeStatement();
		if ($affected === 1) {
			return;
		}
		// MariaDB/MySQL often report 0 when the row matched but values were identical.
		$current = $this->readRawJsonString($companyId);
		if ($current === $newJson) {
			return;
		}
		throw new \InvalidArgumentException('SETTINGS_CONFLICT');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readStored(int $companyId): array
	{
		return $this->decodeRaw($this->readRawJsonString($companyId));
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function writeStored(int $companyId, array $settings): void
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_companies', 'settings_json')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_companies')
			->set('settings_json', $qb->createNamedParameter(json_encode($settings, JSON_THROW_ON_ERROR)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	private function filterPatch(array $patch): array
	{
		// Accept both snake_case and camelCase from API.
		$map = [
			'rotationPatternsEnabled' => 'rotation_patterns_enabled',
			'sollFromDuty' => 'soll_from_duty',
			'peerRosterVisibility' => 'peer_roster_visibility',
			'swapApprovalMode' => 'swap_approval_mode',
			'allowCrossLocationSwaps' => 'allow_cross_location_swaps',
			'claimRequiresPlanner' => 'claim_requires_planner',
			'preferencesEnabled' => 'preferences_enabled',
			'preferenceRankingOnSuggest' => 'preference_ranking_on_suggest',
			'rotationAllowedCycleWeeks' => 'rotation_allowed_cycle_weeks',
			'shiftTerminalPlanStrip' => 'shift_terminal_plan_strip',
			'todayBoardEnabled' => 'today_board_enabled',
			'blackoutsEnabled' => 'blackouts_enabled',
			'earlyEndLatest' => 'early_end_latest',
			'lateStartEarliest' => 'late_start_earliest',
			'pushQuietHoursEnabled' => 'push_quiet_hours_enabled',
			'pushQuietHoursStart' => 'push_quiet_hours_start',
			'pushQuietHoursEnd' => 'push_quiet_hours_end',
			'pushAllowUrgentDuringQuiet' => 'push_allow_urgent_during_quiet',
			// userMayDisableQuiet intentionally omitted — reserved until user prefs exist.
		];
		$out = [];
		foreach ($patch as $key => $value) {
			$snake = $map[$key] ?? (is_string($key) && array_key_exists($key, self::SAFE_DEFAULTS) ? $key : null);
			if ($snake === null) {
				continue;
			}
			// Block reserved quiet-opt-out until a real per-user preference store exists.
			if ($snake === 'user_may_disable_quiet') {
				continue;
			}
			$out[$snake] = $value;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function normalize(array $settings): array
	{
		$out = self::SAFE_DEFAULTS;
		foreach (self::SAFE_DEFAULTS as $key => $default) {
			if (!array_key_exists($key, $settings)) {
				continue;
			}
			$val = $settings[$key];
			$out[$key] = match ($key) {
				'rotation_patterns_enabled', 'soll_from_duty', 'peer_roster_visibility',
				'allow_cross_location_swaps', 'claim_requires_planner', 'preferences_enabled',
				'preference_ranking_on_suggest', 'shift_terminal_plan_strip', 'today_board_enabled',
				'blackouts_enabled', 'push_quiet_hours_enabled', 'push_allow_urgent_during_quiet',
				'user_may_disable_quiet' => (bool) $val,
				'swap_approval_mode' => ((string) $val === self::SWAP_BILATERAL_AUTO)
					? self::SWAP_BILATERAL_AUTO
					: self::SWAP_PLANNER_REQUIRED,
				'rotation_allowed_cycle_weeks' => $this->normalizeCycleWeeks($val),
				'early_end_latest', 'late_start_earliest',
				'push_quiet_hours_start', 'push_quiet_hours_end' => $this->normalizeTime((string) $val, (string) $default),
				default => $val,
			};
		}
		// Preserve internal seed marker if present.
		if (isset($settings['_ga_quiet_seeded'])) {
			$out['_ga_quiet_seeded'] = (bool) $settings['_ga_quiet_seeded'];
		}
		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return list<int>
	 */
	private function normalizeCycleWeeks(mixed $raw): array
	{
		if (!is_array($raw)) {
			return self::ALLOWED_CYCLE_WEEKS;
		}
		$out = [];
		foreach ($raw as $n) {
			$n = (int) $n;
			if (in_array($n, self::ALLOWED_CYCLE_WEEKS, true)) {
				$out[] = $n;
			}
		}
		return $out !== [] ? array_values(array_unique($out)) : self::ALLOWED_CYCLE_WEEKS;
	}

	private function normalizeTime(string $raw, string $fallback): string
	{
		$raw = trim($raw);
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw) !== 1) {
			return $fallback;
		}
		return $raw;
	}
}

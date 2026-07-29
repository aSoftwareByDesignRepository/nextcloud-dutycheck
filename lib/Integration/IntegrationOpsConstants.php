<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

/**
 * Operational defaults for the ArbeitszeitCheck integration (satellite SoT).
 *
 * @see planning/app-ideas/dutycheck/arbeitszeitcheck-integration.md § Operational defaults
 */
final class IntegrationOpsConstants
{
	/** Minimum ArbeitszeitCheck version shipping Version1005 (approved_by_user_id). */
	public const MIN_PEER_VERSION = '1.2.0';

	/** UI staleness window after last successful reconcile (seconds). */
	public const T_STALE_SECONDS = 3600;

	/** Publish gate window when block_publish_when_stale is on (seconds). */
	public const T_STALE_PUBLISH_BLOCK_SECONDS = 7200;

	/** Background reconcile period (seconds). */
	public const RD_PERIOD_SECONDS = 900;

	/** Max wall time per reconcile run (seconds). */
	public const RD_WALL_CAP_SECONDS = 300;

	/** Reader / reconcile UID chunk size. */
	public const RD_BATCH_USER_CHUNK = 200;

	/** Max mirror upserts per reconcile run. */
	public const RD_HARD_ROW_CAP = 50000;

	/** Circuit breaker: consecutive reader errors. */
	public const RD_FAIL_THRESHOLD = 5;

	/** Circuit breaker rolling window (seconds). */
	public const RD_FAIL_WINDOW_SECONDS = 300;

	/** Circuit breaker backoff cap (seconds). */
	public const RD_BACKOFF_CAP_SECONDS = 1800;

	/** Circuit breaker exponential backoff base (seconds); doubles per trip up to the cap. */
	public const RD_BACKOFF_BASE_SECONDS = 60;

	/** Block intent enable after a recent detection failure (seconds). */
	public const SET_DETECTION_GRACE_SECONDS = 30;

	/** Sync now: min interval per admin (seconds). */
	public const SYNC_RL_PER_ADMIN_INTERVAL = 60;

	/** Sync now: max triggers per admin per hour. */
	public const SYNC_RL_PER_ADMIN_HOUR = 6;

	/** Sync now: max triggers per instance per hour. */
	public const SYNC_RL_PER_INSTANCE_HOUR = 30;

	public const BANNER_DISMISS_KEY = 'dc-at-integration-banner-v1';

	private function __construct()
	{
	}
}

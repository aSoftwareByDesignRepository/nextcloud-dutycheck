<?php

declare(strict_types=1);

/**
 * Zeus concurrency smoke (docker):
 *   php /var/www/html/custom_apps/dutycheck/scripts/_zeus_concurrency.php
 */

require '/var/www/html/lib/base.php';

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\PeriodLockService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCA\DutyCheck\Service\SwapService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

OC_App::loadApp('dutycheck');

$app = \OCP\Server::get(Application::class);
$c = $app->getContainer();
$locks = $c->get(PeriodLockService::class);
$patterns = $c->get(RotationPatternService::class);
$blackouts = $c->get(AvailabilityBlackoutService::class);
$swaps = $c->get(SwapService::class);
$roster = $c->get(RosterService::class);
$settings = $c->get(SelfServiceSettingsService::class);
$db = $c->get(IDBConnection::class);

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
	global $failures;
	if ($ok) {
		echo "OK  $label" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
	} else {
		$failures++;
		echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
	}
}

// --- Entity mutex serialization ---
$eid = 910001;
$h1 = 'zeus:a';
$h2 = 'zeus:b';
check('rot_assign first acquire', $locks->acquire($eid, PeriodLockService::KIND_ROT_ASSIGN, $h1, 30));
check('rot_assign second blocked', !$locks->acquire($eid, PeriodLockService::KIND_ROT_ASSIGN, $h2, 30));
$locks->release($eid, PeriodLockService::KIND_ROT_ASSIGN, $h1);
check('rot_assign after release', $locks->acquire($eid, PeriodLockService::KIND_ROT_ASSIGN, $h2, 30));
$locks->release($eid, PeriodLockService::KIND_ROT_ASSIGN, $h2);

check('blackout first acquire', $locks->acquire($eid, PeriodLockService::KIND_BLACKOUT, $h1, 30));
check('blackout second blocked', !$locks->acquire($eid, PeriodLockService::KIND_BLACKOUT, $h2, 30));
$locks->release($eid, PeriodLockService::KIND_BLACKOUT, $h1);

// --- Quiet expire purges undelivered past deliver_after + TTL (Momos / FM-QH) ---
$quiet = $c->get(\OCA\DutyCheck\Service\PushQuietHoursService::class);
if (\OCA\DutyCheck\Db\SchemaProbe::tableExists($db, 'dc_push_quiet_queue')) {
	$now = (new DateTimeImmutable('now'))->modify('-48 hours')->format('Y-m-d H:i:s');
	$qb = $db->getQueryBuilder();
	$qb->insert('dc_push_quiet_queue')->values([
		'user_id' => $qb->createNamedParameter('zeus.quiet.test'),
		'company_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
		'notif_type' => $qb->createNamedParameter('zeus_test'),
		'payload_hash' => $qb->createNamedParameter(hash('sha256', 'zeus-undelivered-' . microtime(true))),
		'payload_json' => $qb->createNamedParameter('{}'),
		'deliver_after' => $qb->createNamedParameter($now),
		'created_at' => $qb->createNamedParameter($now),
		'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
		'delivered_at' => $qb->createNamedParameter(null),
	])->executeStatement();
	$id = (int) $qb->getLastInsertId();
	$quiet->drainDue(1); // expireStale abandons undelivered past TTL
	$sel = $db->getQueryBuilder();
	$sel->select('id')->from('dc_push_quiet_queue')
		->where($sel->expr()->eq('id', $sel->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
	$gone = $sel->executeQuery()->fetch() === false;
	check('quiet undelivered age-purged after TTL', $gone, "id=$id");

	// Overflow must shed + stay deferred (never fail-open during quiet).
	$uid = 'zeus.quiet.overflow';
	$clr = $db->getQueryBuilder();
	$clr->delete('dc_push_quiet_queue')
		->where($clr->expr()->eq('user_id', $clr->createNamedParameter($uid)))
		->executeStatement();
	$max = \OCA\DutyCheck\Service\PushQuietHoursService::MAX_PENDING_PER_USER;
	for ($i = 0; $i < $max; $i++) {
		$ins = $db->getQueryBuilder();
		$ins->insert('dc_push_quiet_queue')->values([
			'user_id' => $ins->createNamedParameter($uid),
			'company_id' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'notif_type' => $ins->createNamedParameter('fill'),
			'payload_hash' => $ins->createNamedParameter(hash('sha256', 'fill-' . $i . '-' . microtime(true))),
			'payload_json' => $ins->createNamedParameter('{}'),
			'deliver_after' => $ins->createNamedParameter((new DateTimeImmutable('now +1 day'))->format('Y-m-d H:i:s')),
			'created_at' => $ins->createNamedParameter((new DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
			'attempts' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'delivered_at' => $ins->createNamedParameter(null),
		])->executeStatement();
	}
	$deferred = $quiet->enqueue($uid, 1, 'overflow_probe', ['i' => 'new']);
	check('quiet overflow still deferred (shed, not fail-open)', $deferred === true);
	$cntQ = $db->getQueryBuilder();
	$cntQ->select($cntQ->func()->count('*', 'c'))->from('dc_push_quiet_queue')
		->where($cntQ->expr()->eq('user_id', $cntQ->createNamedParameter($uid)))
		->andWhere($cntQ->expr()->isNull('delivered_at'));
	$pending = (int) $cntQ->executeQuery()->fetchOne();
	check('quiet overflow pending ≤ max', $pending <= $max, "pending=$pending");
	$clr2 = $db->getQueryBuilder();
	$clr2->delete('dc_push_quiet_queue')
		->where($clr2->expr()->eq('user_id', $clr2->createNamedParameter($uid)))
		->executeStatement();
}

// --- HTTP status mappings ---
$map = \OCA\DutyCheck\Controller\ApiJsonErrorResponse::class;
check('PATTERN_INACTIVE→422', $map::statusForInvalidArgument('PATTERN_INACTIVE') === 422);
check('WEEK_START_INVALID→422', $map::statusForInvalidArgument('WEEK_START_INVALID') === 422);
check('BLACKOUT_CONFLICT→422', $map::statusForInvalidArgument('BLACKOUT_CONFLICT') === 422);
check('PREFERENCE_BAND_INVALID→422', $map::statusForInvalidArgument('PREFERENCE_BAND_INVALID') === 422);

// --- DI wiring ---
$ref = new ReflectionClass($patterns);
$p = $ref->getProperty('locks');
$p->setAccessible(true);
check('RotationPatternService has locks', $p->getValue($patterns) instanceof PeriodLockService);

$refB = new ReflectionClass($blackouts);
$pb = $refB->getProperty('locks');
$pb->setAccessible(true);
check('AvailabilityBlackoutService has locks', $pb->getValue($blackouts) instanceof PeriodLockService);

$refSw = new ReflectionClass($swaps);
$psw = $refSw->getProperty('locks');
$psw->setAccessible(true);
check('SwapService has locks', $psw->getValue($swaps) instanceof PeriodLockService);

check('SETTINGS_CONFLICT→409', $map::statusForInvalidArgument('SETTINGS_CONFLICT') === 409);

// --- Prefs lock DI ---
$prefs = $c->get(\OCA\DutyCheck\Service\ShiftPreferenceService::class);
$refP = new ReflectionClass($prefs);
$pp = $refP->getProperty('locks');
$pp->setAccessible(true);
check('ShiftPreferenceService has locks', $pp->getValue($prefs) instanceof PeriodLockService);

// --- Settings CAS: second writer loses ---
$settings = $c->get(\OCA\DutyCheck\Service\SelfServiceSettingsService::class);
$companyId = 1;
$actor = 'admin';
$before = $settings->getForCompany($companyId);
$settings->updateForCompany($companyId, ['peerRosterVisibility' => !empty($before['peer_roster_visibility'])], $actor);
// Simulate stale write by forcing CAS against wrong expected via reflection is heavy —
// instead: two sequential updates with same baseline should both succeed; conflict needs parallel.
// Verify SETTINGS_CONFLICT is thrown when raw mismatches by calling writeStoredCas with wrong expected.
$refS = new ReflectionClass($settings);
$cas = $refS->getMethod('writeStoredCas');
$cas->setAccessible(true);
$threw = false;
try {
	$cas->invoke($settings, $companyId, '{"__stale__":true}', ['peer_roster_visibility' => true]);
} catch (InvalidArgumentException $e) {
	$threw = $e->getMessage() === 'SETTINGS_CONFLICT';
}
check('settings CAS rejects stale raw', $threw);

echo PHP_EOL . ($failures === 0 ? "ALL ZEUS CONCURRENCY CHECKS PASSED\n" : "FAILURES=$failures\n");
exit($failures === 0 ? 0 : 1);

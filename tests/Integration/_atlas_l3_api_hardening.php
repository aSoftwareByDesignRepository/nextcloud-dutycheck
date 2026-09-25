<?php

declare(strict_types=1);

/**
 * Atlas L3 — live API hardening proof.
 *
 * Covers: role boundaries, CAS/stale-version, idempotency, mass-assignment,
 * validation/error quality, scoped-planner existence blindness, rate limits.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

require '/var/www/html/lib/base.php';

use OCP\DB\QueryBuilder\IQueryBuilder;

$userMgr = \OC::$server->get(\OCP\IUserManager::class);
$session = \OC::$server->get(\OCP\IUserSession::class);
$companies = \OC::$server->get(\OCA\DutyCheck\Service\CompanyService::class);
$roster = \OC::$server->get(\OCA\DutyCheck\Service\RosterService::class);
$access = \OC::$server->get(\OCA\DutyCheck\Service\AccessControlService::class);
$scope = \OC::$server->get(\OCA\DutyCheck\Service\PlannerLocationScopeService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);

const ATLAS_MISSING_ID = 88800123;

$checks = [];
$record = function (string $name, bool $ok, array $extra = []) use (&$checks): void {
	$checks[] = array_merge(['name' => $name, 'ok' => $ok], $extra);
};

$atlasCleanup = function () use ($db, $userMgr, $access): void {
	try {
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('dc_companies')
			->where($qb->expr()->like('name', $qb->createNamedParameter('Atlas Co %')));
		$companyIds = array_map(static fn (array $r): int => (int) $r['id'], $qb->executeQuery()->fetchAll());
		if ($companyIds !== []) {
			$periodIds = [];
			$qb = $db->getQueryBuilder();
			$qb->select('id')->from('dc_periods')
				->where($qb->expr()->in('company_id', $qb->createNamedParameter($companyIds, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($qb->executeQuery()->fetchAll() as $r) {
				$periodIds[] = (int) $r['id'];
			}
			if ($periodIds !== [] && $db->tableExists('dc_assignments')) {
				$del = $db->getQueryBuilder();
				$del->delete('dc_assignments')
					->where($del->expr()->in('period_id', $del->createNamedParameter($periodIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$del->executeStatement();
			}
			foreach (['dc_open_shifts', 'dc_swap_requests', 'dc_emp_rot_assign',
				'dc_avail_blackouts', 'dc_shift_preferences', 'dc_absences',
				'dc_employees', 'dc_locations', 'dc_shift_templates',
				'dc_qualifications', 'dc_rotation_patterns', 'dc_periods',
				'dc_company_members', 'dc_planner_locs', 'dc_api_rate_limits',
				'dc_period_locks'] as $table) {
				if (!$db->tableExists($table)) {
					continue;
				}
				$del = $db->getQueryBuilder();
				if ($table === 'dc_planner_locs') {
					$del->delete($table)->where($del->expr()->like('user_id', $del->createNamedParameter('%_atlas3%')));
				} elseif ($table === 'dc_api_rate_limits' || $table === 'dc_period_locks') {
					$del->delete($table)->where($del->expr()->like(
						$table === 'dc_api_rate_limits' ? 'bucket_key' : 'holder',
						$del->createNamedParameter('%atlas3%')
					));
				} else {
					$del->delete($table)
						->where($del->expr()->in('company_id', $del->createNamedParameter($companyIds, IQueryBuilder::PARAM_INT_ARRAY)));
				}
				$del->executeStatement();
			}
			$del = $db->getQueryBuilder();
			$del->delete('dc_companies')
				->where($del->expr()->in('id', $del->createNamedParameter($companyIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$del->executeStatement();
		}
	} catch (\Throwable $e) {
		fwrite(STDERR, 'cleanup companies: ' . $e->getMessage() . "\n");
	}
	foreach (['alice_atlas3', 'bob_atlas3', 'emp_atlas3', 'scoped_atlas3', 'norole_atlas3'] as $uid) {
		try {
			$access->removeDutyRole($uid);
		} catch (\Throwable) {
		}
		try {
			$u = $userMgr->get($uid);
			if ($u !== null) {
				$u->delete();
			}
		} catch (\Throwable $e) {
			fwrite(STDERR, "cleanup user $uid: " . $e->getMessage() . "\n");
		}
	}
};

function atlasLogin(string $user, string $pass): array {
	$cookieJar = tempnam(sys_get_temp_dir(), 'ncjar');
	$ch = curl_init('http://127.0.0.1/login');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEJAR => $cookieJar,
		CURLOPT_COOKIEFILE => $cookieJar,
		CURLOPT_HEADER => true,
	]);
	$loginPage = curl_exec($ch);
	curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
	unset($ch);
	$requesttoken = '';
	if (preg_match('/data-requesttoken="([^"]+)"/', (string) $loginPage, $m)) {
		$requesttoken = $m[1];
	}
	$ch = curl_init('http://127.0.0.1/login');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEJAR => $cookieJar,
		CURLOPT_COOKIEFILE => $cookieJar,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query([
			'user' => $user,
			'password' => $pass,
			'requesttoken' => $requesttoken,
		]),
		CURLOPT_HTTPHEADER => [
			'requesttoken: ' . $requesttoken,
			'Origin: http://127.0.0.1',
		],
		CURLOPT_HEADER => true,
	]);
	$loginResp = (string) curl_exec($ch);
	curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
	unset($ch);
	if (!preg_match('/^Location: (?!.*\/login)/mi', $loginResp)) {
		throw new \RuntimeException("atlas fixture: login for $user did not redirect away from /login");
	}
	return [$cookieJar, $requesttoken];
}

function atlasReq(string $cookieJar, string $method, string $path, string $token, ?array $json = null): array {
	$ch = curl_init('http://127.0.0.1' . $path);
	$headers = [
		'requesttoken: ' . $token,
		'Accept: application/json',
		'OCS-APIRequest: true',
	];
	$opts = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEFILE => $cookieJar,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADER => true,
	];
	if ($json !== null) {
		$headers[] = 'Content-Type: application/json';
		$opts[CURLOPT_POSTFIELDS] = json_encode($json);
	}
	curl_setopt_array($ch, $opts);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$raw = (string) curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
	unset($ch);
	$parts = explode("\r\n\r\n", $raw, 2);
	$body = $parts[1] ?? $raw;
	$decoded = json_decode($body, true);
	$code = is_array($decoded) ? (string) ($decoded['error']['code'] ?? '') : '';
	return ['status' => $status, 'code' => $code, 'decoded' => $decoded];
}

$overallOk = true;
try {
	foreach (['alice_atlas3', 'bob_atlas3', 'emp_atlas3', 'scoped_atlas3', 'norole_atlas3'] as $uid) {
		$u = $userMgr->get($uid);
		if ($u === null) {
			$u = $userMgr->createUser($uid, 'AtlasIdor2026!');
		}
		if ($u === null) {
			throw new \RuntimeException('atlas fixture: cannot create user ' . $uid);
		}
		$u->setPassword('AtlasIdor2026!');
		if (method_exists($u, 'setEnabled')) {
			$u->setEnabled(true);
		}
	}

	$admin = $userMgr->get('admin');
	$session->setUser($admin);

	$tag = bin2hex(random_bytes(3));
	$coA = $companies->createCompany('Atlas Co A3 ' . $tag, 'admin');
	$idA = (int) ($coA['id'] ?? 0);
	$companies->addMember($idA, 'alice_atlas3', 'admin');
	$companies->addMember($idA, 'scoped_atlas3', 'admin');
	$access->setDutyRole('alice_atlas3', \OCA\DutyCheck\Service\AccessControlService::ROLE_PLANNER);
	$access->setDutyRole('scoped_atlas3', \OCA\DutyCheck\Service\AccessControlService::ROLE_PLANNER);
	$access->setDutyRole('emp_atlas3', \OCA\DutyCheck\Service\AccessControlService::ROLE_EMPLOYEE);
	// norole_atlas3 deliberately gets no duty role.

	$session->setUser($userMgr->get('alice_atlas3'));
	$offA = ((int) time() % 4000) + random_int(0, 200);
	$startA = (new DateTimeImmutable('2087-01-01'))->modify('+' . $offA . ' days')->format('Y-m-d');
	$endA = (new DateTimeImmutable($startA))->modify('+6 days')->format('Y-m-d');
	$periodA = $roster->createPeriod($startA, $endA, 'alice_atlas3');
	$periodAId = (int) $periodA['id'];

	$roster->createLocation(['name' => 'Atlas3 LocA ' . $tag, 'timezone' => 'Europe/Berlin'], 'alice_atlas3');
	$roster->createLocation(['name' => 'Atlas3 LocB ' . $tag, 'timezone' => 'Europe/Berlin'], 'alice_atlas3');
	$roster->createEmployee(['displayName' => 'Atlas3 Emp ' . $tag, 'linkedUserId' => 'emp_atlas3', 'active' => 1], 'alice_atlas3');
	$findId = function (string $table, string $col, string $val) use ($db): int {
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($val)))
			->setMaxResults(1);
		return (int) $qb->executeQuery()->fetchOne();
	};
	$locA = $findId('dc_locations', 'name', 'Atlas3 LocA ' . $tag);
	$locB = $findId('dc_locations', 'name', 'Atlas3 LocB ' . $tag);
	$empA = $findId('dc_employees', 'display_name', 'Atlas3 Emp ' . $tag);
	if ($locA <= 0 || $locB <= 0 || $empA <= 0) {
		throw new \RuntimeException('atlas fixture: loc/emp ids missing');
	}
	$created = $roster->createAssignment([
		'periodId' => $periodAId,
		'employeeId' => $empA,
		'locationId' => $locB,
		'dutyDate' => $startA,
		'startTime' => '08:00',
		'endTime' => '12:00',
		'breakMinutes' => 0,
	], 'alice_atlas3');
	$asgB = 0;
	foreach (($created['assignments'] ?? []) as $row) {
		$asgB = max($asgB, (int) ($row['id'] ?? 0));
	}
	if ($asgB <= 0) {
		$asgB = (int) ($created['createdAssignmentId'] ?? 0);
	}
	if ($asgB <= 0) {
		throw new \RuntimeException('atlas fixture: assignment id missing');
	}

	// Scope scoped_atlas3 to locA only (locB rows must become invisible).
	$scope->setScope('scoped_atlas3', [$locA]);

	// --- Role boundaries ---------------------------------------------------
	[$jarEmp, $tokEmp] = atlasLogin('emp_atlas3', 'AtlasIdor2026!');
	$r = atlasReq($jarEmp, 'POST', '/apps/dutycheck/api/periods', $tokEmp, [
		'startDate' => '2099-01-01', 'endDate' => '2099-01-07',
	]);
	$record('http.role.employee_createPeriod_403',
		$r['status'] === 403, ['got' => [$r['status'], $r['code']]]);

	[$jarNo, $tokNo] = atlasLogin('norole_atlas3', 'AtlasIdor2026!');
	$r = atlasReq($jarNo, 'GET', '/apps/dutycheck/api/periods', $tokNo);
	$record('http.role.norole_listPeriods_403', $r['status'] === 403, ['got' => [$r['status'], $r['code']]]);

	// --- Validation / error quality ----------------------------------------
	[$jarA, $tokA] = atlasLogin('alice_atlas3', 'AtlasIdor2026!');
	$r = atlasReq($jarA, 'POST', '/apps/dutycheck/api/periods', $tokA, [
		'startDate' => '2099-06-10', 'endDate' => '2099-06-01',
	]);
	$record('http.validation.period_range',
		$r['status'] >= 400 && $r['status'] < 500 && $r['code'] === 'INVALID_PERIOD_RANGE',
		['got' => [$r['status'], $r['code']]]);

	$r = atlasReq($jarA, 'POST', '/apps/dutycheck/api/assignments', $tokA, [
		'periodId' => $periodAId, 'employeeId' => $empA, 'locationId' => $locA,
		'dutyDate' => $startA, 'startTime' => '25:99', 'endTime' => '12:00',
	]);
	$record('http.validation.bad_time',
		$r['status'] >= 400 && $r['status'] < 500 && $r['code'] !== '',
		['got' => [$r['status'], $r['code']]]);

	// --- Mass assignment -----------------------------------------------------
	$r = atlasReq($jarA, 'POST', '/apps/dutycheck/api/assignments', $tokA, [
		'periodId' => $periodAId, 'employeeId' => $empA, 'locationId' => $locA,
		'dutyDate' => (new DateTimeImmutable($startA))->modify('+1 day')->format('Y-m-d'),
		'startTime' => '06:00', 'endTime' => '10:00',
		'id' => 424242,
		'companyId' => 99999,
		'company_id' => 99999,
		'version' => 99,
		'isAdmin' => true,
		'status' => 'cancelled',
	]);
	$created2 = $r['decoded']['data']['createdAssignmentId'] ?? null;
	$okMa = in_array($r['status'], [200, 201], true);
	if ($okMa) {
		$qb = $db->getQueryBuilder();
		$qb->select('id', 'employee_id', 'location_id', 'status', 'version')
			->from('dc_assignments')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodAId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('start_time', $qb->createNamedParameter('06:00')));
		$row = $qb->executeQuery()->fetch();
		$okMa = $row !== false
			&& (int) $row['id'] !== 424242
			&& (string) $row['status'] === 'active'
			&& (int) $row['version'] <= 1;
	}
	$record('http.mass_assignment.ignored_fields', $okMa,
		['status' => $r['status'], 'row' => $row ?? null]);

	// --- CAS / stale version -------------------------------------------------
	// updateAssignment takes the full row payload (employeeId/locationId required).
	$casPayload = [
		'employeeId' => $empA, 'locationId' => $locB,
		'dutyDate' => $startA, 'startTime' => '09:00', 'endTime' => '12:00',
	];
	$r = atlasReq($jarA, 'PUT', '/apps/dutycheck/api/assignments/' . $asgB, $tokA,
		$casPayload + ['expectedVersion' => 9999]);
	$record('http.cas.stale_version_409',
		$r['status'] === 409 && $r['code'] === 'STALE_VERSION',
		['got' => [$r['status'], $r['code']]]);

	$qb = $db->getQueryBuilder();
	$qb->select('version')->from('dc_assignments')
		->where($qb->expr()->eq('id', $qb->createNamedParameter($asgB, IQueryBuilder::PARAM_INT)));
	$ver = (int) $qb->executeQuery()->fetchOne();
	$r = atlasReq($jarA, 'PUT', '/apps/dutycheck/api/assignments/' . $asgB, $tokA,
		$casPayload + ['expectedVersion' => $ver]);
	$record('http.cas.current_version_ok',
		$r['status'] === 200,
		['got' => [$r['status'], $r['code']], 'version' => $ver]);
	// Second write with the now-stale version must conflict.
	$r = atlasReq($jarA, 'PUT', '/apps/dutycheck/api/assignments/' . $asgB, $tokA,
		$casPayload + ['startTime' => '09:30', 'expectedVersion' => $ver]);
	$record('http.cas.replayed_version_409',
		$r['status'] === 409 && $r['code'] === 'STALE_VERSION',
		['got' => [$r['status'], $r['code']]]);

	// --- Idempotency: acknowledge twice -------------------------------------
	$ch = curl_init('http://127.0.0.1/apps/dutycheck/api/my/assignments/' . $asgB . '/acknowledge');
	$ack1 = atlasReq($jarEmp, 'POST', '/apps/dutycheck/api/my/assignments/' . $asgB . '/acknowledge', $tokEmp, []);
	$ack2 = atlasReq($jarEmp, 'POST', '/apps/dutycheck/api/my/assignments/' . $asgB . '/acknowledge', $tokEmp, []);
	$record('http.idempotent.acknowledge_repeat',
		in_array($ack1['status'], [200, 422, 409], true)
			&& $ack2['status'] === $ack1['status']
			&& $ack2['code'] === $ack1['code']
			&& ($ack1['status'] !== 200 || ($ack1['decoded']['data']['acknowledgedAt'] ?? null) === ($ack2['decoded']['data']['acknowledgedAt'] ?? null)),
		['first' => [$ack1['status'], $ack1['code']], 'second' => [$ack2['status'], $ack2['code']]]);

	// --- Scoped planner: foreign-scope resource ≡ missing --------------------
	[$jarS, $tokS] = atlasLogin('scoped_atlas3', 'AtlasIdor2026!');
	$cross = atlasReq($jarS, 'PUT', '/apps/dutycheck/api/assignments/' . $asgB, $tokS, [
		'startTime' => '09:00', 'expectedVersion' => 1,
	]);
	$missing = atlasReq($jarS, 'PUT', '/apps/dutycheck/api/assignments/' . ATLAS_MISSING_ID, $tokS, [
		'startTime' => '09:00', 'expectedVersion' => 1,
	]);
	$record('http.scope.assignment_update.foreign_vs_missing_identical',
		$cross['status'] === $missing['status'] && $cross['code'] === $missing['code']
			&& $cross['status'] === 404 && $cross['code'] === 'ASSIGNMENT_NOT_FOUND',
		['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);

	$payload = [
		'periodId' => $periodAId, 'employeeId' => $empA,
		'dutyDate' => (new DateTimeImmutable($startA))->modify('+2 days')->format('Y-m-d'),
		'startTime' => '06:00', 'endTime' => '10:00',
	];
	$cross = atlasReq($jarS, 'POST', '/apps/dutycheck/api/assignments', $tokS,
		$payload + ['locationId' => $locB]);
	$missing = atlasReq($jarS, 'POST', '/apps/dutycheck/api/assignments', $tokS,
		$payload + ['locationId' => ATLAS_MISSING_ID]);
	$record('http.scope.create.location_param.foreign_vs_missing_identical',
		$cross['status'] === $missing['status'] && $cross['code'] === $missing['code']
			&& $cross['status'] === 404 && $cross['code'] === 'LOCATION_NOT_FOUND',
		['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);

	$cross = atlasReq($jarS, 'GET', '/apps/dutycheck/api/today-board?locationId=' . $locB . '&date=2099-01-01', $tokS);
	$missing = atlasReq($jarS, 'GET', '/apps/dutycheck/api/today-board?locationId=' . ATLAS_MISSING_ID . '&date=2099-01-01', $tokS);
	$record('http.scope.today_board.foreign_vs_missing_identical',
		$cross['status'] === $missing['status'] && $cross['code'] === $missing['code']
			&& $cross['status'] === 404,
		['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);

	// --- Rate limit (suggest-preview, 30/min bucket) -------------------------
	$limited = null;
	for ($i = 0; $i < 32; $i++) {
		$limited = atlasReq($jarA, 'POST', '/apps/dutycheck/api/periods/' . $periodAId . '/suggest-preview', $tokA, []);
		if ($limited['status'] === 429) {
			break;
		}
	}
	$record('http.rate_limit.suggest_preview_429',
		$limited !== null && $limited['status'] === 429,
		['last' => [$limited['status'] ?? 0, $limited['code'] ?? '']]);

	foreach ($checks as $c) {
		if (!$c['ok']) {
			$overallOk = false;
		}
	}
} catch (\Throwable $e) {
	$overallOk = false;
	fwrite(STDERR, 'probe fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
} finally {
	try {
		$atlasCleanup();
	} catch (\Throwable $e) {
		fwrite(STDERR, 'cleanup fatal: ' . $e->getMessage() . "\n");
	}
}

$result = [
	'ok' => $overallOk && array_reduce($checks, fn ($c, $x) => $c && ($x['ok'] ?? false), true),
	'checks' => $checks,
];
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit($result['ok'] ? 0 : 1);

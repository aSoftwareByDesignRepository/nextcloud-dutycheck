<?php

declare(strict_types=1);

/**
 * Atlas L2 — live company IDOR proof via RosterService + HTTP.
 *
 * Uniform-404 doctrine: an existing foreign-company row and a missing row
 * must produce IDENTICAL HTTP status and error.code at the same endpoint.
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
$openShifts = \OC::$server->get(\OCA\DutyCheck\Service\OpenShiftService::class);
$access = \OC::$server->get(\OCA\DutyCheck\Service\AccessControlService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);

const ATLAS_MISSING_ID = 88800123;

$checks = [];
$record = function (string $name, bool $ok, array $extra = []) use (&$checks): void {
	$checks[] = array_merge(['name' => $name, 'ok' => $ok], $extra);
};

/** Throw → error-code string, or null on success. */
$codeOf = function (callable $fn): ?string {
	try {
		$fn();
		return null;
	} catch (\InvalidArgumentException $e) {
		return $e->getMessage();
	} catch (\Throwable $e) {
		return 'THROW:' . get_class($e);
	}
};

/**
 * Remove ALL artifacts from every atlas run (this run + leftovers from older runs).
 */
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
			// dc_assignments has no company_id — purge via the period link.
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
				'dc_company_members'] as $table) {
				if (!$db->tableExists($table)) {
					continue;
				}
				$del = $db->getQueryBuilder();
				$del->delete($table)
					->where($del->expr()->in('company_id', $del->createNamedParameter($companyIds, IQueryBuilder::PARAM_INT_ARRAY)));
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
	foreach (['alice_atlas', 'bob_atlas'] as $uid) {
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

$overallOk = true;
try {
	// --- Fixtures ---------------------------------------------------------
	foreach (['alice_atlas', 'bob_atlas'] as $uid) {
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
	$coA = $companies->createCompany('Atlas Co A ' . $tag, 'admin');
	$coB = $companies->createCompany('Atlas Co B ' . $tag, 'admin');
	$idA = (int) ($coA['id'] ?? 0);
	$idB = (int) ($coB['id'] ?? 0);

	$companies->addMember($idA, 'alice_atlas', 'admin');
	$companies->addMember($idB, 'bob_atlas', 'admin');
	$access->setDutyRole('alice_atlas', \OCA\DutyCheck\Service\AccessControlService::ROLE_PLANNER);
	$access->setDutyRole('bob_atlas', \OCA\DutyCheck\Service\AccessControlService::ROLE_PLANNER);

	$offA = ((int) time() % 4000) + random_int(0, 200);
	$session->setUser($userMgr->get('alice_atlas'));
	$startA = (new DateTimeImmutable('2085-01-01'))->modify('+' . $offA . ' days')->format('Y-m-d');
	$endA = (new DateTimeImmutable($startA))->modify('+6 days')->format('Y-m-d');
	$periodA = $roster->createPeriod($startA, $endA, 'alice_atlas');
	$periodAId = (int) $periodA['id'];

	$offB = $offA + 50;
	$session->setUser($userMgr->get('bob_atlas'));
	$startB = (new DateTimeImmutable('2086-01-01'))->modify('+' . $offB . ' days')->format('Y-m-d');
	$endB = (new DateTimeImmutable($startB))->modify('+6 days')->format('Y-m-d');
	$periodB = $roster->createPeriod($startB, $endB, 'bob_atlas');
	$periodBId = (int) $periodB['id'];

	// Bob-side fixture rows inside company B (assignment + open shift).
	$roster->createLocation(['name' => 'Atlas Loc B ' . $tag, 'timezone' => 'Europe/Berlin'], 'bob_atlas');
	$roster->createEmployee(['displayName' => 'Atlas Emp B ' . $tag, 'active' => 1], 'bob_atlas');
	$findId = function (string $table, string $col, string $val) use ($db): int {
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($val)))
			->setMaxResults(1);
		return (int) $qb->executeQuery()->fetchOne();
	};
	$locB = $findId('dc_locations', 'name', 'Atlas Loc B ' . $tag);
	$empB = $findId('dc_employees', 'display_name', 'Atlas Emp B ' . $tag);
	$assignmentB = 0;
	$openShiftB = 0;
	if ($locB > 0 && $empB > 0) {
		$created = $roster->createAssignment([
			'periodId' => $periodBId,
			'employeeId' => $empB,
			'locationId' => $locB,
			'dutyDate' => $startB,
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
		], 'bob_atlas');
		$assignmentB = (int) ($created['createdAssignmentId'] ?? 0);
		if ($assignmentB <= 0) {
			foreach (($created['assignments'] ?? []) as $row) {
				$assignmentB = max($assignmentB, (int) ($row['id'] ?? 0));
			}
		}
		$open = $openShifts->create([
			'periodId' => $periodBId,
			'locationId' => $locB,
			'dutyDate' => $startB,
			'startTime' => '13:00',
			'endTime' => '17:00',
			'breakMinutes' => 0,
		], 'bob_atlas');
		$openShiftB = (int) ($open['id'] ?? 0);
	}

	// --- Service-level uniform-404 (alice vs company B) --------------------
	$session->setUser($userMgr->get('alice_atlas'));

	$foreignCode = $codeOf(fn () => $roster->assertPeriodCompanyAccess('alice_atlas', $periodBId));
	$missingCode = $codeOf(fn () => $roster->assertPeriodCompanyAccess('alice_atlas', ATLAS_MISSING_ID));
	$record('svc.period.foreign_vs_missing_identical',
		$foreignCode === 'PERIOD_NOT_FOUND' && $missingCode === 'PERIOD_NOT_FOUND',
		['foreign' => $foreignCode, 'missing' => $missingCode]);

	$ownCode = $codeOf(fn () => $roster->assertPeriodCompanyAccess('alice_atlas', $periodAId));
	$record('svc.period.own_ok', $ownCode === null, ['code' => $ownCode]);

	if ($assignmentB > 0) {
		$f = $codeOf(fn () => $roster->peekAssignment($assignmentB, 'alice_atlas'));
		$m = $codeOf(fn () => $roster->peekAssignment(ATLAS_MISSING_ID, 'alice_atlas'));
		$record('svc.assignment.peek.foreign_vs_missing_identical',
			$f === 'ASSIGNMENT_NOT_FOUND' && $m === 'ASSIGNMENT_NOT_FOUND',
			['foreign' => $f, 'missing' => $m]);

		$f = $codeOf(fn () => $roster->updateAssignment($assignmentB, ['startTime' => '09:00'], 'alice_atlas'));
		$m = $codeOf(fn () => $roster->updateAssignment(ATLAS_MISSING_ID, ['startTime' => '09:00'], 'alice_atlas'));
		$record('svc.assignment.update.foreign_vs_missing_identical',
			$f === 'ASSIGNMENT_NOT_FOUND' && $m === 'ASSIGNMENT_NOT_FOUND',
			['foreign' => $f, 'missing' => $m]);
	}

	// --- HTTP uniform-404 (alice session) ----------------------------------
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
		// PHP 8.5: curl_close() is a no-op — flush the jar before the handle dies.
		curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
		unset($ch);
		if (!preg_match('/^Location: (?!.*\/login)/mi', $loginResp)) {
			throw new \RuntimeException('atlas fixture: login POST did not redirect away from /login');
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
		return ['status' => $status, 'code' => $code, 'body' => $body];
	}

	[$jar, $tok] = atlasLogin('alice_atlas', 'AtlasIdor2026!');
	$ch = curl_init('http://127.0.0.1/apps/dutycheck/');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEFILE => $jar,
		CURLOPT_COOKIEJAR => $jar,
		CURLOPT_HEADER => true,
		CURLOPT_FOLLOWLOCATION => true,
	]);
	$appPage = (string) curl_exec($ch);
	curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
	unset($ch);
	if (preg_match('/data-requesttoken="([^"]+)"/', $appPage, $m)) {
		$tok = $m[1];
	}

	// Anonymous → 401 on API surface.
	$anon = atlasReq('/dev/null', 'GET', '/apps/dutycheck/api/roster', 'x');
	$record('http.anon.roster_401', $anon['status'] === 401, ['status' => $anon['status']]);

	// Period read: foreign vs missing must be identical 404 + PERIOD_NOT_FOUND.
	$cross = atlasReq($jar, 'GET', '/apps/dutycheck/api/roster?periodId=' . $periodBId, $tok);
	$missing = atlasReq($jar, 'GET', '/apps/dutycheck/api/roster?periodId=' . ATLAS_MISSING_ID, $tok);
	$record('http.period.foreign_vs_missing_identical',
		$cross['status'] === 404 && $missing['status'] === 404
			&& $cross['code'] === 'PERIOD_NOT_FOUND' && $missing['code'] === 'PERIOD_NOT_FOUND',
		['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);

	$own = atlasReq($jar, 'GET', '/apps/dutycheck/api/roster?periodId=' . $periodAId, $tok);
	$record('http.period.own_200', $own['status'] === 200, ['status' => $own['status']]);

	// Period transition mutation: foreign vs missing identical.
	$payload = ['targetStatus' => 'published'];
	$cross = atlasReq($jar, 'POST', '/apps/dutycheck/api/periods/' . $periodBId . '/transition', $tok, $payload);
	$missing = atlasReq($jar, 'POST', '/apps/dutycheck/api/periods/' . ATLAS_MISSING_ID . '/transition', $tok, $payload);
	$record('http.period_transition.foreign_vs_missing_identical',
		$cross['status'] === $missing['status'] && $cross['code'] === $missing['code']
			&& $cross['status'] === 404 && $cross['code'] === 'PERIOD_NOT_FOUND',
		['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);

	if ($assignmentB > 0) {
		$payload = ['startTime' => '09:00', 'expectedVersion' => 1];
		$cross = atlasReq($jar, 'PUT', '/apps/dutycheck/api/assignments/' . $assignmentB, $tok, $payload);
		$missing = atlasReq($jar, 'PUT', '/apps/dutycheck/api/assignments/' . ATLAS_MISSING_ID, $tok, $payload);
		$record('http.assignment_update.foreign_vs_missing_identical',
			$cross['status'] === $missing['status'] && $cross['code'] === $missing['code']
				&& $cross['status'] === 404 && $cross['code'] === 'ASSIGNMENT_NOT_FOUND',
			['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);
	}

	if ($openShiftB > 0) {
		$cross = atlasReq($jar, 'POST', '/apps/dutycheck/api/open-shifts/' . $openShiftB . '/approve', $tok, []);
		$missing = atlasReq($jar, 'POST', '/apps/dutycheck/api/open-shifts/' . ATLAS_MISSING_ID . '/approve', $tok, []);
		$record('http.open_shift_approve.foreign_vs_missing_identical',
			$cross['status'] === $missing['status'] && $cross['code'] === $missing['code']
				&& $cross['status'] === 404 && $cross['code'] === 'OPEN_SHIFT_NOT_FOUND',
			['foreign' => [$cross['status'], $cross['code']], 'missing' => [$missing['status'], $missing['code']]]);
	}

	// CSRF: mutation without requesttoken must be rejected.
	$ch = curl_init('http://127.0.0.1/apps/dutycheck/api/periods/' . $periodAId . '/transition');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEFILE => $jar,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => json_encode(['targetStatus' => 'published']),
		CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
	]);
	curl_exec($ch);
	$noCsrf = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
	unset($ch);
	$record('http.csrf_required_on_mutation', in_array($noCsrf, [400, 401, 403, 412], true) && $noCsrf !== 200,
		['status' => $noCsrf]);

	@unlink($jar);

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
	'multiCompanyActive' => $companies->isMultiCompanyActive(),
];
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit($result['ok'] ? 0 : 1);

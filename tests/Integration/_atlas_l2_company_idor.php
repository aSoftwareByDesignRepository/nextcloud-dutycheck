<?php

declare(strict_types=1);

/**
 * Atlas L2 — live company IDOR proof via RosterService.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

require '/var/www/html/lib/base.php';

$userMgr = \OC::$server->get(\OCP\IUserManager::class);
$session = \OC::$server->get(\OCP\IUserSession::class);
$companies = \OC::$server->get(\OCA\DutyCheck\Service\CompanyService::class);
$roster = \OC::$server->get(\OCA\DutyCheck\Service\RosterService::class);
$access = \OC::$server->get(\OCA\DutyCheck\Service\AccessControlService::class);

foreach (['alice_atlas', 'bob_atlas'] as $uid) {
	if ($userMgr->get($uid) === null) {
		$userMgr->createUser($uid, 'AtlasIdor2026!');
	} else {
		$userMgr->get($uid)->setPassword('AtlasIdor2026!');
	}
}

$admin = $userMgr->get('admin');
$session->setUser($admin);

$tag = bin2hex(random_bytes(3));
try {
	$coA = $companies->createCompany('Atlas Co A ' . $tag, 'admin');
	$coB = $companies->createCompany('Atlas Co B ' . $tag, 'admin');
} catch (Throwable $e) {
	fwrite(STDERR, 'createCompany: ' . $e->getMessage() . "\n");
	exit(1);
}
$idA = (int) ($coA['id'] ?? 0);
$idB = (int) ($coB['id'] ?? 0);

$companies->addMember($idA, 'alice_atlas', 'admin');
$companies->addMember($idB, 'bob_atlas', 'admin');
// Keep planners off each other's companies (remove accidental default-only ambiguity later if needed)

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

$session->setUser($userMgr->get('alice_atlas'));
$aliceDenied = false;
$aliceCode = null;
try {
	$roster->assertPeriodCompanyAccess('alice_atlas', $periodBId);
} catch (Throwable $e) {
	$aliceDenied = true;
	$aliceCode = $e->getMessage();
}

$session->setUser($userMgr->get('bob_atlas'));
$bobDenied = false;
$bobCode = null;
try {
	$roster->assertPeriodCompanyAccess('bob_atlas', $periodAId);
} catch (Throwable $e) {
	$bobDenied = true;
	$bobCode = $e->getMessage();
}

$session->setUser($userMgr->get('alice_atlas'));
$aliceOwnOk = false;
$aliceOwnCode = null;
try {
	$roster->assertPeriodCompanyAccess('alice_atlas', $periodAId);
	$aliceOwnOk = true;
} catch (Throwable $e) {
	$aliceOwnCode = $e->getMessage();
}

// HTTP IDOR: alice session cookie against bob's period API
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
	curl_close($ch);
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
		CURLOPT_HTTPHEADER => ['requesttoken: ' . $requesttoken],
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HEADER => true,
	]);
	curl_exec($ch);
	curl_close($ch);
	return [$cookieJar, $requesttoken];
}

function atlasGet(string $cookieJar, string $path, string $token): array {
	$ch = curl_init('http://127.0.0.1' . $path);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEFILE => $cookieJar,
		CURLOPT_HTTPHEADER => [
			'requesttoken: ' . $token,
			'Accept: application/json',
			'OCS-APIRequest: true',
		],
		CURLOPT_HEADER => true,
	]);
	$raw = (string) curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$parts = explode("\r\n\r\n", $raw, 2);
	$body = $parts[1] ?? $raw;
	return ['status' => $status, 'body' => $body];
}

[$jar, $tok] = atlasLogin('alice_atlas', 'AtlasIdor2026!');
// refresh token from app page
$ch = curl_init('http://127.0.0.1/apps/dutycheck/');
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_COOKIEFILE => $jar,
	CURLOPT_COOKIEJAR => $jar,
	CURLOPT_HEADER => true,
	CURLOPT_FOLLOWLOCATION => true,
]);
$appPage = (string) curl_exec($ch);
curl_close($ch);
if (preg_match('/data-requesttoken="([^"]+)"/', $appPage, $m)) {
	$tok = $m[1];
}

$httpCross = atlasGet($jar, '/apps/dutycheck/api/roster?periodId=' . $periodBId, $tok);
$httpOwn = atlasGet($jar, '/apps/dutycheck/api/roster?periodId=' . $periodAId, $tok);
@unlink($jar);

$httpCrossBlocked = in_array($httpCross['status'], [403, 404], true)
	|| str_contains($httpCross['body'], 'COMPANY')
	|| str_contains($httpCross['body'], 'FORBIDDEN')
	|| str_contains($httpCross['body'], 'ACCESS')
	|| str_contains($httpCross['body'], 'denied')
	|| ($httpCross['status'] >= 400);

$httpOwnOk = $httpOwn['status'] === 200 && str_contains($httpOwn['body'], '"ok"');

$result = [
	'ok' => $aliceDenied && $bobDenied && $aliceOwnOk && $httpCrossBlocked && $httpOwnOk,
	'companyA' => $idA,
	'companyB' => $idB,
	'periodA' => $periodAId,
	'periodB' => $periodBId,
	'aliceCrossDenied' => $aliceDenied,
	'aliceCrossCode' => $aliceCode,
	'bobCrossDenied' => $bobDenied,
	'bobCrossCode' => $bobCode,
	'aliceOwnOk' => $aliceOwnOk,
	'aliceOwnCode' => $aliceOwnCode,
	'httpCrossStatus' => $httpCross['status'],
	'httpCrossBody' => substr($httpCross['body'], 0, 300),
	'httpOwnStatus' => $httpOwn['status'],
	'httpOwnSnippet' => substr($httpOwn['body'], 0, 200),
	'httpCrossBlocked' => $httpCrossBlocked,
	'httpOwnOk' => $httpOwnOk,
	'multiCompanyActive' => $companies->isMultiCompanyActive(),
];
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit($result['ok'] ? 0 : 1);

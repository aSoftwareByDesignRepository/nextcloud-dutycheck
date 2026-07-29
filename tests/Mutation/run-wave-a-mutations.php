<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for Wave A0/A security-critical paths.
 * Run: php tests/Mutation/run-wave-a-mutations.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use OCA\DutyCheck\Controller\ApiJsonErrorResponse;
use OCA\DutyCheck\License\Dty2Codec;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SeatRank;

$failures = 0;
$assert = static function (bool $ok, string $msg) use (&$failures): void {
	if (!$ok) {
		fwrite(STDERR, "FAIL: $msg\n");
		$failures++;
	} else {
		fwrite(STDOUT, "OK: $msg\n");
	}
};

$keys = RosterService::rosterApiConflictMessageKeys();
$assert(!in_array('Weekly hard cap exceeded for employee', $keys, true), 'weekly hard label removed');
$assert(in_array('Period total hard cap exceeded for employee', $keys, true), 'period hard label present');

$assert(ApiJsonErrorResponse::statusForInvalidArgument('PERIOD_NOT_OPEN') === 422, 'PERIOD_NOT_OPEN → 422');
$assert(ApiJsonErrorResponse::statusForInvalidArgument('ASSIGNMENT_CANCELLED') === 422, 'ASSIGNMENT_CANCELLED → 422');
$assert(ApiJsonErrorResponse::statusForInvalidArgument('QUALIFICATION_MISSING') === 422, 'QUALIFICATION_MISSING → 422');
$assert(ApiJsonErrorResponse::statusForInvalidArgument('FORBIDDEN') === 403, 'FORBIDDEN → 403');
$assert(in_array('Employee is missing a required qualification for this location', $keys, true), 'qual missing message key');
$assert(in_array('Employee qualification is expired for this location', $keys, true), 'qual expired message key');
$assert(!in_array('weekly_hours_hard_cap', $keys, true), 'weekly type not in message keys');

// Website honesty (relative to app root → repo website)
$websiteEn = dirname($root, 3) . '/website/en/apps/dutycheck.html';
if (is_file($websiteEn)) {
	$html = (string) file_get_contents($websiteEn);
	$assert(stripos($html, 'draft, validate') === false, 'website EN no draft/validate');
	$assert(stripos($html, 'open, published, closed') !== false || stripos($html, 'Open, publish, and close') !== false, 'website EN honest lifecycle');
}

$ranked = [
	['id' => 10, 'assignedAt' => 1],
	['id' => 20, 'assignedAt' => 2],
	['id' => 30, 'assignedAt' => 3],
];
$assert(SeatRank::isWithinLimit($ranked, 30, 2) === false, 'over-limit seat rejected');
$assert(SeatRank::isWithinLimit($ranked, 10, 2) === true, 'first seat allowed');

$d = ConflictPolicyService::defaults();
$assert($d['maxPeriodHard'] >= $d['maxPeriodSoft'], 'hard ≥ soft defaults');

$assert(Dty2Codec::PRODUCT === 'dutycheck', 'DTY2 product id');
$assert(Dty2Codec::FORMAT === 'DTY2', 'DTY2 format');

exit($failures === 0 ? 0 : 1);

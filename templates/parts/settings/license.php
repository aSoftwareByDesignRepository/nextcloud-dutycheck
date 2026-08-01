<?php
/**
 * Settings sub-page: Mobile license (DTY2 seats).
 *
 * All data (license status, seats, URLs, i18n strings, request token) is
 * computed by PageController::licenseSettingsExtras() and passed via $_ —
 * this partial performs no service lookups, so it renders identically in
 * production and in kernel-free template tests.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$licenseStatus = $_['licenseStatus'] ?? null;
$licenseSeatsList = $_['licenseSeatsList'] ?? null;
$licenseI18n = $_['licenseI18n'] ?? null;
$licenseApiUrl = (string) ($_['licenseApiUrl'] ?? '');
$licenseClearUrl = (string) ($_['licenseClearUrl'] ?? $licenseApiUrl);
$licenseSeatsUrl = (string) ($_['licenseSeatsUrl'] ?? '');
$licenseAssignSeatUrl = (string) ($_['licenseAssignSeatUrl'] ?? $licenseSeatsUrl);
$licenseRemoveSeatBase = (string) ($_['licenseRemoveSeatBase'] ?? ($licenseSeatsUrl !== '' ? rtrim($licenseSeatsUrl, '/') . '/' : ''));
$licenseSearchUsersUrl = (string) ($_['licenseSearchUsersUrl'] ?? '');
$requesttoken = (string) ($_['requesttoken'] ?? '');

include dirname(__DIR__) . '/license-panel.php';

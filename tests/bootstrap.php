<?php

declare(strict_types=1);

$candidates = [];
$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
if ($nextcloudRoot !== '') {
	$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
}
$candidates[] = __DIR__ . '/../../lib/base.php';
$candidates[] = __DIR__ . '/../../../lib/base.php';

$base = null;
foreach ($candidates as $candidate) {
	if (is_file($candidate)) {
		$base = $candidate;
		break;
	}
}

if ($base !== null) {
	require_once $base;
	$integrationBootstrap = dirname(__DIR__, 3) . '/scripts/phpunit-integration-bootstrap.php';
	if (is_file($integrationBootstrap)) {
		require_once $integrationBootstrap;
	}
	if (!class_exists(\Test\TestCase::class)) {
		$shim = __DIR__ . '/shim/TestCase.php';
		if (is_file($shim)) {
			require_once $shim;
		}
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

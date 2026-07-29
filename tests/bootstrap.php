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
}

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Test\TestCase::class)) {
	$shim = __DIR__ . '/shim/TestCase.php';
	if (is_file($shim)) {
		require_once $shim;
	}
}

if ($base === null && !class_exists(\Symfony\Component\Console\Command\Command::class, false)) {
	eval('namespace Symfony\Component\Console\Command; class Command {}');
}

// The vendored OCP package references private server interfaces that only exist
// inside a full Nextcloud installation (e.g. IRootFolder extends OC\Hooks\Emitter).
// Stub them for standalone runs so those interfaces stay mockable.
if ($base === null && !interface_exists(\OC\Hooks\Emitter::class, false)) {
	eval('namespace OC\Hooks; interface Emitter {}');
}

$ocpStubs = dirname(__DIR__, 3) . '/scripts/phpunit-ocp-doctrine-stubs.php';
if ($base === null && is_file($ocpStubs)) {
	require_once $ocpStubs;
}

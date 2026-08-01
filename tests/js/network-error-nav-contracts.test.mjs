/**
 * Source contracts: abort/unload handling must stay wired across API, messaging,
 * periods detail loads, and license settings.
 *
 * Run: node --test tests/js/network-error-nav-contracts.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

test('api.js classifies abort / unload separately from NETWORK_ERROR', () => {
	const src = read('js/common/api.js');
	assert.match(src, /REQUEST_ABORTED/);
	assert.match(src, /function isAborted\s*\(/);
	assert.match(src, /function isPageUnloading\s*\(/);
	assert.match(src, /function classifyFetchFailure\s*\(/);
	assert.match(src, /function composeAbortSignal\s*\(/);
	assert.match(src, /pagehide/);
	assert.match(src, /beforeunload/);
	assert.match(src, /pageshow/);
	// Must not blindly wrap every fetch rejection as NETWORK_ERROR.
	assert.match(src, /throw classifyFetchFailure\(cause,\s*signal\)/);
	assert.doesNotMatch(
		src,
		/catch\s*\(\s*cause\s*\)\s*\{\s*throw networkError\(cause\)\s*;\s*\}/,
	);
});

test('messaging.js silences aborted + unload network errors', () => {
	const src = read('js/common/messaging.js');
	assert.match(src, /isAborted/);
	assert.match(src, /REQUEST_ABORTED/);
	assert.match(src, /isPageUnloading/);
	assert.match(src, /Network error\. Please check your connection and retry\./);
});

test('periods.js ignores aborted rejections in detail fan-out', () => {
	const src = read('js/periods.js');
	assert.match(src, /Api\.isAborted/);
	assert.match(src, /failed\[0\]\.reason/);
});

test('license-settings.js suppresses network feedback on nav abort', () => {
	const src = read('js/license-settings.js');
	assert.match(src, /showNetworkErrorUnlessAbort/);
	assert.match(src, /isNavOrAbort/);
	assert.match(src, /aborted:\s*isNavOrAbort\(cause\)/);
});

test('PageController still loads api before messaging/session', () => {
	const src = read('lib/Controller/PageController.php');
	const apiPos = src.indexOf("addScript(Application::APP_ID, 'common/api')");
	const msgPos = src.indexOf("addScript(Application::APP_ID, 'common/messaging')");
	const sessionPos = src.indexOf("addScript(Application::APP_ID, 'common/session')");
	assert.ok(apiPos > 0);
	assert.ok(msgPos > apiPos, 'messaging must load after api');
	assert.ok(sessionPos > apiPos, 'session must load after api');
});

/**
 * Contract tests for the 0.3.5 customer-report fixes:
 *
 * 1. Delete parity — a shift entered manually in the roster grid opened its
 *    edit modal with no way to delete it; only the assignments table row had
 *    "Cancel shift". The modal now exposes the same destructive action via the
 *    openModal secondary slot.
 * 2. Zugriff (Access) removal trap — whole-document policy save threw
 *    INVALID_ALLOWED_USER on stale stored ids (deleted/disabled accounts),
 *    so removing a person could never persist and the page stayed dirty.
 *    The service now prunes stale stored ids, and the page announces that a
 *    removal still needs "Save app policy".
 *
 * Run: node --test tests/js/assignment-delete-and-policy.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
	return fs.readFileSync(path.join(root, rel), 'utf8');
}

test('openModal supports a secondary action button', () => {
	const src = read('js/common/components.js');
	assert.match(src, /secondaryLabel:\s*null/, 'openModal options must default secondaryLabel');
	assert.match(src, /opts\.secondaryLabel && typeof opts\.onSecondary === 'function'/, 'secondary button requires label + handler');
	assert.match(src, /opts\.secondaryDanger/, 'secondaryDanger must drive the danger styling');
	assert.match(src, /await opts\.onSecondary\(submitContext\(\)\)/, 'secondary click must await the handler');
	assert.match(src, /if \(result === true\) instance\.close\(true\)/, 'secondary success closes the modal');
});

test('edit-assignment modal exposes Cancel shift via the secondary slot', () => {
	const src = read('js/roster.js');
	assert.match(src, /secondaryLabel:\s*t\('dutycheck', 'Cancel shift'\)/, 'edit modal must offer Cancel shift');
	assert.match(src, /secondaryDanger:\s*true/, 'cancel-shift is destructive — danger styling');
	assert.match(src, /onSecondary:\s*\(\) => cancelAssignmentRow\(assignment, triggerEl\)/, 'secondary must reuse cancelAssignmentRow');
});

test('cancelAssignmentRow reports success so the modal can close', () => {
	const src = read('js/roster.js');
	const fn = src.match(/async function cancelAssignmentRow[\s\S]*?\n\t\}/);
	assert.ok(fn, 'cancelAssignmentRow not found');
	assert.match(fn[0], /return false;[\s\S]*window\.confirm/, 'abort path must return false');
	assert.match(fn[0], /Msg\.announce\(t\('dutycheck', 'Shift cancelled\.'\), 'success'\);\s*return true;/, 'success path must return true');
	assert.match(fn[0], /assignments\/\$\{assignment\.id\}\/cancel/, 'must POST the cancel endpoint');
});

test('access policy removal announces the pending save', () => {
	const src = read('js/settings.js');
	assert.match(src, /announceRemovalPendingSave/, 'chip removal must announce that a save is required');
	assert.match(src, /Removed — press “Save app policy” to apply\./, 'removal cue text missing');
});

test('policy save surfaces pruned stale entries', () => {
	const src = read('js/settings.js');
	assert.match(src, /policy\.pruned/, 'save must read the pruned payload');
	assert.match(src, /stale entries were dropped/, 'pruned announcement missing');
});

test('saveAppPolicy validates new ids but prunes stale stored ids', () => {
	const src = read('lib/Service/AccessControlService.php');
	assert.match(src, /\$storedAllowed = array_flip\(\$this->getAllowedUserIds\(\)\)/, 'stored allowlist must be diffed');
	assert.match(src, /\$pruned\['allowedUserIds'\]\[\] = \$uid;/, 'stale stored users must be pruned');
	assert.match(src, /\$pruned\['appAdminUserIds'\]\[\] = \$uid;/, 'stale stored admins must be pruned');
	assert.match(src, /\$pruned\['allowedGroupIds'\]\[\] = \$gid;/, 'stale stored groups must be pruned');
	assert.match(src, /!\$this->groupManager->groupExists\(\$gid\)[\s\S]*?INVALID_ALLOWED_GROUP/, 'new groups must still be validated');
	assert.match(src, /return \$this->appPolicy\(\) \+ \['pruned' => \$pruned\];/, 'response must carry the pruned lists');
});

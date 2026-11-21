/**
 * js_sprintf_escape_passthrough — the "Staff seen" ack counter consumed by
 * roster.js + periods.js ships a literal % in msgid and every catalog cell,
 * not the vsprintf %% escape. window.t is verbatim two-arg translate, so
 * the rendered text paints a single % with no post-collapse. Regression
 * net for the r55 heal (was '{pct}%%' + .replace(/%%/g,'%')).
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const MSGID = 'Staff seen: {acked}/{total} ({pct}%)';

describe('staff-seen ack counter percent', () => {
	it('call sites consume the single-% msgid verbatim', () => {
		for (const f of ['js/roster.js', 'js/periods.js']) {
			const src = fs.readFileSync(path.join(root, f), 'utf8');
			assert.ok(src.includes(`t('dutycheck', '${MSGID}')`), `${f} must t() the single-% msgid`);
			assert.ok(!src.includes('({pct}%%)'), `${f} reintroduced the %% escape`);
			assert.ok(!src.includes("replace(/%%/g"), `${f} reintroduced a post-collapse`);
		}
	});

	it('every shipped catalog carries the healed key with a single %', () => {
		const dir = path.join(root, 'l10n');
		for (const f of fs.readdirSync(dir).filter((n) => n.endsWith('.json'))) {
			const tr = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')).translations;
			assert.ok(MSGID in tr, `${f} missing the healed key — t() lookup would break`);
			assert.ok(!tr[MSGID].includes('%%'), `${f} value still carries %%`);
		}
	});

	it('renders a single % through the verbatim two-arg t()', () => {
		const en = JSON.parse(fs.readFileSync(path.join(root, 'l10n/en.json'), 'utf8')).translations;
		const t = (app, msgid) => en[msgid] ?? msgid;
		const rendered = t('dutycheck', MSGID)
			.replace('{acked}', '3')
			.replace('{total}', '5')
			.replace('{pct}', '60');
		assert.equal(rendered, 'Staff seen: 3/5 (60%)');
	});
});

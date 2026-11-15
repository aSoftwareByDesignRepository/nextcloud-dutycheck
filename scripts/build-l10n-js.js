#!/usr/bin/env node
/**
 * Emits l10n/{lang}.js from l10n/{lang}.json so Nextcloud can load JS
 * translations via OC.L10N.register.
 */
const fs = require('fs');
const path = require('path');

const APP_ID = 'dutycheck';
const l10nDir = path.join(__dirname, '..', 'l10n');

function buildJs(lang) {
	const jsonPath = path.join(l10nDir, `${lang}.json`);
	if (!fs.existsSync(jsonPath)) {
		return;
	}
	const data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
	const translations = data.translations || {};
	const lines = ['OC.L10N.register(', `    "${APP_ID}",`, '    {'];
	const entries = Object.entries(translations);
	// PHP json_encode parity: escape `/` so the emitted bytes match the
	// json_encode needle in L10nCatalogIntegrityTest (byte-identical sync).
	const enc = (s) => JSON.stringify(s).replace(/\//g, '\\/');
	entries.forEach(([key, value], i) => {
		const comma = i < entries.length - 1 ? ',' : '';
		if (Array.isArray(value)) {
			const parts = value.map((v) => enc(v)).join(',');
			lines.push(`    ${enc(key)} : [${parts}]${comma}`);
		} else {
			lines.push(`    ${enc(key)} : ${enc(value)}${comma}`);
		}
	});
	lines.push('});');
	lines.push('');
	fs.writeFileSync(path.join(l10nDir, `${lang}.js`), lines.join('\n'));
}

// Every shipped catalog gets a .js mirror — glob so added locales are never
// silently skipped by a stale literal list.
const langs = fs.readdirSync(l10nDir)
	.filter(f => f.endsWith('.json') && !f.startsWith('_'))
	.map(f => f.slice(0, -'.json'.length))
	.sort();
for (const lang of langs) {
	buildJs(lang);
}
console.log(`Wrote ${APP_ID} l10n/*.js`);

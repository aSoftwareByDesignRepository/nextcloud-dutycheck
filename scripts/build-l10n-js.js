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
	entries.forEach(([key, value], i) => {
		const comma = i < entries.length - 1 ? ',' : '';
		if (Array.isArray(value)) {
			const parts = value.map((v) => JSON.stringify(v)).join(', ');
			lines.push(`    ${JSON.stringify(key)} : [${parts}]${comma}`);
		} else {
			lines.push(`    ${JSON.stringify(key)} : ${JSON.stringify(value)}${comma}`);
		}
	});
	lines.push('});');
	lines.push('');
	fs.writeFileSync(path.join(l10nDir, `${lang}.js`), lines.join('\n'));
}

const langs = [
	'en', 'de', 'de_DE', 'fr', 'fr_FR', 'es', 'es_ES',
	'da', 'da_DK', 'nl', 'nl_NL', 'it', 'it_IT', 'pl', 'pl_PL', 'sv', 'sv_SE', 'nb', 'nb_NO',
];
for (const lang of langs) {
	buildJs(lang);
}
console.log(`Wrote ${APP_ID} l10n/*.js`);

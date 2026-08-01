/**
 * Legacy /settings#anchor → split sub-page forwarding.
 *
 * The old settings page was one long document with jump anchors. URL fragments
 * are never sent to the server, so stale bookmarks like /settings#dc-settings-quals
 * land on the default sub-page. This module forwards them client-side to the
 * owning sub-page, keeping the fragment so the browser still scrolls to the
 * section after navigation.
 *
 * ANCHOR_SECTIONS mirrors OCA\DutyCheck\Service\SettingsSectionCatalog::LEGACY_ANCHORS —
 * a contract test (tests/js/settings-pages.test.mjs + tests/Unit) pins both maps.
 *
 * Security: the target URL is read from the server-rendered, HTML-escaped
 * data-dc-urls payload and selected only through the frozen allowlist below;
 * no fragment content ever becomes a URL by itself.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'dc-settings-quickstart': 'access',
		'dc-settings-policy': 'access',
		'dc-settings-duty-roles': 'duty-roles',
		'dc-settings-planning': 'planning',
		'dc-settings-companies': 'companies',
		'dc-settings-conflict-policy': 'conflicts',
		'dc-settings-templates': 'shift-templates',
		'dc-settings-quals': 'qualifications',
		'dc-settings-scope': 'planner-scope',
		'dc-settings-ops': 'operations',
		'dc-at-integration': 'integration',
		'dc-settings-privacy': 'privacy',
		'dutycheck-license': 'license',
		'dc-support-us': 'support',
	});

	/**
	 * @param {object} doc document (or a stub exposing getElementById)
	 * @param {object} loc location (or a stub exposing hash)
	 * @returns {string|null} absolute-path URL (with fragment) to forward to, or null
	 */
	function resolve(doc, loc) {
		const hash = String((loc && loc.hash) || '').replace(/^#/, '');
		if (!Object.prototype.hasOwnProperty.call(ANCHOR_SECTIONS, hash)) {
			return null;
		}
		const targetSection = ANCHOR_SECTIONS[hash];
		const rootEl = doc && typeof doc.getElementById === 'function'
			? doc.getElementById('app-content')
			: null;
		if (!rootEl || typeof rootEl.getAttribute !== 'function') {
			return null;
		}
		const currentSection = String(rootEl.getAttribute('data-dc-settings-section') || '');
		// Only forward while on a settings sub-page, and never when the anchor
		// already lives on the current page (native scroll handles that).
		if (currentSection === '' || currentSection === targetSection) {
			return null;
		}
		let urls = null;
		try {
			urls = JSON.parse(String(rootEl.getAttribute('data-dc-urls') || '{}'));
		} catch (_err) {
			return null;
		}
		const sectionUrl = urls && urls.settingsSections ? urls.settingsSections[targetSection] : null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return sectionUrl + '#' + hash;
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve });
	if (root) {
		root.DutyCheckSettingsLegacyRedirect = api;
	}
})(typeof window !== 'undefined' ? window : null);

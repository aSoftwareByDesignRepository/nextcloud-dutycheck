<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for the split settings sub-pages.
 *
 * Every artifact that knows about settings sections derives from this class:
 *  - appinfo/routes.php pins its `{section}` requirement to {@see routeRequirement()},
 *  - PageController validates and titles pages through it,
 *  - templates/settings.php dispatches to templates/parts/settings/<section>.php,
 *  - js/settings.js mirrors {@see LEGACY_ANCHORS} for old `/settings#anchor` links.
 *
 * Contract tests in tests/Unit assert all four artifacts stay in sync, so a
 * drifting copy fails CI instead of shipping a dead link.
 */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	/**
	 * Ordered section slugs — order drives the sidebar sub-navigation.
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		'access',
		'duty-roles',
		'planning',
		'companies',
		'conflicts',
		'shift-templates',
		'qualifications',
		'planner-scope',
		'operations',
		'integration',
		'privacy',
		'license',
		'support',
	];

	/**
	 * Legacy single-page anchors → owning section slug.
	 *
	 * The old settings page was one long document with jump anchors. URL
	 * fragments never reach the server, so js/settings.js uses this map to
	 * forward stale bookmarks (e.g. /settings#dc-settings-quals) client-side.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'dc-settings-quickstart' => 'access',
		'dc-settings-policy' => 'access',
		'dc-settings-duty-roles' => 'duty-roles',
		'dc-settings-planning' => 'planning',
		'dc-settings-companies' => 'companies',
		'dc-settings-conflict-policy' => 'conflicts',
		'dc-settings-templates' => 'shift-templates',
		'dc-settings-quals' => 'qualifications',
		'dc-settings-scope' => 'planner-scope',
		'dc-settings-ops' => 'operations',
		'dc-at-integration' => 'integration',
		'dc-settings-privacy' => 'privacy',
		'dutycheck-license' => 'license',
		'dc-support-us' => 'support',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	/**
	 * Value for the `{section}` route placeholder requirement.
	 */
	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	/**
	 * Human page title (H1 / breadcrumb current). Longer, descriptive copy.
	 */
	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'duty-roles' => $l->t('Duty roles'),
			'planning' => $l->t('Planning defaults'),
			'companies' => $l->t('Companies / workspaces'),
			'conflicts' => $l->t('Conflict thresholds'),
			'shift-templates' => $l->t('Shift templates'),
			'qualifications' => $l->t('Qualifications'),
			'planner-scope' => $l->t('Planner location scope'),
			'operations' => $l->t('Notifications & retention'),
			'integration' => $l->t('ArbeitszeitCheck integration'),
			'privacy' => $l->t('Privacy & words we use'),
			'license' => $l->t('Official mobile & terminal licenses'),
			'support' => $l->t('Support & us'),
			default => $l->t('Settings'),
		};
	}

	/**
	 * Short sidebar / in-page chip label (DeskCheck parity). Keeps the
	 * navigation scannable — especially on narrow viewports where 13 long
	 * titles would crowd the sub-list and the chip bar.
	 */
	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access'),
			'duty-roles' => $l->t('Duty roles'),
			'planning' => $l->t('Planning'),
			'companies' => $l->t('Companies'),
			'conflicts' => $l->t('Conflicts'),
			'shift-templates' => $l->t('Templates'),
			'qualifications' => $l->t('Qualifications'),
			'planner-scope' => $l->t('Planner scope'),
			'operations' => $l->t('Operations'),
			'integration' => $l->t('Integration'),
			'privacy' => $l->t('Privacy'),
			'license' => $l->t('License'),
			'support' => $l->t('Support us'),
			default => $l->t('Settings'),
		};
	}

	/**
	 * One-line page lead under the H1. Reuses the former in-card subtitle
	 * strings (already translated in every locale).
	 *
	 * License and support intentionally return '' — their panels ship a
	 * self-contained intro and a page lead would duplicate that copy.
	 */
	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Decide who may open DutyCheck. Restriction takes effect immediately for non-administrators.'),
			'duty-roles' => $l->t('Planners can manage rosters, periods, and the employee catalog. Employee access is granted by linking a Nextcloud account on the Employees page — not here.'),
			'planning' => $l->t('This break is filled in automatically when someone adds a new assignment. They can still change it for each shift.'),
			'companies' => $l->t('One Default company keeps legacy installs unrestricted. Creating a second company turns on membership isolation for planners.'),
			'conflicts' => $l->t('Period totals and daily limits used by planning checks. Defaults stay ArbZG-oriented until you change them.'),
			'shift-templates' => $l->t('Named start/end/break presets for the roster “Add assignment” dialog. Optional location keeps a template site-specific.'),
			'qualifications' => $l->t('Catalog of skills or certificates. Missing required qualifications block assign/publish. Expired quals are soft warnings.'),
			'planner-scope' => $l->t('Limit a planner to specific locations. Leave none selected for unrestricted (legacy global planners). App admins are never scoped.'),
			'operations' => $l->t('Optional soft-cap approach notices, MaintenanceCheck on-duty hook, and cold-archive retention for old snapshots (never deletes the latest close snapshot of a still-closed period).'),
			'integration' => $l->t('Mirror absences from ArbeitszeitCheck for roster conflicts. DutyCheck never writes to ArbeitszeitCheck.'),
			'privacy' => $l->t('How DutyCheck treats personal data, and the plain-language terms used in this app.'),
			default => '',
		};
	}
}

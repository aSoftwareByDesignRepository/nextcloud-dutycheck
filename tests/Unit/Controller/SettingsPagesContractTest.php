<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact drift protection for the split settings sub-pages.
 *
 * Five artifacts share the section contract: SettingsSectionCatalog (source of
 * truth), appinfo/routes.php, templates/settings.php (dispatcher),
 * js/settings-legacy-redirect.js (client mirror of LEGACY_ANCHORS), and the
 * sidebar/page chrome. Each test pins one artifact to the catalog so an
 * unsynchronized edit fails CI instead of shipping a 404 or a dead bookmark.
 */
final class SettingsPagesContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private static function read(string $relative): string
	{
		$path = self::appRoot() . '/' . $relative;
		self::assertFileExists($path);
		return (string) file_get_contents($path);
	}

	// ---- appinfo/routes.php ------------------------------------------------

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	private static function routeByName(string $name): array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		self::fail("Route '{$name}' is not registered");
	}

	public function testLegacySettingsRouteIsPreserved(): void
	{
		$route = self::routeByName('page#settings');
		self::assertSame('/settings', $route['url']);
		self::assertSame('GET', $route['verb']);
	}

	public function testSectionRouteRequirementMatchesCatalog(): void
	{
		$route = self::routeByName('page#settingsSection');
		self::assertSame('/settings/{section}', $route['url']);
		self::assertSame('GET', $route['verb']);
		self::assertSame(
			SettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
			'Route allowlist drifted from SettingsSectionCatalog::routeRequirement()',
		);
	}

	// ---- templates/settings.php dispatcher ----------------------------------

	public function testDispatcherMapCoversExactlyTheCatalogInOrder(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertSame(
			1,
			preg_match('/\$dcSettingsSectionFiles\s*=\s*\[(.*?)\];/s', $dispatcher, $m),
			'Dispatcher must declare the literal slug → file map',
		);
		preg_match_all("/'([a-z-]+)'\s*=>\s*'([a-z.-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			$map[$pair[1]] = $pair[2];
		}
		self::assertSame(SettingsSectionCatalog::SECTIONS, array_keys($map), 'Dispatcher slugs drifted from the catalog');
		foreach ($map as $slug => $file) {
			self::assertSame($slug . '.php', $file, 'Dispatcher file names must mirror slugs (auditability)');
			self::assertFileExists(
				self::appRoot() . '/templates/parts/settings/' . $file,
				"Partial for section '{$slug}' is missing",
			);
		}
	}

	public function testDispatcherFailsClosedAndNeverBuildsPathsFromInput(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertStringContainsString(
			'if (!isset($dcSettingsSectionFiles[$dcRequestedSection]))',
			$dispatcher,
			'Unknown sections must fail closed before include',
		);
		self::assertStringContainsString(
			'DutyCheck settings: unknown section reached the template dispatcher.',
			$dispatcher,
			'Unknown sections must throw rather than soft-fall to another page',
		);
		self::assertStringNotContainsString(
			'$dcRequestedSection . ',
			$dispatcher,
			'The request value must never be concatenated into an include path',
		);
		self::assertStringNotContainsString(
			' . $dcRequestedSection',
			str_replace('$dcSettingsSectionFiles[$dcRequestedSection]', '', $dispatcher),
			'The request value must never be concatenated into an include path',
		);
	}

	public function testDispatcherGuardsNonAdminsBeforeAnyInclude(): void
	{
		$dispatcher = self::read('templates/settings.php');
		$guard = strpos($dispatcher, 'if (!$canAdminApp):');
		$denial = strpos($dispatcher, 'Only app administrators may change these settings.');
		$include = strpos($dispatcher, "include __DIR__ . '/parts/settings/'");
		self::assertNotFalse($guard);
		self::assertNotFalse($denial);
		self::assertNotFalse($include);
		self::assertGreaterThan($guard, $denial, 'Denial card must live inside the guard');
		self::assertGreaterThan($denial, $include, 'Partial dispatch must live in the admin-only else branch');
	}

	// ---- js/settings-legacy-redirect.js -------------------------------------

	/**
	 * @return array<string, string>
	 */
	private static function jsAnchorSections(): array
	{
		$js = self::read('js/settings-legacy-redirect.js');
		self::assertSame(
			1,
			preg_match('/ANCHOR_SECTIONS\s*=\s*Object\.freeze\(\{(.*?)\}\)/s', $js, $m),
			'settings-legacy-redirect.js must declare a frozen ANCHOR_SECTIONS map',
		);
		preg_match_all("/'([a-z0-9-]+)'\s*:\s*'([a-z-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			self::assertArrayNotHasKey($pair[1], $map, "Duplicate anchor '{$pair[1]}' in JS map");
			$map[$pair[1]] = $pair[2];
		}
		return $map;
	}

	public function testJsAnchorMapMirrorsCatalogLegacyAnchorsExactly(): void
	{
		self::assertSame(
			SettingsSectionCatalog::LEGACY_ANCHORS,
			self::jsAnchorSections(),
			'js/settings-legacy-redirect.js drifted from SettingsSectionCatalog::LEGACY_ANCHORS',
		);
	}

	public function testSettingsJsConsumesTheLegacyRedirectBeforeWiringSections(): void
	{
		$js = self::read('js/settings.js');
		// Compare call order inside the boot handler, not definition order.
		$bootPos = strpos($js, "addEventListener('DOMContentLoaded'");
		self::assertNotFalse($bootPos, 'settings.js must boot on DOMContentLoaded');
		$boot = substr($js, $bootPos);
		$resolvePos = strpos($boot, 'DutyCheckSettingsLegacyRedirect');
		$wirePos = strpos($boot, 'wireAtIntegration()');
		self::assertNotFalse($resolvePos, 'settings.js must consult the legacy redirect module at boot');
		self::assertNotFalse($wirePos, 'settings.js must wire the integration section at boot');
		self::assertGreaterThan($resolvePos, $wirePos, 'Redirect must run before section wiring fires requests');
		self::assertStringContainsString('window.location.replace(redirectUrl)', $boot);
		self::assertMatchesRegularExpression(
			'/window\.location\.replace\(redirectUrl\);\s*return;/',
			$boot,
			'Boot must stop after scheduling the redirect (no wasted requests)',
		);
	}

	public function testPageControllerShipsTheRedirectScriptWithSettingsPages(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/if \(\\\$template === 'settings'\) \{\s*Util::addScript\(Application::APP_ID, 'settings-legacy-redirect'\);/s",
			$controller,
			'PageController must load settings-legacy-redirect.js on settings pages',
		);
	}

	// ---- legacy anchors keep working after the forward -----------------------

	public function testEveryLegacyAnchorTargetStillExistsInItsOwningPartial(): void
	{
		// dc-support-us is composed at render time ($prefix . '-support-us') and
		// pinned by SupportUsSectionRenderTest; assert the generator instead.
		$generated = ['dc-support-us' => "templates/parts/support-us-section.php"];
		$sharedPartialsBySection = [
			'license' => 'templates/parts/license-panel.php',
			'support' => 'templates/parts/support-us-section.php',
		];
		foreach (SettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			if (isset($generated[$anchor])) {
				self::assertStringContainsString(
					"'-support-us'",
					self::read($generated[$anchor]),
					"Anchor '{$anchor}' generator disappeared",
				);
				continue;
			}
			$haystack = self::read('templates/parts/settings/' . $section . '.php');
			if (isset($sharedPartialsBySection[$section])) {
				$haystack .= self::read($sharedPartialsBySection[$section]);
			}
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($anchor, '/') . '"/',
				$haystack,
				"Anchor #{$anchor} must exist on the '{$section}' sub-page so the forwarded fragment still scrolls",
			);
		}
	}

	// ---- sidebar + page chrome ----------------------------------------------

	public function testNavigationBuildsSubListFromControllerData(): void
	{
		$nav = self::read('templates/common/navigation.php');
		self::assertStringContainsString("\$_['settingsSectionLabels']", $nav, 'Sub-nav labels must come from the controller (catalog)');
		self::assertStringContainsString("\$urls['settingsSections']", $nav, 'Sub-nav URLs must come from the controller (catalog)');
		self::assertStringContainsString('dc-nav__sublist', $nav);
		self::assertStringContainsString('dc-nav__sublink', $nav);
		// aria-current belongs to the active child, not the expanded parent.
		self::assertStringContainsString('$parentAriaCurrent = $active && $children === [];', $nav);
		self::assertMatchesRegularExpression(
			'/if \(\$childActive\): \?>aria-current="page"/',
			$nav,
			'Active settings sub-page must carry aria-current="page"',
		);
		self::assertStringContainsString("\$isAppAdmin && \$pageId === 'settings'", $nav, 'Sub-nav must stay admin-only and settings-scoped');
		self::assertStringContainsString("if (\$childHref === '' || \$childHref === '#')", $nav, 'Sidebar children must never emit href="#"');
	}

	public function testInPageSettingsNavIsIncludedBeforeSectionDispatch(): void
	{
		$dispatcher = self::read('templates/settings.php');
		$navInclude = strpos($dispatcher, "include __DIR__ . '/parts/settings-nav.php'");
		$sectionInclude = strpos($dispatcher, "include __DIR__ . '/parts/settings/'");
		self::assertNotFalse($navInclude, 'settings.php must include the in-page chip bar');
		self::assertNotFalse($sectionInclude);
		self::assertGreaterThan($navInclude, $sectionInclude, 'Chip bar must render above the section body');

		$nav = self::read('templates/parts/settings-nav.php');
		self::assertStringContainsString('dc-settings-nav', $nav);
		self::assertStringContainsString('dc-settings-nav__link', $nav);
		self::assertStringContainsString('id="dc-settings-pages"', $nav);
		self::assertStringContainsString("\$_['settingsSectionLabels']", $nav);
		self::assertStringContainsString("\$_['urls']['settingsSections']", $nav);
		self::assertStringContainsString("if (\$href === '' || \$href === '#')", $nav, 'Chip bar must never emit href="#"');
		self::assertMatchesRegularExpression(
			'/if \(\$active\): \?>aria-current="page"/',
			$nav,
			'Active chip must carry aria-current="page"',
		);
	}

	public function testPageControllerFeedsNavLabelsNotPageTitlesIntoTheSidebar(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertStringContainsString(
			'$this->settingsSections->navLabel($this->l10n, $sectionId)',
			$controller,
			'Sidebar/chip labels must use navLabel() (short DeskCheck-style names)',
		);
		self::assertStringContainsString(
			"'pageTitle' => \$this->settingsSections->label(\$this->l10n, \$section)",
			$controller,
			'Page H1 must keep the longer label()',
		);
	}

	public function testPageChromeExposesTheCurrentSectionToClientScripts(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('data-dc-settings-section="<?php p($settingsSection); ?>"', $pageStart);
		self::assertStringContainsString("\$_['settingsSection'] ?? ''", $pageStart);
	}

	public function testPageChromeRendersTheParentBreadcrumbForSubPages(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('dc-breadcrumb__parent', $pageStart);
		self::assertStringContainsString("\$_['breadcrumbParent']", $pageStart);
	}
}

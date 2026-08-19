<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\SettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the settings sub-page catalog — the single source of
 * truth for routes, controller validation, template dispatch, and the legacy
 * anchor forwarding. Labels/help are pinned exactly so a swapped match arm or
 * a hardcoded (untranslated) string fails here instead of shipping.
 */
final class SettingsSectionCatalogTest extends TestCase
{
	private SettingsSectionCatalog $catalog;

	protected function setUp(): void
	{
		parent::setUp();
		$this->catalog = new SettingsSectionCatalog();
	}

	/**
	 * IL10N stub that marks every translated string, proving the catalog
	 * routes copy through the translator instead of returning raw literals.
	 */
	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => 'T:' . $text,
		);
		return $l;
	}

	public function testDefaultSectionIsAccessAndListed(): void
	{
		self::assertSame('access', SettingsSectionCatalog::DEFAULT_SECTION);
		self::assertContains(SettingsSectionCatalog::DEFAULT_SECTION, SettingsSectionCatalog::SECTIONS);
	}

	public function testSectionsAreUniqueLowercaseSlugs(): void
	{
		$sections = SettingsSectionCatalog::SECTIONS;
		self::assertSame($sections, array_values(array_unique($sections)), 'Section slugs must be unique');
		self::assertCount(13, $sections);
		foreach ($sections as $section) {
			self::assertMatchesRegularExpression(
				'/^[a-z]+(-[a-z]+)*$/',
				$section,
				"Slug '{$section}' must be lowercase kebab-case (URL- and regex-safe)",
			);
		}
	}

	public function testIsSectionAcceptsEveryCatalogSlug(): void
	{
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			self::assertTrue($this->catalog->isSection($section), "isSection('{$section}') must be true");
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function rejectedSectionProvider(): array
	{
		return [
			'empty string' => [''],
			'unknown slug' => ['nonsense'],
			'case variant' => ['Access'],
			'trailing whitespace' => ['access '],
			'leading whitespace' => [' access'],
			'path traversal' => ['../access'],
			'alternation injection' => ['access|duty-roles'],
			'null byte' => ["access\0"],
			'legacy anchor id' => ['dc-settings-policy'],
		];
	}

	/**
	 * @dataProvider rejectedSectionProvider
	 */
	public function testIsSectionRejectsInvalidInput(string $candidate): void
	{
		self::assertFalse($this->catalog->isSection($candidate));
	}

	public function testRouteRequirementIsPipeJoinedAllowlist(): void
	{
		$requirement = SettingsSectionCatalog::routeRequirement();
		self::assertSame(implode('|', SettingsSectionCatalog::SECTIONS), $requirement);
		// The requirement is embedded verbatim in a route regex: slugs must not
		// smuggle regex metacharacters beyond the alternation pipe itself.
		self::assertMatchesRegularExpression('/^[a-z-]+(\|[a-z-]+)*$/', $requirement);
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			self::assertSame(
				1,
				preg_match('/^(?:' . $requirement . ')$/', $section),
				"Requirement regex must accept '{$section}'",
			);
		}
		self::assertSame(0, preg_match('/^(?:' . $requirement . ')$/', 'not-a-section'));
	}

	public function testEveryLegacyAnchorMapsToAKnownSection(): void
	{
		self::assertNotSame([], SettingsSectionCatalog::LEGACY_ANCHORS);
		foreach (SettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertTrue(
				$this->catalog->isSection($section),
				"Legacy anchor '{$anchor}' targets unknown section '{$section}'",
			);
		}
	}

	public function testEverySectionIsReachableFromALegacyAnchor(): void
	{
		$targets = array_values(array_unique(array_values(SettingsSectionCatalog::LEGACY_ANCHORS)));
		sort($targets);
		$sections = SettingsSectionCatalog::SECTIONS;
		sort($sections);
		self::assertSame($sections, $targets, 'Every section owns at least one legacy anchor');
	}

	public function testLabelsArePinnedAndTranslated(): void
	{
		$l = $this->l10n();
		$expected = [
			'access' => 'T:Access control',
			'duty-roles' => 'T:Duty roles',
			'planning' => 'T:Planning defaults',
			'companies' => 'T:Companies / workspaces',
			'conflicts' => 'T:Conflict thresholds',
			'shift-templates' => 'T:Shift templates',
			'qualifications' => 'T:Qualifications',
			'planner-scope' => 'T:Planner location scope',
			'operations' => 'T:Notifications & retention',
			'integration' => 'T:ArbeitszeitCheck integration',
			'privacy' => 'T:Privacy & words we use',
			'license' => 'T:Official mobile & terminal licenses',
			'support' => 'T:Support & us',
		];
		self::assertSame(array_keys($expected), SettingsSectionCatalog::SECTIONS, 'Label pinning must cover the catalog in order');
		foreach ($expected as $section => $label) {
			self::assertSame($label, $this->catalog->label($l, $section));
		}
	}

	public function testNavLabelsAreShortPinnedAndTranslated(): void
	{
		$l = $this->l10n();
		$expected = [
			'access' => 'T:Access',
			'duty-roles' => 'T:Duty roles',
			'planning' => 'T:Planning',
			'companies' => 'T:Companies',
			'conflicts' => 'T:Conflicts',
			'shift-templates' => 'T:Templates',
			'qualifications' => 'T:Qualifications',
			'planner-scope' => 'T:Planner scope',
			'operations' => 'T:Operations',
			'integration' => 'T:Integration',
			'privacy' => 'T:Privacy',
			'license' => 'T:License',
			'support' => 'T:Support us',
		];
		self::assertSame(array_keys($expected), SettingsSectionCatalog::SECTIONS, 'Nav-label pinning must cover the catalog in order');
		foreach ($expected as $section => $label) {
			$nav = $this->catalog->navLabel($l, $section);
			self::assertSame($label, $nav);
			// Nav labels must stay shorter than (or equal to) the page title —
			// the chip bar and sidebar are scannability surfaces.
			$page = $this->catalog->label($l, $section);
			self::assertLessThanOrEqual(
				strlen($page),
				strlen($nav),
				"navLabel('{$section}') must not be longer than label()",
			);
		}
		self::assertSame('T:Settings', $this->catalog->navLabel($l, 'nonsense'));
	}

	public function testLabelFallsBackToSettingsForUnknownSection(): void
	{
		self::assertSame('T:Settings', $this->catalog->label($this->l10n(), 'nonsense'));
		self::assertSame('T:Settings', $this->catalog->label($this->l10n(), ''));
	}

	public function testHelpTextsAreDistinctTranslatedAndSectionSpecific(): void
	{
		$l = $this->l10n();
		// A stable, section-specific fingerprint per help text: kills mutants
		// that swap, blank, or cross-wire match arms without pinning full copy.
		$fingerprints = [
			'access' => 'Restriction takes effect immediately',
			'duty-roles' => 'employee catalog',
			'planning' => 'filled in automatically',
			'companies' => 'see nothing until',
			'conflicts' => 'ArbZG-oriented',
			'shift-templates' => 'presets',
			'qualifications' => 'block assign/publish',
			'planner-scope' => 'never scoped',
			'operations' => 'cold-archive retention',
			'integration' => 'never writes to ArbeitszeitCheck',
			'privacy' => 'plain-language terms',
		];
		$seen = [];
		foreach ($fingerprints as $section => $fingerprint) {
			$help = $this->catalog->help($l, $section);
			self::assertStringStartsWith('T:', $help, "help('{$section}') must be translated");
			self::assertStringContainsString($fingerprint, $help, "help('{$section}') lost its section-specific copy");
			self::assertNotContains($help, $seen, "help('{$section}') duplicates another section's copy");
			$seen[] = $help;
		}
	}

	public function testHelpIsEmptyForSelfDescribingPanelsAndUnknown(): void
	{
		$l = $this->l10n();
		self::assertSame('', $this->catalog->help($l, 'license'), 'License panel ships its own intro');
		self::assertSame('', $this->catalog->help($l, 'support'), 'Support panel ships its own intro');
		self::assertSame('', $this->catalog->help($l, 'nonsense'));
	}
}

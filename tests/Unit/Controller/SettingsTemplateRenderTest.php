<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Renders every settings sub-page partial through real PHP includes (no
 * Nextcloud kernel) and asserts each fragment is a self-contained, accessible
 * section: stable anchor ids, a heading, and translator-escaped output.
 */
final class SettingsTemplateRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	private function l10n(): object
	{
		return new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
	}

	/**
	 * @param array<string, mixed> $vars template payload ($_)
	 */
	private function renderPartial(string $section, array $vars = [], ?object $l10n = null): string
	{
		$file = dirname(__DIR__, 3) . '/templates/parts/settings/' . $section . '.php';
		self::assertFileExists($file, "Partial for '{$section}' must exist");
		$_ = $vars;
		$l = $l10n ?? $this->l10n();
		ob_start();
		try {
			include $file;
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * @param array<string, mixed> $vars
	 */
	private function assertValidFragment(string $section, string $html, array $vars = []): void
	{
		self::assertStringContainsString('<section', $html, "'{$section}' must render at least one section landmark");
		self::assertMatchesRegularExpression('/<h2[\s>]/', $html, "'{$section}' must render an h2 (page h1 comes from the shell)");
		self::assertDoesNotMatchRegularExpression('/<h1[\s>]/', $html, "'{$section}' must not render an h1 (duplicate page title)");
		// Balanced section landmarks keep the DOM sane for AT users.
		self::assertSame(
			substr_count($html, '<section'),
			substr_count($html, '</section>'),
			"'{$section}' has unbalanced <section> tags",
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function licenseVars(): array
	{
		return [
			'licenseI18n' => ['badgeNotConfigured' => 'Not configured'],
			'licenseStatus' => null,
			'licenseSeatsList' => null,
			'licenseApiUrl' => 'https://cloud.example/apps/dutycheck/api/license',
			'licenseClearUrl' => 'https://cloud.example/apps/dutycheck/api/license',
			'licenseSeatsUrl' => 'https://cloud.example/apps/dutycheck/api/license/seats',
			'licenseAssignSeatUrl' => 'https://cloud.example/apps/dutycheck/api/license/seats',
			'licenseRemoveSeatBase' => 'https://cloud.example/apps/dutycheck/api/license/seats/',
			'licenseSearchUsersUrl' => 'https://cloud.example/apps/dutycheck/api/license/search/users',
			'requesttoken' => 'test-token',
		];
	}

	public function testEverySectionPartialRendersAsAccessibleFragment(): void
	{
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			if ($section === 'support') {
				continue; // needs a SupportUsLinks instance — covered below and by SupportUsSectionRenderTest
			}
			$vars = $section === 'license' ? $this->licenseVars() : [];
			$html = $this->renderPartial($section, $vars);
			$this->assertValidFragment($section, $html, $vars);
		}
	}

	public function testEveryLegacyAnchorIdRendersOnItsOwningSubPage(): void
	{
		foreach (SettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			if ($section === 'support') {
				continue; // rendered + pinned by SupportUsSectionRenderTest
			}
			$vars = $section === 'license' ? $this->licenseVars() : [];
			$html = $this->renderPartial($section, $vars);
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($anchor, '/') . '"/',
				$html,
				"Rendered '{$section}' page must contain the forwarded anchor #{$anchor}",
			);
		}
	}

	public function testSupportPartialRefusesToRenderWithoutALinksValueObject(): void
	{
		self::assertSame('', trim($this->renderPartial('support', [])), 'Support page must render nothing without SupportUsLinks');
		self::assertSame('', trim($this->renderPartial('support', ['supportUsLinks' => 'not-an-object'])));
	}

	public function testPartialsEscapeTranslatedCopy(): void
	{
		$hostileL10n = new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return '<script>alert(1)</script>';
			}
		};
		foreach (['access', 'privacy', 'operations'] as $section) {
			$html = $this->renderPartial($section, [], $hostileL10n);
			self::assertStringNotContainsString('<script>alert(1)</script>', $html, "'{$section}' echoed unescaped translator output");
			self::assertStringContainsString('&lt;script&gt;', $html);
		}
	}

	public function testAccessPartialKeepsThePolicyFormContract(): void
	{
		$html = $this->renderPartial('access', [
			'urls' => [
				'settingsSections' => [
					'duty-roles' => '/apps/dutycheck/settings/duty-roles',
				],
				'employees' => '/apps/dutycheck/employees',
			],
		]);
		// js/settings.js wires these ids; renaming them silently breaks the page.
		foreach (['dc-settings-policy', 'dc-app-policy-form', 'dc-policy-restriction', 'dc-policy-save', 'dc-settings-quickstart'] as $id) {
			self::assertMatchesRegularExpression('/\sid="' . preg_quote($id, '/') . '"/', $html, "Access page lost #{$id}");
		}
		// Split-page copy must deep-link — never say "section below".
		self::assertStringNotContainsString('section below', $html);
		self::assertStringNotContainsString('planner role below', $html);
		self::assertStringContainsString('href="/apps/dutycheck/settings/duty-roles"', $html);
		self::assertStringContainsString('href="/apps/dutycheck/employees"', $html);
	}

	public function testAccessPartialEscapesCrossPageLinkUrls(): void
	{
		$html = $this->renderPartial('access', [
			'urls' => [
				'settingsSections' => [
					'duty-roles' => '"><script>alert(1)</script>',
				],
				'employees' => '"><img src=x onerror=alert(1)>',
			],
		]);
		self::assertStringNotContainsString('<script>alert(1)</script>', $html);
		self::assertStringNotContainsString('<img src=x', $html);
		self::assertStringContainsString('&quot;&gt;', $html);
	}

	public function testAccessPartialOmitsDeepLinksWhenUrlsMissing(): void
	{
		$html = $this->renderPartial('access', ['urls' => []]);
		self::assertStringNotContainsString('href="#"', $html, 'Missing deep-link URLs must omit the <a>, never emit href="#"');
		self::assertStringNotContainsString('Assign planner roles on Duty roles', $html);
		self::assertStringNotContainsString('Link staff accounts on the Employees page', $html);
	}

	public function testPanelTitleHeadingsAreVisuallyHiddenWhenTheyDuplicateChromeH1(): void
	{
		$html = $this->renderPartial('duty-roles');
		self::assertMatchesRegularExpression(
			'/<h2[^>]*class="[^"]*dc-sr-only[^"]*"[^>]*>/',
			$html,
			'Duty roles panel H2 must be visually hidden (chrome already shows the page H1)',
		);
	}

	public function testLicensePartialRendersNotConfiguredStateWithoutLicenseData(): void
	{
		$html = $this->renderPartial('license', $this->licenseVars());
		self::assertMatchesRegularExpression('/\sid="dutycheck-license"/', $html);
		self::assertStringContainsString('Not configured', $html);
		self::assertStringContainsString('test-token', $html, 'License forms need the CSRF request token');
	}
}

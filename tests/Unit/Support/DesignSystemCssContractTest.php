<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Pins the DutyCheck design-system theming contract against regressions that
 * previously broke dark mode / WCAG (hardcoded overlays, invented muted mixes,
 * Wave-A hex leaking past the token layer).
 */
final class DesignSystemCssContractTest extends TestCase
{
	private string $appCss;
	private string $tokensCss;

	protected function setUp(): void
	{
		parent::setUp();
		$root = dirname(__DIR__, 3);
		$this->appCss = (string) file_get_contents($root . '/css/app.css');
		$this->tokensCss = (string) file_get_contents($root . '/css/common/tokens.css');
		self::assertNotSame('', $this->appCss);
		self::assertNotSame('', $this->tokensCss);
	}

	public function testMutedTokenPrefersNextcloudMaxContrast(): void
	{
		self::assertMatchesRegularExpression(
			'/--dc-muted:\s*var\(\s*--color-text-maxcontrast/s',
			$this->appCss,
			'--dc-muted must prefer Nextcloud --color-text-maxcontrast (BudgetCheck parity)',
		);
		self::assertMatchesRegularExpression(
			'/--dc-color-text-muted:\s*var\(\s*--color-text-maxcontrast/s',
			$this->tokensCss,
			'tokens.css bridge must prefer --color-text-maxcontrast',
		);
		self::assertDoesNotMatchRegularExpression(
			'/--dc-muted:\s*color-mix\([^;]*72%/s',
			$this->appCss,
			'Invented 72% transparent mixes must not replace the NC token',
		);
	}

	public function testDialogChromeIsThemeSafe(): void
	{
		self::assertStringContainsString('.dc-dialog::backdrop', $this->appCss);
		self::assertStringContainsString('box-shadow: var(--dc-shadow-md)', $this->appCss);
		self::assertStringNotContainsString('rgba(16, 42, 67', $this->appCss);
		self::assertStringNotContainsString('0 12px 40px rgba(0, 0, 0, 0.18)', $this->appCss);
		self::assertDoesNotMatchRegularExpression(
			'/\.dc-dialog\s*\{[^}]*border:\s*1px solid var\(--color-border,\s*#ddd\)/s',
			$this->appCss,
			'.dc-dialog must use --dc-border, not a raw #ddd fallback',
		);
	}

	public function testMutedCopyInsideCalloutsUsesFullContrastInk(): void
	{
		// Tinted callout backgrounds push --dc-muted below 4.5:1 (axe caught
		// #6b6b6b on #d6e7ef = 4.19:1); nested muted copy must be promoted.
		self::assertMatchesRegularExpression(
			'/\.dc-callout \.dc-field__hint,\s*\.dc-callout \.dc-section__sub,\s*\.dc-callout \.dc-loading\s*\{[^}]*color:\s*var\(--color-main-text/s',
			$this->appCss,
			'Muted copy nested in .dc-callout must use full-contrast ink (WCAG 1.4.3)',
		);
	}

	public function testSettingsSubNavStylesExist(): void
	{
		// Split settings pages: sidebar sub-navigation replaced the in-page TOC.
		self::assertStringContainsString('.dc-nav__sublist', $this->appCss);
		self::assertStringContainsString('.dc-nav__sublink', $this->appCss);
		self::assertStringNotContainsString('.dc-settings-toc', $this->appCss);
		self::assertMatchesRegularExpression(
			'/\.dc-nav__sublink\s*\{[^}]*min-height:\s*44px/s',
			$this->appCss,
			'Sub-nav links must keep the 44px touch target (WCAG 2.5.5)',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-nav__sublink:focus-visible\s*\{[^}]*outline/s',
			$this->appCss,
			'Sub-nav links need a visible keyboard focus indicator',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-nav__subitem\.is-active\s+\.dc-nav__sublink\s*\{[^}]*background/s',
			$this->appCss,
			'Active sub-nav entry must be visually distinct',
		);
		self::assertStringContainsString('.dc-breadcrumb__parent', $this->appCss);
		self::assertStringContainsString('min-height: 44px', $this->appCss);
		self::assertStringContainsString('scroll-margin-top:', $this->appCss);
		// DeskCheck-parity chip bar (needed when #app-navigation collapses).
		self::assertStringContainsString('.dc-settings-nav', $this->appCss);
		self::assertStringContainsString('.dc-settings-nav__link', $this->appCss);
		self::assertMatchesRegularExpression(
			'/\.dc-app \.dc-settings-nav__link\s*\{[^}]*min-height:\s*44px/s',
			$this->appCss,
			'In-page settings chips must keep a 44px hit target',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-app \.dc-settings-nav__link:focus-visible\s*\{[^}]*outline/s',
			$this->appCss,
			'In-page settings chips need a visible focus ring',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-app \.dc-settings-nav__link\[aria-current="page"\]\s*\{[^}]*color:\s*var\(--color-main-text/s',
			$this->appCss,
			'Active chip must keep main-text ink (primary-on-tint fails WCAG 1.4.3 in dark themes)',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-app \.dc-settings-nav__link\[aria-current="page"\]\s*\{[^}]*background:\s*var\(--dc-tint-info/s',
			$this->appCss,
			'Active in-page settings chip must be visually distinct',
		);
	}

	public function testScopeStripUsesDefinitionListLayout(): void
	{
		self::assertStringContainsString('.dc-scope-strip__item', $this->appCss);
		self::assertStringContainsString('.dc-scope-strip__label', $this->appCss);
		self::assertStringNotContainsString('.dc-scope-strip__sep', $this->appCss);
	}

	public function testScopeStripNeutralisesNextcloudCoreDefinitionListChrome(): void
	{
		// Core apps.css: dt { width: 130px; text-align: end } leaves a fake left gap.
		self::assertMatchesRegularExpression(
			'/\.dc-scope-strip(?:\s+dt|\s+dd|__label|__value)[^{]*\{[^}]*text-align:\s*start/s',
			$this->appCss,
			'Scope-strip labels/values must force text-align: start against core dt end-align',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-scope-strip(?:\s+dt|\s+dd|__label|__value)[^{]*\{[^}]*width:\s*auto/s',
			$this->appCss,
			'Scope-strip labels/values must clear core dt width: 130px',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-scope-strip(?:\s+dt|\s+dd|__label|__value)[^{]*\{[^}]*white-space:\s*normal/s',
			$this->appCss,
			'Scope-strip labels must allow wrapping (core uses nowrap on dt)',
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.dc-scope-strip(?:__label|\s+dt)[^{]*\{[^}]*text-align:\s*end/s',
			$this->appCss,
			'Scope-strip must never reintroduce text-align: end on labels',
		);
	}

	public function testPrivacyGlossaryNeutralisesNextcloudCoreDefinitionListChrome(): void
	{
		self::assertMatchesRegularExpression(
			'/\.dc-settings-privacy__glossary\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$this->appCss,
		);
		self::assertMatchesRegularExpression(
			'/\.dc-settings-privacy__glossary\s+dt[^{]*\{[^}]*width:\s*auto/s',
			$this->appCss,
		);
	}

	public function testNavHintsEllipsizeInsteadOfOverflowing(): void
	{
		self::assertMatchesRegularExpression(
			'/\.dc-nav__hint\s*\{[^}]*text-overflow:\s*ellipsis/s',
			$this->appCss,
		);
	}

	public function testTextButtonsKeepFortyFourPxHitTarget(): void
	{
		self::assertDoesNotMatchRegularExpression(
			'/\.button\.button--text[^{]*\{[^}]*min-height:\s*0/s',
			$this->appCss,
			'button--text must not collapse below the 44px WCAG target',
		);
		self::assertMatchesRegularExpression(
			'/\.button\.button--text[^{]*\{[^}]*min-height:\s*44px/s',
			$this->appCss,
		);
	}

	public function testEntityResultsKeepVisibleFocusRing(): void
	{
		self::assertDoesNotMatchRegularExpression(
			'/\.dc-entity-results li:focus-visible[^{]*\{[^}]*outline:\s*none/s',
			$this->appCss,
		);
		self::assertMatchesRegularExpression(
			'/\.dc-entity-results li:focus-visible\s*\{[^}]*outline:\s*3px/s',
			$this->appCss,
		);
	}

	public function testNoHardcodedMaxContrastFallbackHex(): void
	{
		self::assertStringNotContainsString(
			'--color-text-maxcontrast, #767676',
			$this->appCss,
			'#767676 fallback fails AA on many tinted surfaces; use --dc-muted',
		);
	}

	public function testTintsMixIntoMainBackgroundNotTransparent(): void
	{
		self::assertMatchesRegularExpression(
			'/--dc-tint-info:\s*color-mix\(in srgb,\s*var\(--color-primary-element[^)]*\)\s+\d+%,\s*var\(--color-main-background/s',
			$this->appCss,
			'Tints must mix into --color-main-background (AZC contract), not transparent',
		);
		self::assertDoesNotMatchRegularExpression(
			'/--dc-tint-(info|success|warning|critical):\s*color-mix\([^;]*,\s*transparent\)/s',
			$this->appCss,
			'Transparent tint mixes disappear on dark / high-contrast themes',
		);
	}

	public function testShellIsFullWidthWithConstrainedModifiers(): void
	{
		self::assertMatchesRegularExpression(
			'/#app-content\.dc-app #app-content-wrapper\.dc-shell,\s*\.dc-shell\s*\{[^}]*max-width:\s*none/s',
			$this->appCss,
			'Default shell must be full width of #app-content (no fixed 1200px)',
		);
		self::assertStringNotContainsString('max-width: 1200px', $this->appCss);
		self::assertStringContainsString('.dc-shell--constrained', $this->appCss);
		self::assertStringContainsString('.dc-shell--minimal', $this->appCss);
		self::assertStringContainsString('max-width: 72rem', $this->appCss);
	}

	public function testStatusInkUsesSemanticTextTokensNotInventedHex(): void
	{
		self::assertStringNotContainsString('#206027', $this->appCss);
		self::assertStringNotContainsString('#971e1e', $this->appCss);
		self::assertStringNotContainsString('#7a5600', $this->appCss);
		// NC34+ --color-success is a pale surface (#D8F3DA). White / on-primary
		// ink on that fill is ~1.18:1 (fails 1.4.11). Done marks must use the
		// success-text companion — same contract as .dc-status-badge--*.
		self::assertDoesNotMatchRegularExpression(
			'/\.dc-setup-checklist__item\.is-done[^{]*\.dc-setup-checklist__status\s*\{[^}]*color:\s*#fff/s',
			$this->appCss,
			'Done checklist status must not use raw #fff on --color-success surfaces',
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.dc-setup-checklist__item\.is-done\s+\.dc-setup-checklist__status\s*\{[^}]*color:\s*var\(--color-primary-element-text/s',
			$this->appCss,
			'Done checklist status must not use on-primary white on pale --color-success',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-setup-checklist__item\.is-done\s+\.dc-setup-checklist__status\s*\{[^}]*color:\s*var\(--color-success-text/s',
			$this->appCss,
			'Done checklist status must use --color-success-text for AA contrast on NC34 surfaces',
		);
	}

	public function testKeyboardFocusUsesSolidPrimaryRing(): void
	{
		self::assertMatchesRegularExpression(
			'/\.dc-app button:focus-visible\s*,[\s\S]*?outline:\s*3px solid var\(--color-primary-element/s',
			$this->appCss,
		);
	}

	public function testTouchTargetTokenAndSafeAreaPadding(): void
	{
		self::assertStringContainsString('--dc-touch: 44px', $this->appCss);
		self::assertStringContainsString('env(safe-area-inset-top', $this->appCss);
		self::assertStringContainsString('env(safe-area-inset-left', $this->appCss);
	}

	public function testActiveNavHintUsesMainTextForAaContrast(): void
	{
		self::assertMatchesRegularExpression(
			'/\.dc-nav__item\.is-active \.dc-nav__hint\s*\{[^}]*color:\s*var\(--color-main-text/s',
			$this->appCss,
			'Active nav hints sit on --dc-tint-info and must not use --dc-muted (fails 4.5:1)',
		);
	}

	public function testLicenseCssMixesTintsIntoMainBackground(): void
	{
		$root = dirname(__DIR__, 3);
		$licenseCss = (string) file_get_contents($root . '/css/license-settings.css');
		self::assertNotSame('', $licenseCss);
		self::assertDoesNotMatchRegularExpression(
			'/\.dc-license-badge--(active|warning|expired)\s*\{[^}]*transparent\)/s',
			$licenseCss,
			'License badges must mix semantic colour into --color-main-background, not transparent',
		);
		self::assertStringContainsString('var(--dc-muted', $licenseCss);
		self::assertStringContainsString('var(--dc-touch', $licenseCss);
		self::assertMatchesRegularExpression(
			'/\.dc-license-status \.dc-license-meter-label[^{]*\{[^}]*color:\s*var\(--color-main-text/s',
			$licenseCss,
			'Meter labels on --color-background-darker must use main-text ink (WCAG 1.4.3)',
		);
	}

	public function testSupportUsGridPreventsNarrowOverflow(): void
	{
		self::assertMatchesRegularExpression(
			'/\.dc-support-us__secondary\s*\{[^}]*minmax\(\s*min\(\s*100%\s*,\s*16rem\s*\)/s',
			$this->appCss,
			'Support Us grid tracks must use min(100%, 16rem) so 320px viewports do not overflow',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-support-us__cta\s*\{[^}]*white-space:\s*normal/s',
			$this->appCss,
			'Support Us CTAs must wrap long labels on narrow screens',
		);
	}

	public function testCheckboxControlsResetNextcloudMinHeightAndStayThemeSafe(): void
	{
		// NC core: input { min-height: var(--default-clickable-area) } stretches
		// bare checkboxes to ~44px and breaks dark / high-contrast painting.
		self::assertMatchesRegularExpression(
			'/\.dc-checkbox input\[type=[\'"]checkbox[\'"]\][\s\S]*?min-height:\s*1\.25rem/s',
			$this->appCss,
			'.dc-checkbox must reset min-height against NC core clickable-area',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-checkbox input\[type=[\'"]checkbox[\'"]\][\s\S]*?min-width:\s*1\.25rem/s',
			$this->appCss,
			'.dc-checkbox must reset min-width against NC core',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-checkbox input\[type=[\'"]checkbox[\'"]\][\s\S]*?accent-color:\s*var\(--color-primary-element/s',
			$this->appCss,
			'Checkbox accent must follow the active NC primary token',
		);
		self::assertMatchesRegularExpression(
			'/\.dc-checkbox input\[type=[\'"]checkbox[\'"]\][\s\S]*?background-color:\s*var\(--dc-bg-card/s',
			$this->appCss,
			'Checkbox fill must use theme surface tokens (not UA default light)',
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.dc-app input:not\(\[type=[\'"]checkbox[\'"]\]\):not\(\[type=[\'"]radio[\'"]\]\):focus-visible/s',
			$this->appCss,
			'Text-field focus border must not paint onto checkbox/radio controls',
		);
		self::assertMatchesRegularExpression(
			'/@media\s*\(forced-colors:\s*active\)[\s\S]*?\.dc-checkbox[\s\S]*?CanvasText/s',
			$this->appCss,
			'Forced-colours mode must keep .dc-checkbox borders visible',
		);
	}
}

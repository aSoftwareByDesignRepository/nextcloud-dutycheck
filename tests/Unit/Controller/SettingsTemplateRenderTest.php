<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Pins the settings jump-nav partial and the section-id contract on settings.php
 * without bootstrapping OCP\Server (license panel needs the kernel).
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

	private function renderToc(): string
	{
		$l = $this->l10n();
		ob_start();
		try {
			include dirname(__DIR__, 3) . '/templates/parts/settings-toc.php';
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function testTocLinksMatchSettingsSectionIds(): void
	{
		$html = $this->renderToc();
		$settings = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/settings.php');
		$license = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/parts/license-panel.php');

		self::assertStringContainsString('id="dc-settings-toc-title"', $html);
		self::assertStringContainsString('include __DIR__ . \'/parts/settings-toc.php\'', $settings);

		$anchors = [
			'dc-settings-policy',
			'dc-settings-duty-roles',
			'dc-settings-planning',
			'dc-settings-companies',
			'dc-settings-conflict-policy',
			'dc-settings-templates',
			'dc-settings-quals',
			'dc-settings-scope',
			'dc-settings-ops',
			'dc-at-integration',
			'dc-settings-privacy',
			'dutycheck-license',
		];
		foreach ($anchors as $id) {
			self::assertStringContainsString('href="#' . $id . '"', $html, "TOC must link to #{$id}");
			$haystack = $id === 'dutycheck-license' ? $license : $settings;
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($id, '/') . '"/',
				$haystack,
				"Jump target #{$id} must exist in settings markup",
			);
		}
	}

	public function testNonAdminBranchOmitsTocInclude(): void
	{
		$settings = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/settings.php');
		// The TOC include must live inside the isAppAdmin branch, after the denial card.
		$denialPos = strpos($settings, 'Only app administrators may change these settings.');
		$tocPos = strpos($settings, "include __DIR__ . '/parts/settings-toc.php'");
		self::assertNotFalse($denialPos);
		self::assertNotFalse($tocPos);
		self::assertGreaterThan($denialPos, $tocPos);
		self::assertStringContainsString('if (!$canAdminApp):', $settings);
	}

	public function testTocEscapesTranslatedLabelsViaPHelper(): void
	{
		$l = new class {
			public function t(string $text, array $parameters = []): string
			{
				return $text === 'On this page' ? '<script>x</script>' : $text;
			}
		};
		ob_start();
		include dirname(__DIR__, 3) . '/templates/parts/settings-toc.php';
		$html = (string) ob_get_clean();
		self::assertStringNotContainsString('<script>x</script>', $html);
		self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
	}
}

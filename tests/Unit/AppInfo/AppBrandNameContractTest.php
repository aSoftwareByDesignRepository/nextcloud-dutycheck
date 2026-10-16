<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\AppInfo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * English brand names only — no localized <name lang="..."> in App Store metadata
 * and no translated app-name keys in shipped l10n catalogs (Option A).
 */
final class AppBrandNameContractTest extends TestCase
{
	private const BRAND = 'DutyCheck';

	private const NAV_L10N_KEY = 'DutyCheck';

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testInfoXmlHasSingleEnglishBrandName(): void
	{
		$xml = (string)file_get_contents($this->root . '/appinfo/info.xml');
		$this->assertStringNotContainsString('<name lang=', $xml, 'Localized app names are not allowed (English brand everywhere)');

		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml), 'info.xml must be valid XML');
		$xp = new DOMXPath($dom);
		/** @var list<DOMElement> $names */
		$names = iterator_to_array($xp->query('/info/name'));
		$this->assertCount(1, $names, 'Exactly one top-level <name> element is allowed');
		$this->assertSame(self::BRAND, $names[0]->textContent);
	}

	public function testNavigationRegistrationUsesEnglishBrand(): void
	{
		$xml = (string)file_get_contents($this->root . '/appinfo/info.xml');
		if (str_contains($xml, '<navigations>')) {
			$this->assertMatchesRegularExpression(
				'#<navigations>\s*<navigation>\s*<name>' . preg_quote(self::BRAND, '#') . '</name>#s',
				$xml,
				'Static info.xml navigation must use the English brand name',
			);
			return;
		}

		$appPhp = (string)file_get_contents($this->root . '/lib/AppInfo/Application.php');
		$quoted = preg_quote(self::NAV_L10N_KEY, '/');
		if (str_contains($appPhp, 'NavGate::register')) {
			// NavGate path: Application passes the English brand key and the
			// shared gate translates it inside the lazy nav closure.
			$this->assertMatchesRegularExpression(
				"/NavGate::register\\([\\s\\S]*?['\"]{$quoted}['\"][\\s\\S]*?\\)/",
				$appPhp,
				'NavGate::register must receive the English brand key',
			);
			$gatePhp = (string)file_get_contents($this->root . '/lib/Support/NavGate.php');
			$this->assertStringContainsString('IL10NFactory', $gatePhp);
			$this->assertMatchesRegularExpression(
				'/->t\(\$name\)/',
				$gatePhp,
				'NavGate must translate the nav name via l10n',
			);
			return;
		}
		$this->assertStringContainsString('INavigationManager', $appPhp);
		$this->assertMatchesRegularExpression(
			"/->t\\(['\"]{$quoted}['\"]\\)/",
			$appPhp,
			'Dynamic navigation must register the English brand via l10n',
		);
	}

	public function testShippedL10nCatalogsKeepEnglishBrandName(): void
	{
		foreach (['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'en'] as $lang) {
			$path = $this->root . '/l10n/' . $lang . '.json';
			if (!is_file($path)) {
				continue;
			}
			$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$tr = $data['translations'] ?? [];
			foreach ([self::BRAND, self::NAV_L10N_KEY] as $key) {
				if (!array_key_exists($key, $tr)) {
					continue;
				}
				$this->assertSame(
					self::BRAND,
					$tr[$key],
					$lang . ' catalog must not localize the app brand name',
				);
			}
		}
	}
}

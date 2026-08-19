<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

/**
 * Catalog integrity: runtime keys ⊆ en, locale key/order parity, JSON↔JS
 * sync, regional variant keys, placeholders (including pt_BR + variants),
 * and curated status/a11y strings must not ship as English identity leftovers.
 */
final class L10nCatalogIntegrityTest extends TestCase
{
	private const BASE_LOCALES = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];

	private const VARIANTS = [
		'da_DK' => 'da',
		'de_DE' => 'de',
		'es_ES' => 'es',
		'fr_FR' => 'fr',
		'it_IT' => 'it',
		'nb_NO' => 'nb',
		'nl_NL' => 'nl',
		'pl_PL' => 'pl',
		'sv_SE' => 'sv',
	];

	/** Visible chrome that must be translated in every non-English locale. */
	private const MUST_TRANSLATE = [
		'(opens in a new tab)',
		'All {total} rows are on screen.',
		'All {total} people are on screen.',
		'All {total} shifts are on screen.',
		'Showing rows {from}–{to} of {total}. Scroll to see the rest.',
		'Showing people {from}–{to} of {total}. Scroll to see everyone.',
		'Showing shifts {from}–{to} of {total}. Scroll to see everyone.',
	];

	private function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private function l10nDir(): string
	{
		return $this->appRoot() . '/l10n';
	}

	/**
	 * @return list<string>
	 */
	private function shippedCatalogLocales(): array
	{
		$out = [];
		foreach (glob($this->l10nDir() . '/*.json') ?: [] as $path) {
			$name = basename($path, '.json');
			if ($name === '' || $name[0] === '_') {
				continue;
			}
			if (str_ends_with($name, '_dict') || $name === 'formal_scandinavian_data') {
				continue;
			}
			$out[] = $name;
		}
		sort($out);
		self::assertNotSame([], $out);

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private function translations(string $lang): array
	{
		$path = $this->l10nDir() . '/' . $lang . '.json';
		self::assertFileExists($path);
		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data['translations'] ?? null, $lang . '.json');
		/** @var array<string, string> $tr */
		$tr = $data['translations'];

		return $tr;
	}

	/**
	 * @return list<string>
	 */
	private function printfPlaceholders(string $s): array
	{
		preg_match_all('/%%|%(?:\d+\$)?[sd]/', $s, $m);

		return $m[0];
	}

	/**
	 * @return list<string>
	 */
	private function namedPlaceholders(string $s): array
	{
		preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);

		return $m[0];
	}

	public function testRuntimeMsgidsAreInEnglishCatalog(): void
	{
		$en = $this->translations('en');
		$script = $this->appRoot() . '/scripts/check-l10n-runtime.php';
		self::assertFileExists($script);
		$cmd = 'php ' . escapeshellarg($script) . ' --all';
		exec($cmd . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
		self::assertArrayHasKey('All {total} rows are on screen.', $en);
		self::assertArrayHasKey('Showing rows {from}–{to} of {total}. Scroll to see the rest.', $en);
	}

	public function testBaseCatalogsShareKeysAndOrder(): void
	{
		$enKeys = array_keys($this->translations('en'));
		self::assertGreaterThan(1000, count($enKeys));
		foreach (self::BASE_LOCALES as $lang) {
			$keys = array_keys($this->translations($lang));
			self::assertSame($enKeys, $keys, $lang . '.json key set or order drifted from en.json');
		}
	}

	public function testRegionalVariantsShareKeysWithBase(): void
	{
		foreach (self::VARIANTS as $variant => $base) {
			$baseKeys = array_keys($this->translations($base));
			$variantKeys = array_keys($this->translations($variant));
			self::assertSame($baseKeys, $variantKeys, $variant . '.json keys drifted from ' . $base . '.json');
		}
	}

	public function testJsonAndJsCatalogsStayInSync(): void
	{
		$failures = [];
		foreach ($this->shippedCatalogLocales() as $lang) {
			$json = $this->translations($lang);
			$jsPath = $this->l10nDir() . '/' . $lang . '.js';
			self::assertFileExists($jsPath, $lang . '.js missing — Nextcloud loads JS catalogs, not JSON');
			$js = (string) file_get_contents($jsPath);
			self::assertStringContainsString('OC.L10N.register(', $js);
			foreach ($json as $msgid => $msgstr) {
				$needle = json_encode($msgid, JSON_UNESCAPED_UNICODE) . ' : ' . json_encode($msgstr, JSON_UNESCAPED_UNICODE);
				if (!str_contains($js, $needle)) {
					$failures[] = $lang . ': JSON/JS mismatch for "' . $msgid . '"';
				}
			}
		}
		self::assertSame([], $failures, "JSON vs JS catalog drift:\n" . implode("\n", $failures));
	}

	public function testPlaceholdersMatchMsgidInEveryCatalog(): void
	{
		$en = $this->translations('en');
		$failures = [];
		foreach ($this->shippedCatalogLocales() as $lang) {
			$tr = $this->translations($lang);
			foreach ($en as $msgid => $_) {
				if (!isset($tr[$msgid])) {
					$failures[] = $lang . ' missing ' . $msgid;
					continue;
				}
				$msgstr = (string) $tr[$msgid];
				if ($this->printfPlaceholders($msgid) !== $this->printfPlaceholders($msgstr)) {
					$failures[] = $lang . ' printf ' . $msgid;
				}
				if ($this->namedPlaceholders($msgid) !== $this->namedPlaceholders($msgstr)) {
					$failures[] = $lang . ' named ' . $msgid;
				}
			}
		}
		self::assertSame([], $failures, "Placeholder mismatches:\n" . implode("\n", array_slice($failures, 0, 40)));
	}

	public function testVisibleStatusLinesAreTranslated(): void
	{
		$failures = [];
		foreach (self::BASE_LOCALES as $lang) {
			if ($lang === 'en') {
				continue;
			}
			$tr = $this->translations($lang);
			foreach (self::MUST_TRANSLATE as $msgid) {
				self::assertArrayHasKey($msgid, $tr, $lang . ' missing ' . $msgid);
				if ($tr[$msgid] === $msgid) {
					$failures[] = $lang . ' identity leftover: ' . $msgid;
				}
			}
		}
		foreach (self::VARIANTS as $variant => $base) {
			$tr = $this->translations($variant);
			$baseTr = $this->translations($base);
			foreach (self::MUST_TRANSLATE as $msgid) {
				self::assertSame($baseTr[$msgid], $tr[$msgid], $variant . ' diverged from ' . $base . ' for ' . $msgid);
			}
		}
		self::assertSame([], $failures, "Untranslated visible chrome:\n" . implode("\n", $failures));
	}

	public function testRuntimeTranslationOverridesAreApplied(): void
	{
		$path = $this->l10nDir() . '/_runtime_translations.json';
		self::assertFileExists($path);
		/** @var array<string, array<string, string>> $overrides */
		$overrides = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayNotHasKey('en', $overrides);
		foreach ($overrides as $lang => $pairs) {
			$tr = $this->translations($lang);
			foreach ($pairs as $msgid => $msgstr) {
				self::assertArrayHasKey($msgid, $tr, $lang . ' missing override key ' . $msgid);
				self::assertSame($msgstr, $tr[$msgid], $lang . ' catalog does not match _runtime_translations.json for ' . $msgid);
			}
		}
	}

	public function testPlaceholderScriptDiscoversPtBrAndVariants(): void
	{
		$src = (string) file_get_contents($this->appRoot() . '/scripts/check-l10n-placeholders.php');
		self::assertStringContainsString('glob', $src);
		self::assertStringContainsString('pt_BR', $src);
		self::assertStringContainsString('formal_scandinavian_data', $src);
		self::assertStringContainsString('_dict', $src);
		self::assertDoesNotMatchRegularExpression(
			"/localeFiles = \['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb'\];/",
			$src,
			'placeholder checker must not omit pt_BR / regional variants',
		);
	}

	public function testL10nCliGatesExitZero(): void
	{
		$scripts = [
			'scripts/check-l10n-runtime.php --all',
			'scripts/check-l10n-parity.php',
			'scripts/check-l10n-placeholders.php',
		];
		foreach ($scripts as $rel) {
			$cmd = 'php ' . $this->appRoot() . '/' . $rel;
			exec($cmd . ' 2>&1', $out, $code);
			self::assertSame(0, $code, $rel . "\n" . implode("\n", $out));
		}
	}
}

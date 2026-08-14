<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

/**
 * Planning “period” must never be translated as menstruation / menstrual cycle.
 * Evaluation #3 (noci2012): Dutch “No periods yet” → “Nog geen menstruatie”.
 */
final class PeriodTranslationSenseTest extends TestCase
{
	private const FORBIDDEN = '/menstruat|mestruaz|menstruação|\bingen mens\b|pas encore de règles|nessun ciclo ancora|nog geen menstruatie/iu';

	public function testPlanningPeriodKeysAreNotMenstrual(): void
	{
		$dir = dirname(__DIR__, 3) . '/l10n';
		self::assertDirectoryExists($dir);
		$failures = [];
		foreach (glob($dir . '/*.json') ?: [] as $path) {
			$catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$translations = $catalog['translations'] ?? [];
			self::assertIsArray($translations, basename($path));
			foreach ($translations as $msgid => $msgstr) {
				if (!is_string($msgid) || !is_string($msgstr)) {
					continue;
				}
				if (!preg_match('/period/i', $msgid)) {
					continue;
				}
				if (preg_match('/rule/i', $msgid)) {
					continue;
				}
				if (preg_match(self::FORBIDDEN, $msgstr) === 1) {
					$failures[] = basename($path) . ': "' . $msgid . '" → "' . $msgstr . '"';
				}
			}
		}
		self::assertSame([], $failures, "Menstrual mistranslations of planning periods:\n" . implode("\n", $failures));
	}

	public function testDutchEmptyStateUsesPeriodes(): void
	{
		$nl = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/nl.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Nog geen periodes', $nl['translations']['No periods yet']);
		self::assertSame('Periode', $nl['translations']['Period']);
	}

	public function testWorkShiftIsNotTranslatedAsDisplacement(): void
	{
		$dir = dirname(__DIR__, 3) . '/l10n';
		$forbidden = '/verschuiv|spostamento|Kravskifte|turno de reclamo|changement publié|produisent des changements|Ce changement chevauche|questo cambiamento|Wijziging bewerken|changement de poste|avvengono i cambiamenti|ocurren cambios|acontecem mudanças/iu';
		$failures = [];
		foreach (glob($dir . '/*.json') ?: [] as $path) {
			$catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$translations = [];
			if (isset($catalog['translations']) && is_array($catalog['translations'])) {
				$translations = $catalog['translations'];
			}
			if (is_array($catalog)) {
				foreach ($catalog as $msgid => $msgstr) {
					if (is_string($msgid) && is_string($msgstr)) {
						$translations[$msgid] = $msgstr;
					}
				}
			}
			foreach ($translations as $msgid => $msgstr) {
				if (!is_string($msgid) || !is_string($msgstr)) {
					continue;
				}
				if (!preg_match('/shift/i', $msgid)) {
					continue;
				}
				if (preg_match($forbidden, $msgstr) === 1) {
					$failures[] = basename($path) . ': "' . $msgid . '" → "' . $msgstr . '"';
				}
			}
		}
		self::assertSame([], $failures, "Work-shift mistranslated as displacement/change:\n" . implode("\n", $failures));
	}

	public function testShiftActionLabelsUseDutySense(): void
	{
		$nl = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/nl.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Dienst claimen', $nl['translations']['Claim shift']);
		self::assertSame('Dienst bewerken', $nl['translations']['Edit shift']);
		$it = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/it.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Richiedi il turno', $it['translations']['Claim shift']);
		$es = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/es.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Reclamar turno', $es['translations']['Claim shift']);
		$da = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/da.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Tag vagt', $da['translations']['Claim shift']);
		$fr = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/fr.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Aucun poste publié dans cette plage.', $fr['translations']['No published shifts in this range.']);
	}

	public function testStartOfWeekKeyExistsInEnglishCatalog(): void
	{
		$en = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayHasKey('Start of week', $en['translations']);
		self::assertSame('Start of week', $en['translations']['Start of week']);
	}
}

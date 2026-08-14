<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * Bridges Nextcloud language + locale + timezone to templates and JS.
 *
 * Nextcloud personal settings keep these distinct and DutyCheck must too:
 *  - Language → UI strings, {@see htmlLang}, RelativeTimeFormat
 *  - Locale → date/number order, first day of week, {@see locale} / Intl.DateTimeFormat
 *
 * Never pass the account locale as a language to {@see IFactory::get()} or
 * {@see IFactory::findLanguageFromLocale()} — that is the classic bug that
 * shows Dutch UI (including bogus “period” translations) when Language is
 * English and Locale is Dutch.
 */
class LocaleFormatService
{
	public function __construct(
		private IFactory $l10nFactory,
		private IDateTimeFormatter $dateTimeFormatter,
		private IUserSession $userSession,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	public function canonicalHtmlLangFromLocaleString(?string $rawLocale): string
	{
		$locale = strtolower(trim((string)$rawLocale));
		if ($locale === '') {
			return 'en-US';
		}
		$locale = str_replace('_', '-', $locale);
		if (preg_match('/^[a-z]{2}-[a-z]{2}$/', $locale) !== 1) {
			return match ($locale) {
				'de' => 'de-DE',
				'en' => 'en-US',
				'fr' => 'fr-FR',
				'es' => 'es-ES',
				'da' => 'da-DK',
				'nl' => 'nl-NL',
				'it' => 'it-IT',
				'pl' => 'pl-PL',
				'pt' => 'pt-PT',
				'nb', 'nn', 'no' => 'nb-NO',
				'sv' => 'sv-SE',
				default => 'en-US',
			};
		}
		$parts = explode('-', $locale);
		return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
	}

	/**
	 * First day of the week as JS {@see \DateTimeInterface::format} `w`:
	 * 0 = Sunday … 6 = Saturday. Driven by Locale, never by Language.
	 */
	public function firstDayOfWeekFromLocaleString(?string $rawLocale): int
	{
		$locale = strtolower(str_replace('_', '-', trim((string)$rawLocale)));
		if ($locale !== '' && class_exists(\IntlCalendar::class)) {
			try {
				$cal = \IntlCalendar::createInstance('UTC', $locale);
				if ($cal instanceof \IntlCalendar) {
					$icu = (int)$cal->getFirstDayOfWeek();
					if ($icu >= 1 && $icu <= 7) {
						return $icu - 1;
					}
				}
			} catch (\Throwable) {
			}
		}
		return $this->firstDayOfWeekFallback($locale);
	}

	/**
	 * CLDR-oriented fallback when intl calendar data is unavailable.
	 *
	 * @internal unit tests pin this map so locale≠language week-start stays correct
	 */
	public function firstDayOfWeekFallback(string $locale): int
	{
		$tag = strtolower(str_replace('_', '-', trim($locale)));
		$parts = explode('-', $tag);
		$lang = $parts[0] ?? '';
		$region = $parts[1] ?? '';
		$sundayRegions = ['us', 'ca', 'jp', 'il', 'ph', 'br', 'mx', 'tw', 'kr', 'cn', 'hk', 'mo', 'za'];
		if (in_array($region, $sundayRegions, true)) {
			return 0;
		}
		if ($lang === 'en' && ($region === '' || $region === 'us')) {
			return 0;
		}
		return 1;
	}

	/**
	 * Weekday name for the locale's first day of week, in the UI language
	 * (so English UI + Dutch locale still says "Monday", not "maandag").
	 */
	public function weekStartDayName(string $language, int $firstDayOfWeek): string
	{
		$firstDayOfWeek = max(0, min(6, $firstDayOfWeek));
		$sunday = new \DateTimeImmutable('2026-08-16 12:00:00', new \DateTimeZone('UTC'));
		$day = $sunday->modify('+' . $firstDayOfWeek . ' days');
		$tag = $this->canonicalHtmlLangFromLocaleString($language);
		if (class_exists(\IntlDateFormatter::class)) {
			try {
				$fmt = new \IntlDateFormatter(
					$tag,
					\IntlDateFormatter::NONE,
					\IntlDateFormatter::NONE,
					'UTC',
					\IntlDateFormatter::GREGORIAN,
					'EEEE',
				);
				$out = $fmt->format($day);
				if (is_string($out) && trim($out) !== '') {
					return $out;
				}
			} catch (\Throwable) {
			}
		}
		return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$firstDayOfWeek];
	}

	/**
	 * @return array{
	 *   locale:string,
	 *   htmlLang:string,
	 *   language:string,
	 *   firstDayOfWeek:int,
	 *   weekStartDayName:string,
	 *   timezone:string,
	 *   timezoneObject:\DateTimeZone|object|string,
	 *   exampleDate:string
	 * }
	 */
	public function clientHints(): array
	{
		$language = $this->sessionLanguage();
		$localeRaw = $this->sessionLocaleRaw($language);
		$htmlLang = $this->canonicalHtmlLangFromLocaleString($language !== '' ? $language : $localeRaw);
		$intlLocale = $this->canonicalHtmlLangFromLocaleString($localeRaw !== '' ? $localeRaw : $language);
		$firstDay = $this->firstDayOfWeekFromLocaleString($localeRaw !== '' ? $localeRaw : $language);

		$tz = $this->dateTimeZone->getTimeZone();
		if ($tz instanceof \DateTimeZone) {
			$tzName = (string)$tz->getName();
		} elseif (is_object($tz) && method_exists($tz, 'getName')) {
			$tzName = (string)$tz->getName();
		} else {
			$tzName = is_string($tz) && $tz !== '' ? $tz : 'UTC';
		}

		return [
			'locale' => $intlLocale,
			'htmlLang' => $htmlLang,
			'language' => $language !== '' ? $language : 'en',
			'firstDayOfWeek' => $firstDay,
			'weekStartDayName' => $this->weekStartDayName($language !== '' ? $language : 'en', $firstDay),
			'timezone' => $tzName,
			'timezoneObject' => $tz,
			'exampleDate' => $this->dateTimeFormatter->formatDateTime((new \DateTimeImmutable('now'))->getTimestamp()),
		];
	}

	private function sessionLanguage(): string
	{
		$user = $this->userSession->getUser();
		$language = '';
		if ($user !== null) {
			$language = (string)$this->l10nFactory->getUserLanguage($user);
		}
		if ($language === '') {
			$language = (string)$this->l10nFactory->findLanguage(Application::APP_ID);
		}
		return $language;
	}

	private function sessionLocaleRaw(string $language): string
	{
		try {
			$localeRaw = (string)$this->l10nFactory->findLocale($language !== '' ? $language : null);
		} catch (\Throwable) {
			$localeRaw = '';
		}
		if ($localeRaw === '') {
			$localeRaw = $language;
		}
		return $localeRaw;
	}
}

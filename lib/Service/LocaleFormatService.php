<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\L10N\IFactory;
use OCP\IUserSession;

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
				default => 'en-US',
			};
		}
		$parts = explode('-', $locale);
		return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
	}

	public function clientHints(): array
	{
		$user = $this->userSession->getUser();
		$locale = '';
		if ($user !== null) {
			$locale = (string) $this->l10nFactory->getUserLanguage($user);
		}
		if ($locale === '') {
			$locale = (string) $this->l10nFactory->findLanguage(Application::APP_ID);
		}
		$htmlLang = $this->canonicalHtmlLangFromLocaleString($locale);
		$tz = $this->dateTimeZone->getTimeZone();
		if ($tz instanceof \DateTimeZone) {
			$tzName = (string) $tz->getName();
		} elseif (is_object($tz) && method_exists($tz, 'getName')) {
			$tzName = (string) $tz->getName();
		} else {
			$tzName = is_string($tz) && $tz !== '' ? $tz : 'UTC';
		}
		return [
			'locale' => $htmlLang,
			'htmlLang' => $htmlLang,
			'timezone' => $tzName,
			'timezoneObject' => $tz,
			'exampleDate' => $this->dateTimeFormatter->formatDateTime((new \DateTimeImmutable('now'))->getTimestamp()),
		];
	}
}

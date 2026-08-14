<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\LocaleFormatService;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Language (UI) and Locale (date/week-start) must stay distinct.
 * Collapsing them is the evaluation bug: English UI + Dutch locale
 * must not load Dutch translations or Dutch relative-time wording.
 */
final class LocaleFormatServiceLanguageVsLocaleTest extends TestCase
{
	private function service(
		IFactory $l10nFactory,
		?IUser $user = null,
		?\DateTimeZone $tz = null,
	): LocaleFormatService {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$dtz = $this->createMock(IDateTimeZone::class);
		$dtz->method('getTimeZone')->willReturn($tz ?? new \DateTimeZone('UTC'));
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturn('example');
		return new LocaleFormatService($l10nFactory, $formatter, $session, $dtz);
	}

	public function testEnglishLanguageWithDutchLocaleKeepsHtmlLangEnglish(): void
	{
		$user = $this->createMock(IUser::class);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->expects(self::once())->method('getUserLanguage')->with($user)->willReturn('en');
		$l10nFactory->expects(self::once())->method('findLocale')->with('en')->willReturn('nl_NL');
		$l10nFactory->expects(self::never())->method('findLanguageFromLocale');
		$l10nFactory->expects(self::never())->method('findLanguage');

		$hints = $this->service($l10nFactory, $user, new \DateTimeZone('Europe/Amsterdam'))->clientHints();

		self::assertSame('en-US', $hints['htmlLang'], 'html lang follows Language, not Locale');
		self::assertSame('en', $hints['language']);
		self::assertSame('nl-NL', $hints['locale'], 'Intl locale follows Nextcloud Locale');
		self::assertSame(1, $hints['firstDayOfWeek'], 'Netherlands week starts Monday');
		self::assertMatchesRegularExpression('/^monday$/i', $hints['weekStartDayName']);
		self::assertDoesNotMatchRegularExpression('/maandag/i', $hints['weekStartDayName']);
		self::assertSame('Europe/Amsterdam', $hints['timezone']);
	}

	public function testGermanLanguageWithoutExplicitLocaleMapsHtmlLang(): void
	{
		$user = $this->createMock(IUser::class);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('de');
		$l10nFactory->method('findLocale')->with('de')->willReturn('de_DE');

		$hints = $this->service($l10nFactory, $user)->clientHints();
		self::assertSame('de-DE', $hints['htmlLang']);
		self::assertSame('de-DE', $hints['locale']);
		self::assertSame('de', $hints['language']);
	}

	public function testFallsBackToFindLanguageWhenSessionHasNoUser(): void
	{
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->expects(self::never())->method('getUserLanguage');
		$l10nFactory->method('findLanguage')->with('dutycheck')->willReturn('fr');
		$l10nFactory->method('findLocale')->with('fr')->willReturn('fr_FR');

		$hints = $this->service($l10nFactory, null)->clientHints();
		self::assertSame('fr-FR', $hints['htmlLang']);
		self::assertSame('fr-FR', $hints['locale']);
		self::assertSame('fr', $hints['language']);
	}

	public function testEmptyFindLocaleFallsBackToLanguage(): void
	{
		$user = $this->createMock(IUser::class);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('it');
		$l10nFactory->method('findLocale')->willReturn('');

		$hints = $this->service($l10nFactory, $user)->clientHints();
		self::assertSame('it-IT', $hints['htmlLang']);
		self::assertSame('it-IT', $hints['locale']);
	}

	public function testFirstDayOfWeekFallbackSundayForEnUsMondayForNl(): void
	{
		$svc = $this->service($this->createMock(IFactory::class));
		self::assertSame(0, $svc->firstDayOfWeekFallback('en'));
		self::assertSame(0, $svc->firstDayOfWeekFallback('en-US'));
		self::assertSame(0, $svc->firstDayOfWeekFallback('en_US'));
		self::assertSame(1, $svc->firstDayOfWeekFallback('en-GB'));
		self::assertSame(1, $svc->firstDayOfWeekFallback('nl_NL'));
		self::assertSame(1, $svc->firstDayOfWeekFallback('de-DE'));
		self::assertSame(1, $svc->firstDayOfWeekFallback(''));
	}

	public function testWeekStartDayNameFollowsLanguageNotLocale(): void
	{
		$svc = $this->service($this->createMock(IFactory::class));
		self::assertMatchesRegularExpression('/^sunday$/i', $svc->weekStartDayName('en', 0));
		self::assertMatchesRegularExpression('/^monday$/i', $svc->weekStartDayName('en', 1));
		self::assertDoesNotMatchRegularExpression('/maandag|zondag/i', $svc->weekStartDayName('en', 1));
		if (class_exists(\IntlDateFormatter::class)) {
			self::assertMatchesRegularExpression('/^maandag$/i', $svc->weekStartDayName('nl', 1));
			self::assertMatchesRegularExpression('/^montag$/i', $svc->weekStartDayName('de', 1));
		}
	}

	public function testWeekStartDayNameClampsOutOfRange(): void
	{
		$svc = $this->service($this->createMock(IFactory::class));
		self::assertMatchesRegularExpression('/^sunday$/i', $svc->weekStartDayName('en', -3));
		self::assertMatchesRegularExpression('/^saturday$/i', $svc->weekStartDayName('en', 99));
	}

	public function testCanonicalizationKeepsRegion(): void
	{
		$svc = $this->service($this->createMock(IFactory::class));
		self::assertSame('nl-NL', $svc->canonicalHtmlLangFromLocaleString('nl_NL'));
		self::assertSame('en-GB', $svc->canonicalHtmlLangFromLocaleString('en-gb'));
		self::assertSame('nb-NO', $svc->canonicalHtmlLangFromLocaleString('nb'));
	}

	public function testSourceNeverSelectsLanguageFromLocale(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LocaleFormatService.php');
		$code = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
		$code = preg_replace('~//.*$~m', '', $code) ?? $code;
		self::assertStringNotContainsString('findLanguageFromLocale', $code);
		self::assertStringContainsString('findLocale', $code);
		self::assertStringContainsString('getUserLanguage', $code);
	}
}

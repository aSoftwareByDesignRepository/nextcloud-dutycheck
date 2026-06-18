<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\LocaleFormatService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class LocaleFormatServiceHtmlLangTest extends TestCase
{
	public function testCanonicalization(): void
	{
		$service = new LocaleFormatService(
			$this->createMock(\OCP\L10N\IFactory::class),
			$this->createMock(\OCP\IDateTimeFormatter::class),
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\OCP\IDateTimeZone::class),
		);
		self::assertSame('de-DE', $service->canonicalHtmlLangFromLocaleString('de'));
		self::assertSame('de-DE', $service->canonicalHtmlLangFromLocaleString('de_de'));
		self::assertSame('fr-FR', $service->canonicalHtmlLangFromLocaleString('fr'));
		self::assertSame('fr-FR', $service->canonicalHtmlLangFromLocaleString('fr_fr'));
		self::assertSame('es-ES', $service->canonicalHtmlLangFromLocaleString('es'));
		self::assertSame('es-ES', $service->canonicalHtmlLangFromLocaleString('es_es'));
		self::assertSame('en-US', $service->canonicalHtmlLangFromLocaleString(''));
	}

	public function testClientHintsUsesUserLanguageWithoutTypeError(): void
	{
		$l10nFactory = $this->createMock(\OCP\L10N\IFactory::class);
		$dateTimeFormatter = $this->createMock(\OCP\IDateTimeFormatter::class);
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
		$user = $this->createMock(IUser::class);

		$userSession->method('getUser')->willReturn($user);
		$l10nFactory->method('getUserLanguage')->with($user)->willReturn('de');
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$dateTimeFormatter->expects(self::once())
			->method('formatDateTime')
			->with(self::isType('int'))
			->willReturn('now');

		$service = new LocaleFormatService($l10nFactory, $dateTimeFormatter, $userSession, $dateTimeZone);
		$hints = $service->clientHints();

		self::assertSame('de-DE', $hints['htmlLang']);
		self::assertSame('Europe/Berlin', $hints['timezone']);
	}
}

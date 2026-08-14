<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Activity;

use OCA\DutyCheck\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class ActivityProviderTest extends TestCase
{
	public function testParsesRosterPublished(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $p = []): string => sprintf($s, ...$p));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/dutycheck/my-roster');

		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('dutycheck');
		$event->method('getSubject')->willReturn('roster_published');
		$event->method('getSubjectParameters')->willReturn(['2026-07-01 – 2026-07-07']);
		$event->method('getObjectId')->willReturn(42);
		$event->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$event->expects($this->once())->method('setRichSubject')->willReturnSelf();
		$event->expects($this->once())->method('setLink')->willReturnSelf();

		$provider = new Provider($factory, $url);
		self::assertSame($event, $provider->parse('en', $event));
	}

	public function testRejectsForeignAppWithUnknownActivityException(): void
	{
		$factory = $this->createMock(IFactory::class);
		$url = $this->createMock(IURLGenerator::class);
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('files');
		$this->expectException(UnknownActivityException::class);
		(new Provider($factory, $url))->parse('en', $event);
	}

	public function testRejectsUnknownSubjectWithUnknownActivityException(): void
	{
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->createMock(IL10N::class));
		$url = $this->createMock(IURLGenerator::class);
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('dutycheck');
		$event->method('getSubject')->willReturn('not_a_real_subject');
		$this->expectException(UnknownActivityException::class);
		(new Provider($factory, $url))->parse('en', $event);
	}
}

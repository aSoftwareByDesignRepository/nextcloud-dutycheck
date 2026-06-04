<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class PlanningDefaultsServiceTest extends TestCase
{
	public function testDefaultsToZero(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$service = new PlanningDefaultsService($config);
		$this->assertSame(0, $service->getDefaultBreakMinutes());
	}

	public function testSetFromPayloadRejectsMissing(): void
	{
		$config = $this->createMock(IConfig::class);
		$service = new PlanningDefaultsService($config);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('DEFAULT_BREAK_MINUTES_REQUIRED');
		$service->setFromPayload(null);
	}

	public function testSetFromPayloadRejectsNonNumeric(): void
	{
		$config = $this->createMock(IConfig::class);
		$service = new PlanningDefaultsService($config);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_DEFAULT_BREAK_MINUTES');
		$service->setFromPayload('not-a-number');
	}

	public function testSetFromPayloadAcceptsNumericString(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$writes = [];
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value) use (&$writes): void {
			$writes[] = $value;
		});
		$service = new PlanningDefaultsService($config);
		$service->setFromPayload('30');
		$this->assertSame('30', $writes[0]);
	}

	public function testParseAssignmentBreakMinutesRejectsNonNumeric(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_BREAK_MINUTES');
		PlanningDefaultsService::parseAssignmentBreakMinutes('not-a-number');
	}

	public function testParseAssignmentBreakMinutesAcceptsNumericString(): void
	{
		$this->assertSame(30, PlanningDefaultsService::parseAssignmentBreakMinutes('30'));
	}

	public function testParseAssignmentBreakMinutesEmptyIsZero(): void
	{
		$this->assertSame(0, PlanningDefaultsService::parseAssignmentBreakMinutes(null));
		$this->assertSame(0, PlanningDefaultsService::parseAssignmentBreakMinutes(''));
	}

	public function testParseAssignmentBreakMinutesRejectsOutOfRange(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		PlanningDefaultsService::parseAssignmentBreakMinutes(721);
	}

	public function testClampsAndPersists(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('30');
		$writes = [];
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value) use (&$writes): void {
			$writes[] = [$app, $key, $value];
		});
		$service = new PlanningDefaultsService($config);
		$service->setDefaultBreakMinutes(45);
		$this->assertSame([Application::APP_ID, PlanningDefaultsService::KEY_DEFAULT_BREAK_MINUTES, '45'], $writes[0]);
		$service->setDefaultBreakMinutes(999);
		$this->assertSame('720', $writes[1][2]);
	}
}

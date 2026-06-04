<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * Organisation-wide planning defaults (app config).
 */
class PlanningDefaultsService
{
	public const KEY_DEFAULT_BREAK_MINUTES = 'default_break_minutes';
	private const MAX_BREAK_MINUTES = 720;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getDefaultBreakMinutes(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_BREAK_MINUTES, '0');

		return $this->normalizeBreakMinutes($raw);
	}

	public function setDefaultBreakMinutes(int $minutes): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_DEFAULT_BREAK_MINUTES,
			(string) $this->normalizeBreakMinutes($minutes),
		);
	}

	/**
	 * @throws \InvalidArgumentException DEFAULT_BREAK_MINUTES_REQUIRED|INVALID_DEFAULT_BREAK_MINUTES
	 */
	public function setFromPayload(mixed $raw): void
	{
		if ($raw === null || $raw === '') {
			throw new \InvalidArgumentException('DEFAULT_BREAK_MINUTES_REQUIRED');
		}
		if (is_bool($raw) || is_array($raw) || is_object($raw)) {
			throw new \InvalidArgumentException('INVALID_DEFAULT_BREAK_MINUTES');
		}
		$normalized = is_string($raw) ? trim($raw) : $raw;
		if (!is_numeric($normalized)) {
			throw new \InvalidArgumentException('INVALID_DEFAULT_BREAK_MINUTES');
		}
		$this->setDefaultBreakMinutes((int) round((float) $normalized));
	}

	/**
	 * @return array{defaultBreakMinutes:int}
	 */
	public function toApi(): array
	{
		return [
			'defaultBreakMinutes' => $this->getDefaultBreakMinutes(),
		];
	}

	/**
	 * Parse break minutes from an assignment API payload (strict; rejects non-numeric).
	 *
	 * @throws \InvalidArgumentException INVALID_BREAK_MINUTES
	 */
	public static function parseAssignmentBreakMinutes(mixed $raw): int
	{
		if ($raw === null || $raw === '') {
			return 0;
		}
		if (is_bool($raw) || is_array($raw) || is_object($raw)) {
			throw new \InvalidArgumentException('INVALID_BREAK_MINUTES');
		}
		$normalized = is_string($raw) ? trim($raw) : $raw;
		if (!is_numeric($normalized)) {
			throw new \InvalidArgumentException('INVALID_BREAK_MINUTES');
		}
		$minutes = (int) round((float) $normalized);
		if ($minutes < 0 || $minutes > self::MAX_BREAK_MINUTES) {
			throw new \InvalidArgumentException('INVALID_BREAK_MINUTES');
		}

		return $minutes;
	}

	private function normalizeBreakMinutes(int $minutes): int
	{
		if ($minutes < 0) {
			return 0;
		}
		if ($minutes > self::MAX_BREAK_MINUTES) {
			return self::MAX_BREAK_MINUTES;
		}

		return $minutes;
	}
}

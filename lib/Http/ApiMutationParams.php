<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Http;

use OCP\IRequest;

/**
 * Reads JSON / form mutation bodies for DutyCheck API routes.
 *
 * Nextcloud merges decoded JSON and {@see $_POST} into {@see IRequest::getParams()};
 * this helper keeps controllers consistent and documents the contract for auditors.
 *
 * The DutyCheck frontend sends mutations as `application/x-www-form-urlencoded`
 * (see js/common/api.js) so parameters are always available via $_POST even when
 * JSON body parsing is blocked or stripped by a reverse proxy / Snap edge case.
 */
final class ApiMutationParams
{
	/**
	 * @return array<string, mixed>
	 */
	public static function all(IRequest $request): array
	{
		$params = $request->getParams();
		return is_array($params) ? $params : [];
	}

	public static function get(IRequest $request, string $key, mixed $default = null): mixed
	{
		$params = self::all($request);
		return array_key_exists($key, $params) ? $params[$key] : $default;
	}

	/**
	 * Parse a boolean-ish request value from a urlencoded or JSON body.
	 *
	 * The DutyCheck frontend encodes mutations as application/x-www-form-urlencoded,
	 * so JS `false` arrives as the literal string "false" — and PHP's `(bool)`
	 * cast turns that into `true` (the 0.3.4 patterns/settings corruption).
	 * This parser is the single source of truth: it accepts real booleans, 0/1
	 * ints, and the conventional string literals; anything else is rejected
	 * instead of silently coerced.
	 *
	 * @throws \InvalidArgumentException always with $errorCode as the message.
	 */
	public static function boolValue(mixed $value, string $errorCode = 'INVALID_BOOLEAN'): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			if ($value === 1) {
				return true;
			}
			if ($value === 0) {
				return false;
			}
			throw new \InvalidArgumentException($errorCode);
		}
		if (is_float($value)) {
			// Only exactly 1.0 / 0.0 are booleans; anything else (1.5, NAN, INF)
			// must be rejected, never silently truncated by an (int) cast.
			if ($value === 1.0) {
				return true;
			}
			if ($value === 0.0) {
				return false;
			}
			throw new \InvalidArgumentException($errorCode);
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if ($normalized === '') {
				// HTML checkbox semantics: absent/empty means off.
				return false;
			}
			if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
				return true;
			}
			if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
				return false;
			}
		}
		throw new \InvalidArgumentException($errorCode);
	}

	/**
	 * Like {@see boolValue()} but `null` (and only `null`) falls back to $default.
	 */
	public static function boolValueOr(mixed $value, bool $default, string $errorCode = 'INVALID_BOOLEAN'): bool
	{
		return $value === null ? $default : self::boolValue($value, $errorCode);
	}

	/**
	 * Normalise nested acknowledgement rows from form or JSON payloads.
	 *
	 * @return list<array{conflictType: string, reason: string}>
	 */
	public static function acknowledgements(IRequest $request): array
	{
		$raw = self::get($request, 'acknowledgements', []);
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $row) {
			if (!is_array($row)) {
				continue;
			}
			$type = trim((string) ($row['conflictType'] ?? ''));
			$reason = trim((string) ($row['reason'] ?? ''));
			if ($type === '') {
				continue;
			}
			$out[] = ['conflictType' => $type, 'reason' => $reason];
		}
		return $out;
	}
}

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

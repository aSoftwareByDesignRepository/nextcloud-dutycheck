<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

/**
 * Pure logic for mirror row purges (used by {@see ArbeitszeitCheckIntegrationService} and SQLite integration tests).
 */
final class ArbeitszeitCheckMirrorDeleteHelper
{
	/**
	 * Max `at_absence_id` values per DELETE … IN (…) plus one bind for `linked_user_id`.
	 * Stays under SQLite's default SQLITE_MAX_VARIABLE_NUMBER (~999).
	 */
	public const IN_CHUNK = 400;

	/**
	 * @param list<string> $distinctMirrorLinkedUserIds
	 * @param list<string> $validActiveLinkedUserIds
	 * @return list<string>
	 */
	public static function orphanLinkedUserIds(array $distinctMirrorLinkedUserIds, array $validActiveLinkedUserIds): array
	{
		if ($validActiveLinkedUserIds === []) {
			return [];
		}
		$valid = array_flip($validActiveLinkedUserIds);
		$out = [];
		foreach ($distinctMirrorLinkedUserIds as $uid) {
			$u = (string) $uid;
			if ($u === '' || isset($valid[$u])) {
				continue;
			}
			$out[] = $u;
		}
		return $out;
	}

	/**
	 * @param list<int> $presentAtAbsenceIds
	 * @param list<int> $keepAtAbsenceIds
	 * @return list<int>
	 */
	public static function atAbsenceIdsToDelete(array $presentAtAbsenceIds, array $keepAtAbsenceIds): array
	{
		if ($keepAtAbsenceIds === []) {
			return [];
		}
		$keep = array_flip($keepAtAbsenceIds);
		$out = [];
		foreach ($presentAtAbsenceIds as $id) {
			$n = (int) $id;
			if ($n < 1 || isset($keep[$n])) {
				continue;
			}
			$out[] = $n;
		}
		return $out;
	}
}

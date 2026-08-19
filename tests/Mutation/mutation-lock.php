<?php

declare(strict_types=1);

/**
 * Exclusive lock so mutation gauntlets never run in parallel against the same tree.
 * Parallel runs restore each other's backups and can leave production mutants in place.
 */
$lockPath = sys_get_temp_dir() . '/dutycheck-mutation.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
	fwrite(STDERR, "Could not open mutation lock {$lockPath}\n");
	exit(2);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another DutyCheck mutation gauntlet is already running. Do not run mutators in parallel.\n");
	exit(2);
}
register_shutdown_function(static function () use ($lockHandle): void {
	flock($lockHandle, LOCK_UN);
	fclose($lockHandle);
});

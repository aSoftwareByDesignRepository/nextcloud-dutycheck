<?php

declare(strict_types=1);

/**
 * Lightweight mutation gauntlet for the directory-picker "no raw ids" contract
 * (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md §1: "Never
 * ask humans to type raw IDs").
 *
 * Runs DirectoryPickerContractTest as a baseline, then reintroduces known-bad
 * raw-id-entry patterns one at a time — a free-text "Nextcloud user id" /
 * "Planner user ID" field, a "press Enter to add an exact id" hint, an
 * "allowDirectEntry" typed-id fallback on the policy pickers, and a submit
 * handler that no longer guards against a blank picked uid — and asserts the
 * suite fails for each, proving the tests actually catch a regression back to
 * typed Nextcloud user ids.
 *
 * Usage (from app root, inside Docker when applicable):
 *   php tests/Mutation/run-directory-picker-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function run_unit_tests(string $appRoot, string $phpunit): int
{
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	// Host PHP cannot reach MariaDB socket; prefer Docker when available. This
	// contract test does not touch the DB/kernel, but stay consistent with the
	// other mutation scripts so it also works standalone on the host.
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		$cmd = escapeshellarg($dockerRunner) . ' dutycheck --filter DirectoryPickerContractTest';
		passthru($cmd, $code);
		return (int) $code;
	}
	$phpBin = 'php';
	// Disable CLI opcache so file mutations are visible to the next PHPUnit process.
	$cmd = escapeshellarg($phpBin)
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter DirectoryPickerContractTest';
	passthru($cmd, $code);
	return (int) $code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

/**
 * @return array{0: string, 1: int}|null [$mutated, $count] or null if the anchor/pattern was not found.
 */
function apply_mutation(string $original, array $spec): ?array
{
	if (($spec['regex'] ?? false) === true) {
		$mutated = preg_replace($spec['from'], $spec['to'], $original, -1, $count);
		if ($mutated === null || $count < 1) {
			return null;
		}
		return [$mutated, $count];
	}
	if (!str_contains($original, $spec['from'])) {
		return null;
	}
	$mutated = str_replace($spec['from'], $spec['to'], $original);
	return $mutated === $original ? null : [$mutated, 1];
}

$mutations = [
	'company_member_reintroduces_raw_text_user_id' => [
		'file' => $appRoot . '/templates/parts/settings/companies.php',
		'from' => '<input type="hidden" id="dc-company-member-user" name="userId">',
		'to' => '<input id="dc-company-member-user" type="text" class="dc-input" name="userId" maxlength="64" required autocomplete="off">',
	],
	'planner_scope_reintroduces_raw_text_user_id' => [
		'file' => $appRoot . '/templates/parts/settings/planner-scope.php',
		'from' => '<input type="hidden" id="dc-scope-user" name="userId">',
		'to' => '<input id="dc-scope-user" type="text" class="dc-input" name="userId" maxlength="64" required autocomplete="off">',
	],
	'policy_hint_reintroduces_press_enter_for_exact_id' => [
		'file' => $appRoot . '/templates/parts/settings/access.php',
		'from' => 'Type at least 2 characters and pick a user from the list.',
		'to' => 'Type at least 2 characters and pick a result, or press Enter to add an exact user ID.',
	],
	'policy_user_picker_reintroduces_allow_direct_entry' => [
		'file' => $appRoot . '/js/settings.js',
		'regex' => true,
		'from' => '/(wireSearch\(\'dc-policy-user-search\', \'dc-policy-user-results\', fetchUsers, \(item\) => \{\s*'
			. 'state\.allowedUsers = dedupeById\(\[\.\.\.state\.allowedUsers, item\]\);\s*'
			. 'renderAll\(\);\s*'
			. 'recomputeDirty\(\);\s*'
			. '\})\);/',
		'to' => '$1, { allowDirectEntry: true });',
	],
	'raw_uid_submit_guards_removed' => [
		'file' => $appRoot . '/js/settings.js',
		// Drops the "reject a blank/unpicked uid before posting" guard on both the
		// company-member and planner-scope submit handlers in one pass.
		'from' => 'if (!userId) {',
		'to' => 'if (false) {',
	],
];

echo "== baseline DirectoryPickerContractTest ==\n";
$baseline = run_unit_tests($appRoot, $phpunit);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$failedToKill = [];
foreach ($mutations as $name => $spec) {
	echo "\n== mutation: {$name} ==\n";
	$source = $spec['file'];
	$backup = $source . '.mutation-bak';
	if (!is_file($source)) {
		fwrite(STDERR, "Missing source file for {$name}: {$source}\n");
		$failedToKill[] = $name . ' (missing source)';
		continue;
	}
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read source for {$name}\n");
		$failedToKill[] = $name . ' (read failed)';
		continue;
	}
	$result = apply_mutation($original, $spec);
	if ($result === null) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	[$mutated, $count] = $result;
	file_put_contents($backup, $original);
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated source for {$name}\n");
		$failedToKill[] = $name . ' (write failed)';
		restore($source, $backup);
		continue;
	}
	echo "  applied {$count} replacement(s) in " . basename($source) . "\n";
	$code = run_unit_tests($appRoot, $phpunit);
	restore($source, $backup);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "\nMutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll directory-picker mutations killed.\n";
exit(0);

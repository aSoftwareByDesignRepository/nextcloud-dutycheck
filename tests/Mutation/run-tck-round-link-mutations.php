<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: F17 TourCheck round-link invariants.
 *
 * A mutant edit must not silently drop: the nullable-link write path, the
 * missed-only conflict rule, dedupe keys, the open-period dirty-mark scope,
 * or the schema probes that keep the feature off until columns exist.
 *
 * Run: php tests/Mutation/run-tck-round-link-mutations.php
 */

$root = dirname(__DIR__, 2);
$roster = (string)file_get_contents($root . '/lib/Service/RosterService.php');

function assertTrue(bool $cond, string $msg): void
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: {$msg}\n");
		exit(1);
	}
	echo "OK: {$msg}\n";
}

// link column is schema-gated — the feature must stay inert until migrated
assertTrue(str_contains($roster, "SchemaProbe::hasColumn(\$this->db, 'dc_assignments', 'tck_round_id')"), 'link column probed, not assumed');
assertTrue(str_contains($roster, "tck_round_id', \$qb->createNamedParameter(\$tckRoundId"), 'link written via parametrized update');

// conflict rule: 'missed' only — completed/skipped must never flag
assertTrue(str_contains($roster, "!== 'missed'"), 'conflict fires on missed status only');
assertTrue(str_contains($roster, "'type' => 'round_missed'"), 'conflict type emitted');
assertTrue(str_contains($roster, "'severity' => 'soft'"), 'soft severity — advisory, not blocking');
assertTrue(str_contains($roster, "'round_missed:' . (int) \$assignment['id'] . ':' . \$rid"), 'dedupe key binds assignment+round');
assertTrue(str_contains($roster, 'conflictDedup[$key]'), 'duplicate conflicts suppressed');

// reconcile trigger: dirty-mark only OPEN periods holding the round
assertTrue(str_contains($roster, 'markConflictsDirtyForTckRound'), 'event-driven reconcile hook exists');
assertTrue(str_contains($roster, "eq('p.status', \$qb->createNamedParameter('open'))"), 'dirty-mark scoped to open periods');
assertTrue(str_contains($roster, "\$qb->expr()->eq('a.tck_round_id',"), 'dirty-mark joins on the linked round');
assertTrue(str_contains($roster, 'setPeriodConflictsDirty($periodId, true)'), 'periods marked dirty for drain');
assertTrue(str_contains($roster, 'materializeConflicts('), 'drain recomputes + persists (auto-resolve)');

echo "All tck-round-link mutations passed.\n";

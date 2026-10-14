<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen dc_at_absence_mirror.type 32 → 64.
 *
 * ArbeitszeitCheck 1.9 widened `at_absences.type` to VARCHAR(64) for
 * admin-defined custom absence types (`custom_` + up to 56-char slug).
 * A longer code would fail the mirror INSERT on strict-mode MariaDB and
 * kill the reconcile batch. The mirror must store the full code so the
 * type mapper and audit trail see the exact peer value.
 */
class Version1020Date20261016120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_at_absence_mirror')) {
			$t = $schema->getTable('dc_at_absence_mirror');
			if ($t->hasColumn('type')) {
				$t->changeColumn('type', ['length' => 64, 'notnull' => true]);
			}
		}

		return $schema;
	}
}

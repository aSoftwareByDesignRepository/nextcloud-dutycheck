<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Integrity close-out:
 * - Optimistic locking version on assignments (CAS for concurrent updates).
 * - Frozen conflict thresholds JSON on periods (live policy must not rewrite open/published history).
 * - Optional min_headcount on shift templates (coverage conflicts).
 */
class Version1014Date20260727120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$t = $schema->getTable('dc_assignments');
			if (!$t->hasColumn('version')) {
				$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}

		if ($schema->hasTable('dc_periods')) {
			$t = $schema->getTable('dc_periods');
			if (!$t->hasColumn('conflict_thresholds_json')) {
				$t->addColumn('conflict_thresholds_json', Types::TEXT, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('dc_shift_templates')) {
			$t = $schema->getTable('dc_shift_templates');
			if (!$t->hasColumn('min_headcount')) {
				$t->addColumn('min_headcount', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}

		return $schema;
	}
}

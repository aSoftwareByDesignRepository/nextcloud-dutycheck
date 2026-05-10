<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1003Date20260508183000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_absences')) {
			$t = $schema->getTable('dc_absences');
			if (!$t->hasColumn('review_reason')) {
				$t->addColumn('review_reason', 'string', ['length' => 512, 'notnull' => false]);
			}
			if (!$t->hasColumn('reviewed_by')) {
				$t->addColumn('reviewed_by', 'string', ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasColumn('reviewed_at')) {
				$t->addColumn('reviewed_at', 'datetime', ['notnull' => false]);
			}
			if (!$t->hasIndex('dc_abs_review_idx')) {
				$t->addIndex(['status', 'reviewed_at'], 'dc_abs_review_idx');
			}
		}

		if ($schema->hasTable('dc_periods')) {
			$t = $schema->getTable('dc_periods');
			if (!$t->hasColumn('reopened_at')) {
				$t->addColumn('reopened_at', 'datetime', ['notnull' => false]);
			}
			if (!$t->hasColumn('reopened_by')) {
				$t->addColumn('reopened_by', 'string', ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasColumn('reopen_reason')) {
				$t->addColumn('reopen_reason', 'string', ['length' => 512, 'notnull' => false]);
			}
		}

		return $schema;
	}
}

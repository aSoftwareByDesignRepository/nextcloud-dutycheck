<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * F17: durable shift↔round link — `dc_assignments.tck_round_id` is a soft
 * reference to a TourCheck round. Non-unique on purpose: two consecutive
 * shifts may legitimately cover the same round (e.g. a double patrol).
 * Soft ref: TourCheck absent/uninstalled must never break DutyCheck.
 */
class Version1022Date20261020130000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$table = $schema->getTable('dc_assignments');
			if (!$table->hasColumn('tck_round_id')) {
				$table->addColumn('tck_round_id', 'bigint', ['notnull' => false]);
			}
			if (!$table->hasIndex('dc_asg_tck_round_idx')) {
				$table->addIndex(['tck_round_id'], 'dc_asg_tck_round_idx');
			}
		}

		return $schema;
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wave C1 complete — stamp company_id on secondary catalog/marketplace tables.
 * Existing rows default to company_id=1 (Default). Legacy single-company stays unrestricted.
 */
class Version1013Date20260726193000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$targets = [
			'dc_shift_templates' => 'dc_tpl_co_idx',
			'dc_qualifications' => 'dc_qual_co_idx',
			'dc_open_shifts' => 'dc_os_co_idx',
			'dc_swap_requests' => 'dc_sw_co_idx',
			'dc_absences' => 'dc_abs_co_idx',
		];

		foreach ($targets as $table => $idx) {
			if (!$schema->hasTable($table)) {
				continue;
			}
			$t = $schema->getTable($table);
			if (!$t->hasColumn('company_id')) {
				$t->addColumn('company_id', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			}
			if (!$t->hasIndex($idx)) {
				$t->addIndex(['company_id'], $idx);
			}
		}

		return $schema;
	}
}

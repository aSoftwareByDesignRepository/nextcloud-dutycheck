<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Open-period conflict rematerialize budget + dirty catch-up.
 *
 * Adds dc_periods.conflicts_dirty so Save/Apply can rematerialize a sync
 * budget immediately and leave the rest for BackgroundJob / lazy roster GET.
 */
class Version1019Date20260921160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_periods')) {
			$t = $schema->getTable('dc_periods');
			if (!$t->hasColumn('conflicts_dirty')) {
				// Oracle-safe: never notnull boolean; SMALLINT 0/1.
				$t->addColumn('conflicts_dirty', Types::SMALLINT, [
					'notnull' => false,
					'default' => 0,
				]);
			}
			if (!$t->hasIndex('dc_per_cdirty_idx')) {
				$t->addIndex(['status', 'conflicts_dirty'], 'dc_per_cdirty_idx');
			}
		}

		return $schema;
	}
}

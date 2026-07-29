<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Optional T3 PII columns on the AT absence mirror (populated only when
 * integration_arbeitszeitcheck_include_pii = 1).
 */
class Version1015Date20260727150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_at_absence_mirror')) {
			$t = $schema->getTable('dc_at_absence_mirror');
			if (!$t->hasColumn('reason')) {
				$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			}
			if (!$t->hasColumn('approver_comment')) {
				$t->addColumn('approver_comment', Types::TEXT, ['notnull' => false]);
			}
		}

		return $schema;
	}
}

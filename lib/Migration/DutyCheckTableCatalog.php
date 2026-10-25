<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Repair\SchemaTableCatalog;

/**
 * Canonical list of DutyCheck-owned tables (kept in sync with {@see UninstallDropTables}).
 */
final class DutyCheckTableCatalog
{
	public const APP_ID = Application::APP_ID;

	/** @var list<string> */
	public const TABLES = SchemaTableCatalog::EXPECTED_TABLES;
}

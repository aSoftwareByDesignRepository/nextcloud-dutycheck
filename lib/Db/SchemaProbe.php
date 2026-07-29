<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Db;

use OCP\IDBConnection;
use Throwable;

/**
 * Portable column/table probes for Nextcloud ConnectionAdapter (no columnExists()).
 */
final class SchemaProbe
{
	/** @var array<string, bool> */
	private static array $columnCache = [];

	/** @var array<string, bool> */
	private static array $indexCache = [];

	public static function hasColumn(IDBConnection $db, string $table, string $column): bool
	{
		$key = $table . '.' . $column;
		if (array_key_exists($key, self::$columnCache)) {
			return self::$columnCache[$key];
		}
		try {
			if (method_exists($db, 'columnExists')) {
				/** @var callable $fn */
				$fn = [$db, 'columnExists'];
				self::$columnCache[$key] = (bool) $fn($table, $column);
				return self::$columnCache[$key];
			}
			if (method_exists($db, 'getInner')) {
				$inner = $db->getInner();
				$schema = new \OC\DB\SchemaWrapper($inner);
				self::$columnCache[$key] = $schema->hasTable($table)
					&& $schema->getTable($table)->hasColumn($column);
				return self::$columnCache[$key];
			}
			$qb = $db->getQueryBuilder();
			$qb->select($column)->from($table)->setMaxResults(1);
			$qb->executeQuery()->closeCursor();
			self::$columnCache[$key] = true;
		} catch (Throwable) {
			self::$columnCache[$key] = false;
		}
		return self::$columnCache[$key];
	}

	public static function hasIndex(IDBConnection $db, string $table, string $indexName): bool
	{
		$key = $table . '#' . $indexName;
		if (array_key_exists($key, self::$indexCache)) {
			return self::$indexCache[$key];
		}
		try {
			if (!method_exists($db, 'getInner')) {
				self::$indexCache[$key] = false;
				return false;
			}
			$inner = $db->getInner();
			$schema = new \OC\DB\SchemaWrapper($inner);
			self::$indexCache[$key] = $schema->hasTable($table)
				&& $schema->getTable($table)->hasIndex($indexName);
		} catch (Throwable) {
			self::$indexCache[$key] = false;
		}
		return self::$indexCache[$key];
	}

	/** @internal tests */
	public static function resetCache(): void
	{
		self::$columnCache = [];
		self::$indexCache = [];
	}
}

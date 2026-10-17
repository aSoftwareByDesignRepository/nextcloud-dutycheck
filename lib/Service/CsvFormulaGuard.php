<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

/**
 * Neutralizes spreadsheet-formula injection in CSV-export cells.
 *
 * A cell whose first character is = + - @ TAB CR or VT is interpreted as a
 * live formula by Excel/LibreOffice, so customer-controlled values (names,
 * notes, ids) can inject formulas into exports. Prefixing a literal
 * apostrophe marks the cell as text; RFC 4180 quoting alone does not.
 */
final class CsvFormulaGuard
{
	public static function neutralize(string $cell): string
	{
		if ($cell !== '' && preg_match('/^[=+\-@\t\r\x0B]/', $cell) === 1) {
			return "'" . $cell;
		}
		return $cell;
	}
}

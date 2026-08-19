<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Roster UI virtualizes DOM rows; GET still returns the full assignment list (SF-06).
 */
final class RosterVirtualizationContractTest extends TestCase
{
	private function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private function read(string $rel): string
	{
		$src = (string) file_get_contents($this->appRoot() . '/' . $rel);
		self::assertNotSame('', $src, $rel . ' must not be empty');
		return $src;
	}

	public function testVirtualWindowHelperIsPureAndFailClosed(): void
	{
		$src = $this->read('js/common/virtual-window.js');
		self::assertStringContainsString('function visibleRange', $src);
		self::assertStringContainsString('first - overscan', $src);
		self::assertStringContainsString('Math.floor(scrollTop / rowHeight)', $src);
		self::assertStringContainsString('UNSIZED_WINDOW_ROWS', $src);
		self::assertStringContainsString('paintAll', $src);
		self::assertStringContainsString('windowCaption', $src);
		self::assertStringContainsString('function pageStride', $src);
		self::assertStringContainsString('scrollOffsetToRevealIndex', $src);
		self::assertStringNotContainsString('innerHTML', $src);
		self::assertStringNotContainsString('document.', $src);
	}

	public function testPageControllerLoadsVirtualWindowBeforeRoster(): void
	{
		$src = $this->read('lib/Controller/PageController.php');
		$vw = strpos($src, "addScript(Application::APP_ID, 'common/virtual-window')");
		$script = strpos($src, 'Util::addScript(Application::APP_ID, $script)');
		self::assertNotFalse($vw);
		self::assertNotFalse($script);
		self::assertTrue($vw < $script);
		self::assertMatchesRegularExpression(
			"/if \(\\\$template === 'roster'\) \{\s*Util::addScript\(Application::APP_ID, 'common\/virtual-window'\);/s",
			$src,
		);
	}

	public function testRosterJsWindowsRowsAndKeepsKeyboardReach(): void
	{
		$src = $this->read('js/roster.js');
		self::assertStringContainsString('DutyCheckVirtualWindow', $src);
		self::assertStringContainsString('VIRTUAL_FALLBACK', $src);
		self::assertStringContainsString('cellSelectionKey', $src);
		self::assertStringContainsString('employees.slice(range.start, range.end)', $src);
		self::assertStringContainsString('assignments.slice(range.start, range.end)', $src);
		self::assertStringContainsString("window.addEventListener('beforeprint', () => setRosterPaintAll(true));", $src);
		self::assertStringContainsString("window.addEventListener('afterprint', () => setRosterPaintAll(false));", $src);
		self::assertStringContainsString('revealAssignmentRow', $src);
		self::assertStringContainsString("setAttribute('role', 'grid')", $src);
		self::assertStringContainsString("setAttribute('role', 'status')", $src);
		self::assertStringContainsString("inline: 'nearest'", $src);
		self::assertStringContainsString("'PageUp', 'PageDown'", $src);
		self::assertStringContainsString('pageStride', $src);
		self::assertStringContainsString('applyRosterViewChrome', $src);
		self::assertStringContainsString("window.matchMedia('print')", $src);
		self::assertDoesNotMatchRegularExpression("/revealAssignmentRow[\\s\\S]{0,900}behavior:\\s*'smooth'/", $src);
		self::assertStringNotContainsString("throw new Error('DutyCheck virtual window failed to load')", $src);
		self::assertStringNotContainsString('dc-roster-grid__table', $src);
		self::assertStringContainsString('ensureGridFocusVisible', $src);
		self::assertStringContainsString("prefers-reduced-motion: reduce", $src);
		self::assertStringNotContainsString('employees.forEach((emp, rowIdx)', $src);
	}

	public function testTemplateAndCssKeepGrannyStatusAndPrintAll(): void
	{
		$tpl = $this->read('templates/roster.php');
		$css = $this->read('css/app.css');
		self::assertStringContainsString('id="dc-roster-grid"', $tpl);
		self::assertStringNotContainsString('role="grid"', $tpl);
		self::assertStringContainsString('id="dc-roster-grid-scroller"', $tpl);
		self::assertMatchesRegularExpression(
			'/id="dc-roster-grid-scroller"[^>]*tabindex="0"/',
			$tpl,
			'Grid scroller must be a keyboard tab stop (WCAG 2.1.1 scrollable region)',
		);
		self::assertMatchesRegularExpression(
			'/id="dc-assignments-table-wrap"[^>]*tabindex="0"/',
			$tpl,
			'List scroller must be a keyboard tab stop',
		);
		self::assertStringContainsString('id="dc-roster-grid-status"', $tpl);
		self::assertStringContainsString('id="dc-roster-list-status"', $tpl);
		self::assertStringContainsString('aria-hidden="true"', $tpl);
		self::assertStringContainsString('id="dc-roster-list-panel"', $tpl);
		self::assertStringContainsString('only the rows on screen are drawn', $tpl);
		self::assertMatchesRegularExpression('/\.dc-roster-grid-scroller,[\s\S]{0,80}overflow:\s*auto/s', $css);
		self::assertMatchesRegularExpression('/\.dc-roster-virtual-status[\s\S]{0,220}color:\s*var\(--color-main-text\)/s', $css);
		self::assertMatchesRegularExpression(
			'/\.dc-roster-grid-scroller,[\s\S]{0,60}\.dc-roster-list-scroller \{\s*overflow:\s*visible\s*!important;\s*max-height:\s*none\s*!important/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/#dc-assignments-table-wrap\.dc-roster-list-scroller \.dc-table tbody tr\.dc-virtual-spacer \{\s*display:\s*table-row/s',
			$css,
		);
		self::assertStringContainsString('min-height: var(--dc-touch)', $css);
	}

	public function testListAssignmentsStaysUnpaginated(): void
	{
		$src = $this->read('lib/Service/RosterService.php');
		$start = strpos($src, 'private function listAssignments');
		self::assertNotFalse($start);
		$docStart = strrpos(substr($src, 0, $start), '/**');
		self::assertNotFalse($docStart);
		$end = strpos($src, 'private function assignmentHasStatusColumn', $start);
		self::assertNotFalse($end);
		$block = substr($src, $docStart, $end - $docStart);
		self::assertStringContainsString('Never paginated', $block);
		self::assertStringNotContainsString('setMaxResults', $block);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

final class IconCatalogTest extends TestCase
{
	public function testRenderUnknownReturnsEmptyString(): void
	{
		$this->assertSame('', IconCatalog::render('does-not-exist'));
	}

	public function testHasReflectsCatalogMembership(): void
	{
		$this->assertTrue(IconCatalog::has('edit'));
		$this->assertFalse(IconCatalog::has('square-pen'));
		$this->assertFalse(IconCatalog::has('does-not-exist'));
	}

	/**
	 * icon_catalog_candidate_gap: the synced feedback-nav-footer partial
	 * resolves each decorative glyph through a candidate list guarded by
	 * IconCatalog::has(). Without has() the method_exists guard is skipped
	 * and the first candidate goes straight to render() — a catalog whose
	 * render aliases unknown names would paint the wrong glyph, and one
	 * returning '' would silently drop icons the chain could have resolved.
	 * Every call-site chain must reach at least one real catalog member.
	 */
	public function testNavFooterIconChainsResolveToCatalogMembers(): void
	{
		$tpl = (string)file_get_contents(
			dirname(__DIR__, 3) . '/templates/parts/feedback-nav-footer.php'
		);
		self::assertStringContainsString("method_exists(\$catalog, 'has')", $tpl);
		preg_match_all(
			'/\$sbdFeedbackIcon\(\s*\$prefix\s*,\s*\[([^\]]+)\]\)/',
			$tpl,
			$matches
		);
		self::assertNotEmpty($matches[1], 'expected icon candidate call sites');
		foreach ($matches[1] as $listSrc) {
			preg_match_all("/'([a-z0-9_-]+)'/i", $listSrc, $cm);
			$resolved = array_filter($cm[1], [IconCatalog::class, 'has']);
			self::assertNotEmpty(
				$resolved,
				'footer icon chain renders no glyph — candidates: ' . trim($listSrc)
			);
		}
	}
}

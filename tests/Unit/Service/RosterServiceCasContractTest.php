<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source-level contract for optimistic locking on period/absence transitions.
 * Complements behavioural tests by pinning the CAS predicates that prevent
 * double-publish / double-approve races.
 */
final class RosterServiceCasContractTest extends TestCase
{
	private string $source;

	protected function setUp(): void
	{
		parent::setUp();
		$this->source = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		self::assertNotSame('', $this->source);
	}

	public function testPeriodTransitionCasOnCurrentStatus(): void
	{
		self::assertStringContainsString(
			"->andWhere(\$qb->expr()->eq('status', \$qb->createNamedParameter(\$current)));",
			$this->source,
		);
		self::assertStringContainsString("PERIOD_STATUS_CONFLICT", $this->source);
		self::assertMatchesRegularExpression(
			'/\$affected = \$qb->executeStatement\(\);\s+if \(\$affected !== 1\) \{\s+throw new \\\\InvalidArgumentException\(\'PERIOD_STATUS_CONFLICT\'\);/s',
			$this->source,
		);
	}

	public function testAbsenceTransitionCasOnCurrentStatus(): void
	{
		self::assertStringContainsString(
			"->andWhere(\$qb->expr()->eq('status', \$qb->createNamedParameter((string) \$current['status'])));",
			$this->source,
		);
		self::assertStringContainsString('ABSENCE_STATUS_CONFLICT', $this->source);
		self::assertMatchesRegularExpression(
			'/\$affected = \$qb->executeStatement\(\);\s+if \(\$affected !== 1\) \{\s+throw new \\\\InvalidArgumentException\(\'ABSENCE_STATUS_CONFLICT\'\);/s',
			$this->source,
		);
	}
}

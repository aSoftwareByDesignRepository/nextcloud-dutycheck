<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Suite legacy isolation (CHECK-SUITE L1): DutyCheck must not hard-require
 * CustomerCheck / InvoicingCheck / InventoryCheck / MaintenanceCheck.
 * Optional MN on-duty hook must remain soft (flag + capability).
 *
 * @see planning/check-productivity-suite/LEGACY-SAFETY.md
 */
final class SuiteLegacyIsolationContractTest extends TestCase
{
	private const FORBIDDEN_HARD_DEPS = [
		'customercheck',
		'invoicecheck',
		'inventorycheck',
		'maintenancecheck',
	];

	private string $infoXml;
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
		$path = $this->root . '/appinfo/info.xml';
		$this->assertFileExists($path);
		$this->infoXml = (string)file_get_contents($path);
		$this->assertNotSame('', trim($this->infoXml));
	}

	public function testInfoXmlDeclaresDutycheckId(): void
	{
		$this->assertMatchesRegularExpression('/<id>\s*dutycheck\s*<\/id>/', $this->infoXml);
	}

	public function testHardDependenciesDoNotRequireSuiteSpineApps(): void
	{
		$hardBlock = $this->dependenciesInnerXml('dependencies');
		foreach (self::FORBIDDEN_HARD_DEPS as $appId) {
			$this->assertDoesNotMatchRegularExpression(
				'/<app\b[^>]*>\s*' . preg_quote($appId, '/') . '\s*<\/app>/i',
				$hardBlock,
				"Hard <dependencies> must not require {$appId} (suite L1)"
			);
		}
	}

	public function testMaintenanceCheckOnDutyReaderDefaultsToIneffectiveWithoutFlag(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Integration/MaintenanceCheckOnDutyReader.php');
		$this->assertStringContainsString("CONFIG_ENABLED = 'mc_onduty_hook_enabled'", $src);
		$this->assertStringContainsString("getAppValue(Application::APP_ID, self::CONFIG_ENABLED, '0')", $src);
		$this->assertStringContainsString("isEnabledForUser('maintenancecheck')", $src);
		$this->assertStringNotContainsString('use OCA\\MaintenanceCheck\\', $src);
	}

	public function testPhpSourcesDoNotStaticallyUseForbiddenSuiteNamespaces(): void
	{
		$hits = $this->scanPhpForForbiddenUse($this->root . '/lib', [
			'OCA\\CustomerCheck\\',
			'OCA\\InvoiceCheck\\',
			'OCA\\InventoryCheck\\',
			'OCA\\MaintenanceCheck\\',
		]);
		$this->assertSame([], $hits, implode("\n", $hits));
	}

	/**
	 * @param list<string> $forbidden
	 * @return list<string>
	 */
	private function scanPhpForForbiddenUse(string $root, array $forbidden): array
	{
		$hits = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);
		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$contents = (string)file_get_contents($file->getPathname());
			foreach ($forbidden as $ns) {
				if (str_contains($contents, 'use ' . $ns) || str_contains($contents, 'new ' . $ns)) {
					$hits[] = $file->getPathname() . ' → ' . $ns;
				}
			}
		}
		return $hits;
	}

	private function dependenciesInnerXml(string $tag): string
	{
		if (!preg_match(
			'/' . preg_quote('<' . $tag . '>', '/') . '(.*?)' . preg_quote('</' . $tag . '>', '/') . '/is',
			$this->infoXml,
			$m
		)) {
			return '';
		}
		return (string)$m[1];
	}
}

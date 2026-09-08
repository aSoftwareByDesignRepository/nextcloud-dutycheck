<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Exception\UpgradeBackupException;
use OCA\DutyCheck\Migration\DutyCheckTableCatalog;
use OCA\DutyCheck\Service\UpgradeBackupCatalog;
use OCA\DutyCheck\Service\UpgradeBackupIntegrity;
use PHPUnit\Framework\TestCase;

final class UpgradeBackupIntegrityTest extends TestCase
{
	public function testAssertSnapshotIdRejectsTraversal(): void
	{
		$this->expectException(UpgradeBackupException::class);
		UpgradeBackupIntegrity::assertSnapshotId('../etc/passwd');
	}

	public function testAssertSnapshotIdAcceptsValidId(): void
	{
		$id = '20260624T120000Z-deadbeef';
		UpgradeBackupIntegrity::assertSnapshotId($id);
		self::assertMatchesRegularExpression(UpgradeBackupIntegrity::SNAPSHOT_ID_PATTERN, $id);
	}

	public function testValidateManifestAcceptsCompleteDutyCheckSnapshot(): void
	{
		$tables = ['dc_employees' => ['checksum' => 'abc', 'rowCount' => 0]];
		$integrity = hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR));
		UpgradeBackupIntegrity::validateManifest(
			[
				'format' => UpgradeBackupCatalog::FORMAT_VERSION,
				'appId' => UpgradeBackupCatalog::APP_ID,
				'id' => '20260624T120000Z-deadbeef',
				'complete' => true,
				'integrity' => $integrity,
				'tables' => $tables,
			],
			'20260624T120000Z-deadbeef',
			$tables,
		);
		self::assertTrue(UpgradeBackupCatalog::isBackupTable('dc_employees'));
		self::assertFalse(UpgradeBackupCatalog::isBackupTable('pc_projects'));
	}

	public function testValidateManifestRejectsIncompleteSnapshot(): void
	{
		$this->expectException(UpgradeBackupException::class);
		UpgradeBackupIntegrity::validateManifest(
			[
				'format' => UpgradeBackupCatalog::FORMAT_VERSION,
				'appId' => UpgradeBackupCatalog::APP_ID,
				'id' => '20260624T120000Z-deadbeef',
				'complete' => false,
				'integrity' => 'abc',
				'tables' => ['dc_employees' => ['checksum' => 'abc', 'rowCount' => 0]],
			],
			'20260624T120000Z-deadbeef',
			['dc_employees' => ['checksum' => 'abc', 'rowCount' => 0]],
		);
	}

	public function testValidateManifestRejectsTamperedIntegrityHash(): void
	{
		$tables = ['dc_employees' => ['checksum' => 'abc', 'rowCount' => 0]];
		$this->expectException(UpgradeBackupException::class);
		UpgradeBackupIntegrity::validateManifest(
			[
				'format' => UpgradeBackupCatalog::FORMAT_VERSION,
				'appId' => UpgradeBackupCatalog::APP_ID,
				'id' => '20260624T120000Z-deadbeef',
				'complete' => true,
				'integrity' => 'tampered',
				'tables' => $tables,
			],
			'20260624T120000Z-deadbeef',
			$tables,
		);
	}

	public function testValidateManifestRequiresIntegrityHash(): void
	{
		$tables = ['dc_employees' => ['checksum' => 'abc', 'rowCount' => 0]];
		$this->expectException(UpgradeBackupException::class);
		UpgradeBackupIntegrity::validateManifest(
			[
				'format' => UpgradeBackupCatalog::FORMAT_VERSION,
				'appId' => UpgradeBackupCatalog::APP_ID,
				'id' => '20260624T120000Z-deadbeef',
				'complete' => true,
				'tables' => $tables,
			],
			'20260624T120000Z-deadbeef',
			$tables,
		);
	}

	public function testAssertTablePayloadDetectsChecksumMismatch(): void
	{
		$content = json_encode([['id' => 1]], JSON_THROW_ON_ERROR);
		$this->expectException(UpgradeBackupException::class);
		UpgradeBackupIntegrity::assertTablePayload('dc_employees', $content, [
			'checksum' => 'deadbeef',
			'rowCount' => 1,
		]);
	}

	public function testIsAllowedColumnRejectsInvalidNames(): void
	{
		self::assertFalse(UpgradeBackupIntegrity::isAllowedColumn('id;drop'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedColumn('employee_id'));
	}

	public function testIsAllowedConfigKeyRejectsInvalidKeys(): void
	{
		self::assertFalse(UpgradeBackupIntegrity::isAllowedConfigKey('../evil'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedConfigKey('installed_version'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedConfigKey('rate_limit:category_write:admin'));
	}

	public function testIsAllowedAppDataNameRejectsTraversal(): void
	{
		self::assertFalse(UpgradeBackupIntegrity::isAllowedAppDataName('../evil'));
		self::assertFalse(UpgradeBackupIntegrity::isAllowedAppDataName('..'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedAppDataName('roster_files'));
	}

	public function testIsAllowedTableNameRejectsInvalidNames(): void
	{
		self::assertFalse(UpgradeBackupIntegrity::isAllowedTableName('dc;drop'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedTableName('dc_employees'));
		self::assertTrue(UpgradeBackupIntegrity::isAllowedTableName('pc_projects')); // regex-only; catalog gate rejects foreign apps
		self::assertFalse(UpgradeBackupCatalog::isBackupTable('pc_projects'));
	}

	public function testAssertAppDataFolderNameRejectsInvalid(): void
	{
		$this->expectException(UpgradeBackupException::class);
		UpgradeBackupIntegrity::assertAppDataFolderName('../snapshots');
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Request-scoped memoization only (PHP-FPM): no cross-request permission cache.
 * Employee link + access in one HTTP request may see a stale linked flag — accepted.
 */
final class AccessControlRequestScopeContractTest extends TestCase
{
	public function testAccessControlCachesArePrivateInstanceProperties(): void
	{
		$ref = new ReflectionClass(AccessControlService::class);
		foreach (['globalRoleCache', 'linkedEmployeeCache', 'systemAdminCache', 'groupMembershipCache', 'jsonIdListCache'] as $prop) {
			$p = $ref->getProperty($prop);
			self::assertTrue($p->isPrivate(), $prop . ' must stay private');
			self::assertFalse($p->isStatic(), $prop . ' must not be static (request scope)');
		}
	}

	public function testCompanyAndIntegrationCachesAreRequestScoped(): void
	{
		foreach ([CompanyService::class => ['multiCompanyActiveCache', 'schemaReadyCache', 'companyIdsByUser'], ArbeitszeitCheckIntegrationService::class => ['peerInstalledCache']] as $class => $props) {
			$ref = new ReflectionClass($class);
			foreach ($props as $prop) {
				$p = $ref->getProperty($prop);
				self::assertFalse($p->isStatic(), $class . '::' . $prop . ' must not be static');
			}
		}
	}

	public function testNoCrossRequestCacheBackendsInServiceLayer(): void
	{
		$root = dirname(__DIR__, 3) . '/lib/Service';
		$forbidden = ['Redis', 'APCu', 'Memcached', 'opcache_get_status'];
		$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($iter as $file) {
			if (!$file->isFile() || !str_ends_with($file->getPathname(), '.php')) {
				continue;
			}
			$content = (string) file_get_contents($file->getPathname());
			foreach ($forbidden as $needle) {
				self::assertStringNotContainsString($needle, $content, basename($file->getPathname()) . ' must not use ' . $needle);
			}
		}
	}

	public function testForgetUserAuthCachesClearsLinkedEmployeeFlag(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php');
		self::assertStringContainsString('unset($this->systemAdminCache[$userId], $this->linkedEmployeeCache[$userId], $this->globalRoleCache[$userId])', $src);
	}

	public function testEmployeeCatalogUpdateDoesNotInvalidateAccessCacheSameRequest(): void
	{
		$roster = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RosterService.php');
		$start = strpos($roster, 'public function updateEmployee');
		self::assertNotFalse($start);
		$fn = substr($roster, $start, strpos($roster, 'public function listLocationCatalog', $start) - $start);
		self::assertStringContainsString('linked_user_id', $fn);
		self::assertStringNotContainsString('forgetUserAuthCaches', $fn);
		self::assertStringNotContainsString('AccessControlService', $fn);
	}

	public function testHasActiveLinkedEmployeeIsMemoizedPerRequest(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php');
		self::assertMatchesRegularExpression(
			'/function hasActiveLinkedEmployee[\s\S]{0,600}?linkedEmployeeCache\[\$userId\]/',
			$src,
		);
	}
}

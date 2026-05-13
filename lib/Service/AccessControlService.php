<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

class AccessControlService
{
	public const KEY_APP_ADMINS = 'app_admin_user_ids';
	public const KEY_ACCESS_RESTRICTION = 'access_restriction_enabled';
	public const KEY_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const KEY_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';

	public const ROLE_ADMIN = 'admin';
	public const ROLE_PLANNER = 'planner';
	public const ROLE_EMPLOYEE = 'employee';

	public const DENIAL_RESTRICTION = 'restriction';
	public const DENIAL_NO_MEMBERSHIP = 'no_membership';
	/** Global role is employee but no active dc_employees row links this user */
	public const DENIAL_EMPLOYEE_NOT_LINKED = 'employee_not_linked';
	/** Logged-in app user lacks the role needed for this page or action */
	public const DENIAL_INSUFFICIENT_ROLE = 'insufficient_role';

	/** @var array<string, bool> */
	private array $groupMembershipCache = [];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('not_authenticated');
		}
		return $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	public function isAppAdmin(string $userId): bool
	{
		return $this->isSystemAdmin($userId) || in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);
	}

	/**
	 * @return list<string>
	 */
	public function getAppAdminIds(): array
	{
		return $this->getJsonIdList(self::KEY_APP_ADMINS);
	}

	/**
	 * @return list<string>
	 */
	public function getAllowedUserIds(): array
	{
		return $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS);
	}

	/**
	 * @return list<string>
	 */
	public function getAllowedGroupIds(): array
	{
		return $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS);
	}

	public function isAccessRestrictionEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, '0') === '1';
	}

	/**
	 * Whether the user may open DutyCheck at all (navigation entry, routes, APIs except public iCal).
	 *
	 * - Nextcloud system administrators and DutyCheck app administrators: always allowed.
	 * - Directory restriction (if enabled): must match allow-listed users or groups (admins still bypass).
	 * - An active `dc_employees` row with `linked_user_id` set to this user: allowed for self-service
	 *   (my roster, my absences, iCal) even when no `dc_user_roles` row exists yet. The catalog link is
	 *   treated as the organisation’s explicit invitation to that account.
	 * - Duty roles {@see self::ROLE_PLANNER} and {@see self::ROLE_ADMIN}: allowed without a link.
	 * - Duty role {@see self::ROLE_EMPLOYEE}: allowed only when linked as above (same as self-service APIs).
	 */
	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {
			return false;
		}
		if ($this->hasActiveLinkedEmployee($userId)) {
			return true;
		}
		$role = $this->lookupGlobalRole($userId);
		if ($role === null) {
			return false;
		}
		if ($role === self::ROLE_EMPLOYEE) {
			return false;
		}
		return true;
	}

	public function denialReasonWhenCannotUseApp(string $userId): string
	{
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {
			return self::DENIAL_RESTRICTION;
		}
		if ($this->lookupGlobalRole($userId) === self::ROLE_EMPLOYEE && !$this->hasActiveLinkedEmployee($userId)) {
			return self::DENIAL_EMPLOYEE_NOT_LINKED;
		}
		return self::DENIAL_NO_MEMBERSHIP;
	}

	public function isPlannerOrAdmin(string $userId): bool
	{
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		$role = $this->lookupGlobalRole($userId);
		return in_array($role, [self::ROLE_PLANNER, self::ROLE_ADMIN], true);
	}

	public function requirePlannerOrAdmin(string $userId): void
	{
		if (!$this->isPlannerOrAdmin($userId)) {
			throw new AppAccessDeniedException(self::DENIAL_INSUFFICIENT_ROLE);
		}
	}

	public function requireAppAdmin(string $userId): void
	{
		if (!$this->isAppAdmin($userId)) {
			throw new AppAccessDeniedException(self::DENIAL_INSUFFICIENT_ROLE);
		}
	}

	/**
	 * @return array{
	 *   appAdminUserIds:list<string>,
	 *   accessRestrictionEnabled:bool,
	 *   allowedUserIds:list<string>,
	 *   allowedGroupIds:list<string>
	 * }
	 */
	public function appPolicy(): array
	{
		return [
			'appAdminUserIds' => $this->getAppAdminIds(),
			'accessRestrictionEnabled' => $this->isAccessRestrictionEnabled(),
			'allowedUserIds' => $this->getAllowedUserIds(),
			'allowedGroupIds' => $this->getAllowedGroupIds(),
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function saveAppPolicy(array $payload): array
	{
		$appAdmins = $this->normalizeUniqueIdList($payload['appAdminUserIds'] ?? []);
		$allowedUsers = $this->normalizeUniqueIdList($payload['allowedUserIds'] ?? []);
		$allowedGroups = $this->normalizeUniqueIdList($payload['allowedGroupIds'] ?? []);
		$restriction = (bool) ($payload['accessRestrictionEnabled'] ?? false);

		foreach ($appAdmins as $uid) {
			$this->assertKnownUser($uid, 'INVALID_APP_ADMIN_USER');
		}
		foreach ($allowedUsers as $uid) {
			$this->assertKnownUser($uid, 'INVALID_ALLOWED_USER');
		}
		foreach ($allowedGroups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw new \InvalidArgumentException('INVALID_ALLOWED_GROUP');
			}
		}

		if ($restriction && $allowedUsers === [] && $allowedGroups === []) {
			throw new \InvalidArgumentException('ACCESS_LIST_REQUIRED');
		}

		$this->config->setAppValue(Application::APP_ID, self::KEY_APP_ADMINS, json_encode($appAdmins, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_ALLOWED_USER_IDS, json_encode($allowedUsers, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_ALLOWED_GROUP_IDS, json_encode($allowedGroups, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, $restriction ? '1' : '0');

		return $this->appPolicy();
	}

	public function isEmployee(string $userId): bool
	{
		return $this->lookupGlobalRole($userId) === self::ROLE_EMPLOYEE;
	}

	/**
	 * Self-service roster/absences/iCal: allowed when an active dc_employees row links
	 * this user. The global employee role alone is not enough without a link; conversely,
	 * planners or admins who are linked may see their own duties.
	 */
	public function requireEmployee(string $userId): void
	{
		if (!$this->hasActiveLinkedEmployee($userId)) {
			throw new AppAccessDeniedException(self::DENIAL_EMPLOYEE_NOT_LINKED);
		}
	}

	public function hasActiveLinkedEmployee(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	private function lookupGlobalRole(string $userId): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('dc_user_roles')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch(\PDO::FETCH_ASSOC);
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$role = (string)($row['role'] ?? '');
		return in_array($role, [self::ROLE_ADMIN, self::ROLE_PLANNER, self::ROLE_EMPLOYEE], true) ? $role : null;
	}

	private function userMatchesAllowList(string $userId): bool
	{
		if (in_array($userId, $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS), true)) {
			return true;
		}
		foreach ($this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS) as $groupId) {
			if ($this->isUserInGroupCached($userId, $groupId)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	private function getJsonIdList(string $key): array
	{
		$raw = trim((string)$this->config->getAppValue(Application::APP_ID, $key, '[]'));
		if ($raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $entry) {
			if (is_string($entry) && $entry !== '') {
				$out[] = $entry;
			}
		}
		return array_values(array_unique($out));
	}

	private function isUserInGroupCached(string $userId, string $groupId): bool
	{
		$key = $userId . "\0" . $groupId;
		if (!array_key_exists($key, $this->groupMembershipCache)) {
			$this->groupMembershipCache[$key] = $this->groupManager->isInGroup($userId, $groupId);
		}
		return $this->groupMembershipCache[$key];
	}

	private function assertKnownUser(string $uid, string $code): void
	{
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new \InvalidArgumentException($code);
		}
		if (method_exists($user, 'isEnabled') && !$user->isEnabled()) {
			throw new \InvalidArgumentException($code);
		}
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private function normalizeUniqueIdList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}
		$ids = [];
		foreach ($value as $entry) {
			$id = trim((string) $entry);
			if ($id !== '') {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}
}

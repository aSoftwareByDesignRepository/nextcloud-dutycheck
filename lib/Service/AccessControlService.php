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

	/** Roles that may be assigned from Settings (planner only; employee access is via catalog link). */
	public const ASSIGNABLE_DUTY_ROLES = [self::ROLE_PLANNER];

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
	 * Directory door only (portfolio Open/Restricted). Roles and employee links
	 * are enforced separately by requirePlannerOrAdmin / requireEmployee and
	 * needsRoleEnrollment for calm enrollment UX.
	 *
	 * - Nextcloud system administrators and DutyCheck app administrators: always allowed.
	 * - Directory restriction (if enabled): must match allow-listed users or groups.
	 * - Open mode: every logged-in user may open the app shell (menu visible).
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
		return true;
	}

	/**
	 * True when the door is open but the user has no planner/admin role and no
	 * active employee catalog link yet.
	 */
	public function needsRoleEnrollment(string $userId): bool
	{
		if ($userId === '' || $this->isAppAdmin($userId)) {
			return false;
		}
		if (!$this->canUseApp($userId)) {
			return false;
		}
		return !$this->hasDutyMembership($userId);
	}

	/**
	 * Planner/admin role or active linked employee row (self-service invitation).
	 */
	public function hasDutyMembership(string $userId): bool
	{
		if ($userId === '') {
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

	/**
	 * Door denial only. Role/membership denials use requirePlannerOrAdmin /
	 * requireEmployee / needsRoleEnrollment — never fold them into the door.
	 */
	public function denialReasonWhenCannotUseApp(string $userId): string
	{
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {
			return self::DENIAL_RESTRICTION;
		}
		// Fallback when middleware is invoked without a matching allow-list miss
		// (empty uid already rejected by canUseApp). Keep machine-stable code.
		return self::DENIAL_RESTRICTION;
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
		$restriction = $this->normalizeBooleanFlag($payload['accessRestrictionEnabled'] ?? false, 'INVALID_ACCESS_RESTRICTION');

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

	/**
	 * @return list<array{userId:string,displayName:string,role:string,createdAt:string}>
	 */
	public function listDutyRoleAssignments(): array
	{
		$roles = [self::ROLE_PLANNER, self::ROLE_EMPLOYEE];
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'role', 'created_at')
			->from('dc_user_roles')
			->where($qb->expr()->in('role', $qb->createNamedParameter($roles, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'DESC');
		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$userId = (string)($row['user_id'] ?? '');
			if ($userId === '') {
				continue;
			}
			$role = (string)($row['role'] ?? '');
			if (!in_array($role, $roles, true)) {
				continue;
			}
			$user = $this->userManager->get($userId);
			$displayName = $user !== null ? (string)$user->getDisplayName() : $userId;
			$createdAt = (string)($row['created_at'] ?? '');
			$rows[] = [
				'userId' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
				'role' => $role,
				'createdAt' => $createdAt,
			];
		}
		$result->closeCursor();
		return $rows;
	}

	public function setDutyRole(string $userId, string $role): array
	{
		$userId = trim($userId);
		$role = trim($role);
		if ($userId === '') {
			throw new \InvalidArgumentException('INVALID_USER');
		}
		$allowed = array_merge(self::ASSIGNABLE_DUTY_ROLES, [self::ROLE_EMPLOYEE]);
		if (!in_array($role, $allowed, true)) {
			throw new \InvalidArgumentException('INVALID_DUTY_ROLE');
		}
		$this->assertKnownUser($userId, 'INVALID_USER');

		$del = $this->db->getQueryBuilder();
		$del->delete('dc_user_roles')
			->where($del->expr()->eq('user_id', $del->createNamedParameter($userId)));
		$del->executeStatement();

		$ins = $this->db->getQueryBuilder();
		$ins->insert('dc_user_roles')
			->values([
				'user_id' => $ins->createNamedParameter($userId),
				'role' => $ins->createNamedParameter($role),
				'created_at' => $ins->createNamedParameter(gmdate('Y-m-d H:i:s')),
			]);
		$ins->executeStatement();

		return $this->listDutyRoleAssignments();
	}

	public function removeDutyRole(string $userId): array
	{
		$userId = trim($userId);
		if ($userId === '') {
			throw new \InvalidArgumentException('INVALID_USER');
		}
		$this->purgeUserDutyRole($userId);
		return $this->listDutyRoleAssignments();
	}

	public function purgeUserDutyRole(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_user_roles')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * GDPR / user-delete: scrub live authorization and employee account links.
	 * Ledger rows and snapshots stay; only the Nextcloud UID link is cleared (anonymize-don't-delete).
	 */
	public function purgeUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		$this->purgeUserDutyRole($userId);

		$adminIds = array_values(array_filter(
			$this->getJsonIdList(self::KEY_APP_ADMINS),
			static fn (string $id): bool => $id !== $userId,
		));
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_APP_ADMINS,
			json_encode($adminIds, JSON_THROW_ON_ERROR),
		);

		$allowUsers = array_values(array_filter(
			$this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS),
			static fn (string $id): bool => $id !== $userId,
		));
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode($allowUsers, JSON_THROW_ON_ERROR),
		);

		if ($this->db->tableExists('dc_employees')) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_employees')
				->set('linked_user_id', $qb->createNamedParameter(null))
				->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)))
				->executeStatement();
		}

		if ($this->db->tableExists('dc_mobile_seats')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_mobile_seats')
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)))
				->executeStatement();
		}

		if ($this->db->tableExists('dc_company_members')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_company_members')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->executeStatement();
		}

		if ($this->db->tableExists('dc_planner_locs')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_planner_locs')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->executeStatement();
		}

		if ($this->db->tableExists('dc_user_preferences')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('dc_user_preferences')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->executeStatement();
		}
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
		if (is_string($value)) {
			$trimmed = trim($value);
			return $trimmed !== '' ? [$trimmed] : [];
		}
		if (!is_array($value)) {
			if ($value === null) {
				return [];
			}
			$single = trim((string) $value);
			return $single !== '' ? [$single] : [];
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

	private function normalizeBooleanFlag(mixed $value, string $errorCode): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			if ($value === 1) {
				return true;
			}
			if ($value === 0) {
				return false;
			}
			throw new \InvalidArgumentException($errorCode);
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
				return true;
			}
			if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
				return false;
			}
		}

		throw new \InvalidArgumentException($errorCode);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Agent;

use OCA\DutyCheck\Service\AccessControlService;
use OCP\IGroupManager;

/**
 * App-door adapter: the agent surface honours the exact same access policy
 * as the web UI. canUseApp = the app access door; canAdminister = app
 * admins (isAppAdmin) or server admins — matching who may reach the
 * settings pages that host the "AI access (MCP)" section.
 */
final class DutyCheckAgentAccessGate implements AgentAccessGate
{
	public function __construct(
		private readonly AccessControlService $accessControl,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function canUseApp(string $uid): bool
	{
		return $this->accessControl->canUseApp($uid);
	}

	public function canAdminister(string $uid): bool
	{
		return $this->accessControl->isAppAdmin($uid)
			|| $this->groupManager->isAdmin($uid);
	}
}

<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Listener;

use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\ShiftPreferenceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private AccessControlService $access,
		private ?ShiftPreferenceService $preferences = null,
		private ?AvailabilityBlackoutService $blackouts = null,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		// Prefs/blackouts must be purged before access->purgeUser clears linked_user_id.
		$this->preferences?->purgeForUser($uid);
		$this->blackouts?->purgeForUser($uid);
		$this->access->purgeUser($uid);
	}
}

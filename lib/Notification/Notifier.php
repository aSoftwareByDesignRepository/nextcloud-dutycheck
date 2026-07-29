<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Notification;

use OCA\DutyCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('DutyCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		switch ($notification->getSubject()) {
			case 'roster_published':
				$params = $notification->getSubjectParameters();
				$period = (string) ($params['period'] ?? '');
				$notification->setParsedSubject($l->t('Roster published: %s', [$period]));
				$notification->setParsedMessage($l->t('Open DutyCheck to see your shifts.'));
				$notification->setLink(
					$this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster')
					. '?periodId=' . rawurlencode($notification->getObjectId())
				);
				return $notification;
			case 'period_soft_cap_approach':
				$params = $notification->getSubjectParameters();
				$minutes = (int) ($params['minutes'] ?? 0);
				$soft = (int) ($params['soft'] ?? 0);
				$notification->setParsedSubject($l->t('Approaching period total soft cap'));
				$notification->setParsedMessage($l->t(
					'You are scheduled for %1$s minutes this period (soft cap %2$s). Talk to your planner if this looks wrong.',
					[(string) $minutes, (string) $soft],
				));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
				return $notification;
			case 'swap_requested':
				$notification->setParsedSubject($l->t('Shift swap requested'));
				$notification->setParsedMessage($l->t('Open DutyCheck to review the swap request.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
				return $notification;
			case 'swap_approved':
				$notification->setParsedSubject($l->t('Shift swap approved'));
				$notification->setParsedMessage($l->t('Your swap request was approved. Check your roster.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
				return $notification;
			case 'swap_rejected':
				$notification->setParsedSubject($l->t('Shift swap rejected'));
				$notification->setParsedMessage($l->t('Your swap request was rejected. Check with your planner.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
				return $notification;
			case 'assignment_cancelled_late':
				$notification->setParsedSubject($l->t('Your shift was cancelled'));
				$notification->setParsedMessage($l->t('Open DutyCheck to see your updated roster.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
				return $notification;
			case 'assignment_changed_late':
				$notification->setParsedSubject($l->t('Your shift changed'));
				$notification->setParsedMessage($l->t('Open DutyCheck to see your updated roster.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster'));
				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}
}

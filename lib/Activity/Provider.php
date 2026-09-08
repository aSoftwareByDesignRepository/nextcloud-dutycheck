<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Activity;

use OCA\DutyCheck\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Parse Activity subjects published by DutyCheck (roster_published, …).
 */
class Provider implements IProvider
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @throws UnknownActivityException
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent
	{
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		switch ($event->getSubject()) {
			case 'roster_published':
				$params = $event->getSubjectParameters();
				$period = (string) ($params[0] ?? '');
				$event->setParsedSubject($l->t('Roster published: %s', [$period]));
				$event->setRichSubject($l->t('Roster published: {period}'), [
					'period' => [
						'type' => 'highlight',
						'id' => (string) $event->getObjectId(),
						'name' => $period,
					],
				]);
				$event->setLink(
					$this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster')
					. '?periodId=' . rawurlencode((string) $event->getObjectId())
				);
				return $event;
			default:
				throw new UnknownActivityException();
		}
	}
}

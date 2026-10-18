<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Listener;

use OCA\DutyCheck\Service\RosterService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * F17 reconcile trigger: TourCheck emits OCA\TourCheck::roundMissed /
 * roundCompleted / roundSkipped (GenericEvent, subject {siteId,roundId})
 * on every status transition. We never write dc_conflicts from here —
 * materialization stays inside RosterService's computed pipeline — we
 * only flag the affected open periods conflicts_dirty; the drain job /
 * lazy GET recomputes. Never throws: a broken listener must not corrupt
 * the sibling's post-commit notification flow.
 *
 * @template-implements IEventListener<GenericEvent>
 */
class TourCheckRoundLinkListener implements IEventListener
{
	public function __construct(
		private RosterService $roster,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof GenericEvent) {
			return;
		}
		$subject = $event->getSubject();
		$roundId = is_array($subject) ? (int) ($subject['roundId'] ?? 0) : 0;
		if ($roundId <= 0) {
			return;
		}
		try {
			$this->roster->markConflictsDirtyForTckRound($roundId);
		} catch (\Throwable $e) {
			$this->logger->warning('dutycheck: tck round link reconcile failed', [
				'roundId' => $roundId,
				'exception' => $e,
			]);
		}
	}
}

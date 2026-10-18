<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * F17 consumer half — resolves TourCheck's RoundLinkReadFacade with full
 * capability probing (app enabled → class → version → method). Every call
 * fails closed to an empty result; a missing or mid-upgrade sibling must
 * never 500 a roster operation or stall the missed-round reconcile job.
 */
class TckRoundLinkService
{
	private const FACADE = \OCA\TourCheck\Public\RoundLinkReadFacade::class;

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function available(): bool
	{
		return $this->facade() !== null;
	}

	private function facade(): ?object
	{
		try {
			if (!$this->appManager->isEnabledForUser('tourcheck')) {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}
		if (!class_exists(self::FACADE) || (self::FACADE)::FACADE_VERSION < 1) {
			return null;
		}
		try {
			return \OCP\Server::get(self::FACADE);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Round status map for the conflict engine / reconcile job.
	 * @param list<int> $roundIds
	 * @return array<int, array{status:string, siteId:int, siteName:string, scheduledStart:?string, windowEnd:?string}>
	 */
	public function roundStatuses(array $roundIds): array
	{
		$f = $this->facade();
		if ($f === null || $roundIds === [] || !method_exists($f, 'statusesOf')) {
			return [];
		}
		try {
			$r = $f->statusesOf($roundIds);
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck round status read failed', ['exception' => $e]);
			return [];
		}
		return is_array($r) ? $r : [];
	}

	/**
	 * Linkable rounds for the shift-planning picker on a duty date.
	 * @return array{available:bool, rounds:list<array{id:int,siteName:string,status:string,scheduledStart:?string}>}
	 */
	public function openRounds(string $ymd): array
	{
		$f = $this->facade();
		if ($f === null || !method_exists($f, 'openRoundsForDate')) {
			return ['available' => false, 'rounds' => []];
		}
		try {
			$rows = $f->openRoundsForDate($ymd);
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck round list read failed', ['exception' => $e]);
			return ['available' => false, 'rounds' => []];
		}
		$out = [];
		foreach (is_array($rows) ? $rows : [] as $r) {
			$id = (int)($r['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$out[] = [
				'id' => $id,
				'siteName' => (string)($r['siteName'] ?? ''),
				'status' => (string)($r['status'] ?? ''),
				'scheduledStart' => $r['scheduledStart'] ?? null,
			];
		}
		return ['available' => true, 'rounds' => $out];
	}
}

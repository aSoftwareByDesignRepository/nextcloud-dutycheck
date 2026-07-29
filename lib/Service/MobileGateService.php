<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Config\VendorPublicKey;
use OCA\DutyCheck\Exception\MobileGateException;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\License\Dty2Codec;
use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * License/seat gate for /mobile/v1/* (SERVER-MOBILE-API §2).
 *
 * Rungs 1 (NC auth) and 2 (canUseApp) are enforced by Nextcloud + AppAccessMiddleware.
 * This service enforces rungs 3–6:
 *   3 license row exists           → 402 license_missing
 *   4 today ≤ valid_until          → 402 license_expired
 *   5 caller has a seat            → 402 seat_required
 *   6 seat within limit            → 402 seat_limit_exceeded
 *
 * `bootstrap` skips 3–6 and reports state so the official app can render LicenseGate.
 */
class MobileGateService
{
	public function __construct(
		private readonly LicenseService $license,
		private readonly ?IArbeitszeitCheckIntegration $azc = null,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?IAppManager $appManager = null,
	) {
	}

	public function assertGatePassed(string $uid): void
	{
		$state = $this->license->gateState($uid);
		if (!$state['hasLicense']) {
			throw new MobileGateException('license_missing');
		}
		if (!$state['licenseValid']) {
			throw new MobileGateException('license_expired');
		}
		if (!$state['seatAssigned']) {
			throw new MobileGateException('seat_required');
		}
		if (!$state['seatWithinLimit']) {
			throw new MobileGateException('seat_limit_exceeded');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function bootstrapPayload(string $uid, string $displayName, string $appVersion): array
	{
		$state = $this->license->gateState($uid);
		$enabledForUser = $state['seatAssigned'] && $state['seatWithinLimit'] && $state['licenseValid'];
		$status = $this->license->status();
		$expiresAt = is_array($status['state'] ?? null) ? ($status['state']['validUntil'] ?? null) : null;
		$mobileSeats = is_array($status['state'] ?? null) ? (int) ($status['state']['mobileSeats'] ?? 0) : 0;
		$mobileSeatsUsed = is_array($status['seats'] ?? null) ? (int) ($status['seats']['assigned'] ?? 0) : 0;
		$licensing = [
			'product' => Dty2Codec::PRODUCT,
			'format' => Dty2Codec::FORMAT,
			'mobileSeats' => $mobileSeats,
			'mobileSeatsUsed' => $mobileSeatsUsed,
			'enabledForUser' => $enabledForUser,
			'expiresAt' => is_string($expiresAt) ? $expiresAt : null,
		];
		if ($state['hasLicense'] && $state['payloadB64'] !== null && $state['signatureB64'] !== null) {
			$licensing['payloadB64'] = $state['payloadB64'];
			$licensing['signatureB64'] = $state['signatureB64'];
			$licensing['envelope'] = [
				'format' => Dty2Codec::FORMAT,
				'payloadB64' => $state['payloadB64'],
				'signatureB64' => $state['signatureB64'],
			];
			$licensing['vendorPublicKeyB64'] = VendorPublicKey::publicKeyB64();
			$licensing['mobile'] = [
				'enabledForUser' => $enabledForUser,
				'expiresAt' => is_string($expiresAt) ? $expiresAt : null,
			];
		}

		$azcEffective = $this->azc?->isEffective() === true;
		$azcAbsencesUrl = null;
		if ($azcEffective && $this->urlGenerator !== null) {
			try {
				$azcAbsencesUrl = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences');
			} catch (\Throwable) {
				$azcAbsencesUrl = null;
			}
		}

		$myRosterWebUrl = null;
		if ($this->urlGenerator !== null) {
			try {
				$myRosterWebUrl = $this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster');
			} catch (\Throwable) {
				$myRosterWebUrl = null;
			}
		}

		return [
			'appId' => 'dutycheck',
			'serverVersion' => $appVersion,
			'apiVersion' => 1,
			'pushAvailable' => $this->appManager?->isEnabledForUser('notifications') === true,
			'capabilities' => [
				'dutycheck.companion.min' => 1,
				'dutycheck.acknowledge' => true,
				// Web APIs + companion P3 surfaces — advertise when mobile routes ship.
				'dutycheck.swap' => true,
				'dutycheck.openShifts' => true,
				'integration.arbeitszeitcheck.effective' => $azcEffective,
			],
			'licensing' => $licensing,
			'seatAssigned' => $state['seatAssigned'],
			'seatWithinLimit' => $state['seatWithinLimit'],
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'user' => [
				'userId' => $uid,
				'uid' => $uid,
				'displayName' => $displayName,
			],
			'urls' => [
				'azcAbsences' => $azcAbsencesUrl,
				'myRosterWeb' => $myRosterWebUrl,
			],
		];
	}
}

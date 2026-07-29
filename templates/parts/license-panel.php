<?php

declare(strict_types=1);

/**
 * DutyCheck — License panel (DTY2 mobile seats).
 *
 * Seats-only: DutyCheck has no shared-device/signage product. The web app always
 * stays free (AGPL); a DTY2 key only unlocks named seats for the official mobile companion
 * app once it ships. Included from org-settings-form.php (after the form closes, before
 * Support & Us) so the server admin form and the in-app settings page render it identically.
 *
 * @var \OCP\IL10N $l
 * @var array|null $licenseStatus    LicenseService::status() shape, or null if unavailable.
 * @var array|null $licenseSeatsList LicenseService::listSeats() shape: {data, total, limit, offset}.
 * @var array|null $licenseI18n      LicenseUiStrings::forPanel() strings, shared with license-settings.js.
 * @var string $licenseApiUrl
 * @var string $licenseClearUrl
 * @var string $licenseSeatsUrl
 * @var string $licenseAssignSeatUrl
 * @var string $licenseRemoveSeatBase
 * @var string $licenseSearchUsersUrl
 * @var string $requesttoken
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : \OCP\Util::getL10N('dutycheck'));

$licenseI18n = $licenseI18n ?? ($_['licenseI18n'] ?? null);
if (!is_array($licenseI18n)) {
	$licenseI18n = \OCA\DutyCheck\Service\LicenseUiStrings::forPanel($l);
}
$t = static function (string $key, string $fallback = '') use ($licenseI18n): string {
	$v = $licenseI18n[$key] ?? $fallback;
	return is_string($v) ? $v : $fallback;
};

$licenseStatusData = $licenseStatus ?? ($_['licenseStatus'] ?? null);
if (!is_array($licenseStatusData)) {
	$licenseStatusData = null;
}
$licenseState = is_array($licenseStatusData) ? ($licenseStatusData['state'] ?? null) : null;
if (!is_array($licenseState)) {
	$licenseState = null;
}
$licenseSeatsCounts = is_array($licenseStatusData) ? ($licenseStatusData['seats'] ?? null) : null;
if (!is_array($licenseSeatsCounts)) {
	$licenseSeatsCounts = ['assigned' => 0, 'limit' => 0];
}

$licenseSeatsPayload = $licenseSeatsList ?? $licenseSeats ?? ($_['licenseSeatsList'] ?? ($_['licenseSeats'] ?? null));
if (!is_array($licenseSeatsPayload)) {
	$licenseSeatsPayload = ['data' => [], 'total' => 0, 'limit' => 0, 'offset' => 0];
}
$seatRows = is_array($licenseSeatsPayload['data'] ?? null) ? $licenseSeatsPayload['data'] : [];

$licenseApiUrl = (string)($licenseApiUrl ?? ($_['licenseApiUrl'] ?? ''));
$licenseClearUrl = (string)($licenseClearUrl ?? ($_['licenseClearUrl'] ?? $licenseApiUrl));
$licenseSeatsUrl = (string)($licenseSeatsUrl ?? ($_['licenseSeatsUrl'] ?? ''));
$licenseAssignSeatUrl = (string)($licenseAssignSeatUrl ?? ($_['licenseAssignSeatUrl'] ?? $licenseSeatsUrl));
$licenseRemoveSeatBase = (string)($licenseRemoveSeatBase ?? ($_['licenseRemoveSeatBase'] ?? ($licenseSeatsUrl !== '' ? rtrim($licenseSeatsUrl, '/') . '/' : '')));
$licenseSearchUsersUrl = (string)($licenseSearchUsersUrl ?? ($_['licenseSearchUsersUrl'] ?? ($_['orgSearchUsersUrl'] ?? '')));
$requesttoken = (string)($requesttoken ?? ($_['requesttoken'] ?? \OCP\Util::callRegister()));

$productsUrl = 'https://nextcloud.software-by-design.de/';
$purchaseMailto = 'mailto:info@software-by-design.de?subject=' . rawurlencode('DutyCheck mobile license');

// Badge + status derivation for the first (server-rendered) paint; the client script
// re-derives the same state from the API response after load and after every action.
$hasState = $licenseState !== null;
$isValid = $hasState && !empty($licenseState['valid']);
$expiresSoon = $hasState && !empty($licenseState['expiresSoon']);
if (!$hasState) {
	$badgeText = $t('badgeNotConfigured', $l->t('Not configured'));
	$badgeClass = 'dc-license-badge--none';
} elseif ($isValid && $expiresSoon) {
	$badgeText = $t('badgeActiveSoon', $l->t('Active — renew soon'));
	$badgeClass = 'dc-license-badge--warning';
} elseif ($isValid) {
	$badgeText = $t('badgeActive', $l->t('Active'));
	$badgeClass = 'dc-license-badge--active';
} else {
	$badgeText = $t('badgeExpired', $l->t('Expired'));
	$badgeClass = 'dc-license-badge--expired';
}
$validUntil = $hasState ? (string)($licenseState['validUntil'] ?? '') : '';
$daysRemaining = $hasState && isset($licenseState['daysRemaining']) && is_int($licenseState['daysRemaining'])
	? $licenseState['daysRemaining']
	: null;
$seatsAssigned = (int)($licenseSeatsCounts['assigned'] ?? 0);
$seatsLimit = (int)($licenseSeatsCounts['limit'] ?? 0);
$seatsUsedText = str_replace(
	['{used}', '{total}'],
	[(string)$seatsAssigned, (string)$seatsLimit],
	$t('seatsUsedText', '{used} of {total} seats used'),
);
$meterPercent = $seatsLimit > 0 ? (int)min(100, round($seatsAssigned / $seatsLimit * 100)) : 0;
$expiryBody = $daysRemaining !== null
	? str_replace('{days}', (string)max(0, $daysRemaining), $t('expirySoonBody'))
	: $t('expirySoonBody');

try {
	$licenseI18nJson = json_encode($licenseI18n, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (\JsonException) {
	$licenseI18nJson = '{}';
}
?>
<section class="dutycheck-panel dc-license-section" id="dutycheck-license" aria-labelledby="dc-license-heading">
	<div
		id="dc-license-panel"
		class="dc-license-panel"
		data-api-license="<?php p($licenseApiUrl); ?>"
		data-api-clear-license="<?php p($licenseClearUrl); ?>"
		data-api-seats="<?php p($licenseSeatsUrl); ?>"
		data-api-assign-seat="<?php p($licenseAssignSeatUrl); ?>"
		data-api-remove-seat-base="<?php p($licenseRemoveSeatBase); ?>"
		data-api-search-users="<?php p($licenseSearchUsersUrl); ?>"
		data-requesttoken="<?php p($requesttoken); ?>"
		data-i18n="<?php p($licenseI18nJson); ?>"
	>
		<div id="dc-license-live" class="visually-hidden" role="status" aria-live="polite"></div>
		<div id="dc-license-alert" class="visually-hidden" role="alert" aria-live="assertive"></div>

		<header class="dc-license-header">
			<h2 id="dc-license-heading" class="dutycheck-panel__title"><?php p($l->t('Mobile license')); ?></h2>
			<p class="dc-license-intro">
				<?php p($l->t('The DutyCheck web app always stays free. A DTY2 license unlocks named seats for the official DutyCheck mobile companion app for your organisation.')); ?>
			</p>
			<p class="dc-license-intro dc-license-intro--status">
				<?php p($t('mobileAppComingSoon')); ?>
			</p>
		</header>

		<div id="dc-license-feedback" class="dc-license-feedback" role="alert" hidden></div>

		<div class="dc-license-cta">
			<a class="button primary dc-license-cta__link" href="<?php p($purchaseMailto); ?>">
				<?php p($t('askForLicenseButton')); ?>
			</a>
			<a class="button dc-license-cta__link" href="<?php p($productsUrl); ?>" target="_blank" rel="noopener noreferrer">
				<?php p($t('seeProductsButton')); ?>
			</a>
		</div>

		<div class="dc-license-status" id="dc-license-status">
			<div class="dc-license-status__row">
				<span class="dc-license-badge <?php p($badgeClass); ?>" id="dc-license-badge"><?php p($badgeText); ?></span>
				<span class="dc-license-status__valid-until" id="dc-license-valid-until">
					<?php if ($validUntil !== '') { ?>
						<?php p($t('validUntilLabel')); ?> <strong><?php p($validUntil); ?></strong>
					<?php } else { ?>
						<?php p($t('validUntilNone')); ?>
					<?php } ?>
				</span>
			</div>

			<div class="dc-license-meter-wrap">
				<div class="dc-license-meter-label" id="dc-license-meter-label"><?php p($t('seatsMeterLabel')); ?></div>
				<div
					class="dc-license-meter"
					id="dc-license-meter"
					role="meter"
					aria-labelledby="dc-license-meter-label"
					aria-valuemin="0"
					aria-valuenow="<?php p((string)$seatsAssigned); ?>"
					aria-valuemax="<?php p((string)max($seatsLimit, $seatsAssigned, 1)); ?>"
					aria-valuetext="<?php p($seatsUsedText); ?>"
				><div class="dc-license-meter__fill" id="dc-license-meter-fill" style="width: <?php p((string)$meterPercent); ?>%"></div></div>
				<p class="dc-license-meter-text" id="dc-license-meter-text"><?php p($seatsUsedText); ?></p>
			</div>

			<div class="dutycheck-callout dutycheck-callout--caution dc-license-expiry-callout" id="dc-license-expiry-callout" role="status"<?php if (!($isValid && $expiresSoon)) {
				p(' hidden');
			} ?>>
				<p class="dutycheck-callout__p">
					<strong id="dc-license-expiry-title"><?php p($t('expirySoonTitle')); ?></strong>
					<span id="dc-license-expiry-body"><?php p($expiryBody); ?></span>
				</p>
			</div>
		</div>

		<form id="dc-license-form" class="dc-license-form" novalidate>
			<label for="dc-license-key" class="dc-license-form__label"><?php p($t('keyLabel')); ?></label>
			<textarea
				id="dc-license-key"
				name="key"
				class="dutycheck-textarea dc-license-key"
				rows="4"
				autocomplete="off"
				autocapitalize="off"
				spellcheck="false"
				aria-describedby="dc-license-key-hint"
				placeholder="<?php p($t('keyPlaceholder', 'DTY2.…')); ?>"
			></textarea>
			<p id="dc-license-key-hint" class="dutycheck-hint"><?php p($t('keyHint')); ?></p>
			<div class="dc-license-actions">
				<button type="submit" class="button primary" id="dc-license-save"><?php p($t('saveButton')); ?></button>
				<button type="button" class="button" id="dc-license-remove"><?php p($t('removeButton')); ?></button>
			</div>
		</form>

		<section class="dc-license-seats" aria-labelledby="dc-license-seats-heading">
			<h3 id="dc-license-seats-heading" class="dutycheck-panel__title dc-license-seats__title"><?php p($t('seatsHeading')); ?></h3>
			<p class="dc-license-intro"><?php p($t('seatsIntro')); ?></p>

			<div class="dc-license-seat-search">
				<label for="dc-license-seat-search-input" class="dc-license-form__label"><?php p($t('seatSearchLabel')); ?></label>
				<div class="dc-license-seat-search__wrap">
					<input
						type="text"
						id="dc-license-seat-search-input"
						class="dutycheck-input dc-license-seat-search__input"
						role="combobox"
						aria-expanded="false"
						aria-autocomplete="list"
						aria-controls="dc-license-seat-search-suggest"
						aria-haspopup="listbox"
						autocomplete="off"
						autocapitalize="off"
						spellcheck="false"
						placeholder="<?php p($t('seatSearchPlaceholder')); ?>"
					>
					<div class="dc-license-seat-search__suggest" id="dc-license-seat-search-suggest" hidden></div>
				</div>
			</div>

			<div
				class="dc-license-seat-table-wrap"
				role="region"
				aria-label="<?php p($l->t('Assigned seats')); ?>"
				tabindex="0"
			>
				<table class="dc-license-seat-table" id="dc-license-seat-table">
					<caption class="visually-hidden"><?php p($l->t('Assigned seats')); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php p($t('personColumn')); ?></th>
							<th scope="col"><?php p($t('assignedColumn')); ?></th>
							<th scope="col"><span class="visually-hidden"><?php p($t('actionsColumn')); ?></span></th>
						</tr>
					</thead>
					<tbody id="dc-license-seat-tbody">
						<?php if ($seatRows === []) { ?>
						<tr class="dc-license-seat-row dc-license-seat-row--empty" id="dc-license-seat-empty-row">
							<td colspan="3"><?php p($t('seatsEmpty')); ?></td>
						</tr>
						<?php }
						foreach ($seatRows as $seatRow) {
							$seatUid = (string)($seatRow['uid'] ?? '');
							if ($seatUid === '') {
								continue;
							}
							$seatName = (string)($seatRow['displayName'] ?? $seatUid);
							$seatAssignedAt = (int)($seatRow['assignedAt'] ?? 0);
							$seatWithinLimit = (bool)($seatRow['withinLimit'] ?? true);
							$seatAssignedDate = $seatAssignedAt > 0 ? date('Y-m-d', $seatAssignedAt) : '';
							$seatRemoveAria = str_replace('{name}', $seatName, $t('seatRemoveAria', 'Remove seat for {name}'));
						?>
						<tr class="dc-license-seat-row" data-uid="<?php p($seatUid); ?>">
							<td class="dc-license-seat-row__person">
								<span class="dc-license-seat-row__name"><?php p($seatName); ?></span>
								<?php if ($seatName !== $seatUid) { ?>
								<span class="dc-license-seat-row__uid"><?php p($seatUid); ?></span>
								<?php } ?>
								<?php if (!$seatWithinLimit) { ?>
								<span class="dc-license-badge dc-license-badge--warning dc-license-seat-row__over"><?php p($t('seatOverLimitBadge')); ?></span>
								<?php } ?>
							</td>
							<td class="dc-license-seat-row__assigned"><?php p($seatAssignedDate); ?></td>
							<td class="dc-license-seat-row__actions">
								<button type="button" class="button dc-license-seat-remove" data-uid="<?php p($seatUid); ?>" aria-label="<?php p($seatRemoveAria); ?>">
									<?php p($t('seatRemoveButton')); ?>
								</button>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</section>

		<div class="dc-license-modal" id="dc-license-confirm-modal" hidden>
			<div class="dc-license-modal__backdrop" data-dc-license-modal-dismiss="1"></div>
			<div
				class="dc-license-modal__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="dc-license-confirm-title"
				aria-describedby="dc-license-confirm-body"
			>
				<h2 id="dc-license-confirm-title" class="dc-license-modal__title"><?php p($t('confirmRemoveTitle')); ?></h2>
				<p id="dc-license-confirm-body" class="dc-license-modal__body"><?php p($t('confirmRemoveBody')); ?></p>
				<div class="dc-license-modal__actions">
					<button type="button" class="button" id="dc-license-confirm-cancel"><?php p($t('confirmRemoveCancel')); ?></button>
					<button type="button" class="button dc-license-modal__confirm-danger" id="dc-license-confirm-ok"><?php p($t('confirmRemoveConfirm')); ?></button>
				</div>
			</div>
		</div>
	</div>
</section>

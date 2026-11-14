<?php

declare(strict_types=1);

/**
 * Check family — canonical license page (canonical source in _shared/license-page).
 *
 * SYNCED FILE — never edit the in-app copy; edit the source and re-run
 * sync_license_page.py. dc is substituted with the app prefix at sync time.
 *
 * Renders the ONE license UX for every Check app with purchasable companions:
 * status tiles → organisation license key card → mobile seats (combobox +
 * batch assign + table) → optional device card (link or register mode).
 * Design reference: ArbeitszeitCheck admin-license page.
 *
 * Host supplies via $_ (see _shared/license-page/README.md for the contract):
 *   license, productCode, companionText, seatAppName,
 *   mobileSeatsUsed/Limit, mobileSeats[], showMobileSeats,
 *   terminalDevicesUsed/Limit, terminals[], showTerminal, terminalMode,
 *   deviceStrings, instanceId, instanceBound,
 *   licenseRenewMailto, productsUrl, deviceManageUrl, deviceManageLabel,
 *   api*Url endpoints, deviceFields, requesttoken, i18n (overrides)
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$l = $_['l'] ?? ($l ?? null);
if (!is_object($l) || !method_exists($l, 't')) {
	try {
		$l = \OCP\Util::getL10N((string)($_['appId'] ?? ''));
	} catch (\Throwable) {
		// Kernel-free template render (unit tests): identity l10n stub.
		$l = new class {
			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
	}
}

$license = is_array($_['license'] ?? null) ? $_['license'] : null;
$productCode = (string)($_['productCode'] ?? '');
$companionText = (string)($_['companionText'] ?? $l->t('the companion apps'));
$seatAppName = (string)($_['seatAppName'] ?? $l->t('the mobile companion app'));

$mobileUsed = (int)($_['mobileSeatsUsed'] ?? 0);
$mobileLimit = (int)($_['mobileSeatsLimit'] ?? 0);
$mobileSeats = is_array($_['mobileSeats'] ?? null) ? $_['mobileSeats'] : [];
$showMobile = !empty($_['showMobileSeats']);

$terminalUsed = (int)($_['terminalDevicesUsed'] ?? 0);
$terminalLimit = (int)($_['terminalDevicesLimit'] ?? 0);
$terminals = is_array($_['terminals'] ?? null) ? $_['terminals'] : [];
$showTerminal = !empty($_['showTerminal']);
$terminalMode = (string)($_['terminalMode'] ?? 'link'); // 'link' | 'register'
$deviceFields = is_array($_['deviceFields'] ?? null) ? $_['deviceFields'] : [];
$deviceStrings = is_array($_['deviceStrings'] ?? null) ? $_['deviceStrings'] : [];

$instanceId = (string)($_['instanceId'] ?? '');
$instanceBound = !isset($_['instanceBound']) || !empty($_['instanceBound']);
$licenseRenewMailto = (string)($_['licenseRenewMailto'] ?? 'mailto:info@software-by-design.de');
$productsUrl = (string)($_['productsUrl'] ?? 'https://nextcloud.software-by-design.de/');
$deviceManageUrl = (string)($_['deviceManageUrl'] ?? '');
$deviceManageLabel = (string)($_['deviceManageLabel'] ?? $l->t('Manage devices'));

$apiLicenseUrl = (string)($_['apiLicenseUrl'] ?? '');
$apiClearLicenseUrl = (string)($_['apiClearLicenseUrl'] ?? '');
$apiSeatsUrl = (string)($_['apiSeatsUrl'] ?? '');
$apiSeatsBatchUrl = (string)($_['apiSeatsBatchUrl'] ?? '');
$apiRemoveSeatUrl = (string)($_['apiRemoveSeatUrl'] ?? '');
$apiSearchUsersUrl = (string)($_['apiSearchUsersUrl'] ?? '');
$apiRegisterDeviceUrl = (string)($_['apiRegisterDeviceUrl'] ?? '');
$apiRevokeDeviceUrl = (string)($_['apiRevokeDeviceUrl'] ?? '');
$requesttoken = (string)($_['requesttoken'] ?? '');

$ds = static function (string $key, string $fallback) use ($deviceStrings): string {
	$v = $deviceStrings[$key] ?? null;
	return is_string($v) && $v !== '' ? $v : $fallback;
};
$devicesTitle = $ds('title', $l->t('Devices'));
$devicesLead = $ds('lead', $l->t('Pair devices from the linked administration area. Each paired device uses one license slot.'));
$devicesLabel = $ds('label', $l->t('Devices'));
$deviceRegisterLabel = $ds('registerLabel', $l->t('Register device'));
$deviceNameLabel = $ds('nameLabel', $l->t('Display name'));
$deviceNamePlaceholder = $ds('namePlaceholder', '');
$deviceEmptyTitle = $ds('emptyTitle', $l->t('No devices registered yet.'));
$deviceEmptyText = $ds('emptyText', $l->t('Register a device above to give it access.'));
$deviceColLabel = $ds('colLabel', $l->t('Label'));
$deviceColDetail = $ds('colDetail', $l->t('Detail'));
$deviceColRegistered = $ds('colRegistered', $l->t('Registered'));
$deviceRevokeLabel = $ds('revokeLabel', $l->t('Revoke'));

$hasLicense = $license !== null;
$dateValid = $hasLicense && !empty($license['dateValid']);
$cryptoValid = $hasLicense && !empty($license['cryptographicallyValid']);
$isActive = $hasLicense && !empty($license['active']);
$cryptoInvalid = $dateValid && !$cryptoValid;
$validUntil = $hasLicense ? (string)($license['validUntil'] ?? '') : '';
$customerId = $hasLicense ? (string)($license['customerId'] ?? '') : '';
$expiresSoon = false;
if ($validUntil !== '') {
	$untilDt = \DateTimeImmutable::createFromFormat('Y-m-d', $validUntil);
	$today = new \DateTimeImmutable('today');
	if ($untilDt !== false) {
		$daysLeft = (int)$today->diff($untilDt)->format('%r%a');
		$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;
	}
}

$formatAssignedAt = static function (string $iso) use ($l): string {
	if ($iso === '') {
		return '—';
	}
	try {
		$dt = new \DateTimeImmutable($iso);
		return $dt->format('d.m.Y H:i');
	} catch (\Exception) {
		return $iso;
	}
};

$mobilePct = $mobileLimit > 0 ? min(100, (int)round(($mobileUsed / $mobileLimit) * 100)) : 0;
$terminalPct = $terminalLimit > 0 ? min(100, (int)round(($terminalUsed / $terminalLimit) * 100)) : 0;
$mobileFull = $mobileLimit > 0 && $mobileUsed >= $mobileLimit;
$terminalFull = $terminalLimit > 0 && $terminalUsed >= $terminalLimit;

/** @return list<array{href: string, label: string, class: string, target?: string, rel?: string}> */
$licenseContactActions = static function ($l) use ($licenseRenewMailto, $productsUrl): array {
	return [
		[
			'href' => $licenseRenewMailto,
			'label' => $l->t('Purchase or renew license'),
			'class' => 'dc-license-btn dc-license-btn--secondary dc-license-btn--sm',
		],
		[
			'href' => $productsUrl,
			'label' => $l->t('Software by Design — Nextcloud Apps'),
			'class' => 'dc-license-btn dc-license-btn--secondary dc-license-btn--sm',
			'target' => '_blank',
			'rel' => 'noopener noreferrer',
		],
	];
};

$renderContactActions = static function ($l) use ($licenseContactActions): void {
	foreach ($licenseContactActions($l) as $action) {
		$target = !empty($action['target']) ? ' target="' . \htmlspecialchars((string)$action['target'], ENT_QUOTES, 'UTF-8') . '"' : '';
		$rel = !empty($action['rel']) ? ' rel="' . \htmlspecialchars((string)$action['rel'], ENT_QUOTES, 'UTF-8') . '"' : '';
		echo '<a href="' . \htmlspecialchars($action['href'], ENT_QUOTES, 'UTF-8') . '" class="' . \htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8') . '"' . $target . $rel . '>' . \htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') . '</a>';
	}
};

$trashIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dc-license-icon" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

$i18nMsgids = [
	'saveSuccess' => 'License saved successfully.',
	'saveFailed' => 'Could not save license.',
	'emptyKey' => 'Please paste a license key.',
	'networkError' => 'Network error. Please try again.',
	'seatAssigned' => 'Seat assigned.',
	'seatsAssigned' => 'Assigned %1$d, skipped %2$d, failed %3$d.',
	'assignSelected' => 'Assign selected',
	'assignSelectedN' => 'Assign selected (%n)',
	'confirmAssignSeats' => 'Assign mobile seats to %1$d people? %2$d seats remaining.',
	'assignSeatsTitle' => 'Assign mobile seats?',
	'assignSeatsConfirm' => 'Assign seats',
	'confirmAssignSeatsOver' => 'You selected %1$d people but only %2$d seats remain. Only free seats will be assigned.',
	'selectPeopleFirst' => 'Select at least one person.',
	'searching' => 'Searching…',
	'searchFailed' => 'Search failed. Please try again.',
	'seatRemoved' => 'Seat removed.',
	'assignFailed' => 'Could not assign seat.',
	'removeFailed' => 'Could not remove seat.',
	'removeSeat' => 'Remove',
	'removeSeatTitle' => 'Remove mobile seat',
	'removeSeatConfirm' => 'Remove mobile seat for this person?',
	'clearSuccess' => 'License removed.',
	'clearFailed' => 'Could not remove license.',
	'cancel' => 'Cancel',
	'confirm' => 'Confirm',
	'activeLabel' => 'Active',
	'inactiveLabel' => 'Expired or invalid',
	'signatureInvalidLabel' => 'Signature mismatch',
	'searchNoResults' => 'No matching people found.',
	'saving' => 'Saving…',
	'colPerson' => 'Person',
	'colUserId' => 'User ID',
	'colAssigned' => 'Assigned',
	'colActions' => 'Actions',
	'seatCountFmt' => '%1$d of %2$d seats assigned',
	'deviceRegistered' => 'Device registered.',
	'deviceRegisterFailed' => 'Could not register device.',
	'deviceRevoked' => 'Device revoked.',
	'deviceRevokeFailed' => 'Could not revoke device.',
	'deviceRevokeTitle' => 'Revoke device',
	'deviceRevokeConfirm' => 'Revoke this device? The device loses access immediately.',
	'deviceCountFmt' => '%1$d of %2$d device slots in use.',
	'deviceTokenHint' => 'This token is shown only once. Paste it into the companion app on the device.',
	'copyToken' => 'Copy token',
	'copied' => 'Copied.',
	'done' => 'Done',
	'requiredField' => 'Please fill in all required fields.',
	'regenTitle' => 'New pairing code',
	'regenConfirm' => 'Generate a new pairing code for this device? The old code stops working immediately.',
	'regenOk' => 'Generate code',
	'regenDone' => 'New pairing code generated.',
	'regenFailed' => 'Could not generate a pairing code.',
];
$i18n = [];
foreach ($i18nMsgids as $k => $msgid) {
	$i18n[$k] = $l->t($msgid);
	$i18n[$msgid] = $i18n[$k];
}
if (is_array($_['i18n'] ?? null)) {
	foreach ($_['i18n'] as $k => $v) {
		$i18n[$k] = $v;
	}
}
$i18nJson = json_encode($i18n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if (!is_string($i18nJson)) {
	$i18nJson = '{}';
}
?>

<div class="dc-license-page" id="dc-license-page"
	data-license-root="1"
	data-prefix="dc"
	data-api-license="<?php p($apiLicenseUrl); ?>"
	data-api-clear-license="<?php p($apiClearLicenseUrl); ?>"
	data-api-seats="<?php p($apiSeatsUrl); ?>"
	data-api-seats-batch="<?php p($apiSeatsBatchUrl); ?>"
	data-api-remove-seat="<?php p($apiRemoveSeatUrl); ?>"
	data-api-search-users="<?php p($apiSearchUsersUrl); ?>"
	data-api-register-device="<?php p($apiRegisterDeviceUrl); ?>"
	data-api-revoke-device="<?php p($apiRevokeDeviceUrl); ?>"
	data-api-regen-device="<?php p((string)($_['apiRegenDeviceUrl'] ?? '')); ?>"
	data-i18n="<?php p($i18nJson); ?>"
	data-requesttoken="<?php p($requesttoken); ?>">

	<span id="dc-license-heading" class="dc-license-anchor" aria-hidden="true"></span>
	<div id="dc-license-live" class="dc-license-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="dc-license-alert" class="dc-license-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>

	<div id="dc-license-feedback" class="dc-license-feedback" role="alert" hidden></div>

	<?php if ($cryptoInvalid): ?>
	<aside class="dc-license-callout dc-license-callout--warning dc-license-crypto-callout" role="alert" aria-labelledby="dc-license-crypto-title">
		<div class="dc-license-callout__body">
			<h2 id="dc-license-crypto-title" class="dc-license-callout__title"><?php p($l->t('License signature cannot be verified')); ?></h2>
			<p class="dc-license-callout__text"><?php p($l->t('A license key is stored, but this server cannot verify its signature with the vendor public key. The companion apps will reject the license until the key was signed with the matching vendor signing key. Re-apply a correctly signed license, or contact support if this persists after an app update.')); ?></p>
		</div>
	</aside>
	<?php endif; ?>

	<?php if (!$hasLicense): ?>
	<aside class="dc-license-callout dc-license-callout--info dc-license-no-key-callout" role="region" aria-labelledby="dc-license-no-key-title">
		<div class="dc-license-callout__body">
			<h2 id="dc-license-no-key-title" class="dc-license-callout__title"><?php p($l->t('No license yet')); ?></h2>
			<p class="dc-license-callout__text"><?php p($l->t('Paste your %1$s license key below to unlock %2$s. The web app stays free.', [$productCode, $companionText])); ?></p>
			<div class="dc-license-callout__actions">
				<?php $renderContactActions($l); ?>
			</div>
		</div>
	</aside>
	<?php endif; ?>

	<section class="dc-license-overview" aria-label="<?php p($l->t('License overview')); ?>">
		<div class="dc-license-stat-grid">
			<article class="dc-license-stat-tile dc-license-stat <?php echo $isActive ? 'dc-license-stat-tile--success' : ($hasLicense ? 'dc-license-stat-tile--warning' : 'dc-license-stat-tile--neutral'); ?>" aria-labelledby="dc-license-stat-status-label">
				<p id="dc-license-stat-status-label" class="dc-license-stat-tile__label"><?php p($l->t('Status')); ?></p>
				<p class="dc-license-stat-tile__value">
					<span id="dc-license-active-badge" class="dc-license-badge <?php echo $isActive ? 'dc-license-badge--success' : 'dc-license-badge--warning'; ?>"
						data-active-label="<?php p($l->t('Active')); ?>"
						data-inactive-label="<?php p($l->t('Expired or invalid')); ?>"
						data-signature-invalid-label="<?php p($l->t('Signature mismatch')); ?>">
						<?php
						if ($isActive) {
							p($l->t('Active'));
						} elseif ($cryptoInvalid) {
							p($l->t('Signature mismatch'));
						} elseif ($hasLicense) {
							p($l->t('Expired or invalid'));
						} else {
							p($l->t('Not configured'));
						}
						?>
					</span>
				</p>
				<?php if ($hasLicense): ?>
				<p class="dc-license-stat-tile__meta">
					<span class="dc-license-stat__meta-label"><?php p($l->t('Valid until')); ?>:</span>
					<span id="dc-license-valid-until"><?php p($validUntil !== '' ? $validUntil : '—'); ?></span>
				</p>
				<?php endif; ?>
			</article>

			<?php if ($showMobile || !$hasLicense): ?>
			<article class="dc-license-stat-tile dc-license-stat-tile--primary dc-license-stat" aria-labelledby="dc-license-stat-mobile-label">
				<p id="dc-license-stat-mobile-label" class="dc-license-stat-tile__label"><?php p($l->t('Mobile seats')); ?></p>
				<p class="dc-license-stat-tile__value">
					<span id="dc-license-mobile-used"><?php p((string)$mobileUsed); ?></span>
					<span class="dc-license-stat-tile__sep" aria-hidden="true">/</span>
					<span id="dc-license-mobile-limit"><?php p((string)$mobileLimit); ?></span>
				</p>
				<div class="dc-license-meter" role="meter"
					aria-valuemin="0"
					aria-valuemax="<?php p((string)max(1, $mobileLimit)); ?>"
					aria-valuenow="<?php p((string)$mobileUsed); ?>"
					aria-label="<?php p($l->t('Mobile seats used')); ?>">
					<div class="dc-license-meter__fill <?php echo $mobileFull ? 'dc-license-meter__fill--full' : ''; ?>"
						id="dc-license-mobile-meter"
						style="width: <?php p((string)$mobilePct); ?>%;"></div>
				</div>
			</article>
			<?php endif; ?>

			<?php if ($showTerminal || !$hasLicense): ?>
			<article class="dc-license-stat-tile dc-license-stat-tile--primary dc-license-stat" aria-labelledby="dc-license-stat-terminal-label">
				<p id="dc-license-stat-terminal-label" class="dc-license-stat-tile__label"><?php p($devicesLabel); ?></p>
				<p class="dc-license-stat-tile__value">
					<span id="dc-license-terminal-used"><?php p((string)$terminalUsed); ?></span>
					<span class="dc-license-stat-tile__sep" aria-hidden="true">/</span>
					<span id="dc-license-terminal-limit"><?php p((string)$terminalLimit); ?></span>
				</p>
				<div class="dc-license-meter" role="meter"
					aria-valuemin="0"
					aria-valuemax="<?php p((string)max(1, $terminalLimit)); ?>"
					aria-valuenow="<?php p((string)$terminalUsed); ?>"
					aria-label="<?php p($l->t('Devices in use')); ?>">
					<div class="dc-license-meter__fill <?php echo $terminalFull ? 'dc-license-meter__fill--full' : ''; ?>"
						id="dc-license-terminal-meter"
						style="width: <?php p((string)$terminalPct); ?>%;"></div>
				</div>
			</article>
			<?php endif; ?>
		</div>
	</section>

	<section class="dc-license-card dc-license-section" aria-labelledby="dc-license-key-heading">
		<header class="dc-license-card__header">
			<div class="dc-license-card__header-text">
				<h2 id="dc-license-key-heading" class="dc-license-card__title"><?php p($l->t('Organisation license')); ?></h2>
				<p class="dc-license-card__lead"><?php p($l->t('Paste the license key you received after purchase. The web app stays free — this key unlocks %1$s for your organisation.', [$companionText])); ?></p>
			</div>
		</header>
		<div class="dc-license-card__body">
		<div class="dc-license-form">
			<label for="dc-license-key-input" class="dc-license-field__label"><?php p($l->t('License key')); ?></label>
			<textarea id="dc-license-key-input"
				class="dc-license-key-input dc-license-input"
				name="licenseKey"
				rows="4"
				spellcheck="false"
				autocomplete="off"
				aria-describedby="dc-license-key-hint"
				placeholder="<?php p($productCode . '.…'); ?>"></textarea>
			<p id="dc-license-key-hint" class="dc-license-field__hint"><?php p($l->t('Format: %1$s followed by a signed payload. One key per organisation.', [$productCode])); ?></p>
			<?php if ($instanceId !== ''): ?>
			<div class="dc-license-instance-box" role="note" aria-labelledby="dc-license-instance-label">
				<p id="dc-license-instance-label" class="dc-license-field__label"><?php p($l->t('Nextcloud instance ID')); ?></p>
				<p class="dc-license-field__hint"><?php p($instanceBound
					? $l->t('Provide this ID when ordering a license so the key can be bound to this server.')
					: $l->t('This server’s Nextcloud instance ID (for support / sales reference). Licenses are not instance-bound.')); ?></p>
				<code class="dc-license-instance-id" id="dc-license-instance-id"><?php p($instanceId); ?></code>
			</div>
			<?php endif; ?>
			<div class="dc-license-form__actions">
				<button type="button" id="dc-license-save" class="dc-license-btn dc-license-btn--primary">
					<?php p($l->t('Save license')); ?>
				</button>
				<?php if ($hasLicense): ?>
				<button type="button" id="dc-license-clear" class="dc-license-btn dc-license-btn--danger">
					<?php p($l->t('Remove license')); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<div id="dc-license-status" class="dc-license-status" <?php echo $hasLicense ? '' : 'hidden'; ?>>
			<h3 class="dc-license-status__title"><?php p($l->t('Current license')); ?></h3>
			<dl class="dc-license-status__grid">
				<div class="dc-license-status__item">
					<dt><?php p($l->t('Customer ID')); ?></dt>
					<dd id="dc-license-customer"><?php p($customerId); ?></dd>
				</div>
			</dl>
			<?php if ($expiresSoon && $isActive): ?>
			<aside class="dc-license-callout dc-license-callout--warning dc-license-expiry-callout" role="note">
				<div class="dc-license-callout__body">
					<p class="dc-license-callout__title"><?php p($l->t('License expires soon')); ?></p>
					<p class="dc-license-callout__text"><?php p($l->t('Your license expires within 30 days. Contact your vendor to renew.')); ?></p>
					<div class="dc-license-callout__actions">
						<?php $renderContactActions($l); ?>
					</div>
				</div>
			</aside>
			<?php else: ?>
			<div class="dc-license-status__purchase">
				<?php $renderContactActions($l); ?>
			</div>
			<?php endif; ?>
		</div>
		</div><!-- /.dc-license-card__body -->
	</section>

	<?php if ($showMobile): ?>
	<section class="dc-license-card dc-license-section" id="dc-mobile-seats-section" aria-labelledby="dc-mobile-seats-heading">
		<header class="dc-license-card__header">
			<div class="dc-license-card__header-text">
				<span id="dc-license-seats-heading" class="dc-license-anchor" aria-hidden="true"></span>
				<h2 id="dc-mobile-seats-heading" class="dc-license-card__title"><?php p($l->t('Mobile seats')); ?></h2>
				<p class="dc-license-card__lead"><?php p($l->t('Choose which people may use %1$s. Only assigned users can sign in from their companion device.', [$seatAppName])); ?></p>
			</div>
		</header>
		<div class="dc-license-card__body">
		<div class="dc-license-seat-picker">
			<label for="dc-seat-user-search" class="dc-license-field__label"><?php p($l->t('Add person')); ?></label>
			<div class="dc-license-search-wrap">
				<input type="search"
					id="dc-seat-user-search"
					class="dc-license-input dc-license-search-input"
					autocomplete="off"
					placeholder="<?php p($l->t('Search by name or login…')); ?>"
					aria-describedby="dc-seat-picker-hint dc-seat-count"
					aria-controls="dc-seat-search-results"
					aria-expanded="false"
					aria-autocomplete="list"
					role="combobox"
					<?php echo $mobileFull ? 'disabled aria-disabled="true"' : ''; ?>>
				<ul id="dc-seat-search-results" class="dc-seat-search-results" role="listbox" hidden></ul>
			</div>
			<p id="dc-seat-picker-hint" class="dc-license-field__hint"><?php p($l->t('Type at least two characters, tick several people, then assign them together.')); ?></p>
			<p id="dc-seat-count" class="dc-license-seat-count" aria-live="polite">
				<?php p($l->t('%1$d of %2$d seats assigned', [$mobileUsed, $mobileLimit])); ?>
			</p>
			<button type="button" id="dc-seat-assign-selected" class="dc-license-btn dc-license-btn--primary dc-license-assign-btn" hidden disabled
				aria-live="polite">
				<?php p($l->t('Assign selected')); ?>
			</button>
			<p id="dc-seats-full-hint" class="dc-license-field__hint dc-license-seats-full-hint" role="status" <?php echo $mobileFull ? '' : 'hidden'; ?>>
				<?php p($l->t('All mobile seats are assigned. Remove a user or upgrade your license.')); ?>
			</p>
			<div id="dc-seat-batch-result" class="dc-license-callout dc-license-callout--info dc-license-batch-result" role="status" aria-live="polite" hidden></div>
		</div>

		<div class="dc-license-seats-table-wrap">
			<table class="dc-license-seats-table" aria-labelledby="dc-mobile-seats-heading">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Person')); ?></th>
						<th scope="col"><?php p($l->t('User ID')); ?></th>
						<th scope="col"><?php p($l->t('Assigned')); ?></th>
						<th scope="col"><span class="dc-license-sr-only"><?php p($l->t('Actions')); ?></span></th>
					</tr>
				</thead>
				<tbody id="dc-seat-list-body">
					<?php foreach ($mobileSeats as $seat): ?>
						<tr data-user-id="<?php p((string)($seat['userId'] ?? $seat['uid'] ?? $seat['id'] ?? '')); ?>">
							<td data-label="<?php p($l->t('Person')); ?>"><?php p((string)($seat['displayName'] ?? '')); ?></td>
							<td data-label="<?php p($l->t('User ID')); ?>"><code class="dc-license-user-id"><?php p((string)($seat['userId'] ?? $seat['uid'] ?? $seat['id'] ?? '')); ?></code></td>
							<td data-label="<?php p($l->t('Assigned')); ?>"><?php p($formatAssignedAt((string)($seat['assignedAt'] ?? ''))); ?></td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell">
								<div class="dc-table-actions dc-license-table-actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
									<?php $seatRemoveLabel = $l->t('Remove') . ': ' . (string)($seat['displayName'] ?? $seat['userId'] ?? $seat['uid'] ?? ''); ?>
									<button type="button" class="dc-license-btn dc-license-btn--danger dc-license-btn--icon dc-seat-remove" data-user-id="<?php p((string)($seat['userId'] ?? $seat['uid'] ?? $seat['id'] ?? '')); ?>" aria-label="<?php p($seatRemoveLabel); ?>" title="<?php p($l->t('Remove')); ?>">
										<?php print_unescaped($trashIcon); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div id="dc-seat-empty" class="dc-license-empty-state" <?php echo count($mobileSeats) > 0 ? 'hidden' : ''; ?>>
				<p class="dc-license-empty-state__title"><?php p($l->t('No mobile seats assigned yet.')); ?></p>
				<p class="dc-license-empty-state__text"><?php p($l->t('Search for a person above to grant companion app access.')); ?></p>
			</div>
		</div>
		</div><!-- /.dc-license-card__body -->
	</section>
	<?php endif; ?>

	<?php if ($showTerminal): ?>
	<section class="dc-license-card dc-license-section" aria-labelledby="dc-terminal-heading">
		<header class="dc-license-card__header">
			<div class="dc-license-card__header-text">
				<h2 id="dc-terminal-heading" class="dc-license-card__title"><?php p($devicesTitle); ?></h2>
				<p class="dc-license-card__lead"><?php p($devicesLead); ?></p>
			</div>
			<?php if ($terminalMode === 'link' && $deviceManageUrl !== ''): ?>
			<div class="dc-license-card__header-actions">
				<a href="<?php p($deviceManageUrl); ?>" class="dc-license-btn dc-license-btn--primary">
					<?php p($deviceManageLabel); ?>
				</a>
			</div>
			<?php endif; ?>
		</header>
		<div class="dc-license-card__body">
			<?php if ($terminalMode === 'link'): ?>
			<p class="dc-license-terminal-summary">
				<?php p($l->t('%1$d of %2$d device slots in use.', [$terminalUsed, $terminalLimit])); ?>
			</p>
			<?php else: ?>
			<div class="dc-license-terminal-form">
				<div class="dc-license-terminal-fields">
					<div class="dc-license-terminal-field">
						<label for="dc-terminal-label" class="dc-license-field__label"><?php p($deviceNameLabel); ?></label>
						<input type="text"
							id="dc-terminal-label"
							class="dc-license-input"
							maxlength="128"
							autocomplete="off"
							placeholder="<?php p($deviceNamePlaceholder); ?>"
							aria-describedby="dc-terminal-count dc-terminals-full-hint"
							<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
					</div>
					<?php foreach ($deviceFields as $field): ?>
					<?php
						$fName = (string)($field['name'] ?? '');
						if ($fName === '') {
							continue;
						}
						$fType = (string)($field['type'] ?? 'text');
						$fLabel = (string)($field['label'] ?? $fName);
						$fHint = (string)($field['hint'] ?? '');
						$fPlaceholder = (string)($field['placeholder'] ?? '');
						$fRequired = !empty($field['required']);
						$fId = 'dc-terminal-field-' . preg_replace('/[^a-z0-9_-]/i', '-', $fName);
						$fOptions = is_array($field['options'] ?? null) ? $field['options'] : [];
					?>
					<div class="dc-license-terminal-field">
						<label for="<?php p($fId); ?>" class="dc-license-field__label"><?php p($fLabel); ?><?php if ($fRequired) { ?> <span aria-hidden="true">*</span><?php } ?></label>
						<?php if ($fType === 'select' && $fOptions !== []): ?>
						<select id="<?php p($fId); ?>"
							class="dc-license-input"
							data-device-field="<?php p($fName); ?>"
							<?php echo $fRequired ? 'required aria-required="true"' : ''; ?>
							<?php echo $fHint !== '' ? 'aria-describedby="' . \htmlspecialchars($fId . '-hint', ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
							<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
							<?php foreach ($fOptions as $opt): ?>
							<option value="<?php p((string)($opt['value'] ?? '')); ?>"><?php p((string)($opt['label'] ?? ($opt['value'] ?? ''))); ?></option>
							<?php endforeach; ?>
						</select>
						<?php else: ?>
						<input type="<?php p($fType === 'number' ? 'number' : 'text'); ?>"
							id="<?php p($fId); ?>"
							class="dc-license-input"
							data-device-field="<?php p($fName); ?>"
							<?php echo $fType === 'number' ? 'inputmode="numeric"' : ''; ?>
							maxlength="256"
							autocomplete="off"
							placeholder="<?php p($fPlaceholder); ?>"
							<?php echo $fRequired ? 'required aria-required="true"' : ''; ?>
							<?php echo $fHint !== '' ? 'aria-describedby="' . \htmlspecialchars($fId . '-hint', ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
							<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
						<?php endif; ?>
						<?php if ($fHint !== ''): ?>
						<p id="<?php p($fId . '-hint'); ?>" class="dc-license-field__hint"><?php p($fHint); ?></p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
				<div class="dc-license-form__actions">
					<button type="button" id="dc-terminal-register" class="dc-license-btn dc-license-btn--primary"
						<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
						<?php p($deviceRegisterLabel); ?>
					</button>
				</div>
				<p id="dc-terminal-count" class="dc-license-seat-count" aria-live="polite">
					<?php p($l->t('%1$d of %2$d device slots in use.', [$terminalUsed, $terminalLimit])); ?>
				</p>
				<p id="dc-terminals-full-hint" class="dc-license-field__hint dc-license-seats-full-hint" role="status" <?php echo $terminalFull ? '' : 'hidden'; ?>>
					<?php p($l->t('All device slots are in use. Revoke a device or upgrade your license.')); ?>
				</p>
			</div>

			<div class="dc-license-seats-table-wrap">
				<table class="dc-license-seats-table dc-license-terminals-table" aria-labelledby="dc-terminal-heading">
					<thead>
						<tr>
							<th scope="col"><?php p($deviceColLabel); ?></th>
							<th scope="col"><?php p($deviceColDetail); ?></th>
							<th scope="col"><?php p($deviceColRegistered); ?></th>
							<th scope="col"><span class="dc-license-sr-only"><?php p($l->t('Actions')); ?></span></th>
						</tr>
					</thead>
					<tbody id="dc-terminal-list-body">
						<?php foreach ($terminals as $term): ?>
							<tr data-device-id="<?php p((string)($term['id'] ?? '')); ?>">
								<td data-label="<?php p($deviceColLabel); ?>"><?php p((string)($term['label'] ?? '')); ?></td>
								<td data-label="<?php p($deviceColDetail); ?>"><?php p((string)($term['meta'] ?? '') !== '' ? (string)$term['meta'] : '—'); ?></td>
								<td data-label="<?php p($deviceColRegistered); ?>"><?php p($formatAssignedAt((string)($term['registeredAt'] ?? $term['assignedAt'] ?? ''))); ?></td>
								<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell">
									<div class="dc-table-actions dc-license-table-actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
									<?php if ((string)($_['apiRegenDeviceUrl'] ?? '') !== ''): ?>
									<button type="button" class="dc-license-btn dc-license-btn--secondary dc-license-btn--sm dc-terminal-regen" data-device-id="<?php p((string)($term['id'] ?? '')); ?>">
										<?php p($ds('regenLabel', $l->t('New pairing code'))); ?>
									</button>
									<?php endif; ?>
									<button type="button" class="dc-license-btn dc-license-btn--secondary dc-license-btn--sm dc-terminal-revoke" data-device-id="<?php p((string)($term['id'] ?? '')); ?>">
										<?php p($deviceRevokeLabel); ?>
									</button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div id="dc-terminal-empty" class="dc-license-empty-state" <?php echo count($terminals) > 0 ? 'hidden' : ''; ?>>
					<p class="dc-license-empty-state__title"><?php p($deviceEmptyTitle); ?></p>
					<p class="dc-license-empty-state__text"><?php p($deviceEmptyText); ?></p>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<dialog id="dc-license-clear-modal" class="dc-license-modal" aria-labelledby="dc-license-clear-title">
		<h3 id="dc-license-clear-title"><?php p($l->t('Remove license')); ?></h3>
		<p class="dc-license-modal__hint"><?php p($l->t('Remove the organisation license and revoke all seats and devices? This cannot be undone.')); ?></p>
		<div class="dc-license-modal__actions">
			<button type="button" id="dc-license-clear-cancel" class="dc-license-btn dc-license-btn--secondary"><?php p($l->t('Cancel')); ?></button>
			<button type="button" id="dc-license-clear-confirm" class="dc-license-btn dc-license-btn--danger"><?php p($l->t('Remove license')); ?></button>
		</div>
	</dialog>

	<dialog id="dc-license-confirm-modal" class="dc-license-modal" aria-labelledby="dc-license-confirm-title" aria-describedby="dc-license-confirm-body">
		<h3 id="dc-license-confirm-title"></h3>
		<p class="dc-license-modal__hint" id="dc-license-confirm-body"></p>
		<div class="dc-license-modal__actions">
			<button type="button" id="dc-license-confirm-cancel" class="dc-license-btn dc-license-btn--secondary"></button>
			<button type="button" id="dc-license-confirm-ok" class="dc-license-btn dc-license-btn--danger"></button>
		</div>
	</dialog>

	<dialog id="dc-license-token-modal" class="dc-license-modal" aria-labelledby="dc-license-token-title">
		<h3 id="dc-license-token-title"></h3>
		<p class="dc-license-modal__hint" id="dc-license-token-hint"></p>
		<code class="dc-license-token-value" id="dc-license-token-value" tabindex="0"></code>
		<div class="dc-license-modal__actions">
			<button type="button" id="dc-license-token-copy" class="dc-license-btn dc-license-btn--primary"></button>
			<button type="button" id="dc-license-token-close" class="dc-license-btn dc-license-btn--secondary"></button>
		</div>
	</dialog>
</div>

<?php
/**
 * App settings page (governance, app-admins only).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$canAdminApp = !empty($_['isAppAdmin']);
?>
<?php if (!$canAdminApp): ?>
	<section class="dc-card dc-section">
		<header class="dc-section__header">
			<div>
				<h2><?php p($l->t('App policy')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Only app administrators may change these settings.')); ?>
				</p>
			</div>
		</header>
		<div class="dc-callout dc-callout--info">
			<p><?php p($l->t('You do not have permission to view or change app policy. Ask an app administrator if you need adjustments.')); ?></p>
		</div>
	</section>
<?php else: ?>
	<section class="dc-card dc-empty dc-empty--quickstart" id="dc-settings-quickstart" hidden aria-labelledby="dc-settings-quickstart-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Decide who can use DutyCheck and who can change settings. Mistakes here can lock people out — read the safety tip in step 2.')); ?>
				</p>
			</div>
			<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="settings_quickstart_v1" aria-describedby="dc-settings-quickstart-title">
				<?php p($l->t('Hide tips')); ?>
			</button>
		</header>
		<ol class="dc-quickstart">
			<li class="dc-quickstart__item" data-step="audience">
				<strong><?php p($l->t('1. Choose the audience')); ?></strong>
				<p>
					<?php p($l->t('By default, every Nextcloud member can open DutyCheck. If your organisation is large, restrict access to selected users and groups instead.')); ?>
				</p>
			</li>
			<li class="dc-quickstart__item" data-step="safety">
				<strong><?php p($l->t('2. Add allowlist entries before turning restriction on')); ?></strong>
				<p>
					<?php p($l->t('Always add at least one user or group to the allowlist before enabling the restriction toggle. Otherwise the app locks itself — including you, until a system admin re-opens it.')); ?>
				</p>
			</li>
			<li class="dc-quickstart__item" data-step="delegate">
				<strong><?php p($l->t('3. Delegate app-admin powers carefully')); ?></strong>
				<p>
					<?php p($l->t('App administrators can change this policy and re-open closed periods. Only delegate to people you trust to handle the audit log.')); ?>
				</p>
			</li>
		</ol>
	</section>
	<section class="dc-card dc-section" aria-labelledby="dc-settings-policy-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-policy-title"><?php p($l->t('Access control')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Decide who may open DutyCheck. Restriction takes effect immediately for non-administrators.')); ?>
				</p>
			</div>
			<div class="dc-section__controls">
				<span id="dc-policy-state-badge" class="dc-status-badge" aria-live="polite"></span>
				<span id="dc-policy-dirty" class="dc-pill" hidden><?php p($l->t('Unsaved changes')); ?></span>
			</div>
		</header>
		<form id="dc-app-policy-form" class="dc-form-grid" novalidate>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-policy-restriction">
					<input id="dc-policy-restriction" type="checkbox" name="accessRestrictionEnabled">
					<span class="dc-checkbox__text">
						<?php p($l->t('Restrict app access to selected users and groups only')); ?>
					</span>
				</label>
				<p class="dc-field__hint">
					<?php p($l->t('Tip: turn this on after you have already added at least one allowed user or group below, otherwise the app locks itself.')); ?>
				</p>
			</div>

			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-policy-user-search"><?php p($l->t('Allowed users')); ?></label>
				<div class="dc-entity-picker">
					<input id="dc-policy-user-search" type="search" class="dc-input"
						autocomplete="off" placeholder="<?php p($l->t('Search Nextcloud users…')); ?>"
						aria-controls="dc-policy-user-results">
					<ul id="dc-policy-user-results" class="dc-entity-results" role="listbox"
						aria-label="<?php p($l->t('User search results')); ?>"></ul>
				</div>
				<ul id="dc-policy-user-chips" class="dc-chip-list" aria-label="<?php p($l->t('Selected users')); ?>"></ul>
				<p class="dc-field__hint" id="dc-policy-user-search-hint">
					<?php p($l->t('Type at least 2 characters and pick a result, or press Enter to add an exact user ID.')); ?>
				</p>
			</div>

			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-policy-group-search"><?php p($l->t('Allowed groups')); ?></label>
				<div class="dc-entity-picker">
					<input id="dc-policy-group-search" type="search" class="dc-input"
						autocomplete="off" placeholder="<?php p($l->t('Search groups…')); ?>"
						aria-controls="dc-policy-group-results">
					<ul id="dc-policy-group-results" class="dc-entity-results" role="listbox"
						aria-label="<?php p($l->t('Group search results')); ?>"></ul>
				</div>
				<ul id="dc-policy-group-chips" class="dc-chip-list" aria-label="<?php p($l->t('Selected groups')); ?>"></ul>
				<p class="dc-field__hint" id="dc-policy-group-search-hint">
					<?php p($l->t('Type at least 2 characters and pick a result, or press Enter to add an exact group ID.')); ?>
				</p>
			</div>

			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-policy-admin-search"><?php p($l->t('App administrators')); ?></label>
				<div class="dc-entity-picker">
					<input id="dc-policy-admin-search" type="search" class="dc-input"
						autocomplete="off" placeholder="<?php p($l->t('Search Nextcloud users…')); ?>"
						aria-controls="dc-policy-admin-results">
					<ul id="dc-policy-admin-results" class="dc-entity-results" role="listbox"
						aria-label="<?php p($l->t('App administrator search results')); ?>"></ul>
				</div>
				<ul id="dc-policy-admin-chips" class="dc-chip-list" aria-label="<?php p($l->t('Selected app administrators')); ?>"></ul>
				<p class="dc-field__hint" id="dc-policy-admin-search-hint">
					<?php p($l->t('Type at least 2 characters and pick a result, or press Enter to add an exact user ID.')); ?>
				</p>
				<p class="dc-field__hint">
					<?php p($l->t('App administrators can change this policy and may re-open closed periods. System administrators always have these powers.')); ?>
				</p>
			</div>

			<div class="dc-form-actions">
				<button type="submit" class="button primary" id="dc-policy-save"><?php p($l->t('Save app policy')); ?></button>
				<button type="button" id="dc-policy-discard" class="button" disabled>
					<?php p($l->t('Discard changes')); ?>
				</button>
			</div>
		</form>
	</section>

	<section class="dc-card dc-section dc-settings-panel" aria-labelledby="dc-settings-planning-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-planning-title"><?php p($l->t('Planning defaults')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('This break is filled in automatically when someone adds a new assignment. They can still change it for each shift.')); ?>
				</p>
			</div>
		</header>
		<form id="dc-planning-defaults-form" class="dc-form-grid" novalidate>
			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-planning-default-break"><?php p($l->t('Default break (minutes)')); ?></label>
				<input id="dc-planning-default-break" type="number" name="defaultBreakMinutes" class="dc-input dc-input--num"
					min="0" max="720" step="5" value="0" required
					aria-describedby="dc-planning-default-break-hint dc-planning-default-break-note">
				<p id="dc-planning-default-break-hint" class="dc-field__hint">
					<?php p($l->t('Example: 30 for a half-hour lunch. Use 0 when shifts have no break.')); ?>
				</p>
				<p id="dc-planning-default-break-note" class="dc-field__hint dc-settings-planning-note">
					<?php p($l->t('This value is loaded fresh whenever someone opens “Add assignment”. If the default is 0, planners may still see their last break from this browser.')); ?>
				</p>
			</div>
			<p id="dc-planning-defaults-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
			<div class="dc-form-actions">
				<button type="submit" class="button primary" id="dc-planning-defaults-save"><?php p($l->t('Save planning defaults')); ?></button>
			</div>
		</form>
	</section>

	<section class="dc-card dc-section dc-at-integration" id="dc-at-integration" aria-labelledby="dc-at-integ-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-at-integ-title"><?php p($l->t('ArbeitszeitCheck integration')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Mirror absences from ArbeitszeitCheck for roster conflicts. DutyCheck never writes to ArbeitszeitCheck.')); ?>
				</p>
			</div>
		</header>
		<div class="dc-at-integration__body">
			<div class="dc-at-integration__status" aria-labelledby="dc-at-status-heading">
				<h3 id="dc-at-status-heading" class="dc-subsection-heading"><?php p($l->t('Status')); ?></h3>
				<div class="dc-at-integration__banner-row">
					<div id="dc-at-integration-banner" class="dc-callout dc-callout--warning dc-at-integration__banner" hidden role="status" aria-live="polite"></div>
					<button type="button" class="button dc-at-integration__retry" id="dc-at-retry-load-btn" hidden>
						<?php p($l->t('Load integration status again')); ?>
					</button>
				</div>
				<p class="dc-field__hint dc-at-integration__meta" id="dc-at-meta" aria-live="polite"></p>
			</div>
			<hr class="dc-form-grid__divider dc-at-integration__divider" aria-hidden="true">
			<div class="dc-at-integration__controls" role="group" aria-labelledby="dc-at-controls-heading">
				<h3 id="dc-at-controls-heading" class="dc-subsection-heading"><?php p($l->t('Integration controls')); ?></h3>
				<div class="dc-field dc-field--full">
					<label class="dc-checkbox" for="dc-at-intent-enabled">
						<input type="checkbox" id="dc-at-intent-enabled" name="atIntent" aria-describedby="dc-at-intent-hint">
						<span class="dc-checkbox__text"><?php p($l->t('Enable integration')); ?></span>
					</label>
					<p class="dc-field__hint" id="dc-at-intent-hint"></p>
				</div>
				<div class="dc-form-actions dc-at-integration__actions">
					<button type="button" class="button" id="dc-at-sync-btn"><?php p($l->t('Sync now')); ?></button>
					<button type="button" class="button danger" id="dc-at-purge-legacy-btn" hidden><?php p($l->t('Remove legacy DutyCheck absences')); ?></button>
					<a class="button" id="dc-at-open-peer" href="#" hidden target="_blank" rel="noopener noreferrer"><?php p($l->t('Open ArbeitszeitCheck')); ?></a>
				</div>
			</div>
		</div>
	</section>
<?php endif; ?>
<?php include __DIR__ . '/common/page-end.php'; ?>

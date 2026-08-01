<?php
/**
 * Settings sub-page: Access control (default section).
 *
 * Quick-start guidance plus the app access policy form. Section ids are a
 * stable contract: js/settings.js wires them and legacy /settings#… anchors
 * redirect onto this page (see SettingsSectionCatalog::LEGACY_ANCHORS).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$dcDutyRolesUrl = (string) (($_['urls']['settingsSections'] ?? [])['duty-roles'] ?? '');
$dcEmployeesUrl = (string) ($_['urls']['employees'] ?? '');
?>
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
				<?php p($l->t('Opening DutyCheck is separate from planner or staff access. Without directory restriction, any Nextcloud member can open the app — but they still need a planner role on Duty roles or an employee catalog link to use roster features.')); ?>
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
<section class="dc-card dc-section" id="dc-settings-policy" aria-labelledby="dc-settings-policy-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-policy-title" class="dc-sr-only"><?php p($l->t('Access control')); ?></h2>
		</div>
		<div class="dc-section__controls">
			<span id="dc-policy-state-badge" class="dc-status-badge" aria-live="polite"></span>
			<span id="dc-policy-dirty" class="dc-pill" hidden><?php p($l->t('Unsaved changes')); ?></span>
		</div>
	</header>
	<div class="dc-callout dc-callout--info" role="note" aria-labelledby="dc-access-gate-title">
		<p id="dc-access-gate-title"><strong><?php p($l->t('This list controls the door, not the data.')); ?></strong></p>
		<p class="dc-field__hint">
			<?php p($l->t('Adding users or groups here only lets them open DutyCheck.')); ?>
			<?php if ($dcDutyRolesUrl !== '' && $dcDutyRolesUrl !== '#'): ?>
				<a class="dc-inline-link" href="<?php p($dcDutyRolesUrl); ?>"><?php p($l->t('Assign planner roles on Duty roles')); ?></a>.
			<?php endif; ?>
			<?php if ($dcEmployeesUrl !== '' && $dcEmployeesUrl !== '#'): ?>
				<a class="dc-inline-link" href="<?php p($dcEmployeesUrl); ?>"><?php p($l->t('Link staff accounts on the Employees page')); ?></a>.
			<?php endif; ?>
		</p>
	</div>
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
			<?php p($l->t('Type at least 2 characters and pick a user from the list.')); ?>
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
			<?php p($l->t('Type at least 2 characters and pick a group from the list.')); ?>
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
			<?php p($l->t('Type at least 2 characters and pick a user from the list.')); ?>
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

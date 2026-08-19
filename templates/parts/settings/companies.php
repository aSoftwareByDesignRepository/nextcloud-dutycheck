<?php
/**
 * Settings sub-page: Companies / workspaces (multi-company isolation).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-companies" aria-labelledby="dc-settings-companies-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-companies-title" class="dc-sr-only"><?php p($l->t('Companies / workspaces')); ?></h2>
		</div>
	</header>
	<div class="dc-callout dc-callout--info" id="dc-companies-legacy-hint">
		<p><?php p($l->t('With only the Default company, everyone with planner access sees all employees, locations, and periods — same as before multi-company.')); ?></p>
		<p><?php p($l->t('After you create a second company, planners who are not members of any company see an empty roster. Add them below — they do not fall back to Default.')); ?></p>
	</div>
	<form id="dc-company-form" class="dc-form-grid" novalidate>
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-company-name"><?php p($l->t('New company name')); ?></label>
			<input id="dc-company-name" type="text" class="dc-input" name="name" maxlength="120" required
				aria-describedby="dc-company-name-hint">
			<p id="dc-company-name-hint" class="dc-field__hint">
				<?php p($l->t('You stay a member of Default so existing rosters remain visible.')); ?>
			</p>
		</div>
		<p id="dc-company-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Create company')); ?></button>
		</div>
	</form>
	<ul id="dc-company-list" class="dc-chip-list" aria-label="<?php p($l->t('Companies')); ?>"></ul>
	<form id="dc-company-member-form" class="dc-form-grid dc-form-grid--nested" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-company-member-company"><?php p($l->t('Company')); ?></label>
			<select id="dc-company-member-company" class="dc-input" name="companyId" required></select>
		</div>
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-company-member-user-search"><?php p($l->t('Colleague')); ?></label>
			<div class="dc-entity-picker">
				<input id="dc-company-member-user-search" type="search" class="dc-input"
					autocomplete="off" placeholder="<?php p($l->t('Search colleagues…')); ?>"
					aria-controls="dc-company-member-user-results">
				<ul id="dc-company-member-user-results" class="dc-entity-results" role="listbox"
					aria-label="<?php p($l->t('User search results')); ?>"></ul>
			</div>
			<input type="hidden" id="dc-company-member-user" name="userId">
			<p class="dc-field__hint" id="dc-company-member-user-hint">
				<?php p($l->t('Type at least 2 characters and pick a colleague from the list.')); ?>
			</p>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-company-member-role"><?php p($l->t('Role')); ?></label>
			<select id="dc-company-member-role" class="dc-input" name="role">
				<option value="member"><?php p($l->t('Member')); ?></option>
				<option value="admin"><?php p($l->t('Admin')); ?></option>
			</select>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button" id="dc-company-member-submit" disabled><?php p($l->t('Add member')); ?></button>
		</div>
	</form>
	<ul id="dc-company-member-list" class="dc-chip-list" aria-label="<?php p($l->t('Company members')); ?>"></ul>
</section>

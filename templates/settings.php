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
	<?php include __DIR__ . '/parts/settings-toc.php'; ?>

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
					<?php p($l->t('Opening DutyCheck is separate from planner or staff access. Without directory restriction, any Nextcloud member can open the app — but they still need a planner role below or an employee catalog link to use roster features.')); ?>
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
		<div class="dc-callout dc-callout--info" role="note" aria-labelledby="dc-access-gate-title">
			<p id="dc-access-gate-title"><strong><?php p($l->t('This list controls the door, not the data.')); ?></strong></p>
			<p class="dc-field__hint">
				<?php p($l->t('Adding users or groups here only lets them open DutyCheck. Planners need a duty role in the section below. Staff need their Nextcloud account linked to an employee record on the Employees page.')); ?>
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

	<section class="dc-card dc-section" id="dc-settings-duty-roles" aria-labelledby="dc-settings-duty-roles-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-duty-roles-title"><?php p($l->t('Duty roles')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Planners can manage rosters, periods, and the employee catalog. Employee access is granted by linking a Nextcloud account on the Employees page — not here.')); ?>
				</p>
			</div>
		</header>
		<div class="dc-form-grid">
			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-duty-role-user-search"><?php p($l->t('Assign planner role')); ?></label>
				<div class="dc-entity-picker">
					<input id="dc-duty-role-user-search" type="search" class="dc-input"
						autocomplete="off" placeholder="<?php p($l->t('Search users to assign planner role…')); ?>"
						aria-controls="dc-duty-role-user-results">
					<ul id="dc-duty-role-user-results" class="dc-entity-results" role="listbox"
						aria-label="<?php p($l->t('User search results')); ?>"></ul>
				</div>
				<div class="dc-form-actions">
					<button type="button" class="button primary" id="dc-duty-role-assign" disabled>
						<?php p($l->t('Assign planner')); ?>
					</button>
				</div>
			</div>
			<div class="dc-field dc-field--full">
				<h3 class="dc-subsection-heading"><?php p($l->t('Current duty role assignments')); ?></h3>
				<div class="dc-table-wrap">
					<table class="dc-table" id="dc-duty-roles-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('User')); ?></th>
								<th scope="col"><?php p($l->t('Role')); ?></th>
								<th scope="col"><?php p($l->t('Assigned')); ?></th>
								<th scope="col" class="dc-table__col--actions"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody id="dc-duty-roles-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>
	</section>

	<section class="dc-card dc-section dc-settings-panel" id="dc-settings-planning" aria-labelledby="dc-settings-planning-title">
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

	<section class="dc-card dc-section" id="dc-settings-companies" aria-labelledby="dc-settings-companies-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-companies-title"><?php p($l->t('Companies / workspaces')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('One Default company keeps legacy installs unrestricted. Creating a second company turns on membership isolation for planners.')); ?>
				</p>
			</div>
		</header>
		<div class="dc-callout dc-callout--info" id="dc-companies-legacy-hint">
			<p><?php p($l->t('With only the Default company, everyone with planner access sees all employees, locations, and periods — same as before multi-company.')); ?></p>
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
			<div class="dc-field">
				<label class="dc-field__label" for="dc-company-member-user"><?php p($l->t('Nextcloud user id')); ?></label>
				<input id="dc-company-member-user" type="text" class="dc-input" name="userId" maxlength="64" required autocomplete="off">
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-company-member-role"><?php p($l->t('Role')); ?></label>
				<select id="dc-company-member-role" class="dc-input" name="role">
					<option value="member"><?php p($l->t('Member')); ?></option>
					<option value="admin"><?php p($l->t('Admin')); ?></option>
				</select>
			</div>
			<div class="dc-form-actions">
				<button type="submit" class="button"><?php p($l->t('Add member')); ?></button>
			</div>
		</form>
		<ul id="dc-company-member-list" class="dc-chip-list" aria-label="<?php p($l->t('Company members')); ?>"></ul>
	</section>

	<section class="dc-card dc-section" id="dc-settings-conflict-policy" aria-labelledby="dc-settings-conflict-policy-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-conflict-policy-title"><?php p($l->t('Conflict thresholds')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Period totals and daily limits used by planning checks. Defaults stay ArbZG-oriented until you change them.')); ?>
				</p>
			</div>
		</header>
		<form id="dc-conflict-policy-form" class="dc-form-grid" novalidate>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-policy-min-rest"><?php p($l->t('Minimum rest (minutes)')); ?></label>
				<input id="dc-policy-min-rest" type="number" class="dc-input dc-input--num" name="minRestMinutes" min="0" max="1440" step="1" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-policy-max-daily"><?php p($l->t('Daily hard cap (minutes)')); ?></label>
				<input id="dc-policy-max-daily" type="number" class="dc-input dc-input--num" name="maxDailyHard" min="1" max="1440" step="1" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-policy-max-soft"><?php p($l->t('Period soft cap (minutes)')); ?></label>
				<input id="dc-policy-max-soft" type="number" class="dc-input dc-input--num" name="maxPeriodSoft" min="1" max="20000" step="1" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-policy-max-hard"><?php p($l->t('Period hard cap (minutes)')); ?></label>
				<input id="dc-policy-max-hard" type="number" class="dc-input dc-input--num" name="maxPeriodHard" min="1" max="20000" step="1" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-policy-max-consec"><?php p($l->t('Max consecutive days')); ?></label>
				<input id="dc-policy-max-consec" type="number" class="dc-input dc-input--num" name="maxConsecutiveDays" min="1" max="31" step="1" required>
			</div>
			<p id="dc-conflict-policy-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Save conflict thresholds')); ?></button>
			</div>
		</form>
	</section>

	<section class="dc-card dc-section" id="dc-settings-templates" aria-labelledby="dc-settings-templates-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-templates-title"><?php p($l->t('Shift templates')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Named start/end/break presets for the roster “Add assignment” dialog. Optional location keeps a template site-specific.')); ?>
				</p>
			</div>
		</header>
		<form id="dc-template-form" class="dc-form-grid" novalidate>
			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-template-name"><?php p($l->t('Name')); ?></label>
				<input id="dc-template-name" type="text" class="dc-input" name="name" maxlength="120" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-template-start"><?php p($l->t('Start')); ?></label>
				<input id="dc-template-start" type="time" class="dc-input dc-input--time-24h" name="startTime" step="60" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-template-end"><?php p($l->t('End')); ?></label>
				<input id="dc-template-end" type="time" class="dc-input dc-input--time-24h" name="endTime" step="60" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-template-break"><?php p($l->t('Break (minutes)')); ?></label>
				<input id="dc-template-break" type="number" class="dc-input dc-input--num" name="breakMinutes" min="0" max="720" step="5" value="0" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-template-headcount"><?php p($l->t('Minimum headcount')); ?></label>
				<input id="dc-template-headcount" type="number" class="dc-input dc-input--num" name="minHeadcount" min="0" max="99" step="1" value="0" aria-describedby="dc-template-headcount-hint">
				<p id="dc-template-headcount-hint" class="dc-field__hint">
					<?php p($l->t('When greater than zero and a location is set, days with fewer assignments show a “Confirm to continue” understaffed check. Use 0 to disable.')); ?>
				</p>
			</div>
			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-template-location"><?php p($l->t('Location (optional)')); ?></label>
				<select id="dc-template-location" class="dc-input" name="locationId">
					<option value=""><?php p($l->t('Global (all locations)')); ?></option>
				</select>
			</div>
			<p id="dc-template-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Add template')); ?></button>
			</div>
		</form>
		<ul id="dc-template-list" class="dc-chip-list" aria-label="<?php p($l->t('Saved shift templates')); ?>"></ul>
	</section>

	<section class="dc-card dc-section" id="dc-settings-quals" aria-labelledby="dc-settings-quals-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-quals-title"><?php p($l->t('Qualifications')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Catalog of skills or certificates. Missing required qualifications block assign/publish. Expired quals are soft warnings.')); ?>
				</p>
			</div>
		</header>
		<form id="dc-qual-form" class="dc-form-grid" novalidate>
			<div class="dc-field dc-field--full">
				<label class="dc-field__label" for="dc-qual-name"><?php p($l->t('Name')); ?></label>
				<input id="dc-qual-name" type="text" class="dc-input" name="name" maxlength="120" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-code"><?php p($l->t('Code (optional)')); ?></label>
				<input id="dc-qual-code" type="text" class="dc-input" name="code" maxlength="64">
			</div>
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Add qualification')); ?></button>
			</div>
		</form>
		<ul id="dc-qual-list" class="dc-chip-list" aria-label="<?php p($l->t('Qualification catalog')); ?>"></ul>
		<form id="dc-qual-attach-form" class="dc-form-grid dc-form-grid--nested" novalidate>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-attach-emp"><?php p($l->t('Employee')); ?></label>
				<select id="dc-qual-attach-emp" class="dc-input" name="employeeId" required>
					<option value=""><?php p($l->t('Choose an employee…')); ?></option>
				</select>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-attach-id"><?php p($l->t('Qualification')); ?></label>
				<select id="dc-qual-attach-id" class="dc-input" name="qualificationId" required>
					<option value=""><?php p($l->t('Choose a qualification…')); ?></option>
				</select>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-attach-expires"><?php p($l->t('Expires on (optional)')); ?></label>
				<input id="dc-qual-attach-expires" type="date" class="dc-input" name="expiresOn">
			</div>
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Attach to employee')); ?></button>
			</div>
		</form>
		<form id="dc-qual-detach-form" class="dc-form-grid dc-form-grid--nested" novalidate>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-detach-emp"><?php p($l->t('Employee')); ?></label>
				<select id="dc-qual-detach-emp" class="dc-input" name="employeeId" required>
					<option value=""><?php p($l->t('Choose an employee…')); ?></option>
				</select>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-detach-id"><?php p($l->t('Qualification')); ?></label>
				<select id="dc-qual-detach-id" class="dc-input" name="qualificationId" required>
					<option value=""><?php p($l->t('Choose a qualification…')); ?></option>
				</select>
			</div>
			<div class="dc-form-actions">
				<button type="submit" class="button"><?php p($l->t('Remove from employee')); ?></button>
			</div>
		</form>
		<form id="dc-qual-loc-form" class="dc-form-grid" novalidate>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-loc-id"><?php p($l->t('Location')); ?></label>
				<select id="dc-qual-loc-id" class="dc-input" name="locationId" required>
					<option value=""><?php p($l->t('Choose a location…')); ?></option>
				</select>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-qual-loc-qid"><?php p($l->t('Required qualification')); ?></label>
				<select id="dc-qual-loc-qid" class="dc-input" name="qualificationId" required>
					<option value=""><?php p($l->t('Choose a qualification…')); ?></option>
				</select>
			</div>
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Require at location')); ?></button>
			</div>
		</form>
	</section>

	<section class="dc-card dc-section" id="dc-settings-scope" aria-labelledby="dc-settings-scope-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-scope-title"><?php p($l->t('Planner location scope')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Limit a planner to specific locations. Leave none selected for unrestricted (legacy global planners). App admins are never scoped.')); ?>
				</p>
			</div>
		</header>
		<form id="dc-planner-scope-form" class="dc-form-grid" novalidate>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-scope-user"><?php p($l->t('Planner user ID')); ?></label>
				<input id="dc-scope-user" type="text" class="dc-input" name="userId" maxlength="64" required autocomplete="off">
			</div>
			<div class="dc-field dc-field--full">
				<fieldset class="dc-fieldset">
					<legend class="dc-field__label"><?php p($l->t('Locations this planner may edit')); ?></legend>
					<p class="dc-hint" id="dc-scope-locs-hint"><?php p($l->t('Tick locations to restrict. Leave all unticked for every location (legacy).')); ?></p>
					<div id="dc-scope-locs" class="dc-check-grid" role="group" aria-describedby="dc-scope-locs-hint"></div>
				</fieldset>
			</div>
			<p id="dc-scope-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
			<div class="dc-form-actions">
				<button type="button" class="button" id="dc-scope-load"><?php p($l->t('Load current scope')); ?></button>
				<button type="submit" class="button primary"><?php p($l->t('Save scope')); ?></button>
			</div>
		</form>
	</section>

	<section class="dc-card dc-section" id="dc-settings-ops" aria-labelledby="dc-settings-ops-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-ops-title"><?php p($l->t('Notifications & retention')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Optional soft-cap approach notices, MaintenanceCheck on-duty hook, and cold-archive retention for old snapshots (never deletes the latest close snapshot of a still-closed period).')); ?>
				</p>
			</div>
		</header>
		<form id="dc-ops-flags-form" class="dc-form-grid" novalidate>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-ops-threshold-notify">
					<input id="dc-ops-threshold-notify" type="checkbox" name="thresholdApproachNotify">
					<span class="dc-checkbox__text"><?php p($l->t('Notify linked staff when their period total approaches the soft cap')); ?></span>
				</label>
			</div>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-ops-mc-hook">
					<input id="dc-ops-mc-hook" type="checkbox" name="mcOnDutyHookEnabled">
					<span class="dc-checkbox__text"><?php p($l->t('Enable MaintenanceCheck on-duty read hook (feature-flagged)')); ?></span>
				</label>
			</div>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-ops-hr-export">
					<input id="dc-ops-hr-export" type="checkbox" name="hrRosterMinutesExportEnabled">
					<span class="dc-checkbox__text"><?php p($l->t('Enable HR roster-minutes CSV export (no salary amounts)')); ?></span>
				</label>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-ops-retention-days"><?php p($l->t('Snapshot retention (days, 0 = keep forever)')); ?></label>
				<input id="dc-ops-retention-days" type="number" class="dc-input dc-input--num" name="snapshotRetentionDays" min="0" max="3650" step="1" value="0">
			</div>
			<p id="dc-ops-flags-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Save notification & retention settings')); ?></button>
				<button type="button" class="button" id="dc-ops-prune-snapshots"><?php p($l->t('Prune expired snapshots now')); ?></button>
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
						<span class="dc-checkbox__text"><?php p($l->t('Connect DutyCheck to ArbeitszeitCheck')); ?></span>
					</label>
					<p class="dc-field__hint" id="dc-at-intent-hint"></p>
				</div>
				<div class="dc-field dc-field--full" id="dc-at-disable-reason-wrap" hidden>
					<label class="dc-field__label" for="dc-at-disable-reason"><?php p($l->t('Why are you turning the connection off? (optional)')); ?></label>
					<input type="text" class="dc-input" id="dc-at-disable-reason" name="disableReason" maxlength="500" autocomplete="off" aria-describedby="dc-at-disable-reason-hint">
					<p class="dc-field__hint" id="dc-at-disable-reason-hint"><?php p($l->t('A short note is saved in the audit log so others know why the connector was disabled.')); ?></p>
				</div>
				<div class="dc-field dc-field--full">
					<label class="dc-checkbox" for="dc-at-block-publish-stale">
						<input type="checkbox" id="dc-at-block-publish-stale" name="blockPublishWhenStale" aria-describedby="dc-at-block-publish-hint">
						<span class="dc-checkbox__text"><?php p($l->t('Block roster publish when absence sync is stale')); ?></span>
					</label>
					<p class="dc-field__hint" id="dc-at-block-publish-hint">
						<?php p($l->t('When on, planners cannot publish a period if the last sync is older than the publish window or the sync circuit breaker is open. Default: off (show a warning instead).')); ?>
					</p>
				</div>
				<div class="dc-field dc-field--full">
					<label class="dc-checkbox" for="dc-at-include-pii">
						<input type="checkbox" id="dc-at-include-pii" name="includePii" aria-describedby="dc-at-include-pii-hint">
						<span class="dc-checkbox__text"><?php p($l->t('Include sensitive absence notes in the mirror (PII)')); ?></span>
					</label>
					<p class="dc-field__hint" id="dc-at-include-pii-hint">
						<?php p($l->t('Off by default. When on, reason and approver comments are copied into DutyCheck. Requires a short written justification. Turn off to scrub stored notes.')); ?>
					</p>
					<label class="dc-field__label" for="dc-at-pii-justification"><?php p($l->t('PII justification (required to enable)')); ?></label>
					<input type="text" class="dc-input" id="dc-at-pii-justification" name="piiJustification" maxlength="500" autocomplete="off" aria-describedby="dc-at-include-pii-hint">
				</div>
				<div class="dc-form-actions dc-at-integration__actions">
					<button type="button" class="button primary" id="dc-at-sync-btn"><?php p($l->t('Sync now')); ?></button>
					<button type="button" class="button danger" id="dc-at-purge-legacy-btn" hidden><?php p($l->t('Remove legacy DutyCheck absences')); ?></button>
					<a class="button" id="dc-at-open-peer" hidden target="_blank" rel="noopener noreferrer"><?php p($l->t('Open ArbeitszeitCheck')); ?></a>
				</div>
			</div>
		</div>
	</section>

	<section class="dc-card dc-section" id="dc-settings-privacy" aria-labelledby="dc-settings-privacy-title">
		<header class="dc-section__header">
			<div>
				<h2 id="dc-settings-privacy-title"><?php p($l->t('Privacy & words we use')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('How DutyCheck treats personal data, and the plain-language terms used in this app.')); ?>
				</p>
			</div>
		</header>
		<div class="dc-settings-privacy">
			<h3 class="dc-subsection-heading"><?php p($l->t('Privacy')); ?></h3>
			<ul class="dc-settings-privacy__list">
				<li><?php p($l->t('Duty assignments and absences stay on your Nextcloud. There is no vendor cloud copy of your roster.')); ?></li>
				<li><?php p($l->t('When a Nextcloud account is deleted, DutyCheck removes that user’s roles, access-list entries, company membership, planner location scope, mobile seat, preferences, and employee account link. Historical roster rows stay for audit evidence with the link cleared.')); ?></li>
				<li><?php p($l->t('Close snapshots are hash-chained and never rewritten. Re-opening a period starts a new planning cycle without mutating the old evidence.')); ?></li>
				<li><?php p($l->t('iCal feed tokens can be rotated per employee. Treat feed URLs like passwords.')); ?></li>
			</ul>
			<h3 class="dc-subsection-heading"><?php p($l->t('Words we use')); ?></h3>
			<dl class="dc-settings-privacy__glossary">
				<div>
					<dt><?php p($l->t('Period')); ?></dt>
					<dd><?php p($l->t('A date range you plan in: open → published → closed.')); ?></dd>
				</div>
				<div>
					<dt><?php p($l->t('Assignment')); ?></dt>
					<dd><?php p($l->t('One planned shift for one person on one day.')); ?></dd>
				</div>
				<div>
					<dt><?php p($l->t('Must fix')); ?></dt>
					<dd><?php p($l->t('A hard conflict that blocks saving or publishing until resolved.')); ?></dd>
				</div>
				<div>
					<dt><?php p($l->t('Confirm to continue')); ?></dt>
					<dd><?php p($l->t('A soft conflict you may keep after writing a short reason (≥ 10 characters).')); ?></dd>
				</div>
				<div>
					<dt><?php p($l->t('Snapshot')); ?></dt>
					<dd><?php p($l->t('An immutable roster picture taken on publish or close, with an integrity hash.')); ?></dd>
				</div>
			</dl>
		</div>
	</section>

	<?php
	$licenseStatus = null;
	$licenseSeatsList = null;
	try {
		$licenseService = \OCP\Server::get(\OCA\DutyCheck\Service\LicenseService::class);
		$licenseStatus = $licenseService->status();
		$licenseSeatsList = $licenseService->listSeats(50, 0);
	} catch (\Throwable) {
		$licenseStatus = null;
		$licenseSeatsList = null;
	}
	$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	$licenseApiUrl = $urlGenerator->linkToRouteAbsolute('dutycheck.license.show');
	$licenseClearUrl = $licenseApiUrl;
	$licenseSeatsUrl = $urlGenerator->linkToRouteAbsolute('dutycheck.license.seats');
	$licenseAssignSeatUrl = $licenseSeatsUrl;
	$licenseRemoveSeatBase = rtrim($licenseSeatsUrl, '/') . '/';
	$licenseSearchUsersUrl = $urlGenerator->linkToRouteAbsolute('dutycheck.rosterApi.directoryUsers');
	$requesttoken = \OCP\Util::callRegister();
	include __DIR__ . '/parts/license-panel.php';
	\OCP\Util::addStyle('dutycheck', 'license-settings');
	\OCP\Util::addScript('dutycheck', 'license-settings');
	?>
	<?php
	// Support & Us — informational CTAs only; never gates AGPL use.
	$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
	$supportUsCssPrefix = 'dc';
	$supportUsBtnPrimaryClass = 'button primary';
	$supportUsBtnSecondaryClass = 'button';
	$supportUsLinks = new \OCA\DutyCheck\Support\SupportUsLinks(
		'DutyCheck',
		true,
		$urlGenerator->linkToRouteAbsolute('dutycheck.page.settings') . '#dutycheck-license',
	);
	include __DIR__ . '/parts/support-us-section.php';
	?>
<?php endif; ?>
<?php include __DIR__ . '/common/page-end.php'; ?>

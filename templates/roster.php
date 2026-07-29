<?php

/**
 * Roster planning page.
 *
 * Layout (top → bottom):
 *  - Period switcher
 *  - Assignments table (+ “Add assignment” opens the create modal)
 *  - Planning checks (conflicts)
 *  - Export (app admins only)
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$htmlLang = (string) (($_['clientHints']['htmlLang'] ?? 'en-US'));
$urls = (array) ($_['urls'] ?? []);
$rosterPhpL10n = [
	'conflictMessages' => [],
];
foreach (\OCA\DutyCheck\Service\RosterService::rosterApiConflictMessageKeys() as $enMsg) {
	$rosterPhpL10n['conflictMessages'][$enMsg] = $l->t($enMsg);
}
?>
<div id="dc-roster-php-l10n" hidden data-l10n="<?php p(json_encode($rosterPhpL10n, JSON_UNESCAPED_UNICODE)); ?>"></div>
<section class="dc-card dc-empty dc-empty--quickstart" id="dc-roster-quickstart" hidden aria-labelledby="dc-roster-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-roster-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('How to add a duty assignment without breaking labour rules or double-booking anyone.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="roster_quickstart_v1" aria-describedby="dc-roster-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="select-period">
			<strong><?php p($l->t('1. Choose the period to plan in')); ?></strong>
			<p>
				<?php p($l->t('Use the period selector below. New assignments can only be added to "Open" periods; published and closed ones are read-only.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="add-assignment">
			<strong><?php p($l->t('2. Add assignments from the table')); ?></strong>
			<p>
				<?php p($l->t('Click “Add assignment” next to the assignments list, fill in the three steps in the dialog, and save. Violations of hard rules are blocked with a clear message.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="resolve-conflicts">
			<strong><?php p($l->t('3. Resolve every conflict')); ?></strong>
			<p>
				<?php p($l->t('Issues marked “Must fix” block publishing. “Confirm to continue” (for example less than 11 hours rest between shifts) needs a short written reason — it is stored in the audit log.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="publish-on-periods">
			<strong><?php p($l->t('4. Publish the period from Periods')); ?></strong>
			<p>
				<?php p($l->t('This Roster page stores shifts only. Publishing and closing happen on Periods — employees see My roster only after you publish there. Publish only when every “Must fix” issue is resolved and “Confirm to continue” items are confirmed.')); ?>
			</p>
			<p class="dc-quickstart__cta">
				<a class="button" href="<?php p((string) ($urls['periods'] ?? '#')); ?>"><?php p($l->t('Go to Periods')); ?></a>
			</p>
		</li>
	</ol>
</section>

<section class="dc-card dc-section dc-roster-panel" id="dc-roster-period-section" aria-labelledby="dc-roster-period-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-roster-period-title"><?php p($l->t('Active period')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Your choice here controls the assignment list and planning checks below. Only open periods accept new assignments.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-form-grid dc-roster-panel__body">
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-roster-period-switcher">
				<?php p($l->t('Period')); ?>
			</label>
			<select id="dc-roster-period-switcher" class="dc-input" aria-describedby="dc-roster-period-hint"></select>
			<p id="dc-roster-period-hint" class="dc-field__hint">
				<?php p($l->t('Periods are listed newest first. Closed periods are read-only. Changing the period updates the list below — no full page reload.')); ?>
			</p>
			<p id="dc-roster-ack-stats" class="dc-pill dc-roster-ack-stats" role="status" aria-live="polite" hidden></p>
			<div class="dc-roster-period__lifecycle" role="note">
				<p class="dc-field__hint">
					<?php p($l->t('Publishing and closing happen on the Periods page. Use “Add assignment” on this page to plan shifts.')); ?>
				</p>
				<a class="button" href="<?php p((string) ($urls['periods'] ?? '#')); ?>"><?php p($l->t('Go to Periods')); ?></a>
			</div>
			<div class="dc-roster-copy-period" id="dc-roster-copy-period" hidden>
				<label class="dc-field__label" for="dc-roster-copy-source"><?php p($l->t('Copy assignments from another period')); ?></label>
				<div class="dc-roster-copy-period__row">
					<select id="dc-roster-copy-source" class="dc-input" aria-describedby="dc-roster-copy-hint"></select>
					<button type="button" class="button" id="dc-roster-copy-preview"><?php p($l->t('Preview copy')); ?></button>
					<button type="button" class="button primary" id="dc-roster-copy-apply" disabled><?php p($l->t('Apply copy')); ?></button>
				</div>
				<p id="dc-roster-copy-hint" class="dc-field__hint">
					<?php p($l->t('Preview never writes. Apply only after you confirm the dry-run counts. Conflicts are recomputed after apply.')); ?>
				</p>
				<p id="dc-roster-copy-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
			</div>
		</div>
	</div>
</section>

<section class="dc-card dc-section dc-roster-panel" id="dc-roster-assignments-section" aria-labelledby="dc-assignments-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-assignments-title"><?php p($l->t('Assignments')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Shifts for the period selected above. Use the grid for a week overview, or the list for details.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<div class="dc-roster-view-toggle" role="group" aria-label="<?php p($l->t('Roster view')); ?>">
				<button type="button" class="button" id="dc-roster-view-grid" aria-pressed="true"><?php p($l->t('Grid')); ?></button>
				<button type="button" class="button" id="dc-roster-view-list" aria-pressed="false"><?php p($l->t('List')); ?></button>
			</div>
			<button type="button" class="button primary dc-roster-add-assignment-trigger" id="dc-roster-add-assignment"
				aria-describedby="dc-roster-add-assignment-desc" aria-disabled="true">
				<?php p($l->t('Add assignment')); ?>
			</button>
			<span id="dc-roster-add-assignment-desc" class="dc-sr-only">
				<?php p($l->t('Opens a dialog to create a new shift for the selected open period.')); ?>
			</span>
		</div>
	</header>
	<p id="dc-roster-assignments-success" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
	<div id="dc-roster-grid-wrap" class="dc-roster-grid-wrap">
		<div
			id="dc-roster-grid"
			class="dc-roster-grid"
			role="grid"
			aria-labelledby="dc-assignments-title"
			aria-describedby="dc-roster-grid-hint"
			tabindex="0"
		></div>
		<p id="dc-roster-grid-hint" class="dc-field__hint">
			<?php p($l->t('Rows are people, columns are days. Arrow keys move between cells. Enter opens the shift. Space selects empty cells for bulk fill.')); ?>
		</p>
		<div id="dc-roster-bulk-bar" class="dc-roster-bulk-bar" hidden>
			<p class="dc-roster-bulk-bar__count" id="dc-roster-bulk-count" role="status" aria-live="polite"></p>
			<label class="dc-field__label" for="dc-roster-bulk-template"><?php p($l->t('Fill selected cells from template')); ?></label>
			<div class="dc-roster-bulk-bar__row">
				<select id="dc-roster-bulk-template" class="dc-input"></select>
				<button type="button" class="button primary" id="dc-roster-bulk-apply"><?php p($l->t('Apply to selected')); ?></button>
				<button type="button" class="button" id="dc-roster-bulk-clear"><?php p($l->t('Clear selection')); ?></button>
			</div>
		</div>
	</div>
	<div class="dc-table-wrap" id="dc-assignments-table-wrap" hidden>
		<table class="dc-table" id="dc-assignments-table">
			<caption class="dc-sr-only"><?php p($l->t('Assignments for the selected period')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Date')); ?></th>
					<th scope="col"><?php p($l->t('Start')); ?></th>
					<th scope="col"><?php p($l->t('End')); ?></th>
					<th scope="col"><?php p($l->t('Employee')); ?></th>
					<th scope="col"><?php p($l->t('Location')); ?></th>
					<th scope="col"><?php p($l->t('Break')); ?></th>
					<th scope="col"><?php p($l->t('Note')); ?></th>
					<th scope="col"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-assignments-table-body"></tbody>
		</table>
		<div id="dc-roster-assignments-empty" class="dc-roster-empty-panel" hidden>
			<p class="dc-roster-empty-state__text">
				<?php p($l->t('No assignments in this period yet.')); ?>
			</p>
			<p id="dc-roster-empty-add-hint" class="dc-roster-empty-state__hint"></p>
		</div>
	</div>
</section>

<section class="dc-card dc-section dc-roster-panel" id="dc-roster-conflicts-section" aria-labelledby="dc-conflict-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-conflict-title"><?php p($l->t('Planning checks')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Automatic checks for the same period as the assignment list. “Must fix” blocks publishing until resolved. “Confirm to continue” lets you proceed after you confirm with a short reason.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<span id="dc-conflict-summary" class="dc-pill" aria-live="polite">
				<?php p($l->t('Loading conflicts...')); ?>
			</span>
		</div>
	</header>
	<ul id="dc-conflict-list" class="dc-conflicts" role="list" aria-label="<?php p($l->t('Planning checks')); ?>" aria-live="polite"></ul>
</section>

<section class="dc-card dc-section dc-roster-panel" id="dc-roster-marketplace-section" aria-labelledby="dc-marketplace-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-marketplace-title"><?php p($l->t('Swaps & open shifts')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Review staff swap requests and post unassigned shifts for the selected period. Approving a pool swap cancels the old shift and opens it for claiming.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<button type="button" class="button" id="dc-open-shift-create"><?php p($l->t('Post open shift')); ?></button>
		</div>
	</header>
	<div class="dc-form-grid" id="dc-open-shift-form" hidden>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-os-date"><?php p($l->t('Date')); ?></label>
			<input id="dc-os-date" type="date" class="dc-input">
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-os-location"><?php p($l->t('Location')); ?></label>
			<select id="dc-os-location" class="dc-input"></select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-os-start"><?php p($l->t('Start')); ?></label>
			<input id="dc-os-start" type="time" class="dc-input dc-input--time-24h" step="60" lang="en-GB">
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-os-end"><?php p($l->t('End')); ?></label>
			<input id="dc-os-end" type="time" class="dc-input dc-input--time-24h" step="60" lang="en-GB">
		</div>
		<div class="dc-form-actions">
			<button type="button" class="button primary" id="dc-os-save"><?php p($l->t('Save open shift')); ?></button>
		</div>
	</div>
	<h3 class="dc-subsection-heading"><?php p($l->t('Pending swap requests')); ?></h3>
	<ul id="dc-swap-list" class="dc-conflicts" role="list" aria-live="polite"></ul>
	<p id="dc-swap-empty" class="dc-field__hint" hidden><?php p($l->t('No pending swaps.')); ?></p>
	<h3 class="dc-subsection-heading"><?php p($l->t('Pending open-shift claims')); ?></h3>
	<ul id="dc-open-claim-list" class="dc-conflicts" role="list" aria-live="polite"></ul>
	<p id="dc-open-claim-empty" class="dc-field__hint" hidden><?php p($l->t('No pending claims.')); ?></p>
</section>

<?php if (!empty($_['isAppAdmin'])): ?>
<section class="dc-card dc-section dc-roster-panel dc-roster-export" id="dc-roster-admin-export" aria-labelledby="dc-roster-export-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-roster-export-title"><?php p($l->t('Export & print')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Download a spreadsheet file or open a clean print layout for the period selected above. Only DutyCheck and server administrators see this section.')); ?>
			</p>
			<p class="dc-roster-export__hint" id="dc-roster-export-hint">
				<?php p($l->t('Files include every assignment row for the active period. Use them for archives, handovers, or regulatory evidence — treat exports like personal data and store them securely.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-roster-export__actions" role="group" aria-labelledby="dc-roster-export-title">
		<a class="button primary" id="dc-roster-export-csv" href="#" aria-describedby="dc-roster-export-hint">
			<?php p($l->t('Download CSV')); ?>
		</a>
		<a class="button" id="dc-roster-export-print" href="#" target="_blank" rel="noopener noreferrer" aria-describedby="dc-roster-export-hint">
			<?php p($l->t('Open printable view')); ?>
		</a>
	</div>
</section>
<?php endif; ?>

<div id="dc-assignment-form-host" class="dc-assignment-form-host" hidden>
	<div id="dc-assignment-form-panel" class="dc-assignment-form-panel">
		<div id="dc-roster-setup-callout" class="dc-callout dc-callout--warning" role="status" aria-live="polite" hidden>
			<p><strong><?php p($l->t('Setup is required before you can plan duties.')); ?></strong></p>
			<ul class="dc-callout__list" id="dc-roster-setup-checklist"></ul>
		</div>
		<form id="dc-assignment-form" class="dc-form-grid dc-form-grid--roster-assignment" novalidate>
			<input type="hidden" name="periodId" id="dc-assignment-period" value="">
			<input type="hidden" name="assignmentId" id="dc-assignment-id" value="">
			<p id="dc-assignment-form-intro" class="dc-roster-form__intro">
				<?php p($l->t('Three steps: pick the day, choose who works and where, then enter shift times. People on approved leave are not listed for that day.')); ?>
			</p>
			<div class="dc-field dc-field--full" id="dc-assignment-template-field">
				<label class="dc-field__label" for="dc-assignment-template"><?php p($l->t('Shift template (optional)')); ?></label>
				<select id="dc-assignment-template" class="dc-input" aria-describedby="dc-assignment-template-hint">
					<option value=""><?php p($l->t('No template — enter times manually')); ?></option>
				</select>
				<p id="dc-assignment-template-hint" class="dc-field__hint">
					<?php p($l->t('Templates fill start, end, and break. You can still change any field before saving.')); ?>
				</p>
			</div>
			<fieldset class="dc-roster-form__step-group" aria-labelledby="dc-roster-step-day">
				<h3 id="dc-roster-step-day" class="dc-roster-form__section-heading dc-roster-form__section-heading--step">
					<span class="dc-roster-form__step-badge" aria-hidden="true">1</span>
					<?php p($l->t('Pick the duty day')); ?>
				</h3>
				<div class="dc-field dc-field--roster-date">
					<label class="dc-field__label" for="dc-assignment-date"><?php p($l->t('Date')); ?></label>
					<input id="dc-assignment-date" type="date" name="dutyDate" class="dc-input" lang="<?php p($htmlLang); ?>"
						aria-describedby="dc-assignment-date-hint" required>
					<p id="dc-assignment-date-hint" class="dc-field__hint">
						<?php p($l->t('Only dates inside the open planning period selected on this page.')); ?>
					</p>
				</div>
			</fieldset>
			<fieldset class="dc-roster-form__step-group" aria-labelledby="dc-roster-step-who">
				<h3 id="dc-roster-step-who" class="dc-roster-form__section-heading dc-roster-form__section-heading--step">
					<span class="dc-roster-form__step-badge" aria-hidden="true">2</span>
					<?php p($l->t('Who and where')); ?>
				</h3>
				<div class="dc-field dc-field--roster-employee">
					<label class="dc-field__label" for="dc-assignment-employee"><?php p($l->t('Employee')); ?></label>
					<select id="dc-assignment-employee" name="employeeId" class="dc-input" required
						aria-describedby="dc-assignment-employee-hint dc-assignment-form-feedback"></select>
					<p id="dc-assignment-employee-hint" class="dc-field__hint" aria-live="polite" aria-atomic="true">
						<?php p($l->t('Pick a date in step 1 first. People on approved leave that day are not listed here.')); ?>
					</p>
				</div>
				<div class="dc-field dc-field--roster-location">
					<label class="dc-field__label" for="dc-assignment-location"><?php p($l->t('Location')); ?></label>
					<select id="dc-assignment-location" name="locationId" class="dc-input" required
						aria-describedby="dc-assignment-location-tz-hint"></select>
					<p id="dc-assignment-location-tz-hint" class="dc-field__hint" aria-live="polite" aria-atomic="true" hidden></p>
				</div>
			</fieldset>
			<fieldset class="dc-roster-form__step-group" aria-labelledby="dc-roster-shift-schedule-label">
				<h3 id="dc-roster-shift-schedule-label" class="dc-roster-form__section-heading dc-roster-form__section-heading--step">
					<span class="dc-roster-form__step-badge" aria-hidden="true">3</span>
					<?php p($l->t('Shift times')); ?>
				</h3>
				<div class="dc-field dc-field--full dc-field--roster-time-hint">
					<p id="dc-roster-24h-hint" class="dc-field__hint dc-roster-24h-hint">
						<?php p($l->t('Times use the 24-hour clock (HH:mm). For shifts past midnight, set an end time earlier than the start time (e.g. 22:00–06:00).')); ?>
					</p>
				</div>
				<div class="dc-field dc-field--roster-start">
					<label class="dc-field__label" for="dc-assignment-start"><?php p($l->t('Start')); ?></label>
					<input id="dc-assignment-start" type="time" name="startTime" class="dc-input dc-input--time-24h" step="60"
						lang="en-GB"
						aria-label="<?php p($l->t('Start time (24-hour HH:mm)')); ?>"
						aria-describedby="dc-roster-24h-hint dc-assignment-location-tz-hint" required>
				</div>
				<div class="dc-field dc-field--roster-end">
					<label class="dc-field__label" for="dc-assignment-end"><?php p($l->t('End')); ?></label>
					<input id="dc-assignment-end" type="time" name="endTime" class="dc-input dc-input--time-24h" step="60"
						lang="en-GB"
						aria-label="<?php p($l->t('End time (24-hour HH:mm)')); ?>"
						aria-describedby="dc-roster-24h-hint dc-assignment-location-tz-hint dc-assignment-rest-hint" required>
				</div>
				<p id="dc-assignment-rest-hint" class="dc-field__hint dc-field--full dc-field--roster-rest-hint">
					<?php p($l->t('If Save asks you to confirm, a planning rule was triggered (for example less than 11 hours rest between shifts). You can review issues in')); ?>
					<a class="dc-hint-link" href="#dc-roster-conflicts-section"><?php p($l->t('Planning checks')); ?></a><?php p(' '); ?><?php p($l->t('on this page.')); ?>
				</p>
			</fieldset>
			<hr class="dc-form-grid__divider" aria-hidden="true">
			<div class="dc-roster-form__extras" role="group" aria-labelledby="dc-roster-form-extras-label">
				<h3 id="dc-roster-form-extras-label" class="dc-roster-form__section-heading">
					<?php p($l->t('Break and note')); ?>
				</h3>
			<div class="dc-field dc-field--roster-break">
				<label class="dc-field__label" for="dc-assignment-break"><?php p($l->t('Break (minutes)')); ?></label>
				<input id="dc-assignment-break" type="number" name="breakMinutes" class="dc-input dc-input--num"
					min="0" max="720" step="5" value="0" required
					aria-describedby="dc-assignment-break-prefill dc-assignment-break-hint">
				<p id="dc-assignment-break-prefill" class="dc-roster-break-prefill" aria-live="polite" aria-atomic="true"></p>
				<p id="dc-assignment-break-hint" class="dc-field__hint">
					<?php p($l->t('Unpaid break during the shift. Use 0 when there is no break.')); ?>
				</p>
			</div>
			<div class="dc-field dc-field--roster-note">
				<label class="dc-field__label" for="dc-assignment-note"><?php p($l->t('Note (optional)')); ?></label>
				<input id="dc-assignment-note" type="text" name="note" class="dc-input" maxlength="512" autocomplete="off">
			</div>
			</div>
			<div id="dc-assignment-form-feedback" class="dc-roster-form__feedback" role="status" aria-live="polite" aria-atomic="true" hidden></div>
			<div class="dc-form-actions dc-form-actions--roster">
				<button type="submit" class="button primary" tabindex="-1" aria-hidden="true" hidden><?php p($l->t('Save assignment')); ?></button>
				<button type="button" id="dc-assignment-form-clear" class="button"><?php p($l->t('Clear entered fields')); ?></button>
			</div>
		</form>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>

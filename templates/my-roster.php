<?php
/**
 * Employee self-service roster.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$urls = (array) ($_['urls'] ?? []);
$myAbsencesUrl = (string) ($urls['myAbsences'] ?? '#');
?>
<div id="dc-employee-account-alert" class="dc-card dc-callout dc-callout--critical dc-employee-alert" hidden role="alert"></div>

<section class="dc-card dc-section dc-roster-panel" aria-labelledby="dc-my-roster-filters-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-my-roster-filters-title"><?php p($l->t('Time range')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Pick a quick range or enter your own dates. Only published shifts are shown.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-roster-panel__body">
		<div id="dc-my-roster-quickfilters" class="dc-quickfilters" role="group" aria-label="<?php p($l->t('Quick range')); ?>">
			<button type="button" class="dc-quickfilters__btn" data-range="upcoming" aria-pressed="true"><?php p($l->t('All upcoming')); ?></button>
			<button type="button" class="dc-quickfilters__btn" data-range="today" aria-pressed="false"><?php p($l->t('Today')); ?></button>
			<button type="button" class="dc-quickfilters__btn" data-range="week" aria-pressed="false"><?php p($l->t('This week')); ?></button>
			<button type="button" class="dc-quickfilters__btn" data-range="next-week" aria-pressed="false"><?php p($l->t('Next week')); ?></button>
			<button type="button" class="dc-quickfilters__btn" data-range="14d" aria-pressed="false"><?php p($l->t('Next 14 days')); ?></button>
			<button type="button" class="dc-quickfilters__btn" data-range="month" aria-pressed="false"><?php p($l->t('This month')); ?></button>
		</div>
		<form id="dc-my-roster-filter" class="dc-form-grid dc-form-grid--my-roster-filter" aria-label="<?php p($l->t('Custom range')); ?>" novalidate>
			<div class="dc-field dc-field--my-roster-from">
				<label class="dc-field__label" for="dc-my-roster-from"><?php p($l->t('From')); ?></label>
				<input id="dc-my-roster-from" type="date" name="from" class="dc-input dc-input--date" autocomplete="off">
			</div>
			<div class="dc-field dc-field--my-roster-to">
				<label class="dc-field__label" for="dc-my-roster-to"><?php p($l->t('To')); ?></label>
				<input id="dc-my-roster-to" type="date" name="to" class="dc-input dc-input--date" autocomplete="off">
			</div>
			<div class="dc-form-actions dc-form-actions--my-roster-filter">
				<button type="submit" class="button primary"><?php p($l->t('Apply range')); ?></button>
			</div>
		</form>
	</div>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-my-roster-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-my-roster-title"><?php p($l->t('Upcoming shifts')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Every published shift assigned to you in the selected range. If anything looks wrong, contact your planner before the shift starts.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<span id="dc-my-roster-status" class="dc-pill" role="status" aria-live="polite"><?php p($l->t('Loading…')); ?></span>
		</div>
	</header>
	<div class="dc-employee-crossnav">
		<a class="button" href="<?php p($myAbsencesUrl); ?>"><?php p($l->t('My absences — request time off')); ?></a>
	</div>
	<div class="dc-table-wrap">
		<table class="dc-table" id="dc-my-roster-table" aria-describedby="dc-my-roster-status">
			<caption class="dc-sr-only"><?php p($l->t('Your published shifts in the selected range')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Date')); ?></th>
					<th scope="col"><?php p($l->t('Day')); ?></th>
					<th scope="col"><?php p($l->t('Start')); ?></th>
					<th scope="col"><?php p($l->t('End')); ?></th>
					<th scope="col"><?php p($l->t('Location')); ?></th>
					<th scope="col"><?php p($l->t('Break')); ?></th>
					<th scope="col"><?php p($l->t('Note')); ?></th>
					<th scope="col"><?php p($l->t('Confirm')); ?></th>
					<th scope="col"><?php p($l->t('Swap')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-my-roster-table-body"></tbody>
		</table>
	</div>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-open-shifts-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-open-shifts-title"><?php p($l->t('Open shifts')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Unassigned shifts you can claim. Hard planning rules still apply — if a claim fails, ask your planner.')); ?>
			</p>
		</div>
	</header>
	<ul id="dc-open-shifts-list" class="dc-conflicts" role="list" aria-live="polite"></ul>
	<p id="dc-open-shifts-empty" class="dc-field__hint" hidden><?php p($l->t('No open shifts right now.')); ?></p>
</section>

<section class="dc-card dc-section dc-ical-panel" aria-labelledby="dc-ical-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-ical-title"><?php p($l->t('Add shifts to your personal calendar')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Use one private link with Google Calendar, Apple Calendar, Outlook, or any app that supports calendar subscriptions. New shifts appear after the planner publishes them.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-ical-panel__body">
		<section class="dc-ical-steps" aria-labelledby="dc-ical-steps-title">
			<h3 id="dc-ical-steps-title" class="dc-ical-subtitle"><?php p($l->t('How it works')); ?></h3>
			<ol class="dc-ical-steps__list">
				<li><?php p($l->t('Tap “Create calendar link”, then copy the address that appears.')); ?></li>
				<li><?php p($l->t('Open your calendar app and choose something like “Subscribe”, “Add calendar”, or “From URL” (the name differs per app).')); ?></li>
				<li><?php p($l->t('Paste the address. You usually only do this once; the app refreshes your shifts in the background.')); ?></li>
			</ol>
		</section>
		<div class="dc-callout dc-callout--warning" id="dc-ical-security-hint" role="region"
			aria-label="<?php p($l->t('Security note')); ?>">
			<p class="dc-callout__hint dc-callout__hint--tight">
				<strong><?php p($l->t('Treat this link like a password.')); ?></strong>
				<?php p($l->t('Anyone with the link can see your published shifts. If someone else might have seen it, use “Replace calendar link” and paste the new address into your calendar app.')); ?>
			</p>
		</div>
		<div class="dc-callout dc-callout--info dc-ical-at-disclosure" id="dc-ical-at-disclosure" hidden role="region"
			aria-labelledby="dc-ical-at-disclosure-title">
			<p class="dc-callout__title" id="dc-ical-at-disclosure-title"><?php p($l->t('This calendar feed shows DutyCheck shifts only')); ?></p>
			<p class="dc-callout__hint dc-callout__hint--tight">
				<?php p($l->t('Time off lives in ArbeitszeitCheck and is not included in this subscription. Use ArbeitszeitCheck to request or change absences.')); ?>
			</p>
			<p class="dc-callout__actions">
				<a class="button" id="dc-ical-open-azc" hidden rel="noopener noreferrer">
					<?php p($l->t('View or request absences in ArbeitszeitCheck')); ?>
				</a>
			</p>
		</div>
		<div class="dc-ical-link-card">
			<h3 id="dc-ical-link-heading" class="dc-ical-subtitle"><?php p($l->t('Your private link')); ?></h3>
			<div class="dc-ical__actions">
				<button type="button" id="dc-ical-rotate-button" class="button primary"
					aria-describedby="dc-ical-security-hint">
					<?php p($l->t('Create calendar link')); ?>
				</button>
				<button type="button" id="dc-ical-copy-button" class="button" disabled
					aria-label="<?php p($l->t('Copy calendar link to clipboard')); ?>">
					<?php p($l->t('Copy link')); ?>
				</button>
			</div>
			<div class="dc-field dc-field--ical-url">
				<label class="dc-field__label" for="dc-ical-url"><?php p($l->t('Private calendar address')); ?></label>
				<input id="dc-ical-url" class="dc-input dc-ical__url" type="text"
					autocomplete="off" spellcheck="false" readonly
					placeholder="<?php p($l->t('Your link appears here after you create it.')); ?>"
					aria-describedby="dc-ical-note dc-ical-security-hint">
			</div>
			<p id="dc-ical-note" class="dc-field__hint" role="status" aria-live="polite">
				<?php p($l->t('No calendar link yet. Tap “Create calendar link” above.')); ?>
			</p>
		</div>
	</div>
</section>

<dialog id="dc-swap-dialog" class="dc-dialog" aria-labelledby="dc-swap-dialog-title">
	<form method="dialog" id="dc-swap-form" class="dc-dialog__panel" novalidate>
		<h2 id="dc-swap-dialog-title" class="dc-dialog__title"><?php p($l->t('Request a swap')); ?></h2>
		<p class="dc-dialog__intro" id="dc-swap-dialog-intro">
			<?php p($l->t('Choose a colleague, or leave “Open pool” so anyone can claim this shift after a planner approves.')); ?>
		</p>
		<input type="hidden" name="assignmentId" id="dc-swap-assignment-id" value="">
		<div class="dc-field">
			<label class="dc-field__label" for="dc-swap-colleague"><?php p($l->t('Swap with')); ?></label>
			<select id="dc-swap-colleague" class="dc-input" name="toEmployeeId" aria-describedby="dc-swap-dialog-intro">
				<option value=""><?php p($l->t('Open pool (anyone can claim)')); ?></option>
			</select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-swap-reason"><?php p($l->t('Note for your planner (optional)')); ?></label>
			<textarea id="dc-swap-reason" class="dc-input" name="reason" rows="2" maxlength="512"></textarea>
		</div>
		<div class="dc-dialog__actions">
			<button type="submit" value="cancel" class="button"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" value="confirm" class="button primary"><?php p($l->t('Send swap request')); ?></button>
		</div>
	</form>
</dialog>
<?php include __DIR__ . '/common/page-end.php'; ?>

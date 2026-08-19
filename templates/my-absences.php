<?php
/**
 * Employee self-service absences.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$htmlLang = (string) (($_['clientHints']['htmlLang'] ?? 'en-US'));
$urls = (array) ($_['urls'] ?? []);
$myRosterUrl = (string) ($urls['myRoster'] ?? '#');
$readonlyAbsences = !empty($_['readonlyAbsencesForCurrentUser']);
?>
<div id="dc-employee-account-alert" class="dc-card dc-callout dc-callout--critical dc-employee-alert" hidden role="alert"></div>
<div id="dc-my-absences-integration-banner" class="dc-card dc-callout dc-callout--info" hidden></div>

<section class="dc-card dc-empty dc-empty--quickstart" id="dc-my-absences-quickstart"<?php if ($readonlyAbsences) { ?> data-dc-hint-suppress="integration" hidden<?php } else { ?> hidden<?php } ?> aria-labelledby="dc-my-absences-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-my-absences-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Check your existing requests first, then send a new one. Your planner reviews every request.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="my_absences_quickstart_v1" aria-describedby="dc-my-absences-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="submit">
			<strong><?php p($l->t('1. Submit early')); ?></strong>
			<p>
				<?php p($l->t('Pick a type, a start and end date, and submit. Earlier requests give your planner more flexibility to find cover.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="wait">
			<strong><?php p($l->t('2. Wait for the planner\'s decision')); ?></strong>
			<p>
				<?php p($l->t('Requests start as "Pending". The status changes to Approved, Rejected, or Cancelled once reviewed. Reload the page to refresh the list.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="read-reason">
			<strong><?php p($l->t('3. Read the reason if rejected')); ?></strong>
			<p>
				<?php p($l->t('If a request is rejected or cancelled, the planner adds a written reason next to the status — so you know what to do next.')); ?>
			</p>
		</li>
	</ol>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-my-absences-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-my-absences-title"><?php p($l->t('My absence requests')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Newest first. Statuses update after your planner reviews each row.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-table-wrap" tabindex="0" role="region" aria-labelledby="dc-my-absences-title">
		<table class="dc-table" id="dc-my-absences-table">
			<caption class="dc-sr-only"><?php p($l->t('Your absence requests and statuses')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Source')); ?></th>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col"><?php p($l->t('Range')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-my-absences-table-body"></tbody>
		</table>
	</div>
	<div class="dc-employee-crossnav">
		<a class="button" href="<?php p($myRosterUrl); ?>"><?php p($l->t('My roster — see published shifts')); ?></a>
	</div>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-my-absence-form-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-my-absence-form-title"><?php p($l->t('New absence request')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Everything you send stays "Pending" until reviewed. For a single day off, use the same date twice.')); ?>
			</p>
		</div>
	</header>
	<form id="dc-my-absence-form" class="dc-form-grid dc-form-grid--my-absence" novalidate>
		<fieldset class="dc-fieldset-reset">
			<legend class="dc-sr-only"><?php p($l->t('Request details')); ?></legend>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-my-absence-kind"><?php p($l->t('Type')); ?></label>
				<select id="dc-my-absence-kind" name="kind" class="dc-input" required>
					<option value="vacation"><?php p($l->t('Vacation')); ?></option>
					<option value="sick"><?php p($l->t('Sick')); ?></option>
					<option value="training"><?php p($l->t('Training')); ?></option>
					<option value="unpaid"><?php p($l->t('Unpaid')); ?></option>
					<option value="other"><?php p($l->t('Other')); ?></option>
				</select>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-my-absence-start"><?php p($l->t('Start date')); ?></label>
				<input id="dc-my-absence-start" type="date" name="startDate" class="dc-input" lang="<?php p($htmlLang); ?>" required>
			</div>
			<div class="dc-field">
				<label class="dc-field__label" for="dc-my-absence-end"><?php p($l->t('End date')); ?></label>
				<input id="dc-my-absence-end" type="date" name="endDate" class="dc-input" lang="<?php p($htmlLang); ?>" required>
			</div>
		</fieldset>
		<div class="dc-form-actions">
			<button type="submit" id="dc-my-absence-submit" class="button primary"><?php p($l->t('Submit request')); ?></button>
		</div>
	</form>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

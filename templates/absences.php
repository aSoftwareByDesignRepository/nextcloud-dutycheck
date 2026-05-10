<?php
/**
 * Absences management for planners.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$htmlLang = (string) (($_['clientHints']['htmlLang'] ?? 'en-US'));
$locksLinked = !empty($_['integrationLocksLinkedDutyCheckAbsences']);
?>
<section class="dc-card dc-empty dc-empty--quickstart" id="dc-absences-quickstart"<?php if ($locksLinked) { ?> data-dc-hint-suppress="integration" hidden<?php } else { ?> hidden<?php } ?> aria-labelledby="dc-absences-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-absences-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Absences keep the roster honest: approved time off becomes a hard conflict against overlapping assignments.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="absences_quickstart_v1" aria-describedby="dc-absences-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="review">
			<strong><?php p($l->t('1. Look at pending requests first')); ?></strong>
			<p>
				<?php p($l->t('The list below sorts by submission date so the oldest request comes first. Use the filter to focus on a specific employee or type.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="decide">
			<strong><?php p($l->t('2. Approve, reject, or cancel with a reason')); ?></strong>
			<p>
				<?php p($l->t('Rejections and cancellations always require a written reason of at least 10 characters. The reason is shown to the employee and saved in the audit log.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="impact">
			<strong><?php p($l->t('3. Watch the roster react')); ?></strong>
			<p>
				<?php p($l->t('After approval, jump to the Roster — overlapping assignments now appear as hard conflicts that must be reassigned or removed before publishing.')); ?>
			</p>
			<a class="button" href="#" data-dc-link="roster"><?php p($l->t('Open Roster')); ?></a>
		</li>
	</ol>
</section>

<div id="dc-absences-integration-banner" class="dc-card dc-callout dc-callout--info" hidden></div>
<div id="dc-absences-all-linked-callout" class="dc-card dc-callout dc-callout--warning" hidden role="status"></div>

<section class="dc-card dc-section" aria-labelledby="dc-absence-form-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-absence-form-title"><?php p($l->t('Record absence on behalf of an employee')); ?></h2>
			<p class="dc-section__sub">
				<?php if ($locksLinked) { ?>
					<?php p($l->t('Use this only for people who are not linked to a Nextcloud account (they have no “ArbeitszeitCheck” tag in the list). Linked people are managed in ArbeitszeitCheck — link them under Employees if they should use the integration.')); ?>
				<?php } else { ?>
					<?php p($l->t('Use this form when an employee cannot submit a request themselves. Approved absences automatically conflict with overlapping assignments.')); ?>
				<?php } ?>
			</p>
		</div>
	</header>
	<form id="dc-absence-form" class="dc-form-grid" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-absence-employee"><?php p($l->t('Employee')); ?></label>
			<select id="dc-absence-employee" name="employeeId" class="dc-input" required></select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-absence-kind"><?php p($l->t('Type')); ?></label>
			<select id="dc-absence-kind" name="kind" class="dc-input" required>
				<option value="vacation"><?php p($l->t('Vacation')); ?></option>
				<option value="sick"><?php p($l->t('Sick')); ?></option>
				<option value="training"><?php p($l->t('Training')); ?></option>
				<option value="unpaid"><?php p($l->t('Unpaid')); ?></option>
				<option value="other"><?php p($l->t('Other')); ?></option>
			</select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-absence-start"><?php p($l->t('Start date')); ?></label>
			<input id="dc-absence-start" type="date" name="startDate" class="dc-input" lang="<?php p($htmlLang); ?>" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-absence-end"><?php p($l->t('End date')); ?></label>
			<input id="dc-absence-end" type="date" name="endDate" class="dc-input" lang="<?php p($htmlLang); ?>" required>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Create absence')); ?></button>
		</div>
	</form>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-absences-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-absences-title"><?php p($l->t('Absences')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Approve, reject, or cancel requests. Reject and cancel always require a written reason.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-table-wrap">
		<table class="dc-table">
			<caption class="dc-sr-only"><?php p($l->t('Absences list')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Employee')); ?></th>
					<th scope="col"><?php p($l->t('Source')); ?></th>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col"><?php p($l->t('Range')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col" class="dc-table__col--actions"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-absences-table-body"></tbody>
		</table>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

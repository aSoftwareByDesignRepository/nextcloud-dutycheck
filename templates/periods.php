<?php
/**
 * Periods management page.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$htmlLang = (string) (($_['clientHints']['htmlLang'] ?? 'en-US'));
$isAppAdmin = !empty($_['isAppAdmin']);
?>
<section class="dc-card dc-empty dc-empty--quickstart" id="dc-periods-quickstart" hidden aria-labelledby="dc-periods-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-periods-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Periods are the timeline of your roster. Each period flows through three stages: open, published, closed.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="periods_quickstart_v1" aria-describedby="dc-periods-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="create">
			<strong><?php p($l->t('1. Create the date range')); ?></strong>
			<p>
				<?php p($l->t('A period is a window (e.g. a month) in which you plan duties. Pick start and end dates below — overlapping ranges are blocked automatically.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="plan">
			<strong><?php p($l->t('2. Plan duties inside it')); ?></strong>
			<p>
				<?php p($l->t('Switch to the Roster page and add assignments. Only "Open" periods accept new entries; published or closed periods are read-only.')); ?>
			</p>
			<a class="button" href="#" data-dc-link="roster"><?php p($l->t('Open Roster')); ?></a>
		</li>
		<li class="dc-quickstart__item" data-step="publish">
			<strong><?php p($l->t('3. Publish to make it visible')); ?></strong>
			<p>
				<?php p($l->t('Publishing freezes a tamper-evident snapshot and shows the roster to employees. Re-opening later is possible but always recorded with a written reason.')); ?>
			</p>
		</li>
	</ol>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-period-create-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-period-create-title"><?php p($l->t('Create period')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('A period is a date range during which assignments are planned, published, and closed.')); ?>
			</p>
		</div>
	</header>
	<div id="dc-period-empty-callout" class="dc-callout dc-callout--info" role="status" aria-live="polite" hidden>
		<p><strong><?php p($l->t('No periods yet.')); ?></strong> <?php p($l->t('Pick a start and end date below to create your first planning period.')); ?></p>
	</div>
	<form id="dc-period-form" class="dc-form-grid" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-period-start"><?php p($l->t('Start date')); ?></label>
			<input id="dc-period-start" type="date" name="startDate" class="dc-input" lang="<?php p($htmlLang); ?>" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-period-end"><?php p($l->t('End date')); ?></label>
			<input id="dc-period-end" type="date" name="endDate" class="dc-input" lang="<?php p($htmlLang); ?>" required>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Create period')); ?></button>
		</div>
	</form>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-periods-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-periods-title"><?php p($l->t('Periods')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Select a period to load its lifecycle, snapshots, and audit trail. Publishing requires zero hard conflicts.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<span id="dc-publish-readiness" class="dc-pill" aria-live="polite"></span>
		</div>
	</header>
	<div class="dc-table-wrap">
		<table class="dc-table">
			<caption class="dc-sr-only"><?php p($l->t('Periods and their lifecycle actions')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Range')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col" class="dc-table__col--actions"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-periods-table-body" data-can-reopen="<?php p($isAppAdmin ? '1' : '0'); ?>"></tbody>
		</table>
	</div>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-snapshot-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-snapshot-title"><?php p($l->t('Snapshot evidence')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Immutable snapshots are generated at publish and close. Verify integrity to detect tampering.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<button type="button" id="dc-verify-snapshots-button" class="button">
				<?php p($l->t('Verify integrity')); ?>
			</button>
		</div>
	</header>
	<div id="dc-snapshot-integrity-banner" class="dc-integrity-banner" role="alert" hidden></div>
	<p id="dc-snapshot-verify-result" class="dc-field__hint" aria-live="polite"></p>
	<div class="dc-table-wrap">
		<table class="dc-table">
			<caption class="dc-sr-only"><?php p($l->t('Snapshots of the selected period')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col"><?php p($l->t('Hash')); ?></th>
					<th scope="col"><?php p($l->t('Generated at')); ?></th>
					<th scope="col"><?php p($l->t('Generated by')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-snapshots-table-body"></tbody>
		</table>
	</div>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-audit-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-audit-title"><?php p($l->t('Period audit trail')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Recent governance events for the selected period, in chronological order.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-table-wrap">
		<table class="dc-table">
			<caption class="dc-sr-only"><?php p($l->t('Recent audit events for selected period')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Time')); ?></th>
					<th scope="col"><?php p($l->t('Actor')); ?></th>
					<th scope="col"><?php p($l->t('Action')); ?></th>
					<th scope="col"><?php p($l->t('Target')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-period-audit-table-body"></tbody>
		</table>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

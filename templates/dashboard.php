<?php
/**
 * Planner / admin dashboard.
 *
 * Shows the four operational KPIs as summary tiles, a publish-readiness
 * checklist, a soft-conflict acknowledgement reminder, and quick links to the
 * places where decisions are usually made. The KPI tiles use semantic colour
 * tints so a glance reveals where attention is needed.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$urls = (array) ($_['urls'] ?? []);
?>
<section class="dc-card dc-section dc-setup-progress" id="dc-dashboard-setup" hidden aria-labelledby="dc-dashboard-setup-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-dashboard-setup-title"><?php p($l->t('Setup progress')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Complete these steps before your team can plan duties. Each item links to the right page.')); ?>
			</p>
		</div>
	</header>
	<div id="dc-dashboard-setup-schema-alert" class="dc-callout dc-callout--critical" role="alert" hidden>
		<p><strong><?php p($l->t('Database setup is incomplete.')); ?></strong></p>
		<p><?php p($l->t('An administrator must run “Update apps” or `php occ upgrade` on the server. Until then, DutyCheck cannot save data.')); ?></p>
	</div>
	<ol class="dc-setup-checklist" id="dc-dashboard-setup-list" aria-live="polite"></ol>
</section>

<section class="dc-card dc-empty dc-empty--quickstart" id="dc-quickstart" hidden aria-labelledby="dc-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('New to DutyCheck? Three short steps to get a roster ready for your team.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="dashboard_quickstart_v1" aria-describedby="dc-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="catalog">
			<strong><?php p($l->t('1. Build the catalog')); ?></strong>
			<p>
				<?php p($l->t('Add the people who work shifts (Employees) and the places where shifts happen (Locations). You only do this once per organisation.')); ?>
			</p>
			<a class="button" href="#" data-dc-link="employees"><?php p($l->t('Open Employees')); ?></a>
		</li>
		<li class="dc-quickstart__item" data-step="period">
			<strong><?php p($l->t('2. Create a planning period')); ?></strong>
			<p>
				<?php p($l->t('A period is a date range (e.g. one month) in which assignments are created. Open the Periods page, pick a start and end date, and the period stays "Open" so you can plan inside it.')); ?>
			</p>
			<a class="button" href="#" data-dc-link="periods"><?php p($l->t('Open Periods')); ?></a>
		</li>
		<li class="dc-quickstart__item" data-step="roster">
			<strong><?php p($l->t('3. Plan and publish duties')); ?></strong>
			<p>
				<?php p($l->t('On the Roster page, use “Add assignment” for each shift. “Must fix” issues (e.g. someone in two places at once) block saving. “Confirm to continue” items need a short written reason. Then publish the period so employees can see their roster.')); ?>
			</p>
			<a class="button" href="#" data-dc-link="roster"><?php p($l->t('Open Roster')); ?></a>
		</li>
	</ol>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-dashboard-summary-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-dashboard-summary-title"><?php p($l->t('Operational summary')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('A snapshot of the current planning workload. Numbers update each time you reload the page.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-summary-grid" role="list">
		<article class="dc-summary-tile dc-summary-tile--primary" role="listitem">
			<span class="dc-summary-tile__label"><?php p($l->t('Open periods')); ?></span>
			<span id="dc-metric-open-periods" class="dc-summary-tile__value">0</span>
			<span class="dc-summary-tile__hint"><?php p($l->t('Currently editable, accept new assignments.')); ?></span>
		</article>
		<article class="dc-summary-tile dc-summary-tile--success" role="listitem">
			<span class="dc-summary-tile__label"><?php p($l->t('Published periods')); ?></span>
			<span id="dc-metric-published-periods" class="dc-summary-tile__value">0</span>
			<span class="dc-summary-tile__hint"><?php p($l->t('Visible to employees on their roster.')); ?></span>
		</article>
		<article class="dc-summary-tile" role="listitem">
			<span class="dc-summary-tile__label"><?php p($l->t('Active employees')); ?></span>
			<span id="dc-metric-employees" class="dc-summary-tile__value">0</span>
			<span class="dc-summary-tile__hint"><?php p($l->t('Available for new assignments.')); ?></span>
		</article>
		<article class="dc-summary-tile" role="listitem">
			<span class="dc-summary-tile__label"><?php p($l->t('Assignments')); ?></span>
			<span id="dc-metric-assignments" class="dc-summary-tile__value">0</span>
			<span class="dc-summary-tile__hint"><?php p($l->t('Total recorded across all periods.')); ?></span>
		</article>
	</div>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-dashboard-checklist-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-dashboard-checklist-title"><?php p($l->t('Planner checklist')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Walk through this list each time you prepare a period for publication.')); ?>
			</p>
		</div>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item">
			<strong><?php p($l->t('Create or review the period')); ?></strong>
			<p><?php p($l->t('Open Periods, set the date range, and confirm there is no overlap.')); ?></p>
			<a class="button" href="<?php p((string) ($urls['periods'] ?? '#')); ?>">
				<?php p($l->t('Go to Periods')); ?>
			</a>
		</li>
		<li class="dc-quickstart__item">
			<strong><?php p($l->t('Plan assignments')); ?></strong>
			<p><?php p($l->t('Open Roster, use “Add assignment” for each shift, and resolve every “Must fix” issue before continuing.')); ?></p>
			<a class="button" href="<?php p((string) ($urls['roster'] ?? '#')); ?>">
				<?php p($l->t('Go to Roster')); ?>
			</a>
		</li>
		<li class="dc-quickstart__item">
			<strong><?php p($l->t('Confirm any “Confirm to continue” items')); ?></strong>
			<p><?php p($l->t('These need a short written confirmation on the Roster page. It is stored in the audit trail.')); ?></p>
		</li>
		<li class="dc-quickstart__item">
			<strong><?php p($l->t('Publish the period')); ?></strong>
			<p><?php p($l->t('Publishing creates an immutable snapshot. Re-opening later requires a reason.')); ?></p>
			<a class="button" href="<?php p((string) ($urls['periods'] ?? '#')); ?>">
				<?php p($l->t('Manage publication')); ?>
			</a>
		</li>
	</ol>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-dashboard-conflicts-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-dashboard-conflicts-title"><?php p($l->t('Planning status')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('A quick read on open planning issues in the latest editable period. “Must fix” always blocks publishing.')); ?>
			</p>
		</div>
	</header>
	<div id="dc-dashboard-conflict-pulse" class="dc-loading"
		role="status" aria-live="polite" aria-busy="true">
		<?php p($l->t('Loading planning checks…')); ?>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

<?php
/**
 * Settings sub-page: Notifications & retention (ops flags, snapshot pruning).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-ops" aria-labelledby="dc-settings-ops-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-ops-title" class="dc-sr-only"><?php p($l->t('Notifications & retention')); ?></h2>
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
				<span class="dc-checkbox__text"><?php p($l->t('Enable on-duty read hook for compatible companion apps (feature-flagged)')); ?></span>
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

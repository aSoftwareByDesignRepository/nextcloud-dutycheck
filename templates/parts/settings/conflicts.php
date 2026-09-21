<?php
/**
 * Settings sub-page: Conflict thresholds (ArbZG-oriented planning checks).
 *
 * Caps freeze on each open period at create time. Saving the live policy alone
 * does not rewrite open periods — admins must apply explicitly when the callout
 * shows the Apply CTA (hidden when already in sync — no dead primary button).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-conflict-policy" aria-labelledby="dc-settings-conflict-policy-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-conflict-policy-title" class="dc-sr-only"><?php p($l->t('Conflict thresholds')); ?></h2>
			<p class="dc-section__sub" id="dc-conflict-policy-intro">
				<?php p($l->t('These limits decide when the roster shows “Must fix” or “Confirm to continue”. Values are minutes. Hard limits always block publishing.')); ?>
			</p>
		</div>
	</header>

	<div class="dc-callout dc-callout--info" id="dc-conflict-freeze-callout" role="status" aria-live="polite" aria-atomic="true" aria-labelledby="dc-conflict-freeze-title">
		<p class="dc-callout__title" id="dc-conflict-freeze-title"><?php p($l->t('Checking open periods…')); ?></p>
		<p class="dc-callout__hint" id="dc-conflict-open-status">
			<?php p($l->t('Open periods keep the caps from when they were created. Saving updates the default for new periods.')); ?>
		</p>
		<div class="dc-callout__actions" id="dc-conflict-apply-actions" hidden>
			<button type="button" class="button primary" id="dc-conflict-apply-open" hidden disabled aria-disabled="true">
				<?php p($l->t('Apply to open periods')); ?>
			</button>
		</div>
	</div>

	<form id="dc-conflict-policy-form" class="dc-form-grid" novalidate aria-describedby="dc-conflict-policy-intro">
		<div class="dc-field">
			<label class="dc-field__label" for="dc-policy-min-rest"><?php p($l->t('Minimum rest (minutes)')); ?></label>
			<input id="dc-policy-min-rest" type="number" class="dc-input dc-input--num" name="minRestMinutes" min="0" max="1440" step="1" required aria-describedby="dc-policy-min-rest-hint">
			<p class="dc-field__hint" id="dc-policy-min-rest-hint"><span data-dc-minutes-hint></span></p>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-policy-max-daily"><?php p($l->t('Daily hard cap (minutes)')); ?></label>
			<input id="dc-policy-max-daily" type="number" class="dc-input dc-input--num" name="maxDailyHard" min="1" max="1440" step="1" required aria-describedby="dc-policy-max-daily-hint">
			<p class="dc-field__hint" id="dc-policy-max-daily-hint"><span data-dc-minutes-hint></span></p>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-policy-max-soft"><?php p($l->t('Period soft cap (minutes)')); ?></label>
			<input id="dc-policy-max-soft" type="number" class="dc-input dc-input--num" name="maxPeriodSoft" min="1" max="20160" step="1" required aria-describedby="dc-policy-max-soft-hint dc-policy-max-soft-note">
			<p class="dc-field__hint" id="dc-policy-max-soft-hint"><span data-dc-minutes-hint></span></p>
			<p class="dc-field__hint" id="dc-policy-max-soft-note">
				<?php p($l->t('Also used as the soft calendar-week total (Mon–Sun). Soft = confirm with a short reason.')); ?>
			</p>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-policy-max-hard"><?php p($l->t('Period hard cap (minutes)')); ?></label>
			<input id="dc-policy-max-hard" type="number" class="dc-input dc-input--num" name="maxPeriodHard" min="1" max="30240" step="1" required aria-describedby="dc-policy-max-hard-hint dc-policy-max-hard-note">
			<p class="dc-field__hint" id="dc-policy-max-hard-hint"><span data-dc-minutes-hint></span></p>
			<p class="dc-field__hint" id="dc-policy-max-hard-note">
				<?php p($l->t('Also used as the hard calendar-week total. Hard = must fix before publishing. Defaults (~60 h) fit a week; raise them for a full month.')); ?>
			</p>
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

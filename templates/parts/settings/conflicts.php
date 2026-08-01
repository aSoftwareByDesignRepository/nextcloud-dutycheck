<?php
/**
 * Settings sub-page: Conflict thresholds (ArbZG-oriented planning checks).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-conflict-policy" aria-labelledby="dc-settings-conflict-policy-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-conflict-policy-title" class="dc-sr-only"><?php p($l->t('Conflict thresholds')); ?></h2>
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

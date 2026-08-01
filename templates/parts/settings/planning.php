<?php
/**
 * Settings sub-page: Planning defaults.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section dc-settings-panel" id="dc-settings-planning" aria-labelledby="dc-settings-planning-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-planning-title" class="dc-sr-only"><?php p($l->t('Planning defaults')); ?></h2>
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

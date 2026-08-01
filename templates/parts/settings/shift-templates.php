<?php
/**
 * Settings sub-page: Shift templates (start/end/break presets).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-templates" aria-labelledby="dc-settings-templates-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-templates-title" class="dc-sr-only"><?php p($l->t('Shift templates')); ?></h2>
		</div>
	</header>
	<form id="dc-template-form" class="dc-form-grid" novalidate>
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-template-name"><?php p($l->t('Name')); ?></label>
			<input id="dc-template-name" type="text" class="dc-input" name="name" maxlength="120" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-template-start"><?php p($l->t('Start')); ?></label>
			<input id="dc-template-start" type="time" class="dc-input dc-input--time-24h" name="startTime" step="60" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-template-end"><?php p($l->t('End')); ?></label>
			<input id="dc-template-end" type="time" class="dc-input dc-input--time-24h" name="endTime" step="60" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-template-break"><?php p($l->t('Break (minutes)')); ?></label>
			<input id="dc-template-break" type="number" class="dc-input dc-input--num" name="breakMinutes" min="0" max="720" step="5" value="0" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-template-headcount"><?php p($l->t('Minimum headcount')); ?></label>
			<input id="dc-template-headcount" type="number" class="dc-input dc-input--num" name="minHeadcount" min="0" max="99" step="1" value="0" aria-describedby="dc-template-headcount-hint">
			<p id="dc-template-headcount-hint" class="dc-field__hint">
				<?php p($l->t('When greater than zero and a location is set, days with fewer assignments show a “Confirm to continue” understaffed check. Use 0 to disable.')); ?>
			</p>
		</div>
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-template-location"><?php p($l->t('Location (optional)')); ?></label>
			<select id="dc-template-location" class="dc-input" name="locationId">
				<option value=""><?php p($l->t('Global (all locations)')); ?></option>
			</select>
		</div>
		<p id="dc-template-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Add template')); ?></button>
		</div>
	</form>
	<ul id="dc-template-list" class="dc-chip-list" aria-label="<?php p($l->t('Saved shift templates')); ?>"></ul>
</section>

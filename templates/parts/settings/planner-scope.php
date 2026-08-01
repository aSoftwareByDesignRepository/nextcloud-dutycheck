<?php
/**
 * Settings sub-page: Planner location scope.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-scope" aria-labelledby="dc-settings-scope-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-scope-title" class="dc-sr-only"><?php p($l->t('Planner location scope')); ?></h2>
		</div>
	</header>
	<form id="dc-planner-scope-form" class="dc-form-grid" novalidate>
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-scope-user-search"><?php p($l->t('Colleague')); ?></label>
			<div class="dc-entity-picker">
				<input id="dc-scope-user-search" type="search" class="dc-input"
					autocomplete="off" placeholder="<?php p($l->t('Search colleagues…')); ?>"
					aria-controls="dc-scope-user-results">
				<ul id="dc-scope-user-results" class="dc-entity-results" role="listbox"
					aria-label="<?php p($l->t('User search results')); ?>"></ul>
			</div>
			<input type="hidden" id="dc-scope-user" name="userId">
			<p class="dc-field__hint" id="dc-scope-user-hint">
				<?php p($l->t('Type at least 2 characters and pick a colleague from the list.')); ?>
			</p>
		</div>
		<div class="dc-field dc-field--full">
			<fieldset class="dc-fieldset">
				<legend class="dc-field__label"><?php p($l->t('Locations this planner may edit')); ?></legend>
				<p class="dc-hint" id="dc-scope-locs-hint"><?php p($l->t('Tick locations to restrict. Leave all unticked for every location (legacy).')); ?></p>
				<div id="dc-scope-locs" class="dc-check-grid" role="group" aria-describedby="dc-scope-locs-hint"></div>
			</fieldset>
		</div>
		<p id="dc-scope-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
		<div class="dc-form-actions">
			<button type="button" class="button" id="dc-scope-load" disabled><?php p($l->t('Load current scope')); ?></button>
			<button type="submit" class="button primary" id="dc-scope-save" disabled><?php p($l->t('Save scope')); ?></button>
		</div>
	</form>
</section>

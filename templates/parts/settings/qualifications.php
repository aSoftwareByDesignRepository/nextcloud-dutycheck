<?php
/**
 * Settings sub-page: Qualifications (catalog, employee links, location requirements).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-quals" aria-labelledby="dc-settings-quals-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-quals-title" class="dc-sr-only"><?php p($l->t('Qualifications')); ?></h2>
		</div>
	</header>
	<form id="dc-qual-form" class="dc-form-grid" novalidate>
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-qual-name"><?php p($l->t('Name')); ?></label>
			<input id="dc-qual-name" type="text" class="dc-input" name="name" maxlength="120" required>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-code"><?php p($l->t('Code (optional)')); ?></label>
			<input id="dc-qual-code" type="text" class="dc-input" name="code" maxlength="64">
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Add qualification')); ?></button>
		</div>
	</form>
	<ul id="dc-qual-list" class="dc-chip-list" aria-label="<?php p($l->t('Qualification catalog')); ?>"></ul>
	<form id="dc-qual-attach-form" class="dc-form-grid dc-form-grid--nested" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-attach-emp"><?php p($l->t('Employee')); ?></label>
			<select id="dc-qual-attach-emp" class="dc-input" name="employeeId" required>
				<option value=""><?php p($l->t('Choose an employee…')); ?></option>
			</select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-attach-id"><?php p($l->t('Qualification')); ?></label>
			<select id="dc-qual-attach-id" class="dc-input" name="qualificationId" required>
				<option value=""><?php p($l->t('Choose a qualification…')); ?></option>
			</select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-attach-expires"><?php p($l->t('Expires on (optional)')); ?></label>
			<input id="dc-qual-attach-expires" type="date" class="dc-input" name="expiresOn">
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Attach to employee')); ?></button>
		</div>
	</form>
	<form id="dc-qual-detach-form" class="dc-form-grid dc-form-grid--nested" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-detach-emp"><?php p($l->t('Employee')); ?></label>
			<select id="dc-qual-detach-emp" class="dc-input" name="employeeId" required>
				<option value=""><?php p($l->t('Choose an employee…')); ?></option>
			</select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-detach-id"><?php p($l->t('Qualification')); ?></label>
			<select id="dc-qual-detach-id" class="dc-input" name="qualificationId" required>
				<option value=""><?php p($l->t('Choose a qualification…')); ?></option>
			</select>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button"><?php p($l->t('Remove from employee')); ?></button>
		</div>
	</form>
	<form id="dc-qual-loc-form" class="dc-form-grid" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-loc-id"><?php p($l->t('Location')); ?></label>
			<select id="dc-qual-loc-id" class="dc-input" name="locationId" required>
				<option value=""><?php p($l->t('Choose a location…')); ?></option>
			</select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-qual-loc-qid"><?php p($l->t('Required qualification')); ?></label>
			<select id="dc-qual-loc-qid" class="dc-input" name="qualificationId" required>
				<option value=""><?php p($l->t('Choose a qualification…')); ?></option>
			</select>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Require at location')); ?></button>
		</div>
	</form>
</section>

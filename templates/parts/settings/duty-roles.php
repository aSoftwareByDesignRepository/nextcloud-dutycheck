<?php
/**
 * Settings sub-page: Duty roles (planner role assignment).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-duty-roles" aria-labelledby="dc-settings-duty-roles-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-duty-roles-title" class="dc-sr-only"><?php p($l->t('Duty roles')); ?></h2>
		</div>
	</header>
	<div class="dc-form-grid">
		<div class="dc-field dc-field--full">
			<label class="dc-field__label" for="dc-duty-role-user-search"><?php p($l->t('Assign planner role')); ?></label>
			<div class="dc-entity-picker">
				<input id="dc-duty-role-user-search" type="search" class="dc-input"
					autocomplete="off" placeholder="<?php p($l->t('Search users to assign planner role…')); ?>"
					aria-controls="dc-duty-role-user-results">
				<ul id="dc-duty-role-user-results" class="dc-entity-results" role="listbox"
					aria-label="<?php p($l->t('User search results')); ?>"></ul>
			</div>
			<div class="dc-form-actions">
				<button type="button" class="button primary" id="dc-duty-role-assign" disabled>
					<?php p($l->t('Assign planner')); ?>
				</button>
			</div>
		</div>
		<div class="dc-field dc-field--full">
			<h3 id="dc-duty-roles-current-title" class="dc-subsection-heading"><?php p($l->t('Current duty role assignments')); ?></h3>
			<div class="dc-table-wrap" tabindex="0" role="region" aria-labelledby="dc-duty-roles-current-title">
				<table class="dc-table" id="dc-duty-roles-table">
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('User')); ?></th>
							<th scope="col"><?php p($l->t('Role')); ?></th>
							<th scope="col"><?php p($l->t('Assigned')); ?></th>
							<th scope="col" class="dc-table__col--actions"><?php p($l->t('Actions')); ?></th>
						</tr>
					</thead>
					<tbody id="dc-duty-roles-tbody"></tbody>
				</table>
			</div>
		</div>
	</div>
</section>

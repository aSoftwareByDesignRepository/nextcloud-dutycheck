<?php
/**
 * Employee directory page.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
?>
<section class="dc-card dc-empty dc-empty--quickstart" id="dc-employees-quickstart" hidden aria-labelledby="dc-employees-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-employees-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Employees are the people who work duties. Once added, they can be assigned in the Roster.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="employees_quickstart_v1" aria-describedby="dc-employees-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="link">
			<strong><?php p($l->t('1. Optionally link a Nextcloud account')); ?></strong>
			<p>
				<?php p($l->t('Search the directory first — the display name is filled in automatically. Linking enables self-service: roster, absences, and calendar feed for that person.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="add">
			<strong><?php p($l->t('2. Confirm the display name')); ?></strong>
			<p>
				<?php p($l->t('The name appears on rosters and in audit logs. Adjust it if needed, or type one manually when no account is linked.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="lifecycle">
			<strong><?php p($l->t('3. Activate or deactivate later')); ?></strong>
			<p>
				<?php p($l->t('Records are never deleted — that keeps the audit trail intact. When someone leaves, deactivate them so they vanish from new assignments while existing entries stay readable.')); ?>
			</p>
		</li>
	</ol>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-employee-form-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-employee-form-title"><?php p($l->t('Add or update employee')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Search for a Nextcloud user first to fill in the name, or enter a display name for staff without an account.')); ?>
			</p>
		</div>
	</header>
	<form id="dc-employee-form" class="dc-form-grid dc-form-grid--employees" novalidate>
		<div class="dc-field dc-field--employee-link">
			<label class="dc-field__label" for="dc-employee-search"><?php p($l->t('Linked user account')); ?></label>
			<div class="dc-entity-picker">
				<div class="dc-entity-picker__field">
					<input id="dc-employee-search" type="search" class="dc-input"
						autocomplete="off" placeholder="<?php p($l->t('Search Nextcloud users…')); ?>"
						aria-controls="dc-employee-search-results">
				</div>
				<ul id="dc-employee-search-results" class="dc-entity-results" role="listbox"
					aria-label="<?php p($l->t('User search results')); ?>"></ul>
			</div>
			<input type="hidden" name="linkedUserId" id="dc-employee-linked-user" value="">
			<ul id="dc-employee-linked-chips" class="dc-chip-list" aria-label="<?php p($l->t('Linked account')); ?>"></ul>
			<p class="dc-field__hint">
				<?php p($l->t('Type at least 2 characters to search the directory. Leave empty for an unlinked employee.')); ?>
			</p>
		</div>
		<div class="dc-field dc-field--employee-name">
			<label class="dc-field__label" for="dc-employee-name"><?php p($l->t('Display name')); ?></label>
			<input id="dc-employee-name" type="text" name="displayName" class="dc-input"
				maxlength="191" autocomplete="name" required
				aria-describedby="dc-employee-name-hint">
			<p id="dc-employee-name-hint" class="dc-field__hint">
				<?php p($l->t('Filled automatically when you link an account; edit if needed.')); ?>
			</p>
		</div>
		<div class="dc-field dc-field--employee-active">
			<span class="dc-field__label" id="dc-employee-active-label"><?php p($l->t('Status')); ?></span>
			<label class="dc-checkbox" for="dc-employee-active" aria-labelledby="dc-employee-active-label">
				<input id="dc-employee-active" type="checkbox" name="active" checked>
				<span class="dc-checkbox__text"><?php p($l->t('Active – available for new assignments')); ?></span>
			</label>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Save employee')); ?></button>
			<button type="button" id="dc-employee-form-reset" class="button" hidden><?php p($l->t('Cancel edit')); ?></button>
		</div>
	</form>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-employees-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-employees-title"><?php p($l->t('Employees')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Use Edit to change details, and Activate / Deactivate to control planning availability.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-table-wrap">
		<table class="dc-table">
			<caption class="dc-sr-only"><?php p($l->t('Employees list')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Linked user')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col" class="dc-table__col--actions"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-employees-table-body"></tbody>
		</table>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

<?php
/**
 * Locations management.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$timezones = (array) ($_['timezones'] ?? []);
$clientHints = (array) ($_['clientHints'] ?? []);
$defaultTimezone = (string) ($clientHints['timezone'] ?? 'Europe/Berlin');
?>
<section class="dc-card dc-empty dc-empty--quickstart" id="dc-locations-quickstart" hidden aria-labelledby="dc-locations-quickstart-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-locations-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Locations are the physical or virtual places where duties happen. Each one carries a timezone so shift times stay correct year-round.')); ?>
			</p>
		</div>
		<button type="button" class="dc-hint-dismiss" data-dc-dismiss-hint="locations_quickstart_v1" aria-describedby="dc-locations-quickstart-title">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="dc-quickstart">
		<li class="dc-quickstart__item" data-step="name">
			<strong><?php p($l->t('1. Name the place')); ?></strong>
			<p>
				<?php p($l->t('Pick something short and recognisable, e.g. "Reception desk" or "Warehouse - Munich". This is what planners and employees see in every assignment.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="timezone">
			<strong><?php p($l->t('2. Pick the correct timezone')); ?></strong>
			<p>
				<?php p($l->t('Use IANA names like "Europe/Berlin". Timezones matter: a 22:00 shift on the last Sunday in March must still be the right time after the clock change.')); ?>
			</p>
		</li>
		<li class="dc-quickstart__item" data-step="lifecycle">
			<strong><?php p($l->t('3. Activate or deactivate later')); ?></strong>
			<p>
				<?php p($l->t('Closing a location? Deactivate it instead of deleting. New assignments stop appearing for that location, but past rosters remain intact.')); ?>
			</p>
		</li>
	</ol>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-location-form-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-location-form-title"><?php p($l->t('Add or update location')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('A location is a place where duties happen. Set the correct timezone so shift times stay accurate across DST.')); ?>
			</p>
		</div>
	</header>
	<form id="dc-location-form" class="dc-form-stack dc-form-stack--locations" novalidate>
		<div class="dc-form-stack__segment">
			<div class="dc-form-stack__grid dc-form-stack__grid--2">
				<div class="dc-field dc-field--location-name">
					<label class="dc-field__label" for="dc-location-name"><?php p($l->t('Location name')); ?></label>
					<input id="dc-location-name" type="text" name="name" class="dc-input"
						maxlength="191" autocomplete="organization" required>
				</div>
				<div class="dc-field dc-field--location-active">
					<span class="dc-field__label"><?php p($l->t('Status')); ?></span>
					<label class="dc-checkbox" for="dc-location-active">
						<input id="dc-location-active" type="checkbox" name="active" checked>
						<span class="dc-checkbox__text"><?php p($l->t('Active – available for assignments')); ?></span>
					</label>
				</div>
			</div>
		</div>
		<div class="dc-form-stack__segment">
			<div class="dc-field dc-field--location-timezone">
				<label class="dc-field__label" for="dc-location-timezone"><?php p($l->t('Timezone')); ?></label>
				<select id="dc-location-timezone" name="timezone" class="dc-input dc-input--timezone-select" required>
					<?php foreach ($timezones as $timezone):
						$zone = (string) $timezone;
						$selected = $zone === $defaultTimezone;
						?>
						<option value="<?php p($zone); ?>" <?php if ($selected): ?>selected<?php endif; ?>>
							<?php p($zone); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="dc-field__hint">
					<?php p($l->t('Defaults to your account timezone. IANA names only (for example: Europe/Berlin).')); ?>
				</p>
			</div>
		</div>
		<div class="dc-form-stack__segment dc-form-stack__segment--actions">
			<div class="dc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Save location')); ?></button>
				<button type="button" id="dc-location-form-reset" class="button" hidden><?php p($l->t('Cancel edit')); ?></button>
			</div>
		</div>
	</form>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-locations-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-locations-title"><?php p($l->t('Locations')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Deactivate locations that should no longer appear in assignment planning.')); ?>
			</p>
		</div>
	</header>
	<div class="dc-table-wrap">
		<table class="dc-table">
			<caption class="dc-sr-only"><?php p($l->t('Locations list')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Timezone')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col" class="dc-table__col--actions"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody id="dc-locations-table-body"></tbody>
		</table>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

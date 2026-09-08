<?php
/**
 * Today board — “Wer ist wo?” (US-H04).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$urls = (array) ($_['urls'] ?? []);
$rosterUrl = (string) ($urls['roster'] ?? '#');
$dienstUrl = (string) ($urls['dienstTeamSettings'] ?? ($urls['settings'] ?? '#'));
?>
<section class="dc-card dc-section dc-today" id="dc-today-board"
	aria-labelledby="dc-today-title"
	data-roster-url="<?php p($rosterUrl); ?>"
	data-settings-url="<?php p($dienstUrl); ?>"
	data-msg-loading="<?php p($l->t('Loading today’s board…')); ?>"
	data-msg-empty="<?php p($l->t('Nobody is scheduled here on this day.')); ?>"
	data-msg-error="<?php p($l->t('Could not load the Today board.')); ?>"
	data-msg-disabled="<?php p($l->t('The Today board is turned off in Duty & team settings.')); ?>"
	data-msg-gap="<?php p($l->t('Coverage gap')); ?>"
	data-msg-draft="<?php p($l->t('Draft')); ?>"
	data-msg-published="<?php p($l->t('Published')); ?>">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-today-title"><?php p($l->t('Who is where?')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('One location, one day. Open the roster when you need to change the plan.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<a class="button primary" id="dc-today-open-roster" href="<?php p($rosterUrl); ?>">
				<?php p($l->t('Open roster')); ?>
			</a>
		</div>
	</header>

	<form id="dc-today-filters" class="dc-form-grid dc-today__filters" aria-label="<?php p($l->t('Today filters')); ?>" novalidate>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-today-location"><?php p($l->t('Location')); ?></label>
			<select id="dc-today-location" name="locationId" class="dc-input" required></select>
		</div>
		<div class="dc-field">
			<label class="dc-field__label" for="dc-today-date"><?php p($l->t('Date')); ?></label>
			<input id="dc-today-date" type="date" name="date" class="dc-input dc-input--date" required autocomplete="off">
			<div class="dc-today__date-shortcuts" role="group" aria-label="<?php p($l->t('Quick date')); ?>">
				<button type="button" class="button" id="dc-today-prev" aria-label="<?php p($l->t('Previous day')); ?>"><?php p($l->t('Yesterday')); ?></button>
				<button type="button" class="button primary" id="dc-today-now" aria-label="<?php p($l->t('Today')); ?>"><?php p($l->t('Today')); ?></button>
				<button type="button" class="button" id="dc-today-next" aria-label="<?php p($l->t('Next day')); ?>"><?php p($l->t('Tomorrow')); ?></button>
			</div>
		</div>
		<div class="dc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Show')); ?></button>
		</div>
	</form>

	<p id="dc-today-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true"></p>
	<div id="dc-today-disabled" class="dc-callout dc-callout--warning" hidden role="status">
		<p id="dc-today-disabled-text"></p>
		<p><a class="button" id="dc-today-settings-link" href="<?php p($dienstUrl); ?>"><?php p($l->t('Open Duty & team')); ?></a></p>
	</div>
	<div id="dc-today-skeleton" class="dc-today__skeleton" aria-hidden="true" hidden>
		<div class="dc-today__skeleton-row"></div>
		<div class="dc-today__skeleton-row"></div>
		<div class="dc-today__skeleton-row"></div>
	</div>
	<ul id="dc-today-gaps" class="dc-today__gaps" role="list" aria-label="<?php p($l->t('Coverage gaps')); ?>" hidden></ul>
	<ol id="dc-today-timeline" class="dc-today__timeline" aria-labelledby="dc-today-title"></ol>
	<div id="dc-today-empty" class="dc-today__empty" hidden>
		<p><?php p($l->t('Nobody is scheduled here on this day.')); ?></p>
	</div>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

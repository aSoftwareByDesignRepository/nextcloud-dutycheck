<?php
/**
 * Rotation patterns — list + create/edit (US-A01).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$urls = (array) ($_['urls'] ?? []);
$dienstUrl = (string) ($urls['dienstTeamSettings'] ?? ($urls['settings'] ?? '#'));
?>
<section class="dc-card dc-section dc-patterns" id="dc-patterns-page"
	aria-labelledby="dc-patterns-title"
	data-settings-url="<?php p($dienstUrl); ?>"
	data-msg-disabled="<?php p($l->t('Patterns are off. Turn on Muster in Duty & team settings first.')); ?>"
	data-msg-empty="<?php p($l->t('No patterns yet. Create one for a person or role.')); ?>"
	data-msg-saved="<?php p($l->t('Pattern saved.')); ?>"
	data-msg-assigned="<?php p($l->t('Pattern assigned.')); ?>"
	data-msg-load-error="<?php p($l->t('Could not load patterns.')); ?>"
	data-label-week="<?php p($l->t('Week {n}')); ?>"
	data-label-mon="<?php p($l->t('Mon')); ?>"
	data-label-tue="<?php p($l->t('Tue')); ?>"
	data-label-wed="<?php p($l->t('Wed')); ?>"
	data-label-thu="<?php p($l->t('Thu')); ?>"
	data-label-fri="<?php p($l->t('Fri')); ?>"
	data-label-sat="<?php p($l->t('Sat')); ?>"
	data-label-sun="<?php p($l->t('Sun')); ?>"
	data-label-working="<?php p($l->t('Working')); ?>"
	data-label-net="<?php p($l->t('Net minutes')); ?>"
	data-label-start="<?php p($l->t('Start')); ?>"
	data-label-end="<?php p($l->t('End')); ?>">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-patterns-title"><?php p($l->t('Rotation patterns')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Each pattern is an N-week cycle. Assign it to a person with a start date.')); ?>
			</p>
		</div>
		<div class="dc-section__controls">
			<button type="button" class="button primary" id="dc-patterns-create"><?php p($l->t('New pattern')); ?></button>
		</div>
	</header>
	<p id="dc-patterns-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true"></p>
	<div id="dc-patterns-disabled" class="dc-callout dc-callout--warning" hidden role="status">
		<p id="dc-patterns-disabled-text"></p>
		<p><a class="button" id="dc-patterns-settings-link" href="<?php p($dienstUrl); ?>"><?php p($l->t('Open Duty & team')); ?></a></p>
	</div>
	<div id="dc-patterns-empty" class="dc-patterns__empty" hidden>
		<p><?php p($l->t('No patterns yet. Create one for a person or role.')); ?></p>
	</div>
	<ul id="dc-patterns-list" class="dc-patterns__list" role="list" aria-labelledby="dc-patterns-title"></ul>
</section>
<?php include __DIR__ . '/common/page-end.php'; ?>

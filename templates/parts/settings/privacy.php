<?php
/**
 * Settings sub-page: Privacy & glossary.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section" id="dc-settings-privacy" aria-labelledby="dc-settings-privacy-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-privacy-title" class="dc-sr-only"><?php p($l->t('Privacy & words we use')); ?></h2>
		</div>
	</header>
	<div class="dc-settings-privacy">
		<h3 class="dc-subsection-heading"><?php p($l->t('Privacy')); ?></h3>
		<ul class="dc-settings-privacy__list">
			<li><?php p($l->t('Duty assignments and absences stay on your Nextcloud. There is no vendor cloud copy of your roster.')); ?></li>
			<li><?php p($l->t('When a Nextcloud account is deleted, DutyCheck removes that user’s roles, access-list entries, company membership, planner location scope, mobile seat, preferences, and employee account link. Historical roster rows stay for audit evidence with the link cleared.')); ?></li>
			<li><?php p($l->t('Close snapshots are hash-chained and never rewritten. Re-opening a period starts a new planning cycle without mutating the old evidence.')); ?></li>
			<li><?php p($l->t('iCal feed tokens can be rotated per employee. Treat feed URLs like passwords.')); ?></li>
		</ul>
		<h3 class="dc-subsection-heading"><?php p($l->t('Words we use')); ?></h3>
		<dl class="dc-settings-privacy__glossary">
			<div>
				<dt><?php p($l->t('Period')); ?></dt>
				<dd><?php p($l->t('A date range you plan in: open → published → closed.')); ?></dd>
			</div>
			<div>
				<dt><?php p($l->t('Assignment')); ?></dt>
				<dd><?php p($l->t('One planned shift for one person on one day.')); ?></dd>
			</div>
			<div>
				<dt><?php p($l->t('Must fix')); ?></dt>
				<dd><?php p($l->t('A hard conflict that blocks saving or publishing until resolved.')); ?></dd>
			</div>
			<div>
				<dt><?php p($l->t('Confirm to continue')); ?></dt>
				<dd><?php p($l->t('A soft conflict you may keep after writing a short reason (≥ 10 characters).')); ?></dd>
			</div>
			<div>
				<dt><?php p($l->t('Snapshot')); ?></dt>
				<dd><?php p($l->t('An immutable roster picture taken on publish or close, with an integrity hash.')); ?></dd>
			</div>
		</dl>
	</div>
</section>

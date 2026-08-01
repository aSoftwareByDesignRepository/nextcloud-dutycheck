<?php
/**
 * Settings sub-page: ArbeitszeitCheck integration (absence mirror controls).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section dc-at-integration" id="dc-at-integration" aria-labelledby="dc-at-integ-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-at-integ-title" class="dc-sr-only"><?php p($l->t('ArbeitszeitCheck integration')); ?></h2>
		</div>
	</header>
	<div class="dc-at-integration__body">
		<div class="dc-at-integration__status" aria-labelledby="dc-at-status-heading">
			<h3 id="dc-at-status-heading" class="dc-subsection-heading"><?php p($l->t('Status')); ?></h3>
			<div class="dc-at-integration__banner-row">
				<div id="dc-at-integration-banner" class="dc-callout dc-callout--warning dc-at-integration__banner" hidden role="status" aria-live="polite"></div>
				<button type="button" class="button dc-at-integration__retry" id="dc-at-retry-load-btn" hidden>
					<?php p($l->t('Load integration status again')); ?>
				</button>
			</div>
			<p class="dc-field__hint dc-at-integration__meta" id="dc-at-meta" aria-live="polite"></p>
		</div>
		<hr class="dc-form-grid__divider dc-at-integration__divider" aria-hidden="true">
		<div class="dc-at-integration__controls" role="group" aria-labelledby="dc-at-controls-heading">
			<h3 id="dc-at-controls-heading" class="dc-subsection-heading"><?php p($l->t('Integration controls')); ?></h3>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-at-intent-enabled">
					<input type="checkbox" id="dc-at-intent-enabled" name="atIntent" aria-describedby="dc-at-intent-hint">
					<span class="dc-checkbox__text"><?php p($l->t('Connect DutyCheck to ArbeitszeitCheck')); ?></span>
				</label>
				<p class="dc-field__hint" id="dc-at-intent-hint"></p>
			</div>
			<div class="dc-field dc-field--full" id="dc-at-disable-reason-wrap" hidden>
				<label class="dc-field__label" for="dc-at-disable-reason"><?php p($l->t('Why are you turning the connection off? (optional)')); ?></label>
				<input type="text" class="dc-input" id="dc-at-disable-reason" name="disableReason" maxlength="500" autocomplete="off" aria-describedby="dc-at-disable-reason-hint">
				<p class="dc-field__hint" id="dc-at-disable-reason-hint"><?php p($l->t('A short note is saved in the audit log so others know why the connector was disabled.')); ?></p>
			</div>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-at-block-publish-stale">
					<input type="checkbox" id="dc-at-block-publish-stale" name="blockPublishWhenStale" aria-describedby="dc-at-block-publish-hint">
					<span class="dc-checkbox__text"><?php p($l->t('Block roster publish when absence sync is stale')); ?></span>
				</label>
				<p class="dc-field__hint" id="dc-at-block-publish-hint">
					<?php p($l->t('When on, planners cannot publish a period if the last sync is older than the publish window or the sync circuit breaker is open. Default: off (show a warning instead).')); ?>
				</p>
			</div>
			<div class="dc-field dc-field--full">
				<label class="dc-checkbox" for="dc-at-include-pii">
					<input type="checkbox" id="dc-at-include-pii" name="includePii" aria-describedby="dc-at-include-pii-hint">
					<span class="dc-checkbox__text"><?php p($l->t('Include sensitive absence notes in the mirror (PII)')); ?></span>
				</label>
				<p class="dc-field__hint" id="dc-at-include-pii-hint">
					<?php p($l->t('Off by default. When on, reason and approver comments are copied into DutyCheck. Requires a short written justification. Turn off to scrub stored notes.')); ?>
				</p>
				<label class="dc-field__label" for="dc-at-pii-justification"><?php p($l->t('PII justification (required to enable)')); ?></label>
				<input type="text" class="dc-input" id="dc-at-pii-justification" name="piiJustification" maxlength="500" autocomplete="off" aria-describedby="dc-at-include-pii-hint">
			</div>
			<div class="dc-form-actions dc-at-integration__actions">
				<button type="button" class="button primary" id="dc-at-sync-btn"><?php p($l->t('Sync now')); ?></button>
				<button type="button" class="button danger" id="dc-at-purge-legacy-btn" hidden><?php p($l->t('Remove legacy DutyCheck absences')); ?></button>
				<a class="button" id="dc-at-open-peer" hidden target="_blank" rel="noopener noreferrer"><?php p($l->t('Open ArbeitszeitCheck')); ?></a>
			</div>
		</div>
	</div>
</section>

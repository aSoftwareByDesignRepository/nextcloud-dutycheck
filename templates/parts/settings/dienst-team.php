<?php
/**
 * Settings sub-page: Duty & team (Roster Self-Service GA — US-F01).
 *
 * Primary (always visible): Muster · Soll · Today · Kollegenplan · Kann nicht · Nachtruhe.
 * Advanced (under “More options”): swap rules, wishes, quiet-hour times.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$urls = (array) ($_['urls'] ?? []);
$todayUrl = (string) ($urls['today'] ?? '#');
$patternsUrl = (string) ($urls['patterns'] ?? '#');
?>
<section class="dc-card dc-section dc-dienst-team" id="dc-settings-dienst-team"
	aria-labelledby="dc-settings-dienst-team-title"
	data-msg-saved="<?php p($l->t('Duty & team settings saved.')); ?>"
	data-msg-load-error="<?php p($l->t('Could not load duty & team settings.')); ?>"
	data-msg-conflict="<?php p($l->t('Someone else saved these settings first. We reloaded the latest values — check and save again.')); ?>"
	data-msg-soll-warn="<?php p($l->t('Soll from Duty needs people linked to Nextcloud accounts (and usually ArbeitszeitCheck). Turn it on anyway?')); ?>"
	data-msg-saving="<?php p($l->t('Saving…')); ?>"
	data-msg-admin-only="<?php p($l->t('Only a DutyCheck app admin can change these settings. You can still open Patterns and Today.')); ?>">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-dienst-team-title" class="dc-sr-only"><?php p($l->t('Duty & team')); ?></h2>
			<p class="dc-section__sub" id="dc-dt-intro">
				<?php p($l->t('Big switches first. Open “More options” only if you need swap rules or quiet-hour times.')); ?>
			</p>
		</div>
	</header>

	<div id="dc-dt-admin-only" class="dc-callout dc-callout--info" role="status" hidden>
		<p></p>
	</div>

	<form id="dc-dienst-team-form" class="dc-dienst-team__form" novalidate>
		<div class="dc-dienst-team__group" role="group" aria-labelledby="dc-dt-group-plan">
			<h3 id="dc-dt-group-plan" class="dc-dienst-team__group-title"><?php p($l->t('Plan & Soll')); ?></h3>
			<p class="dc-dienst-team__group-lead">
				<?php p($l->t('Turn on rotation patterns so each person can have their own repeating plan.')); ?>
			</p>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-muster">
				<input id="dc-dt-muster" type="checkbox" name="rotationPatternsEnabled">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Patterns')); ?></strong>
					<span class="dc-dienst-team__hint"><?php p($l->t('N-week patterns per person. Open Patterns to create them.')); ?></span>
				</span>
			</label>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-soll">
				<input id="dc-dt-soll" type="checkbox" name="sollFromDuty" aria-describedby="dc-dt-soll-hint">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Soll from Duty')); ?></strong>
					<span id="dc-dt-soll-hint" class="dc-dienst-team__hint">
						<?php p($l->t('Weekly target hours follow published duties instead of a static model.')); ?>
					</span>
					<span class="dc-dienst-team__hint"><?php p($l->t('Also enable in ArbeitszeitCheck → Integration')); ?></span>
				</span>
			</label>
			<div id="dc-dt-soll-warning" class="dc-callout dc-callout--warning" role="status" hidden>
				<p><?php p($l->t('No linked employees yet. Soll from Duty only helps when people are linked — and usually when ArbeitszeitCheck is connected.')); ?></p>
			</div>
			<p class="dc-dienst-team__inline-link">
				<a class="button" href="<?php p($patternsUrl); ?>"><?php p($l->t('Open Patterns')); ?></a>
			</p>
		</div>

		<div class="dc-dienst-team__group" role="group" aria-labelledby="dc-dt-group-today">
			<h3 id="dc-dt-group-today" class="dc-dienst-team__group-title"><?php p($l->t('Locations & Today')); ?></h3>
			<p class="dc-dienst-team__group-lead">
				<?php p($l->t('The Today board answers “who is where?” for one location and one day.')); ?>
			</p>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-today-board">
				<input id="dc-dt-today-board" type="checkbox" name="todayBoardEnabled">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Today board')); ?></strong>
					<span class="dc-dienst-team__hint"><?php p($l->t('Show the Wer ist wo? page for planners.')); ?></span>
				</span>
			</label>
			<p class="dc-dienst-team__inline-link">
				<a class="button primary" href="<?php p($todayUrl); ?>"><?php p($l->t('Open Today board')); ?></a>
			</p>
		</div>

		<div class="dc-dienst-team__group" role="group" aria-labelledby="dc-dt-group-team">
			<h3 id="dc-dt-group-team" class="dc-dienst-team__group-title"><?php p($l->t('Team self-service')); ?></h3>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-kollegen">
				<input id="dc-dt-kollegen" type="checkbox" name="peerRosterVisibility">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Kollegenplan')); ?></strong>
					<span class="dc-dienst-team__hint"><?php p($l->t('Staff can see published colleagues at the same location.')); ?></span>
				</span>
			</label>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-blackouts">
				<input id="dc-dt-blackouts" type="checkbox" name="blackoutsEnabled">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Kann nicht')); ?></strong>
					<span class="dc-dienst-team__hint"><?php p($l->t('Hard “cannot work” days — suggest-fill skips them; assign needs a reason.')); ?></span>
				</span>
			</label>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-prefs">
				<input id="dc-dt-prefs" type="checkbox" name="preferencesEnabled">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Wishes (Früh / Spät)')); ?></strong>
					<span class="dc-dienst-team__hint"><?php p($l->t('Soft preferences only — they never block a shift.')); ?></span>
				</span>
			</label>
			<div class="dc-dienst-team__more-block" data-dc-more hidden>
				<div class="dc-field dc-field--full">
					<label class="dc-field__label" for="dc-dt-swap-mode"><?php p($l->t('Swap approval')); ?></label>
					<select id="dc-dt-swap-mode" name="swapApprovalMode" class="dc-input">
						<option value="planner_required"><?php p($l->t('Planner must approve')); ?></option>
						<option value="bilateral_auto"><?php p($l->t('Both accept — auto apply')); ?></option>
					</select>
				</div>
				<label class="dc-checkbox" for="dc-dt-cross-loc">
					<input id="dc-dt-cross-loc" type="checkbox" name="allowCrossLocationSwaps">
					<span class="dc-checkbox__text"><?php p($l->t('Allow swaps across locations')); ?></span>
				</label>
				<label class="dc-checkbox" for="dc-dt-claim-planner">
					<input id="dc-dt-claim-planner" type="checkbox" name="claimRequiresPlanner">
					<span class="dc-checkbox__text"><?php p($l->t('Open-shift claims need planner approval')); ?></span>
				</label>
			</div>
		</div>

		<div class="dc-dienst-team__group" role="group" aria-labelledby="dc-dt-group-notify">
			<h3 id="dc-dt-group-notify" class="dc-dienst-team__group-title"><?php p($l->t('Notifications')); ?></h3>
			<label class="dc-checkbox dc-dienst-team__primary" for="dc-dt-quiet">
				<input id="dc-dt-quiet" type="checkbox" name="pushQuietHoursEnabled">
				<span class="dc-checkbox__text">
					<strong><?php p($l->t('Nachtruhe')); ?></strong>
					<span class="dc-dienst-team__hint"><?php p($l->t('Hold non-urgent push messages overnight.')); ?></span>
					<span id="dc-dt-quiet-badge" class="dc-badge dc-badge--info dc-dienst-team__badge" hidden><?php p($l->t('Recommended')); ?></span>
				</span>
			</label>
			<div class="dc-dienst-team__more-block" data-dc-more hidden>
				<div class="dc-form-grid dc-dienst-team__quiet-times">
					<div class="dc-field">
						<label class="dc-field__label" for="dc-dt-quiet-start"><?php p($l->t('Quiet from')); ?></label>
						<input id="dc-dt-quiet-start" type="time" name="pushQuietHoursStart" class="dc-input dc-input--time-24h" value="22:00" step="60" lang="en-GB">
					</div>
					<div class="dc-field">
						<label class="dc-field__label" for="dc-dt-quiet-end"><?php p($l->t('Quiet until')); ?></label>
						<input id="dc-dt-quiet-end" type="time" name="pushQuietHoursEnd" class="dc-input dc-input--time-24h" value="06:00" step="60" lang="en-GB">
					</div>
				</div>
				<label class="dc-checkbox" for="dc-dt-urgent">
					<input id="dc-dt-urgent" type="checkbox" name="pushAllowUrgentDuringQuiet">
					<span class="dc-checkbox__text"><?php p($l->t('Still deliver urgent messages during quiet hours')); ?></span>
				</label>
			</div>
		</div>

		<details class="dc-dienst-team__details" id="dc-dt-more">
			<summary class="dc-dienst-team__summary"><span><?php p($l->t('More options')); ?></span></summary>
			<p class="dc-field__hint">
				<?php p($l->t('Swap rules and quiet-hour times.')); ?>
			</p>
		</details>

		<p id="dc-dienst-team-status" class="dc-roster-flash" role="status" aria-live="polite" aria-atomic="true" hidden></p>
		<div class="dc-form-actions">
			<button type="submit" class="button primary" id="dc-dienst-team-save"><?php p($l->t('Save duty & team settings')); ?></button>
		</div>
	</form>
</section>

<?php
/**
 * Settings jump navigation (admin only).
 *
 * Kept as a partial so render-contract tests can exercise the TOC without
 * bootstrapping OCP\Server for the license panel that follows on the page.
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="dc-card dc-section dc-settings-toc" aria-labelledby="dc-settings-toc-title">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-settings-toc-title"><?php p($l->t('On this page')); ?></h2>
			<p class="dc-section__sub">
				<?php p($l->t('Jump to a settings section. Each block saves on its own.')); ?>
			</p>
		</div>
	</header>
	<ul class="dc-settings-toc__list">
		<li><a class="dc-settings-toc__link" href="#dc-settings-policy"><?php p($l->t('Access control')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-duty-roles"><?php p($l->t('Duty roles')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-planning"><?php p($l->t('Planning defaults')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-companies"><?php p($l->t('Companies / workspaces')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-conflict-policy"><?php p($l->t('Conflict thresholds')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-templates"><?php p($l->t('Shift templates')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-quals"><?php p($l->t('Qualifications')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-scope"><?php p($l->t('Planner location scope')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-ops"><?php p($l->t('Notifications & retention')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-at-integration"><?php p($l->t('ArbeitszeitCheck integration')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dc-settings-privacy"><?php p($l->t('Privacy & words we use')); ?></a></li>
		<li><a class="dc-settings-toc__link" href="#dutycheck-license"><?php p($l->t('Official mobile & terminal licenses')); ?></a></li>
	</ul>
</section>

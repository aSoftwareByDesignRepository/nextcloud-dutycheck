<?php
/**
 * Enrollment page: directory door is open, but no DutyCheck membership yet.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

\OCP\Util::addStyle('dutycheck', 'common/tokens');
\OCP\Util::addStyle('dutycheck', 'app');

use OCA\DutyCheck\Service\IconCatalog;

$message = (string) ($_['message'] ?? $l->t('You are not enrolled in DutyCheck yet.'));
$hint = (string) ($_['hint'] ?? $l->t('Ask a DutyCheck administrator to link your account as an employee or assign you a planner role before you can use rosters and absences.'));
$homeUrl = (string) ($_['homeUrl'] ?? '/');
?>
<div id="app-content" class="dc-app dc-app--needs-role">
	<a class="dc-skip-link" href="#dc-needs-role-main"><?php p($l->t('Skip to main content')); ?></a>
	<div class="dc-denied">
		<section id="dc-needs-role-main" class="dc-card" aria-labelledby="dc-needs-role-title" tabindex="-1">
			<div class="dc-page-header__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('user', 'dc-page-header__icon-svg')); ?>
			</div>
			<h1 id="dc-needs-role-title"><?php p($l->t('Not enrolled yet')); ?></h1>
			<p><?php p($message); ?></p>
			<p class="dc-callout__hint"><?php p($hint); ?></p>
			<a class="button primary" href="<?php p($homeUrl); ?>">
				<?php p($l->t('Back to Nextcloud')); ?>
			</a>
		</section>
	</div>
</div>

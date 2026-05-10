<?php
/**
 * Rendered by AppAccessMiddleware when the current user is not allowed to use
 * DutyCheck. We deliberately render without the sidebar so the user is not
 * stared down by a list of links they cannot click.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

\OCP\Util::addStyle('dutycheck', 'common/tokens');
\OCP\Util::addStyle('dutycheck', 'app');

use OCA\DutyCheck\Service\IconCatalog;

$message = (string) ($_['message'] ?? $l->t('You are not allowed to use DutyCheck right now.'));
$hint = (string) ($_['hint'] ?? $l->t('If you believe this is a mistake, contact your DutyCheck administrator and ask to be granted a planner or employee role.'));
$homeUrl = (string) ($_['homeUrl'] ?? '/');
?>
<div id="app-content" class="dc-app dc-app--access-denied">
	<a class="dc-skip-link" href="#dc-denied-main"><?php p($l->t('Skip to main content')); ?></a>
	<div class="dc-denied">
		<section id="dc-denied-main" class="dc-card" role="alert" aria-labelledby="dc-denied-title" tabindex="-1">
			<div class="dc-page-header__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('lock', 'dc-page-header__icon-svg')); ?>
			</div>
			<h1 id="dc-denied-title"><?php p($l->t('Access denied')); ?></h1>
			<p><?php p($message); ?></p>
			<p class="dc-callout__hint">
				<?php p($hint); ?>
			</p>
			<a class="button primary" href="<?php p($homeUrl); ?>">
				<?php p($l->t('Back to Nextcloud')); ?>
			</a>
		</section>
	</div>
</div>

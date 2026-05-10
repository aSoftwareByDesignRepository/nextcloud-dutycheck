<?php

/**
 * Administrator-only print view: missing or invalid period.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
use OCA\DutyCheck\Service\IconCatalog;

$message = (string) ($_['message'] ?? $l->t('Something went wrong.'));
$backUrl = (string) ($_['backUrl'] ?? '/');
$htmlLang = (string) ($_['htmlLang'] ?? 'en-US');
?>
<!DOCTYPE html>
<html lang="<?php p($htmlLang); ?>" class="dc-html-print">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php p($l->t('Roster print')); ?> — <?php p($l->t('DutyCheck')); ?></title>
</head>
<body class="dc-body-print dc-body-print--error">
	<div id="app-content" class="dc-app dc-app--roster-print dc-app--roster-print-error">
		<a class="dc-skip-link" href="#dc-print-main"><?php p($l->t('Skip to main content')); ?></a>
		<main id="dc-print-main" class="dc-print-error" tabindex="-1">
			<div class="dc-print-error__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('clipboard-list', 'dc-page-header__icon-svg')); ?>
			</div>
			<h1><?php p($l->t('Printable roster')); ?></h1>
			<p class="dc-print-error__message" role="alert"><?php p($message); ?></p>
			<p>
				<a class="button primary" href="<?php p($backUrl); ?>"><?php p($l->t('Back to roster')); ?></a>
			</p>
		</main>
	</div>
</body>
</html>

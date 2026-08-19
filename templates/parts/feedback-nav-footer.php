<?php

declare(strict_types=1);

/**
 * Nav footer: single popover button with Help & Feedback menu.
 *
 * Expected variables (set by the including template):
 * @var \OCP\IL10N $l
 * @var \OCA\DutyCheck\Support\AppFeedbackLinks $appFeedbackLinks optional; constructed when omitted
 * @var string $appFeedbackCssPrefix CSS BEM prefix (e.g. azc, dc, crm)
 * @var string|null $appFeedbackLanguageCode
 * @var string|null $appFeedbackVersion
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

use OCA\DutyCheck\Service\IconCatalog;
use OCA\DutyCheck\Support\AppFeedbackLinks;

$l = $l ?? (\OCP\Util::getL10N('dutycheck'));
$prefix = isset($appFeedbackCssPrefix) && is_string($appFeedbackCssPrefix) && $appFeedbackCssPrefix !== ''
	? preg_replace('/[^a-z0-9\-]/i', '', $appFeedbackCssPrefix)
	: 'dc';
$lang = isset($appFeedbackLanguageCode) && is_string($appFeedbackLanguageCode) && $appFeedbackLanguageCode !== ''
	? $appFeedbackLanguageCode
	: (method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en');
$version = isset($appFeedbackVersion) && is_string($appFeedbackVersion) ? $appFeedbackVersion : '';
if (!isset($appFeedbackLinks) || !$appFeedbackLinks instanceof AppFeedbackLinks) {
	$appFeedbackLinks = new AppFeedbackLinks('dutycheck', 'DutyCheck', $version);
}
$pageUrl = '';
if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
	$pageUrl = $appFeedbackLinks->sanitizePageUrl((string)$_SERVER['REQUEST_URI']);
}
$ncVersion = '';
if (class_exists(\OCP\Server::class)) {
	try {
		$config = \OCP\Server::get(\OCP\IConfig::class);
		$ncVersion = (string)$config->getSystemValue('version', '');
	} catch (\Throwable) {
		$ncVersion = '';
	}
}
$ctx = [
	'pageUrl' => $pageUrl,
	'locale' => $lang,
	'ncVersion' => $ncVersion,
];
$links = $appFeedbackLinks->forLocale($lang, $ctx);
$github = (string)($links['githubIssuesUrl'] ?? '');
$footerId = $prefix . '-nav-footer';
$menuId = $prefix . '-nav-footer-menu';
$newTab = $l->t('(opens in a new tab)');
?>
<div
	class="<?php p($prefix); ?>-nav-footer"
	id="<?php p($footerId); ?>"
	data-app-feedback="1"
	data-app-feedback-app="<?php p((string)$links['appId']); ?>"
>
	<button
		type="button"
		class="<?php p($prefix); ?>-nav-footer__trigger"
		aria-haspopup="true"
		aria-expanded="false"
		aria-controls="<?php p($menuId); ?>"
	><?php print_unescaped(IconCatalog::render('help', $prefix . '-nav-footer__trigger-icon')); ?><?php p($l->t('Help & Feedback')); ?></button>
	<ul
		class="<?php p($prefix); ?>-nav-footer__menu"
		id="<?php p($menuId); ?>"
		role="menu"
		aria-label="<?php p($l->t('Help & Feedback')); ?>"
		hidden
	>
		<li role="none">
			<a
				class="<?php p($prefix); ?>-nav-footer__menu-item"
				id="<?php p($prefix); ?>-feedback-problem"
				href="<?php p((string)$links['problemMailto']); ?>"
				role="menuitem"
				data-app-feedback-kind="problem"
			><?php print_unescaped(IconCatalog::render('alert-triangle', $prefix . '-nav-footer__menu-icon')); ?><?php p($l->t('Report a problem')); ?></a>
		</li>
		<li role="none">
			<a
				class="<?php p($prefix); ?>-nav-footer__menu-item"
				id="<?php p($prefix); ?>-feedback-idea"
				href="<?php p((string)$links['ideaMailto']); ?>"
				role="menuitem"
				data-app-feedback-kind="idea"
			><?php print_unescaped(IconCatalog::render('edit', $prefix . '-nav-footer__menu-icon')); ?><?php p($l->t('Suggest an improvement')); ?></a>
		</li>
		<?php if ($github !== ''): ?>
		<li role="none">
			<a
				class="<?php p($prefix); ?>-nav-footer__menu-item"
				id="<?php p($prefix); ?>-feedback-github"
				href="<?php p($github); ?>"
				target="_blank"
				rel="noopener noreferrer"
				role="menuitem"
			><?php print_unescaped(IconCatalog::render('clipboard-list', $prefix . '-nav-footer__menu-icon')); ?><?php p($l->t('GitHub Issues')); ?><span class="<?php p($prefix); ?>-nav-footer__sr-only"><?php p($newTab); ?></span></a>
		</li>
		<?php endif; ?>
	</ul>
	<script type="application/json" id="<?php p($prefix); ?>-app-feedback-config"><?php
		print_unescaped(json_encode([
			'appId' => $links['appId'],
			'appDisplayName' => $links['appDisplayName'],
			'appVersion' => $links['appVersion'],
			'feedbackEmail' => $links['feedbackEmail'],
			'githubIssuesUrl' => $github,
			'problemMailto' => $links['problemMailto'],
			'ideaMailto' => $links['ideaMailto'],
			'cssPrefix' => $prefix,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
	?></script>
</div>

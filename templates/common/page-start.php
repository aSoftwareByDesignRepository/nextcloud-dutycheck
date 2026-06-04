<?php
/**
 * Common DutyCheck page chrome.
 *
 * Renders:
 *  - the sidebar navigation (#app-navigation) via navigation.php
 *  - the app shell (#app-content / #app-content-wrapper)
 *  - skip link, polite + assertive live regions
 *  - the page header with breadcrumb, icon, and primary action slot
 *  - a role/scope strip showing the current actor role + timezone
 *  - opens <main id="dc-main-content"> for the body
 *
 * Per-page contract (variables read from $_):
 *  - pageId (string, required)
 *  - pageTitle (string, required, already translated)
 *  - pageHelp  (string, optional, already translated)
 *  - role (string, required: admin|planner|planner_employee|employee|self_service)
 *  - roleLabel (string, translated)
 *  - isEmployee (bool): global employee role in dc_user_roles
 *  - hasLinkedEmployee (bool): active dc_employees row links this user (self-service)
 *  - isAppAdmin (bool)
 *  - urls (array<string,string>)
 *  - clientHints (array)
 *  - integrationBootstrapJson (string, optional): HTML-escaped JSON for {@see \OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration::buildBootstrapForUser}
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\DutyCheck\Service\IconCatalog;

$pageId = (string) ($_['pageId'] ?? '');
$pageTitle = (string) ($_['pageTitle'] ?? $l->t('DutyCheck'));
$pageHelp = (string) ($_['pageHelp'] ?? '');
$urls = (array) ($_['urls'] ?? []);
$clientHints = (array) ($_['clientHints'] ?? []);
$htmlLang = (string) ($clientHints['htmlLang'] ?? 'en-US');
$locale = (string) ($clientHints['locale'] ?? $htmlLang);
$timezone = (string) ($clientHints['timezone'] ?? 'UTC');
$role = (string) ($_['role'] ?? 'employee');
$roleLabel = (string) ($_['roleLabel'] ?? $l->t('Member'));
$isEmployee = !empty($_['isEmployee']);
$hasLinkedEmployee = !empty($_['hasLinkedEmployee']);
$isAppAdmin = !empty($_['isAppAdmin']);

$pageIcons = [
	'dashboard' => 'layout-grid',
	'roster' => 'clipboard-list',
	'periods' => 'calendar',
	'employees' => 'users',
	'locations' => 'map-pin',
	'absences' => 'calendar-off',
	'my-absences' => 'calendar-off',
	'my-roster' => 'user',
	'settings' => 'settings',
];
$headerIconName = $pageIcons[$pageId] ?? 'layout-grid';

$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<?php include __DIR__ . '/navigation.php'; ?>
<div id="app-content" class="dc-app dc-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-locale="<?php p($locale); ?>"
	data-timezone="<?php p($timezone); ?>"
	data-dc-time-24h="1"
	data-dc-time-input-lang="en-GB"
	data-dc-page="<?php p($pageId); ?>"
	data-dc-role="<?php p($role); ?>"
	data-dc-is-app-admin="<?php p($isAppAdmin ? '1' : '0'); ?>"
	data-dc-is-employee="<?php p($isEmployee ? '1' : '0'); ?>"
	data-dc-has-linked-employee="<?php p($hasLinkedEmployee ? '1' : '0'); ?>"
	data-dc-urls="<?php print_unescaped($urlsJson); ?>"
	data-dc-integration-bootstrap="<?php print_unescaped($_['integrationBootstrapJson'] ?? ''); ?>">
	<a class="dc-skip-link" href="#dc-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="dc-live-region" class="dc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="dc-alert-region" class="dc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="dc-shell">
		<header class="dc-page-header" aria-labelledby="dc-page-title">
			<nav class="dc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li>
						<a class="dc-breadcrumb__brand"
							href="<?php p((string) ($urls[$hasLinkedEmployee ? 'myRoster' : 'dashboard'] ?? '#')); ?>">
							<?php p($l->t('DutyCheck')); ?>
						</a>
					</li>
					<li class="dc-breadcrumb__sep" aria-hidden="true">/</li>
					<li class="dc-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
				</ol>
			</nav>
			<div class="dc-page-header__main">
				<div class="dc-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIconName, 'dc-page-header__icon-svg')); ?>
				</div>
				<div class="dc-page-header__text">
					<h1 id="dc-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="dc-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="dc-page-actions" class="dc-page-header__actions" aria-live="polite"></div>
			</div>
			<div class="dc-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="dc-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="dc-badge dc-badge--<?php p($role === 'admin' ? 'critical' : (($role === 'planner' || $role === 'planner_employee') ? 'info' : (($role === 'self_service') ? 'neutral' : 'success'))); ?>">
					<?php p($roleLabel); ?>
				</span>
				<span aria-hidden="true" class="dc-scope-strip__sep">·</span>
				<span class="dc-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="dc-scope-strip__value"><?php p($timezone); ?></span>
				<span aria-hidden="true" class="dc-scope-strip__sep">·</span>
				<span class="dc-scope-strip__label"><?php p($l->t('Time format')); ?></span>
				<span class="dc-scope-strip__value"><?php p($l->t('24-hour (HH:mm)')); ?></span>
			</div>
		</header>
		<main id="dc-main-content" class="dc-main" tabindex="-1" aria-labelledby="dc-page-title">

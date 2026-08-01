<?php
/**
 * App settings shell (governance, app-admins only).
 *
 * Split into one sub-page per section (DeskCheck pattern): the controller
 * validates `settingsSection` against {@see \OCA\DutyCheck\Service\SettingsSectionCatalog}
 * and this template dispatches through a literal slug → file map, so no
 * request value is ever used to build an include path.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$canAdminApp = !empty($_['isAppAdmin']);
?>
<?php if (!$canAdminApp): ?>
	<section class="dc-card dc-section">
		<header class="dc-section__header">
			<div>
				<h2><?php p($l->t('App policy')); ?></h2>
				<p class="dc-section__sub">
					<?php p($l->t('Only app administrators may change these settings.')); ?>
				</p>
			</div>
		</header>
		<div class="dc-callout dc-callout--info">
			<p><?php p($l->t('You do not have permission to view or change app policy. Ask an app administrator if you need adjustments.')); ?></p>
		</div>
	</section>
<?php else:
	$dcSettingsSectionFiles = [
		'access' => 'access.php',
		'duty-roles' => 'duty-roles.php',
		'planning' => 'planning.php',
		'companies' => 'companies.php',
		'conflicts' => 'conflicts.php',
		'shift-templates' => 'shift-templates.php',
		'qualifications' => 'qualifications.php',
		'planner-scope' => 'planner-scope.php',
		'operations' => 'operations.php',
		'integration' => 'integration.php',
		'privacy' => 'privacy.php',
		'license' => 'license.php',
		'support' => 'support.php',
	];
	$dcRequestedSection = (string) ($_['settingsSection'] ?? '');
	include __DIR__ . '/parts/settings-nav.php';
	if (!isset($dcSettingsSectionFiles[$dcRequestedSection])) {
		throw new \RuntimeException('DutyCheck settings: unknown section reached the template dispatcher.');
	}
	include __DIR__ . '/parts/settings/' . $dcSettingsSectionFiles[$dcRequestedSection];
endif; ?>
<?php include __DIR__ . '/common/page-end.php'; ?>

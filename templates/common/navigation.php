<?php
/**
 * DutyCheck sidebar navigation.
 *
 * Items are role-aware:
 *  - any user with an active linked employee → "My duties" (including catalog-linked accounts without a `dc_user_roles` row)
 *  - planner / app admin without a link → planning + catalog (+ governance if app admin)
 *
 * Each link includes a hint copy line so newcomers can grasp what each section
 * is for, mirroring BudgetCheck's information density. The active link gets
 * `aria-current=page` and a stronger background tint.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\DutyCheck\Service\IconCatalog;

$urls = (array) ($_['urls'] ?? []);
$pageId = (string) ($_['pageId'] ?? '');
$isEmployee = !empty($_['isEmployee']);
$hasLinkedEmployee = !empty($_['hasLinkedEmployee']);
$isAppAdmin = !empty($_['isAppAdmin']);
$isPlannerOrAdmin = !empty($_['isPlannerOrAdmin']);
$role = (string) ($_['role'] ?? 'employee');
$roleLabel = (string) ($_['roleLabel'] ?? $l->t('Member'));

$selfServiceItems = [
	['id' => 'my-roster', 'label' => $l->t('My roster'), 'hint' => $l->t('Your upcoming published duties'), 'icon' => 'user', 'url' => $urls['myRoster'] ?? '#'],
	['id' => 'my-absences', 'label' => $l->t('My absences'), 'hint' => $l->t('Your requests and statuses'), 'icon' => 'calendar-off', 'url' => $urls['myAbsences'] ?? '#'],
];
$planningItems = [
	['id' => 'dashboard', 'label' => $l->t('Dashboard'), 'hint' => $l->t('KPIs and planner checks'), 'icon' => 'layout-grid', 'url' => $urls['dashboard'] ?? '#'],
	['id' => 'roster', 'label' => $l->t('Roster'), 'hint' => $l->t('Create assignments and resolve conflicts'), 'icon' => 'clipboard-list', 'url' => $urls['roster'] ?? '#'],
	['id' => 'periods', 'label' => $l->t('Periods'), 'hint' => $l->t('Lifecycle, snapshots and audit trail'), 'icon' => 'calendar', 'url' => $urls['periods'] ?? '#'],
	['id' => 'absences', 'label' => $l->t('Absences'), 'hint' => $l->t('Review and transition requests'), 'icon' => 'calendar-off', 'url' => $urls['absences'] ?? '#'],
];
$catalogItems = [
	['id' => 'employees', 'label' => $l->t('Employees'), 'hint' => $l->t('Directory and linked Nextcloud accounts'), 'icon' => 'users', 'url' => $urls['employees'] ?? '#'],
	['id' => 'locations', 'label' => $l->t('Locations'), 'hint' => $l->t('Timezone-aware duty locations'), 'icon' => 'map-pin', 'url' => $urls['locations'] ?? '#'],
];
// Settings sub-pages (split layout): shown as an expanded sub-list under the
// Settings entry while the admin is on any settings page. Labels and URLs come
// from the controller (SettingsSectionCatalog), so nav and routes cannot drift.
$settingsSection = (string) ($_['settingsSection'] ?? '');
$settingsSectionUrls = (array) ($urls['settingsSections'] ?? []);
$settingsSectionLabels = (array) ($_['settingsSectionLabels'] ?? []);
$settingsChildren = [];
if ($isAppAdmin && $pageId === 'settings') {
	foreach ($settingsSectionLabels as $sectionId => $sectionLabel) {
		$childHref = (string) ($settingsSectionUrls[$sectionId] ?? '');
		if ($childHref === '' || $childHref === '#') {
			continue;
		}
		$settingsChildren[] = [
			'id' => (string) $sectionId,
			'label' => (string) $sectionLabel,
			'url' => $childHref,
			'active' => $settingsSection === (string) $sectionId,
		];
	}
}

$governanceItems = $isAppAdmin
	? [[
		'id' => 'settings',
		'label' => $l->t('Settings'),
		'hint' => $l->t('Security and governance'),
		'icon' => 'shield-check',
		'url' => $urls['settings'] ?? '#',
		'children' => $settingsChildren,
	]]
	: [];

$renderGroup = function (string $title, array $items) use ($pageId, $l): void {
	if ($items === []) {
		return;
	}
	?>
	<div class="dc-nav__group" aria-labelledby="dc-nav-group-<?php p(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title))); ?>">
		<p id="dc-nav-group-<?php p(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title))); ?>" class="dc-nav__group-title"><?php p($title); ?></p>
		<ul class="dc-nav__list">
			<?php foreach ($items as $item):
				$children = (array) ($item['children'] ?? []);
				$active = $pageId === $item['id'];
				// With an expanded sub-list, aria-current belongs to the active
				// child link only; the parent keeps the visual active state.
				$parentAriaCurrent = $active && $children === [];
				?>
				<li class="dc-nav__item <?php p($active ? 'is-active active' : ''); ?>">
					<a class="dc-nav__link" href="<?php p((string) $item['url']); ?>"
						<?php if ($parentAriaCurrent): ?>aria-current="page"<?php endif; ?>>
						<span class="dc-nav__icon" aria-hidden="true">
							<?php print_unescaped(IconCatalog::render((string) ($item['icon'] ?? 'layout-grid'), 'dc-icon')); ?>
						</span>
						<span class="dc-nav__label">
							<span class="dc-nav__name"><?php p((string) $item['label']); ?></span>
							<span class="dc-nav__hint"><?php p((string) ($item['hint'] ?? '')); ?></span>
						</span>
					</a>
					<?php if ($children !== []): ?>
						<ul class="dc-nav__sublist">
							<?php foreach ($children as $child):
								$childActive = !empty($child['active']);
								?>
								<li class="dc-nav__subitem <?php p($childActive ? 'is-active active' : ''); ?>">
									<a class="dc-nav__sublink" href="<?php p((string) $child['url']); ?>"
										<?php if ($childActive): ?>aria-current="page"<?php endif; ?>>
										<?php p((string) $child['label']); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
};
?>
<div id="app-navigation" class="dc-nav" role="navigation" aria-label="<?php p($l->t('DutyCheck navigation')); ?>">
	<div class="dc-nav__brand">
		<span class="dc-nav__brand-icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('clipboard-list', 'dc-icon dc-icon--lg')); ?>
		</span>
		<div class="dc-nav__brand-text">
			<h2 class="dc-nav__title"><?php p($l->t('DutyCheck')); ?></h2>
			<p class="dc-nav__subtitle"><?php p($l->t('Planning and compliance')); ?></p>
			<span class="dc-nav__role dc-badge dc-badge--<?php p($role === 'admin' ? 'critical' : (($role === 'planner' || $role === 'planner_employee') ? 'info' : (($role === 'self_service') ? 'neutral' : 'success'))); ?>">
				<?php p($roleLabel); ?>
			</span>
		</div>
	</div>
	<?php if ($hasLinkedEmployee): ?>
		<?php $renderGroup($l->t('My duties'), $selfServiceItems); ?>
	<?php endif; ?>
	<?php if ($isPlannerOrAdmin): ?>
		<?php $renderGroup($l->t('Planning'), $planningItems); ?>
		<?php $renderGroup($l->t('Catalog'), $catalogItems); ?>
		<?php if ($governanceItems !== []): ?>
			<?php $renderGroup($l->t('Governance'), $governanceItems); ?>
		<?php endif; ?>
	<?php endif; ?>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</div>

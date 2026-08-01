<?php
/**
 * In-page settings sub-navigation (DeskCheck parity).
 *
 * Complements the sidebar sub-list: Nextcloud collapses #app-navigation below
 * ~1024px, so without this chip bar admins cannot reach sibling settings pages
 * on phones/tablets. Labels and URLs come from the controller
 * (SettingsSectionCatalog) — never hardcoded here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $dcRequestedSection
 */

$dcNavLabels = (array) ($_['settingsSectionLabels'] ?? []);
$dcNavUrls = (array) (($_['urls']['settingsSections'] ?? []) ?: []);
if ($dcNavLabels === []) {
	return;
}
?>
<nav class="dc-settings-nav" id="dc-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($dcNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string) $sectionId;
		$href = (string) ($dcNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = $dcRequestedSection === $sectionId;
		?>
		<a class="dc-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string) $sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php

/**
 * Administrator-only printable roster (no navigation chrome).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
use OCA\DutyCheck\Service\IconCatalog;

$period = (array) ($_['period'] ?? []);
$assignments = (array) ($_['assignments'] ?? []);
$pageTitle = (string) ($_['pageTitle'] ?? $l->t('Printable roster'));
$generatedAtUtcIso = (string) ($_['generatedAtUtcIso'] ?? '');
$generatedAtUtcDisplay = (string) ($_['generatedAtUtcDisplay'] ?? '');
$snapshotHash = (string) ($_['snapshotHash'] ?? '');
$snapshotKind = (string) ($_['snapshotKind'] ?? '');
$rosterUrl = (string) ($_['rosterUrl'] ?? '');
$htmlLang = (string) ($_['htmlLang'] ?? 'en-US');

$statusKey = strtolower((string) ($period['status'] ?? ''));
$statusLabel = match ($statusKey) {
	'open' => $l->t('Open'),
	'published' => $l->t('Published'),
	'closed' => $l->t('Closed'),
	default => (string) ($period['status'] ?? ''),
};

$hasOvernight = false;
foreach ($assignments as $row) {
	$st = (string) ($row['startTime'] ?? '');
	$en = (string) ($row['endTime'] ?? '');
	if ($st !== '' && $en !== '' && $en < $st) {
		$hasOvernight = true;
		break;
	}
}
?>
<!DOCTYPE html>
<html lang="<?php p($htmlLang); ?>" class="dc-html-print">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php p($pageTitle); ?> — <?php p($l->t('DutyCheck')); ?></title>
</head>
<body class="dc-body-print">
	<div id="app-content" class="dc-app dc-app--roster-print">
		<a class="dc-skip-link" href="#dc-print-main"><?php p($l->t('Skip to main content')); ?></a>
		<header class="dc-print-header" role="banner">
			<div class="dc-print-header__brand" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('clipboard-list', 'dc-print-header__icon')); ?>
			</div>
			<div class="dc-print-header__text">
				<h1 id="dc-print-title" class="dc-print-title"><?php p($pageTitle); ?></h1>
				<p class="dc-print-meta">
					<?php p($l->t('Period')); ?>:
					<strong><?php p((string) ($period['startDate'] ?? '')); ?></strong>
					–
					<strong><?php p((string) ($period['endDate'] ?? '')); ?></strong>
					·
					<?php p($l->t('Status')); ?>:
					<strong><?php p($statusLabel); ?></strong>
				</p>
				<p class="dc-print-meta dc-print-meta--sub">
					<?php p($l->t('Generated')); ?>:
					<time datetime="<?php p($generatedAtUtcIso); ?>"><?php p($generatedAtUtcDisplay); ?></time>
				</p>
			</div>
			<div class="dc-print-toolbar no-print">
				<button type="button" id="dc-print-action" class="button primary">
					<?php p($l->t('Print')); ?>
				</button>
				<a class="button" href="<?php p($rosterUrl); ?>"><?php p($l->t('Back to roster')); ?></a>
			</div>
		</header>

		<main id="dc-print-main" class="dc-print-main" tabindex="-1" aria-labelledby="dc-print-title">
			<?php if ($hasOvernight): ?>
				<p class="dc-print-footnote no-print" role="note">
					<?php p($l->t('Shifts that end earlier on the clock than they start continue past midnight into the next calendar day.')); ?>
				</p>
			<?php endif; ?>

			<div class="dc-print-table-wrap">
				<table class="dc-print-table">
					<caption class="dc-sr-only">
						<?php p($l->t('Duty assignments for this period')); ?>
					</caption>
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('Date')); ?></th>
							<th scope="col"><?php p($l->t('Start')); ?></th>
							<th scope="col"><?php p($l->t('End')); ?></th>
							<th scope="col"><?php p($l->t('Employee')); ?></th>
							<th scope="col"><?php p($l->t('Location')); ?></th>
							<th scope="col"><?php p($l->t('Break')); ?></th>
							<th scope="col"><?php p($l->t('Note')); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (!count($assignments)): ?>
							<tr>
								<td colspan="7" class="dc-print-table__empty"><?php p($l->t('No assignments in this period.')); ?></td>
							</tr>
						<?php else: ?>
							<?php foreach ($assignments as $a): ?>
								<?php
								$st = (string) ($a['startTime'] ?? '');
								$en = (string) ($a['endTime'] ?? '');
								$overnight = $st !== '' && $en !== '' && $en < $st;
								?>
								<tr>
									<td><?php p((string) ($a['dutyDate'] ?? '')); ?></td>
									<td><?php p($st); ?></td>
									<td>
										<?php p($en); ?>
										<?php if ($overnight): ?>
											<span class="dc-print-overnight" title="<?php p($l->t('Continues into the next day.')); ?>">*</span>
										<?php endif; ?>
									</td>
									<td><?php p((string) ($a['employeeName'] ?? '')); ?></td>
									<td><?php p((string) ($a['locationName'] ?? '')); ?></td>
									<td><?php p(str_replace('{n}', (string) ((int) ($a['breakMinutes'] ?? 0)), $l->t('{n} min'))); ?></td>
									<td><?php p((string) ($a['note'] ?? '')); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ($hasOvernight && count($assignments) > 0): ?>
				<p class="dc-print-legend print-only" aria-hidden="true">
					* <?php p($l->t('Continues into the next day.')); ?>
				</p>
			<?php endif; ?>

			<footer class="dc-print-integrity" role="contentinfo">
				<?php if ($snapshotHash !== ''): ?>
					<p class="dc-print-integrity__hash">
						<?php p($l->t('Integrity hash')); ?>
						(<span class="dc-print-integrity__kind"><?php p($snapshotKind !== '' ? $snapshotKind : $l->t('snapshot')); ?></span>):
						<code class="dc-print-integrity__value"><?php p($snapshotHash); ?></code>
					</p>
					<p class="dc-print-integrity__hint">
						<?php p($l->t('Verify this hash against the period snapshot chain in DutyCheck → Periods.')); ?>
					</p>
				<?php else: ?>
					<p class="dc-print-integrity__hint">
						<?php p($l->t('No publish or close snapshot yet — this print is a working draft without an integrity hash.')); ?>
					</p>
				<?php endif; ?>
			</footer>
		</main>
	</div>
</body>
</html>

<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="dc-card dc-section" aria-labelledby="dc-xfer-export-h" id="dc-transfer-export-card">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="dc-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="dc-callout dc-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="dc-transfer-export-form" class="dc-form" novalidate>
		<div class="dc-form-row">
			<label for="dc-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="dc-input-group">
				<input type="password" id="dc-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="dc-transfer-passphrase-hint dc-transfer-passphrase-strength">
				<button type="button" class="dc-btn dc-btn--ghost" id="dc-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="dc-btn dc-btn--secondary" id="dc-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="dc-form-row__hint" id="dc-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="dc-form-row__hint dc-transfer-strength" id="dc-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="dc-actions">
			<button type="submit" class="dc-btn dc-btn--primary" id="dc-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="dc-card dc-section" aria-labelledby="dc-xfer-import-h" id="dc-transfer-import-card">
	<header class="dc-section__header">
		<div>
			<h2 id="dc-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="dc-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="dc-transfer-last" id="dc-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="dc-transfer-import-form" class="dc-form" novalidate>
		<fieldset class="dc-form-row dc-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="dc-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="dc-radio dc-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="dc-form-row__hint" id="dc-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="dc-form-row">
			<label for="dc-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="dc-transfer-file" name="file" accept=".nctransfer,application/json" class="dc-file-input">
		</div>
		<div class="dc-form-row">
			<label for="dc-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="dc-input-group">
				<input type="password" id="dc-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="dc-btn dc-btn--ghost" id="dc-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="dc-transfer-import-status" class="dc-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="dc-transfer-report" class="dc-dialog dc-transfer-report" aria-labelledby="dc-transfer-report-h">
	<div class="dc-dialog__body">
		<h3 id="dc-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="dc-transfer-report-verdict" class="dc-transfer-report__verdict"></p>
		<p id="dc-transfer-report-file" class="dc-form-row__hint"></p>
		<div id="dc-transfer-report-groups"></div>
	</div>
	<footer class="dc-dialog__footer">
		<div id="dc-transfer-replace-confirm" class="dc-form-row" hidden>
			<label for="dc-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="dc-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="dc-actions">
			<button type="button" class="dc-btn dc-btn--secondary" id="dc-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="dc-btn dc-btn--primary" id="dc-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="dc-transfer-progress" class="dc-dialog" aria-labelledby="dc-transfer-progress-h" aria-live="polite">
	<div class="dc-dialog__body">
		<h3 id="dc-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="dc-transfer-progress-bar" max="100" value="0"></progress>
		<p id="dc-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="dc-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="dc-transfer-result-live" class="dc-visually-hidden" role="status" aria-live="polite"></div>

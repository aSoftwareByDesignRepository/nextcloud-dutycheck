<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (dc).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$dc_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$dc_tokens = is_array($dc_state['tokens']) ? $dc_state['tokens'] : [];
?>
<section class="dc-section dc-agent" id="dc-agent-access" aria-labelledby="dc-agent-h">
	<h2 class="dc-section-title" id="dc-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="dc-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="dc-agent-live" class="dc-visually-hidden" aria-live="polite"></div>

	<?php if ($dc_tokens === []): ?>
	<div class="dc-agent-empty" id="dc-agent-empty">
		<p class="dc-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($dc_state['canMint']): ?>
		<button type="button" class="dc-btn dc-btn--primary" id="dc-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="dc-table-wrap dc-agent-tablewrap" tabindex="0" role="region" aria-labelledby="dc-agent-h">
		<table class="dc-table dc-table--cards dc-agent-table" id="dc-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="dc-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="dc-agent-tbody">
				<?php foreach ($dc_tokens as $dc_tok): ?>
				<tr data-token-id="<?php p((string)($dc_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($dc_tok['label'] ?? '')); ?>"<?php if (($dc_tok['state'] ?? '') !== 'active'): ?> class="dc-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>" class="dc-agent-label-cell"><span class="dc-agent-labelcell"><span class="dc-agent-label" title="<?php p((string)($dc_tok['label'] ?? '')); ?>"><?php p((string)($dc_tok['label'] ?? '')); ?></span>
						<?php if (($dc_tok['state'] ?? '') === 'revoked'): ?>
							<span class="dc-badge dc-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($dc_tok['state'] ?? '') === 'expired'): ?>
							<span class="dc-badge dc-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="dc-badge dc-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($dc_state['isAdmin'] && ($dc_tok['userId'] ?? '') !== ''): ?>
							<span class="dc-agent-owner"><?php p((string)$dc_tok['userId']); ?></span>
						<?php endif; ?></span></td>
					<td data-cell="<?php p($l->t('Scopes')); ?>" class="dc-agent-scopes-cell">
						<span class="dc-agent-scopes">
						<?php foreach (($dc_tok['scopes'] ?? []) as $dc_scope): ?>
							<span class="dc-badge dc-badge--neutral dc-agent-scope" title="<?php p((string)$dc_scope); ?>"><?php p((string)($dc_state['scopeLabels'][$dc_scope] ?? $dc_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($dc_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($dc_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($dc_tok['lastUsedAt'] ?? null) === null): ?> class="dc-agent-never"<?php endif; ?> data-ts="<?php p((string)($dc_tok['lastUsedAt'] ?? '')); ?>"><?php if (($dc_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="dc-row-actions">
						<?php if (($dc_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="dc-btn dc-btn--ghost dc-agent-rotate" data-token-id="<?php p((string)$dc_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="dc-btn dc-btn--danger dc-agent-revoke" data-token-id="<?php p((string)$dc_tok['id']); ?>" data-token-label="<?php p((string)($dc_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($dc_state['canMint']): ?>
		<div class="dc-agent-actions">
			<button type="button" class="dc-btn dc-btn--primary" id="dc-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($dc_state['isAdmin']): ?>
	<div class="dc-form-row dc-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="dc-checkbox-label" for="dc-agent-selfmint-cb">
			<input type="checkbox" id="dc-agent-selfmint-cb" <?php if ($dc_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="dc-form-row__hint" id="dc-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="dc-agent-mint-dialog" class="dc-dialog dc-agent-mint-dialog" aria-labelledby="dc-agent-mint-h">
		<div class="dc-dialog__body">
			<h3 id="dc-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="dc-agent-mint-form" class="dc-form-stack" novalidate>
				<div class="dc-form-row">
					<label for="dc-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="dc-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="dc-field-error" id="dc-agent-label-error" hidden></p>
				</div>
				<fieldset class="dc-form-row dc-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="dc-choice-cards">
						<label class="dc-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="dc-choice-card__copy">
								<span class="dc-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="dc-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="dc-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="dc-choice-card__copy">
								<span class="dc-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="dc-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="dc-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="dc-choice-card__copy">
								<span class="dc-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="dc-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="dc-form-row dc-fieldset" id="dc-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="dc-agent-chips" id="dc-agent-chips"></div>
					<p class="dc-field-error" id="dc-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="dc-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="dc-dialog__footer">
			<div class="dc-actions">
				<button type="button" class="dc-btn dc-btn--secondary" id="dc-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="dc-agent-mint-form" class="dc-btn dc-btn--primary" id="dc-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="dc-agent-secret-dialog" class="dc-dialog dc-agent-secret-dialog" aria-labelledby="dc-agent-secret-h">
		<div class="dc-dialog__body">
			<h3 id="dc-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="dc-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="dc-form-row">
				<label for="dc-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="dc-input-group">
					<input type="password" id="dc-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="dc-btn dc-btn--ghost" id="dc-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="dc-btn dc-btn--ghost" id="dc-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="dc-form-row">
				<label for="dc-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="dc-agent-config" class="dc-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="dc-dialog__footer">
			<div class="dc-actions">
				<button type="button" class="dc-btn dc-btn--secondary" id="dc-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="dc-btn dc-btn--primary" id="dc-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="dc-agent-revoke-dialog" class="dc-dialog" aria-labelledby="dc-agent-revoke-h">
		<div class="dc-dialog__body">
			<h3 id="dc-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="dc-agent-revoke-text"></p>
		</div>
		<footer class="dc-dialog__footer">
			<div class="dc-actions">
				<button type="button" class="dc-btn dc-btn--secondary" id="dc-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="dc-btn dc-btn--danger" id="dc-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="dc-agent-state"><?php
		print_unescaped(json_encode($dc_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>

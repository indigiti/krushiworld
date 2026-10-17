<?php
/** Only included after an authenticated individual login, never for the legacy token. */
if (!$identityMode || !$authorized) return;
?>
<section class="admin-section mt-4" id="security-access">
  <div class="admin-section-head">
    <div><h2 class="font-bold">Security &amp; Access</h2>
    <p class="text-xs text-muted">Identity: <?= apmc_escape((string)$authUser['username']) ?> · <?= apmc_escape((string)$authUser['role']) ?> · <?= apmc_escape((string)$authUser['provider']) ?></p></div>
  </div>
  <?php if ($authNotice !== ''): ?><div class="admin-alert admin-alert-warning" role="status"><?= apmc_escape($authNotice) ?></div><?php endif; ?>
  <?php if (is_array($authRecovery)): ?>
    <article class="admin-card" style="border:2px solid #8b7100;margin:12px 0">
      <h3 class="font-bold">New one-time recovery codes</h3>
      <p class="text-xs text-muted">Save these securely; old codes are now revoked. They will not be shown again.</p>
      <pre style="white-space:pre-wrap"><?= apmc_escape(implode("\n",$authRecovery)) ?></pre>
    </article>
  <?php endif; ?>
  <?php if (is_array($authEnrollment)): ?>
    <article class="admin-card" style="border:2px solid #8b7100;margin:12px 0">
      <h3 class="font-bold">One-time authenticator enrollment for <?= apmc_escape($authEnrollment['username']) ?></h3>
      <p class="text-xs text-muted">Save the following information now. It will not be displayed again. Verify the account in a separate browser session using its password and authenticator.</p>
      <p>Authenticator setup key: <code><?= apmc_escape($authEnrollment['secret']) ?></code></p>
      <p>Setup URI: <code style="overflow-wrap:anywhere"><?= apmc_escape($authEnrollment['uri']) ?></code></p>
      <p>One-use recovery codes:</p>
      <pre style="white-space:pre-wrap"><?= apmc_escape(implode("\n",$authEnrollment['recovery'])) ?></pre>
    </article>
  <?php endif; ?>
  <?php if (($authUser['provider'] ?? '') === 'local'): ?>
    <article class="admin-card" style="margin-bottom:12px">
      <h3 class="font-bold">My local account security</h3>
      <p class="text-xs text-muted">Changing your password revokes all sessions. Recovery rotation invalidates all prior recovery codes. Both require a fresh authenticator code.</p>
      <div class="grid gap-3 md:grid-cols-2">
        <form method="post" action="<?= apmc_escape($adminUrl) ?>" class="admin-login-form">
          <input type="hidden" name="_csrf" value="<?= apmc_escape($csrf) ?>">
          <input type="hidden" name="auth_action" value="change_password">
          <label>Current password <input name="current_password" type="password" required autocomplete="current-password"></label>
          <label>Fresh authenticator code <input name="fresh_otp" type="text" required inputmode="numeric" maxlength="6"></label>
          <label>New password (14+ characters) <input name="new_password" type="password" required minlength="14" autocomplete="new-password"></label>
          <button type="submit" class="small-action">Change password and log out</button>
        </form>
        <form method="post" action="<?= apmc_escape($adminUrl) ?>" class="admin-login-form">
          <input type="hidden" name="_csrf" value="<?= apmc_escape($csrf) ?>">
          <input type="hidden" name="auth_action" value="rotate_recovery">
          <label>Current password <input name="current_password" type="password" required autocomplete="current-password"></label>
          <label>Fresh authenticator code <input name="fresh_otp" type="text" required inputmode="numeric" maxlength="6"></label>
          <button type="submit" class="small-action">Replace recovery codes</button>
        </form>
      </div>
    </article>
  <?php endif; ?>
  <div class="grid gap-3 md:grid-cols-2">
    <article class="admin-card">
      <h3 class="font-bold">Authentication providers</h3>
      <p>Server policy: <strong><?= apmc_escape(apmc_auth()->mode()) ?></strong></p>
      <p>GitHub sign-in: <strong><?= $githubEnabled ? 'Enabled for approved staging identities' : 'Disabled' ?></strong></p>
      <p>Local Super Admin verified: <strong><?= $localReady ? 'Yes' : 'Not yet' ?></strong></p>
      <p class="text-xs text-muted">Production policy overrides dashboard toggles. Disable GitHub only after testing local MFA login.</p>
      <?php if (($authUser['role'] ?? '') === 'super_admin' && ($authUser['provider'] ?? '') === 'local'
           && $authUser['local_verified_at'] && apmc_auth()->mode() === 'staging'): ?>
        <form method="post" action="<?= apmc_escape($adminUrl) ?>">
          <input type="hidden" name="_csrf" value="<?= apmc_escape($csrf) ?>">
          <input type="hidden" name="auth_action" value="<?= $githubEnabled ? 'provider_disable' : 'provider_enable' ?>">
          <button type="submit" class="small-action" <?= !$localReady && $githubEnabled ? 'disabled' : '' ?>><?= $githubEnabled ? 'Disable GitHub and revoke sessions' : 'Enable staging GitHub' ?></button>
        </form>
      <?php endif; ?>
    </article>
    <?php if (($authUser['role'] ?? '') === 'super_admin'): ?>
      <article class="admin-card">
        <h3 class="font-bold">Create administrator</h3>
        <p class="text-xs text-muted">Creates a pending account. Password + MFA verification is required before activation.</p>
        <form method="post" action="<?= apmc_escape($adminUrl) ?>" class="admin-login-form">
          <input type="hidden" name="_csrf" value="<?= apmc_escape($csrf) ?>">
          <input type="hidden" name="auth_action" value="create_user">
          <label class="admin-token-field">Username <input name="username" type="text" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="off" required></label>
          <label class="admin-token-field">Initial password (14+ characters) <input name="password" type="password" autocomplete="new-password" minlength="14" required></label>
          <label class="admin-token-field">Role
            <select name="role"><option value="admin">Admin</option><option value="operator">Operator</option><option value="viewer">Viewer</option><option value="super_admin">Super Admin</option></select></label>
          <button type="submit" class="admin-primary-action">Create account and MFA enrollment</button>
        </form>
      </article>
    <?php endif; ?>
  </div>
  <?php if (($authUser['role'] ?? '') === 'super_admin'): ?>
    <article class="admin-card" style="margin-top:12px">
      <h3 class="font-bold">User access and sessions</h3>
      <div style="overflow-x:auto"><table style="width:100%;border-spacing:8px"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Local verification</th><th>Manage</th></tr></thead>
        <tbody><?php foreach ($authUsers as $account): ?><tr>
          <td><?= apmc_escape((string)$account['username']) ?></td>
          <td><?= apmc_escape((string)$account['role']) ?></td>
          <td><?= apmc_escape((string)$account['status']) ?></td>
          <td><?= $account['local_verified_at'] ? apmc_escape(date('Y-m-d H:i',(int)$account['local_verified_at'])) : 'Not yet' ?></td>
          <td><?php if ((int)$account['id'] !== (int)$authUser['user_id'] && $account['status'] !== 'pending_mfa'): ?>
            <form method="post" action="<?= apmc_escape($adminUrl) ?>">
              <input type="hidden" name="_csrf" value="<?= apmc_escape($csrf) ?>">
              <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
              <input type="hidden" name="auth_action" value="<?= $account['status']==='disabled' ? 'enable_user' : 'disable_user' ?>">
              <button type="submit" class="small-action"><?= $account['status']==='disabled' ? 'Enable' : 'Disable' ?></button>
            </form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    </article>
    <article class="admin-card" style="margin-top:12px">
      <h3 class="font-bold">Security audit trail</h3>
      <?php foreach (array_slice($authEvents,0,12) as $event): ?>
        <p class="text-xs text-muted"><?= apmc_escape(date('Y-m-d H:i',(int)$event['happened_at'])) ?> · <?= apmc_escape((string)$event['event']) ?> · actor #<?= (int)$event['actor_id'] ?></p>
      <?php endforeach; ?>
    </article>
  <?php endif; ?>
</section>

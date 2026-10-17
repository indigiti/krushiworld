<?php
$authorized = apmc_admin_authorized();
$baseApi = apmc_script_url();
$adminUrl = apmc_script_url() . '?view=admin';
$loginFailed = (string)($_POST['admin_login'] ?? '') === '1' && !$authorized;
$runtime = $refresh->runtimeCapabilities();
$webRuntime = $webCollector->capabilities();
$engineStatus = strtolower((string)($health['status'] ?? 'warming'));
$adminTokenValue = (string)apmc_config('admin_token', '');
$defaultTokenInUse = $adminTokenValue === 'APMC-CHANGE-ME-9K7X2Q';
$adminDisabled = $adminTokenValue === '' || $defaultTokenInUse;
$i18nJson = json_encode(apmc_i18n(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
$nonce = apmc_csp_nonce();
$csrf = $authorized ? apmc_admin_csrf_token() : '';
?>
<!doctype html>
<html lang="en" data-lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta id="themeColor" name="theme-color" content="#0b0c0a">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>APMC Engine · System</title>
  <script nonce="<?= apmc_escape($nonce) ?>">(function(){try{var saved=localStorage.getItem('apmc-theme');var dark=saved?saved==='dark':true;document.documentElement.classList.toggle('dark',!!dark);document.documentElement.dataset.theme=dark?'dark':'light';var lang=localStorage.getItem('apmc-language')==='mr'?'mr':'en';document.documentElement.lang=lang;document.documentElement.dataset.lang=lang;}catch(e){}})();</script>
  <script nonce="<?= apmc_escape($nonce) ?>">window.APMC_I18N = <?= $i18nJson ?: '{}' ?>; window.APMC_CSRF = <?= json_encode($csrf) ?>;</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400..900&amp;family=Space+Grotesk:wght@400..700&amp;display=swap" rel="stylesheet" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= apmc_escape(apmc_asset('app.css')) ?>">
  <script defer src="<?= apmc_escape(apmc_asset('admin.js')) ?>"></script>
</head>
<body class="min-h-screen antialiased">
<div class="site-shell admin-shell mx-auto max-w-6xl p-4 sm:p-6">
  <header class="admin-toolbar lex-header">
    <div class="min-w-0">
      <a class="admin-back-link" href="<?= apmc_escape(apmc_script_url()) ?>">
        <span aria-hidden="true">←</span> <span data-i18n="marketPage">Market page</span>
      </a>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <h1 class="text-2xl font-black tracking-tight" data-i18n="dataEngine">APMC Data Engine</h1>
        <span class="version-pill">v<?= apmc_escape((string)apmc_config('version')) ?></span>
      </div>
      <p class="mt-1 text-xs text-muted" data-i18n="adminSubtitle">System health, collector runtime and flat-file storage.</p>
    </div>
    <div class="flex items-center gap-2">
      <div class="language-switcher" role="group" aria-label="Language">
        <button id="langEn" type="button" class="language-option" data-lang-option="en" aria-pressed="false">EN</button>
        <button id="langMr" type="button" class="language-option" data-lang-option="mr" aria-pressed="false">मराठी</button>
      </div>
      <button id="themeToggle" type="button" class="material-icon-button" data-i18n-aria="toggleTheme" aria-label="Toggle light and dark theme" title="Toggle theme">
        <svg viewBox="0 0 24 24" class="theme-icon theme-icon-light size-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
        <svg viewBox="0 0 24 24" class="theme-icon theme-icon-dark size-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.5 14.2A8 8 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
      </button>
      <?php if ($authorized): ?>
        <form method="post" action="<?= apmc_escape($adminUrl) ?>" class="inline">
          <input type="hidden" name="admin_logout" value="1">
          <input type="hidden" name="_csrf" value="<?= apmc_escape($csrf) ?>">
          <button type="submit" class="small-action" data-i18n="signOut">Sign out</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

<?php if (!$authorized): ?>
  <section class="admin-login-card">
    <div class="admin-login-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
    </div>
    <div>
      <h2 class="text-lg font-bold" data-i18n="adminAccess">Admin access</h2>
      <p class="mt-1 text-sm text-muted" data-i18n="adminAccessHelp">Enter the configured admin token. A secure HttpOnly session cookie is used after login, so the token does not remain in the address bar.</p>
    </div>
    <?php if ($adminDisabled): ?><div class="admin-alert admin-alert-warning" role="alert"><span data-i18n="adminDisabled">Admin access is disabled until APMC_ADMIN_TOKEN is configured in the server environment.</span></div><?php endif; ?>
    <?php if ($loginFailed && !$adminDisabled): ?><div class="admin-alert admin-alert-danger" role="alert"><span data-i18n="tokenRejected">The token was not accepted. Check APMC_ADMIN_TOKEN or config/app.php.</span></div><?php endif; ?>
    <form method="post" action="<?= apmc_escape($adminUrl) ?>" class="admin-login-form">
      <input type="hidden" name="admin_login" value="1">
      <label class="admin-token-field"><span data-i18n="adminToken">Admin token</span><input name="token" type="password" autocomplete="current-password" required placeholder="Enter token" data-i18n-placeholder="enterToken" <?= $adminDisabled ? 'disabled' : '' ?>></label>
      <button class="admin-primary-action" type="submit" data-i18n="openConsole" <?= $adminDisabled ? 'disabled' : '' ?>>Open system console</button>
    </form>
  </section>
<?php else: ?>
  <?php if ($defaultTokenInUse): ?>
    <div class="admin-alert admin-alert-warning mb-4" role="alert"><b data-i18n="securityAction">Security action:</b> <span data-i18n="defaultTokenWarning">the package default admin token is still configured. Replace it before exposing this console publicly.</span></div>
  <?php endif; ?>

  <section class="grid gap-3 md:grid-cols-4">
    <article class="admin-card"><div class="metric-label" data-i18n="engine">Engine</div><div class="mt-3 flex items-center gap-2"><span class="health-dot health-dot-<?= apmc_escape($engineStatus) ?>"></span><div class="text-xl font-black" data-status-key="<?= apmc_escape($engineStatus) ?>"><?= apmc_escape(strtoupper($engineStatus)) ?></div></div><div class="metric-note mt-1" data-i18n="collectorStorageState">Collector and storage state</div></article>
    <article class="admin-card"><div class="metric-label" data-i18n="lastSourceCheck">Last source check</div><div class="mt-3 text-sm font-bold" data-empty-time="<?= empty($manifest['last_checked_at']) ? '1' : '0' ?>"><?= apmc_escape((string)($manifest['last_checked_at'] ?? 'Never')) ?></div><div class="metric-note mt-1" data-i18n="requestRefresh">Server cron primary · page fallback on failure</div></article>
    <article class="admin-card"><div class="metric-label" data-i18n="lastDataChange">Last data change</div><div class="mt-3 text-sm font-bold" data-empty-time="<?= empty($manifest['last_updated_at']) ? '1' : '0' ?>"><?= apmc_escape((string)($manifest['last_updated_at'] ?? 'Never')) ?></div><div class="metric-note mt-1" data-i18n="hashSnapshot">Hash-verified snapshot</div></article>
    <article class="admin-card"><div class="metric-label" data-i18n="coverage">Coverage</div><div class="mt-3 text-xl font-black"><?= (int)($data['meta']['markets_available'] ?? 0) ?> / <?= (int)($data['meta']['markets_expected'] ?? 19) ?></div><div class="metric-note mt-1" data-i18n="configuredFeeds">Configured market feeds</div></article>
  </section>

  <section class="admin-section mt-4">
    <div class="admin-section-head">
      <div><h2 class="font-bold" data-i18n="webOperations">Web operations</h2><p class="mt-1 text-xs text-muted" data-i18n="webOperationsHelp">Actions are POST-only and run against the existing collector. Public cached data remains available while a refresh runs.</p></div>
      <div class="flex flex-wrap gap-2"><button class="admin-action" data-action="force" data-i18n="checkNow">Check now</button><button class="admin-action" data-action="retry" data-i18n="retryMissing">Retry missing</button><button class="admin-action" data-action="rebuild" data-i18n="rebuildIndexes">Rebuild indexes</button><button class="admin-action" data-action="backfill7" data-i18n="backfill7">Backfill 7 days</button></div>
    </div>
    <div id="actionResult" class="admin-action-result hidden" role="status" aria-live="polite"></div>
  </section>

  <section class="grid gap-3 mt-4 admin-runtime-grid">
    <article class="admin-card"><div class="metric-label" data-i18n="processLaunch">Process launch</div><div class="mt-3 text-sm font-bold"><span data-i18n="<?= !empty($runtime['process_spawn_available']) ? 'available' : 'unavailable' ?>"><?= !empty($runtime['process_spawn_available']) ? 'Available' : 'Unavailable' ?></span></div><div class="metric-note mt-1" data-i18n="runtimeChain">exec / popen / proc_open fallback chain</div></article>
    <article class="admin-card"><div class="metric-label" data-i18n="webFallback">Web-native fallback</div><div class="mt-3 text-sm font-bold"><span data-i18n="<?= !empty($webRuntime['available']) ? 'available' : 'unavailable' ?>"><?= !empty($webRuntime['available']) ? 'Available' : 'Unavailable' ?></span></div><div class="metric-note mt-1" data-i18n="sourceRecovery">Works when PHP process launch is disabled</div></article>
    <article class="admin-card"><div class="metric-label" data-i18n="storage">Storage</div><div class="mt-3 text-sm font-bold"><span data-i18n="<?= !empty($health['storage_writable']) ? 'writable' : 'checkPermissions' ?>"><?= !empty($health['storage_writable']) ? 'Writable' : 'Check permissions' ?></span></div><div class="metric-note mt-1" data-i18n="atomicStorage">Atomic JSON snapshots and exports</div></article>
    <article class="admin-card"><div class="metric-label" data-i18n="collectorErrors">Collector errors</div><div class="mt-3 text-sm font-bold"><?= count((array)($health['errors'] ?? [])) ?></div><div class="metric-note mt-1" data-i18n="currentFeedErrors">Current feed errors retained in health state</div></article>
  </section>

  <section class="admin-section mt-4 overflow-hidden">
    <div class="admin-section-head"><div><h2 class="font-bold" data-i18n="marketFeeds">Market feeds</h2><p class="mt-1 text-xs text-muted" data-i18n="marketFeedsHelp">Each feed retains its last valid snapshot if the source becomes unavailable.</p></div></div>
    <div class="admin-feed-list">
    <?php foreach (($manifest['markets'] ?? []) as $slug => $m):
      $status = strtolower((string)($m['status'] ?? 'unknown'));
    ?>
      <div class="admin-feed-row">
        <div class="min-w-0"><b class="block truncate" data-market-slug="<?= apmc_escape((string)$slug) ?>"><?= apmc_escape((string)$slug) ?></b><span class="feed-subtext" data-empty-date="<?= empty($m['date']) ? '1' : '0' ?>"><?= apmc_escape((string)($m['date'] ?? 'No date')) ?></span></div>
        <span class="status-pill status-pill-<?= apmc_escape($status) ?>" data-status-key="<?= apmc_escape($status) ?>"><?= apmc_escape(strtoupper($status)) ?></span>
        <span class="feed-check"><?= apmc_escape((string)($m['last_checked_at'] ?? '—')) ?></span>
        <span class="feed-error" title="<?= apmc_escape((string)($m['error'] ?? '')) ?>"><span class="feed-error-text" data-empty-error="<?= empty($m['error']) ? '1' : '0' ?>"><?= apmc_escape((string)($m['error'] ?? 'No current error')) ?></span></span>
      </div>
    <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

  <footer class="admin-footer">APMC Pune Data Engine · <span data-i18n="visualBuild">Bilingual visual/runtime build</span> · v<?= apmc_escape((string)apmc_config('version')) ?></footer>
</div>
</body>
</html>

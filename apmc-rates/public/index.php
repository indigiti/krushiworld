<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$repo = new RateRepository();
$refresh = new RefreshService($repo);
$webCollector = new WebCollector($repo);
$queryService = new PublicQueryService($repo);
$seoService = new SeoService($repo);

$view = (string) ($_GET['view'] ?? 'home');
apmc_apply_security_headers($view === 'admin');

// Promote a valid one-time token to an HttpOnly SameSite cookie, then remove the
// token from the browser address bar/history. This keeps legacy token URLs working
// without continuing to expose the credential on every admin action.
if ($view === 'admin' && isset($_GET['token']) && apmc_admin_authorized()) {
    apmc_set_admin_cookie(true);
    header('Location: ' . apmc_script_url() . '?view=admin', true, 303);
    exit;
}
if ($view === 'admin' && (string)($_POST['admin_login'] ?? '') === '1') {
    if (!apmc_same_origin_request()) { http_response_code(403); exit('Invalid request origin'); }
    if (apmc_admin_authorized()) {
        apmc_set_admin_cookie(true);
        header('Location: ' . apmc_script_url() . '?view=admin', true, 303);
        exit;
    }
}
if ($view === 'admin' && (string)($_POST['admin_logout'] ?? '') === '1') {
    if (!apmc_admin_authorized() || !apmc_same_origin_request() || !apmc_admin_csrf_valid()) {
        http_response_code(403); exit('Invalid logout request');
    }
    apmc_set_admin_cookie(false);
    header('Location: ' . apmc_script_url() . '?view=admin', true, 303);
    exit;
}

$seo = (string) ($_GET['seo'] ?? '');
if ($seo !== '') {
    if ($seo === 'robots') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $seoService->robotsTxt(); exit;
    }
    if ($seo === 'sitemap') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        echo $seoService->sitemapXml(); exit;
    }
    if ($seo === 'llms') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        echo $seoService->llmsTxt(); exit;
    }
    if ($seo === 'openapi') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        echo json_encode($seoService->openApi(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit;
    }
    if ($seo === 'agent-manifest') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        echo json_encode($seoService->agentManifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit;
    }
    http_response_code(404); exit('Not found');
}

$api = (string) ($_GET['api'] ?? '');
if ($api !== '') {
    if ($api === 'latest') apmc_json_response(['ok' => true, 'data' => $repo->latest(), 'fallback_due' => $refresh->serverFallbackDue()]);
    if ($api === 'query') apmc_json_response(['ok' => true, 'data' => $queryService->query($_GET)]);
    if ($api === 'summary') apmc_json_response(['ok' => true, 'data' => $queryService->summary((string)($_GET['lang'] ?? 'en'))]);
    if ($api === 'markets') {
        $latest = $repo->latest();
        $markets = (array)($latest['markets'] ?? []);
        $directory = apmc_json_read(APMC_ROOT . '/config/market_coordinates.json', []);
        foreach ($markets as &$market) {
            if (!is_array($market)) continue;
            $id = (string)($market['market_id'] ?? '');
            $geo = $directory[$id] ?? ($market['geo'] ?? null);
            if (is_array($geo) && isset($geo['latitude'], $geo['longitude'])
                && is_numeric($geo['latitude']) && is_numeric($geo['longitude'])
                && is_finite((float)$geo['latitude']) && is_finite((float)$geo['longitude'])
                && abs((float)$geo['latitude']) <= 90 && abs((float)$geo['longitude']) <= 180) {
                $market['geo'] = ['latitude' => (float)$geo['latitude'], 'longitude' => (float)$geo['longitude'], 'precision' => 'approximate'];
            } else $market['geo'] = null;
        }
        unset($market);
        apmc_json_response(['ok' => true, 'data' => ['markets' => $markets, 'count' => count($markets)]]);
    }
    if ($api === 'discover') apmc_json_response(['ok' => true, 'agent' => $seoService->agentManifest(), 'openapi' => apmc_absolute_url('/openapi.json')]);
    if ($api === 'health') {
        $runtime = $refresh->runtimeCapabilities();
        if (!apmc_admin_authorized()) {
            $latest = $repo->latest();
            apmc_json_response(['ok' => true, 'health' => [
                'status' => (string)($latest['meta']['status'] ?? 'warming'),
                'as_of' => $latest['meta']['serving_date'] ?? null,
                'records' => (int)($latest['meta']['records'] ?? 0),
                'markets_available' => (int)($latest['meta']['markets_available'] ?? 0),
                'markets_expected' => (int)($latest['meta']['markets_expected'] ?? 19),
            ]]);
        }
        apmc_json_response(['ok' => true, 'health' => $repo->health(), 'manifest' => $repo->manifest(), 'refresh_runtime' => $runtime, 'web_collector' => $webCollector->capabilities()]);
    }
    if ($api === 'market') apmc_json_response(['ok' => true, 'data' => $repo->market((string)($_GET['slug'] ?? ''))]);

    // Public, fixed-source, one-feed-at-a-time recovery endpoint. It accepts no URL
    // or market argument, is lock protected, and exists specifically for hosts where
    // PHP process execution is disabled.
    if ($api === 'bootstrap') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') apmc_json_response(['ok' => false, 'error' => 'POST required'], 405);
        $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        $origin = strtolower(rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/'));
        $expectedOrigin = strtolower(apmc_origin());
        if ($fetchSite !== 'same-origin' && ($origin === '' || $expectedOrigin === '' || !hash_equals($expectedOrigin, $origin))) {
            apmc_json_response(['ok' => false, 'error' => 'Same-origin browser request required'], 403);
        }
        $fallback = $refresh->fallbackStatus();
        if (!(bool)($fallback['due'] ?? false)) {
            apmc_json_response([
                'ok' => true,
                'fallback_due' => false,
                'result' => ['triggered' => false, 'reason' => 'server_scheduler_healthy'],
                'data' => $repo->latest(),
                'health' => $repo->health(),
            ]);
        }
        $result = $webCollector->step(false);
        apmc_json_response(['ok' => true, 'fallback_due' => true, 'result' => $result, 'data' => $repo->latest(), 'health' => $repo->health()]);
    }

    if (in_array($api, ['refresh', 'admin-action'], true)) {
        if (!apmc_admin_authorized()) apmc_json_response(['ok' => false, 'error' => 'Unauthorized'], 403);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            apmc_json_response(['ok' => false, 'error' => 'POST required'], 405);
        }
        if (!apmc_same_origin_request() || !apmc_admin_csrf_valid()) apmc_json_response(['ok' => false, 'error' => 'CSRF validation failed'], 403);
        $action = (string) ($_POST['action'] ?? 'force');
        $args = match ($action) {
            'retry' => ['--check', '--retry-errors', '--trigger', 'admin'],
            'rebuild' => ['--rebuild', '--trigger', 'admin'],
            'backfill7' => ['--backfill-days', '7', '--trigger', 'admin'],
            'backfill30' => ['--backfill-days', '30', '--trigger', 'admin'],
            default => ['--force', '--trigger', 'admin'],
        };
        $result = $refresh->spawn($args);
        if (!(bool)($result['triggered'] ?? false) && in_array($action, ['force', 'retry'], true)) {
            $web = $webCollector->step($action === 'force');
            $result['web_fallback'] = $web;
            if ((bool)($web['triggered'] ?? false)) {
                $result['triggered'] = true;
                $result['method'] = 'php_web';
                $result['reason'] = 'process_runtime_unavailable_web_fallback_started';
            }
        }
        apmc_json_response(['ok' => true, 'result' => $result]);
    }

    apmc_json_response(['ok' => false, 'error' => 'Unknown API route'], 404);
}

// The HTML shell is runtime state, not a static document. Prevent an intermediary
// or browser from pinning an old shell to old CSS/JS after an overlay release.
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// Demand-driven stale-while-revalidate. This never blocks page rendering.
$refreshResult = $refresh->maybeTrigger();
$data = $repo->latest();
$health = $repo->health();
$manifest = $repo->manifest();

if ($view === 'admin') {
    require dirname(__DIR__) . '/app/views/admin.php';
} else {
    require dirname(__DIR__) . '/app/views/home.php';
}

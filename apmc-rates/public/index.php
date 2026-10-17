<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$repo = new RateRepository();
$national = new NationalMarketRepository();
$refresh = new RefreshService($repo);
$webCollector = new WebCollector($repo);
$queryService = new PublicQueryService($repo);
$seoService = new SeoService($repo);
$marketDirectory = new \KrushiPulse\Application\MarketDirectoryService($repo, $national);
$marketRates = new \KrushiPulse\Application\MarketRatesService($repo, $national, $marketDirectory);
$route = \KrushiPulse\Http\RouteResolver::resolve(
    (string)($_SERVER['REQUEST_URI'] ?? '/'),
    apmc_script_url(),
);
if ($route['kind'] === 'not_found') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Page not found');
}
if (isset($route['lang'])) $_GET['lang'] = $route['lang'];
if ($route['kind'] === 'mandi') {
    $routeMarket = $marketDirectory->find((string)$route['market']);
    if ($routeMarket === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Unknown mandi');
    }
    $route['market_data'] = $routeMarket;
}
$GLOBALS['apmc_route'] = $route;

$view = $route['kind'] === 'admin' ? 'admin' : (string) ($_GET['view'] ?? 'home');
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

$seo = (string)($route['kind'] === 'seo' ? $route['name'] : ($_GET['seo'] ?? ''));
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

$api = (string)($route['kind'] === 'api' ? $route['api'] : ($_GET['api'] ?? ''));
if ($api !== '') {
    \KrushiPulse\Http\PublicApiController::dispatch(
        $api, $route, $repo, $national, $refresh, $webCollector,
        $queryService, $seoService, $marketDirectory, $marketRates,
    );
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

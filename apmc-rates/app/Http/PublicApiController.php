<?php
declare(strict_types=1);

namespace KrushiPulse\Http;

use KrushiPulse\Application\MarketDirectoryService;
use KrushiPulse\Application\MarketRatesService;
use NationalMarketRepository;
use PublicQueryService;
use RateRepository;
use RefreshService;
use SeoService;
use WebCollector;

/**
 * HTTP JSON controller. Reads are delegated to application services/repositories.
 * Legacy query-string endpoints remain available to existing clients.
 */
final class PublicApiController {
    public static function dispatch(
        string $api,
        array $route,
        RateRepository $repo,
        NationalMarketRepository $national,
        RefreshService $refresh,
        WebCollector $webCollector,
        PublicQueryService $queryService,
        SeoService $seoService,
        MarketDirectoryService $marketDirectory,
        MarketRatesService $marketRates,
    ): never {
    if ($api === 'v1-weather') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') apmc_json_response(['ok'=>false,'error'=>'GET required'],405);
        $weather = new \KrushiPulse\Application\WeatherAdvisoryService();
        apmc_json_response(['ok'=>true,'data'=>$weather->latest()]);
    }
    if ($api === 'v1-market-profile') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') apmc_json_response(['ok'=>false,'error'=>'GET required'],405);
        $service = new \KrushiPulse\Application\MarketProfileService($marketDirectory);
        $profile = $service->find((string)($route['market'] ?? ''));
        if ($profile === null) apmc_json_response(['ok'=>false,'error'=>'Unknown mandi'],404);
        apmc_json_response(['ok'=>true,'data'=>$profile]);
    }
    if ($api === 'v1-msp') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') apmc_json_response(['ok'=>false,'error'=>'GET required'],405);
        $service = new \KrushiPulse\Application\MspReferenceService();
        apmc_json_response(['ok'=>true,'data'=>$service->lookup($_GET)]);
    }
    if ($api === 'v1-insights') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') apmc_json_response(['ok'=>false,'error'=>'GET required'],405);
        $service = new \KrushiPulse\Application\ExplainableInsightService(new \KrushiPulse\Application\PriceIntelligenceService());
        apmc_json_response(['ok'=>true,'data'=>$service->explain($_GET)]);
    }
    if (in_array($api, ['v1-history','v1-compare'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            apmc_json_response(['ok'=>false,'error'=>'GET required'],405);
        }
        $intelligence = new \KrushiPulse\Application\PriceIntelligenceService();
        $result = $api === 'v1-history' ? $intelligence->history($_GET) : $intelligence->compare($_GET);
        header('Cache-Control: public, max-age=240');
        apmc_json_response(['ok'=>true,'data'=>$result]);
    }
    if ($api === 'latest') apmc_json_response(['ok' => true, 'data' => $repo->latest(), 'fallback_due' => $refresh->serverFallbackDue()]);
    if ($api === 'query') apmc_json_response(['ok' => true, 'data' => $queryService->query($_GET)]);
    if ($api === 'summary') apmc_json_response(['ok' => true, 'data' => $queryService->summary((string)($_GET['lang'] ?? 'en'))]);
    if ($api === 'markets') {
        apmc_json_response(['ok' => true, 'data' => $marketDirectory->summary()]);
    }
    if ($api === 'v1-mandi-rates') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            apmc_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $id = (string)($route['market'] ?? '');
        $data = $marketRates->forMarket($id, (int)($_GET['limit'] ?? 1500));
        if ($data === null) apmc_json_response(['ok' => false, 'error' => 'Unknown mandi'], 404);
        apmc_json_response(['ok' => true, 'data' => $data]);
    }
    if ($api === 'national') {
        $id = (string)($_GET['market'] ?? '');
        try {
            apmc_json_response(['ok' => true, 'data' =>
                $national->marketRates($id, (int)($_GET['limit'] ?? 1500))]);
        } catch (InvalidArgumentException $ex) {
            apmc_json_response(['ok' => false, 'error' => $ex->getMessage()], 400);
        }
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
}

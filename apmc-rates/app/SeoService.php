<?php
declare(strict_types=1);

final class SeoService {
    public function __construct(private RateRepository $repo) {}

    public function canonical(string $lang): string {
        $lang = in_array($lang, ['en', 'mr', 'hi'], true) ? $lang : 'en';
        $route = (array)($GLOBALS['apmc_route'] ?? []);
        if (($route['kind'] ?? '') === 'mandi' && !empty($route['market'])) {
            return apmc_absolute_url('/' . $lang . '/mandis/' . rawurlencode((string)$route['market']) . '/');
        }
        if (($route['kind'] ?? '') === 'directory') return apmc_absolute_url('/' . $lang . '/mandis/');
        return apmc_absolute_url('/' . $lang . '/');
    }

    public function sitemapXml(): string {
        $latest = $this->repo->latest();
        $modified = (string)($latest['meta']['serving_date'] ?? date('Y-m-d'));
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $alternate = static function(string $en, string $mr, string $hi) use ($esc): string {
            return '<xhtml:link rel="alternate" hreflang="en-IN" href="' . $esc($en) . '"/>'
                . '<xhtml:link rel="alternate" hreflang="mr-IN" href="' . $esc($mr) . '"/>'
                . '<xhtml:link rel="alternate" hreflang="hi-IN" href="' . $esc($hi) . '"/>'
                . '<xhtml:link rel="alternate" hreflang="x-default" href="' . $esc($en) . '"/>';
        };
        $rows = [];
        $append = static function(string $path, string $date) use (&$rows, $alternate, $esc): void {
            $en = apmc_absolute_url('/en/' . $path);
            $mr = apmc_absolute_url('/mr/' . $path);
            $hi = apmc_absolute_url('/hi/' . $path);
            foreach ([$en, $mr, $hi] as $url) {
                $rows[] = '  <url><loc>' . $esc($url) . '</loc>'
                    . $alternate($en, $mr, $hi) . '<lastmod>' . $esc($date)
                    . '</lastmod><changefreq>daily</changefreq></url>';
            }
        };
        $append('', $modified);
        $append('mandis/', $modified);
        $directory = new \KrushiPulse\Application\MarketDirectoryService($this->repo, new NationalMarketRepository());
        $shown = 0;
        foreach ($directory->all() as $market) {
            $id = (string)($market['market_id'] ?? '');
            if ($id === '' || (int)($market['records'] ?? 0) <= 0) continue;
            $date = (string)($market['date'] ?? '');
            $append('mandis/' . rawurlencode($id) . '/', preg_match('/^\d{4}-\d\d-\d\d$/', $date) ? $date : $modified);
            if (++$shown >= 4000) break;
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n"
            . implode("\n", $rows) . "\n</urlset>\n";
    }

    public function robotsTxt(): string {
        $sitemap = apmc_absolute_url('/sitemap.xml');
        $base = apmc_app_base_path();
        $prefix = $base === '' ? '' : $base;
        return "User-agent: *\nAllow: {$prefix}/\nDisallow: {$prefix}/admin/\nDisallow: {$prefix}/config/\nDisallow: {$prefix}/storage/\nDisallow: {$prefix}/collector/\nDisallow: {$prefix}/*?view=admin\n\nSitemap: {$sitemap}\n";
    }

    public function llmsTxt(): string {
        $base = rtrim(apmc_absolute_url('/'), '/');
        return <<<TXT
# Pune APMC Market Intelligence

Public, cached wholesale market-rate intelligence for Pune APMC markets and source feeds in English and Marathi.

## Primary pages
- English: {$base}/en/
- Marathi: {$base}/mr/
- Hindi: {$base}/hi/
- Mandi directory: {$base}/en/mandis/
- Mandi pages: {$base}/en/mandis/{market-id}/

## Machine-readable public endpoints
- Latest snapshot: {$base}/api/v1/latest
- Search/query: {$base}/api/query?q=tomato&lang=en&limit=20
- Canonical market list: {$base}/api/v1/markets
- Mandi-specific rates: {$base}/api/v1/mandis/{market-id}/rates
- Feed snapshot: {$base}/api/market?slug=<feed-slug>
- Compact summary: {$base}/api/summary?lang=en
- API discovery: {$base}/api/discover
- OpenAPI 3.1: {$base}/openapi.json

## Data provenance
Source: Agricultural Produce Market Committee, Pune
Source URL: https://apmcpune.in

## Safety and scope
Public endpoints are read-only market-data interfaces. Admin, refresh, collector runtime, tokens, internal errors, and server configuration are not part of the public agent surface.
TXT;
    }

    public function agentManifest(): array {
        $base = rtrim(apmc_absolute_url('/'), '/');
        return [
            'name' => 'Pune APMC Market Data Interface',
            'version' => (string)apmc_config('version', '1.3.0'),
            'description' => 'Read-only English, Marathi and Hindi Pune APMC market-rate retrieval interface for applications, search systems and AI agents.',
            'languages' => ['en', 'mr', 'hi'],
            'openapi' => $base . '/openapi.json',
            'capabilities' => [
                ['id' => 'latest-market-snapshot', 'method' => 'GET', 'url' => $base . '/api/latest'],
                ['id' => 'search-market-rates', 'method' => 'GET', 'url' => $base . '/api/query'],
                ['id' => 'market-summary', 'method' => 'GET', 'url' => $base . '/api/summary'],
                ['id' => 'canonical-markets', 'method' => 'GET', 'url' => $base . '/api/markets'],
            ],
            'write_actions' => false,
            'authentication' => ['public_read' => 'none'],
            'a2a' => [
                'status' => 'not-enabled',
                'note' => 'This is an agent-ready read API, not an A2A 1.0 server. Publish /.well-known/agent-card.json only after the A2A transport and required operations are implemented.'
            ],
        ];
    }

    public function openApi(): array {
        $server = rtrim(apmc_absolute_url('/'), '/');
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Pune APMC Market Intelligence Public API',
                'version' => (string)apmc_config('version', '1.3.0'),
                'description' => 'Read-only public API for English, Marathi and Hindi Pune APMC market-rate retrieval. Administrative and collector actions are intentionally excluded.'
            ],
            'servers' => [['url' => $server]],
            'paths' => [
                '/api/v1/markets' => [
                    'get' => [
                        'operationId' => 'listMandisV1',
                        'summary' => 'Known mandi directory with report status',
                        'responses' => ['200' => ['description' => 'Reported market directory']],
                    ],
                ],
                '/api/v1/mandis/{market}/rates' => [
                    'get' => [
                        'operationId' => 'getMandiRatesV1',
                        'summary' => 'One mandi rates with source dates',
                        'parameters' => [['name' => 'market', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9-]{1,110}$']]],
                        'responses' => [
                            '200' => ['description' => 'Source-reported prices (may be empty)'],
                            '404' => ['description' => 'Unknown market'],
                        ],
                    ],
                ],
                '/api/latest' => [
                    'get' => [
                        'operationId' => 'getLatestSnapshot',
                        'summary' => 'Get latest cached market snapshot',
                        'responses' => ['200' => ['description' => 'Latest snapshot']],
                    ],
                ],
                '/api/query' => [
                    'get' => [
                        'operationId' => 'queryMarketRates',
                        'summary' => 'Search public market rates',
                        'parameters' => [
                            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Commodity, market or category text in English or Marathi'],
                            ['name' => 'market', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Canonical market_id (main-market-yard, manjari, moshi, pimpri, khadki) or a compatible market/feed label'],
                            ['name' => 'category', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['en', 'mr', 'hi'], 'default' => 'en']],
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]],
                        ],
                        'responses' => ['200' => ['description' => 'Matching market-rate records']],
                    ],
                ],
                '/api/markets' => [
                    'get' => [
                        'operationId' => 'getCanonicalMarkets',
                        'summary' => 'List canonical customer-facing markets',
                        'responses' => ['200' => ['description' => 'Five deduplicated Pune APMC market locations with internal feed coverage']],
                    ],
                ],
                '/api/summary' => [
                    'get' => [
                        'operationId' => 'getMarketSummary',
                        'summary' => 'Get compact market status and pulse summary',
                        'parameters' => [['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['en', 'mr', 'hi'], 'default' => 'en']]],
                        'responses' => ['200' => ['description' => 'Compact market summary']],
                    ],
                ],
                '/api/market' => [
                    'get' => [
                        'operationId' => 'getMarketBySlug',
                        'summary' => 'Get one internal source-feed snapshot',
                        'parameters' => [['name' => 'slug', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$']]],
                        'responses' => ['200' => ['description' => 'Market snapshot']],
                    ],
                ],
            ],
        ];
    }

    public function jsonLd(string $lang): array {
        $route = (array)($GLOBALS['apmc_route'] ?? []);
        if (($route['kind'] ?? '') === 'mandi' && isset($route['market_data']) && is_array($route['market_data'])) {
            $market = $route['market_data'];
            $marketName = (string)($lang === 'mr' ? ($market['name_mr'] ?? $market['name_en'] ?? '') : ($market['name_en'] ?? ''));
            $canonical = $this->canonical($lang);
            return ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'WebPage', '@id' => $canonical . '#webpage',
                    'url' => $canonical, 'name' => $marketName . ' | KrushiPulse',
                    'description' => 'Published mandi price information with source dates and units.',
                    'inLanguage' => $lang === 'mr' ? 'mr-IN' : 'en-IN'],
            ]];
        }
        $data = $this->repo->latest();
        $meta = (array)($data['meta'] ?? []);
        $canonical = $this->canonical($lang);
        $base = rtrim(apmc_absolute_url('/'), '/');
        $description = ['mr' => 'पुणे एपीएमसीमधील दैनंदिन घाऊक शेतमाल बाजारभाव, आवक आणि बाजार हालचालींची माहिती.', 'hi' => 'पुणे एपीएमसी मंडियों में दैनिक थोक कृषि उपज भाव, आवक और बाजार की जानकारी।', 'en' => 'Daily wholesale commodity rates, arrivals and market movement intelligence for Pune APMC markets.'][$lang] ?? 'Daily wholesale commodity rates.';
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    '@id' => $base . '/#website',
                    'url' => $base . '/',
                    'name' => ['mr' => 'पुणे एपीएमसी बाजार', 'hi' => 'पुणे एपीएमसी मंडी भाव', 'en' => 'Pune APMC Market Intelligence'][$lang] ?? 'Pune APMC Market Intelligence',
                    'description' => $description,
                    'inLanguage' => ['en-IN', 'mr-IN', 'hi-IN'],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $canonical . '#webpage',
                    'url' => $canonical,
                    'name' => ['mr' => 'पुणे एपीएमसी बाजारभाव', 'hi' => 'पुणे एपीएमसी मंडी भाव', 'en' => 'Pune APMC Market Rates'][$lang] ?? 'Pune APMC Market Rates',
                    'description' => $description,
                    'isPartOf' => ['@id' => $base . '/#website'],
                    'inLanguage' => ['mr' => 'mr-IN', 'hi' => 'hi-IN', 'en' => 'en-IN'][$lang] ?? 'en-IN',
                ],
                [
                    '@type' => 'Dataset',
                    '@id' => $base . '/#dataset',
                    'name' => ['mr' => 'पुणे एपीएमसी दैनंदिन बाजारभाव डेटा', 'hi' => 'पुणे एपीएमसी दैनिक मंडी भाव डेटा', 'en' => 'Pune APMC Daily Market Rates Dataset'][$lang] ?? 'Pune APMC Daily Market Rates Dataset',
                    'description' => $description,
                    'url' => $canonical,
                    'inLanguage' => ['en-IN', 'mr-IN'],
                    'dateModified' => $meta['serving_date'] ?? null,
                    'isBasedOn' => (string)apmc_config('source_base', 'https://apmcpune.in'),
                    'creator' => [
                        '@type' => 'Organization',
                        'name' => (string)apmc_config('site.publisher_name', 'Pune APMC Market Intelligence'),
                    ],
                    'provider' => [
                        '@type' => 'Organization',
                        'name' => 'Agricultural Produce Market Committee, Pune',
                        'url' => (string)apmc_config('source_base', 'https://apmcpune.in'),
                    ],
                    'citation' => (string)apmc_config('source_base', 'https://apmcpune.in'),
                    'distribution' => [
                        ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => $base . '/api/latest'],
                        ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => $base . '/api/query'],
                    ],
                ],
            ],
        ];
    }
}

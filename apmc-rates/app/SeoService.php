<?php
declare(strict_types=1);

final class SeoService {
    public function __construct(private RateRepository $repo) {}

    public function canonical(string $lang): string {
        $lang = $lang === 'mr' ? 'mr' : 'en';
        return apmc_absolute_url('/?lang=' . $lang);
    }

    public function sitemapXml(): string {
        $latest = $this->repo->latest();
        $modified = (string)($latest['meta']['serving_date'] ?? date('Y-m-d'));
        $en = $this->canonical('en');
        $mr = $this->canonical('mr');
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $alternate = static function(string $en, string $mr) use ($esc): string {
            return '<xhtml:link rel="alternate" hreflang="en-IN" href="' . $esc($en) . '"/>'
                . '<xhtml:link rel="alternate" hreflang="mr-IN" href="' . $esc($mr) . '"/>'
                . '<xhtml:link rel="alternate" hreflang="x-default" href="' . $esc($en) . '"/>';
        };
        $rows = [
            '  <url><loc>' . $esc($en) . '</loc>' . $alternate($en, $mr) . '<lastmod>' . $esc($modified) . '</lastmod><changefreq>daily</changefreq></url>',
            '  <url><loc>' . $esc($mr) . '</loc>' . $alternate($en, $mr) . '<lastmod>' . $esc($modified) . '</lastmod><changefreq>daily</changefreq></url>',
        ];
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n" . implode("\n", $rows) . "\n</urlset>\n";
    }

    public function robotsTxt(): string {
        $sitemap = apmc_absolute_url('/sitemap.xml');
        $base = apmc_app_base_path();
        $prefix = $base === '' ? '' : $base;
        return "User-agent: *\nAllow: {$prefix}/\nDisallow: {$prefix}/config/\nDisallow: {$prefix}/storage/\nDisallow: {$prefix}/collector/\nDisallow: {$prefix}/*?view=admin\n\nSitemap: {$sitemap}\n";
    }

    public function llmsTxt(): string {
        $base = rtrim(apmc_absolute_url('/'), '/');
        return <<<TXT
# Pune APMC Market Intelligence

Public, cached wholesale market-rate intelligence for Pune APMC markets and source feeds in English and Marathi.

## Primary pages
- English: {$base}/?lang=en
- Marathi: {$base}/?lang=mr

## Machine-readable public endpoints
- Latest snapshot: {$base}/api/latest
- Search/query: {$base}/api/query?q=tomato&lang=en&limit=20
- Canonical market list: {$base}/api/markets
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
            'description' => 'Read-only bilingual Pune APMC market-rate retrieval interface for applications, search systems and AI agents.',
            'languages' => ['en', 'mr'],
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
                'description' => 'Read-only public API for bilingual Pune APMC market-rate retrieval. Administrative and collector actions are intentionally excluded.'
            ],
            'servers' => [['url' => $server]],
            'paths' => [
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
                            ['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['en', 'mr'], 'default' => 'en']],
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
                        'parameters' => [['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['en', 'mr'], 'default' => 'en']]],
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
        $data = $this->repo->latest();
        $meta = (array)($data['meta'] ?? []);
        $canonical = $this->canonical($lang);
        $base = rtrim(apmc_absolute_url('/'), '/');
        $description = $lang === 'mr'
            ? 'पुणे एपीएमसीमधील दैनंदिन घाऊक शेतमाल बाजारभाव, आवक आणि बाजार हालचालींची द्विभाषिक माहिती.'
            : 'Bilingual daily wholesale commodity rates, arrivals and market movement intelligence for Pune APMC markets.';
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    '@id' => $base . '/#website',
                    'url' => $base . '/',
                    'name' => $lang === 'mr' ? 'पुणे एपीएमसी बाजार' : 'Pune APMC Market Intelligence',
                    'description' => $description,
                    'inLanguage' => ['en-IN', 'mr-IN'],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $canonical . '#webpage',
                    'url' => $canonical,
                    'name' => $lang === 'mr' ? 'पुणे एपीएमसी बाजारभाव' : 'Pune APMC Market Rates',
                    'description' => $description,
                    'isPartOf' => ['@id' => $base . '/#website'],
                    'inLanguage' => $lang === 'mr' ? 'mr-IN' : 'en-IN',
                ],
                [
                    '@type' => 'Dataset',
                    '@id' => $base . '/#dataset',
                    'name' => $lang === 'mr' ? 'पुणे एपीएमसी दैनंदिन बाजारभाव डेटा' : 'Pune APMC Daily Market Rates Dataset',
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

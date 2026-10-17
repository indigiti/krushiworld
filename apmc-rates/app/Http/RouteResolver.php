<?php
declare(strict_types=1);

namespace KrushiPulse\Http;

/**
 * Pure HTTP route matcher. Does not touch the database, caches or $_GET.
 * Routes are relative to the deployment prefix (/krushiworld or a portable subfolder).
 */
final class RouteResolver {
    public static function resolve(string $requestUri, string $scriptName): array {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return ['kind' => 'not_found'];
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($base === '.' || $base === '/') $base = '';
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) return ['kind' => 'not_found'];
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path === '/' || $path === '/index.php') return ['kind' => 'legacy'];
        // Reject encoded path separators, ambiguity and traversal before matching routes.
        if (str_contains($path, '%') || str_contains($path, '//') || str_contains($path, '..')) return ['kind' => 'not_found'];
        if (preg_match('~^/(en|mr|hi)/?$~D', $path, $m)) return ['kind' => 'home', 'lang' => $m[1]];
        if (preg_match('~^/(en|mr|hi)/mandis/?$~D', $path, $m)) return ['kind' => 'directory', 'lang' => $m[1]];
        if (preg_match('~^/(en|mr|hi)/mandis/([a-z0-9-]{1,110})/?$~D', $path, $m)) {
            return ['kind' => 'mandi', 'lang' => $m[1], 'market' => $m[2]];
        }
        if (preg_match('~^/admin/?$~D', $path)) return ['kind' => 'admin'];
        if (preg_match('~^/api/v1/intelligence/(history|compare|weather)/?$~D', $path, $m)) return ['kind' => 'api', 'api' => 'v1-' . $m[1]];
        if (preg_match('~^/api/v1/(latest|markets|query|summary|health|discover)/?$~D', $path, $m)) {
            return ['kind' => 'api', 'api' => $m[1]];
        }
        if (preg_match('~^/api/v1/mandis/([a-z0-9-]{1,110})/rates/?$~D', $path, $m)) {
            return ['kind' => 'api', 'api' => 'v1-mandi-rates', 'market' => $m[1]];
        }
        // Apache clean-URL aliases and their query-string equivalents are both supported.
        if (preg_match('~^/api/(latest|markets|query|summary|health|discover|market|national)/?$~D', $path, $m)) {
            return ['kind' => 'api', 'api' => $m[1]];
        }
        if (preg_match('~^/(openapi\.json|sitemap\.xml|robots\.txt|llms\.txt|\.well-known/apmc-data-agent\.json)$~D', $path, $m)) {
            return ['kind' => 'seo', 'name' => match($m[1]) {
                'openapi.json' => 'openapi',
                'sitemap.xml' => 'sitemap',
                'robots.txt' => 'robots',
                'llms.txt' => 'llms',
                default => 'agent-manifest',
            }];
        }
        return ['kind' => 'not_found'];
    }
}

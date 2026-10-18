<?php
declare(strict_types=1);

define('APMC_ROOT', dirname(__DIR__));
define('APMC_STORAGE', APMC_ROOT . '/storage');
// DigiOps public entrypoints set this to public_html/krushiworld.
// Keep the original single-folder module deployment fully supported.
if (!defined('APMC_PUBLIC_DIR')) define('APMC_PUBLIC_DIR', APMC_ROOT . '/public');

// Server-only optional persistent settings, never part of public/release files.
require_once __DIR__ . '/PrivateRuntimeSettings.php';
KrushiPulseRuntimeSettings::load(APMC_STORAGE . '/config/runtime.json');

$config = require APMC_ROOT . '/config/app.php';
$versionFile = APMC_ROOT . '/VERSION';
if (is_file($versionFile)) {
    $versionFromFile = trim((string) @file_get_contents($versionFile));
    if ($versionFromFile !== '') $config['version'] = $versionFromFile;
}
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');

require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/RateRepository.php';
require_once __DIR__ . '/NationalMarketRepository.php';
require_once __DIR__ . '/RefreshService.php';
require_once __DIR__ . '/WebCollector.php';
require_once __DIR__ . '/PublicQueryService.php';
require_once __DIR__ . '/SeoService.php';
// HTTP routing -> application use cases -> existing read-only data repositories.
require_once __DIR__ . '/Application/MarketDirectoryService.php';
require_once __DIR__ . '/Application/MarketRatesService.php';
require_once __DIR__ . '/Application/PriceIntelligenceService.php';
require_once __DIR__ . '/Application/WeatherAdvisoryService.php';
require_once __DIR__ . '/Application/MspReferenceService.php';
require_once __DIR__ . '/Application/MarketProfileService.php';
require_once __DIR__ . '/Application/ExplainableInsightService.php';
require_once __DIR__ . '/Http/RouteResolver.php';
require_once __DIR__ . '/Http/PublicApiController.php';
require_once __DIR__ . '/Security/AdminIdentity.php';
require_once __DIR__ . '/Security/AdminAuth.php';
require_once __DIR__ . '/Security/AdminHttp.php';
require_once __DIR__ . '/Security/PrivateAppSettings.php';
require_once __DIR__ . '/Security/OwnerSetup.php';
require_once __DIR__ . '/Security/WebBootstrap.php';

function apmc_auth(): \KrushiPulse\Security\AdminAuth {
    static $auth = null;
    return $auth ??= new \KrushiPulse\Security\AdminAuth();
}


function apmc_config(?string $key = null, mixed $default = null): mixed {
    global $config;
    if ($key === null) return $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function apmc_json_read(string $path, array $fallback = []): array {
    if (!is_file($path)) return $fallback;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $fallback;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

function apmc_i18n(): array {
    static $i18n = null;
    if ($i18n === null) {
        $i18n = apmc_json_read(APMC_ROOT . '/config/i18n.json', [
            'default' => 'en', 'supported' => ['en', 'mr', 'hi'], 'labels' => [],
            'markets' => [], 'market_slugs' => [], 'categories' => [], 'units' => [], 'commodities' => [],
        ]);
    }
    return $i18n;
}

function apmc_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apmc_script_url(): string {
    return $_SERVER['SCRIPT_NAME'] ?? '/index.php';
}

function apmc_public_base(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $filename = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($scriptDir === '.' || $scriptDir === '/') $scriptDir = '';

    // When the web server document root points directly to /public.
    if ($filename && realpath(dirname($filename)) === realpath(APMC_PUBLIC_DIR)) {
        return $scriptDir;
    }

    // When the portable root index.php wrapper is used.
    return $scriptDir . '/public';
}

function apmc_asset(string $file): string {
    return apmc_public_base() . '/build/' . ltrim($file, '/') . '?v=' . rawurlencode((string) apmc_config('version', '1'));
}


function apmc_admin_token_value(): string {
    $value = trim((string) apmc_config('admin_token', ''));
    // v1.0-v1.2 placeholder must never remain a valid production credential.
    if ($value === 'APMC-CHANGE-ME-9K7X2Q') return '';
    return $value;
}

function apmc_admin_cookie_name(): string {
    return 'apmc_admin_session';
}

function apmc_admin_session_value(): string {
    $expected = apmc_admin_token_value();
    if ($expected === '') return '';
    return hash_hmac('sha256', 'apmc-admin-session-v1', $expected);
}

function apmc_admin_supplied_token(): string {
    $header = (string) ($_SERVER['HTTP_X_APMC_TOKEN'] ?? '');
    if ($header !== '') return $header;
    $post = (string) ($_POST['token'] ?? '');
    if ($post !== '') return $post;
    if ((bool) apmc_config('admin.allow_legacy_query_token', false)) {
        return (string) ($_GET['token'] ?? '');
    }
    return '';
}

function apmc_admin_authorized(): bool {
    if (apmc_auth()->enabled()) return apmc_auth()->user() !== null;
    $expected = apmc_admin_token_value();
    if ($expected === '') return false;

    $cookie = (string) ($_COOKIE[apmc_admin_cookie_name()] ?? '');
    $sessionValue = apmc_admin_session_value();
    if ($cookie !== '' && $sessionValue !== '' && hash_equals($sessionValue, $cookie)) return true;

    $supplied = apmc_admin_supplied_token();
    return $supplied !== '' && hash_equals($expected, $supplied);
}

function apmc_set_admin_cookie(bool $enabled = true): void {
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $value = $enabled ? apmc_admin_session_value() : '';
    $cookiePath = rtrim(str_replace('\\', '/', dirname(apmc_script_url())), '/');
    if ($cookiePath === '' || $cookiePath === '.') $cookiePath = '/';
    setcookie(apmc_admin_cookie_name(), $value, [
        'expires' => $enabled ? time() + 28800 : time() - 3600,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function apmc_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function apmc_csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    return $nonce;
}

function apmc_request_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function apmc_origin(): string {
    $configured = trim((string) (getenv('APMC_PUBLIC_ORIGIN') ?: apmc_config('site.origin', '')));
    if ($configured !== '' && preg_match('#^https?://[a-z0-9.-]+(?::\\d+)?$#i', $configured)) return rtrim($configured, '/');
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if (!preg_match('/^[a-z0-9.-]+(?::\\d+)?$/i', $host)) return '';
    return (apmc_request_is_https() ? 'https://' : 'http://') . $host;
}

function apmc_app_base_path(): string {
    $script = str_replace('\\', '/', apmc_script_url());
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return ($dir === '' || $dir === '.') ? '' : $dir;
}

function apmc_absolute_url(string $suffix = ''): string {
    $origin = apmc_origin();
    $base = apmc_app_base_path();
    if ($suffix === '') $suffix = '/';
    if ($suffix[0] !== '/' && $suffix[0] !== '?') $suffix = '/' . $suffix;
    if ($suffix[0] === '?') return $origin . $base . '/' . $suffix;
    return $origin . $base . $suffix;
}

function apmc_brand_slot_settings(string $envPrefix, string $configRoot): array {
    $enabledEnv = getenv($envPrefix . '_ENABLED');
    $enabled = $enabledEnv !== false && $enabledEnv !== ''
        ? filter_var($enabledEnv, FILTER_VALIDATE_BOOLEAN)
        : (bool) apmc_config($configRoot . '.enabled', false);
    $name = trim((string)(getenv($envPrefix . '_NAME') ?: apmc_config($configRoot . '.name', '')));
    $url = trim((string)(getenv($envPrefix . '_URL') ?: apmc_config($configRoot . '.url', '')));
    $logo = trim((string)(getenv($envPrefix . '_LOGO') ?: apmc_config($configRoot . '.logo', '')));

    // Keep sponsor imagery local to avoid expanding img-src and leaking visitor requests
    // to arbitrary advertiser hosts. Only WebP assets are accepted.
    $logoUrl = '';
    if ($logo !== '') {
        $base = basename(str_replace('\\', '/', $logo));
        if (preg_match('/^[A-Za-z0-9._-]+\.webp$/', $base)) {
            $path = APMC_PUBLIC_DIR . '/media/sponsors/' . $base;
            if (is_file($path)) $logoUrl = apmc_public_base() . '/media/sponsors/' . rawurlencode($base);
        }
    }

    if ($url !== '' && !preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?(?:/|$)#', $url)) $url = '';
    $initials = '';
    if ($name !== '') {
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice($parts, 0, 2) as $part) {
            if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
            } else {
                $initials .= strtoupper(substr($part, 0, 1));
            }
        }
    }

    return [
        'enabled' => $enabled && $name !== '',
        'name' => $name,
        'url' => $url,
        'logo_url' => $logoUrl,
        'initials' => $initials,
    ];
}

function apmc_sponsor_settings(): array {
    return apmc_brand_slot_settings('APMC_SPONSOR', 'sponsor');
}

function apmc_cosponsor_settings(): array {
    return apmc_brand_slot_settings('APMC_COSPONSOR', 'co_sponsor');
}

function apmc_page_language(): string {
    $query = strtolower((string)($_GET['lang'] ?? ''));
    if (in_array($query, ['en', 'mr', 'hi'], true)) return $query;
    $cookie = strtolower((string)($_COOKIE['apmc_language'] ?? ''));
    return in_array($cookie, ['en', 'mr', 'hi'], true) ? $cookie : 'mr';
}

function apmc_admin_csrf_token(): string {
    if (apmc_auth()->enabled()) return apmc_auth()->csrf();
    $secret = apmc_admin_token_value();
    if ($secret === '') return '';
    return hash_hmac('sha256', 'apmc-admin-csrf-v1', $secret);
}

function apmc_admin_csrf_valid(): bool {
    if (apmc_auth()->enabled())
        return apmc_auth()->validCsrf((string)($_SERVER['HTTP_X_APMC_CSRF'] ?? ($_POST['_csrf'] ?? '')));
    $expected = apmc_admin_csrf_token();
    if ($expected === '') return false;
    $provided = (string)($_SERVER['HTTP_X_APMC_CSRF'] ?? ($_POST['_csrf'] ?? ''));
    return $provided !== '' && hash_equals($expected, $provided);
}

function apmc_same_origin_request(): bool {
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) return false;
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return true;
    $expected = apmc_origin();
    return $expected !== '' && hash_equals(strtolower($expected), strtolower(rtrim($origin, '/')));
}

function apmc_apply_security_headers(bool $admin = false): void {
    $nonce = apmc_csp_nonce();
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(self), payment=(), usb=(), browsing-topics=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Origin-Agent-Cluster: ?1');
    if (apmc_request_is_https()) header('Strict-Transport-Security: max-age=31536000');
    $csp = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net/npm/@alpinejs/csp@3.17.3/dist/cdn.min.js",
        "style-src 'self' https://fonts.googleapis.com",
        "style-src-elem 'self' https://fonts.googleapis.com",
        "style-src-attr 'unsafe-inline'",
        "font-src https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "manifest-src 'self'",
        "worker-src 'self'",
        "upgrade-insecure-requests",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
    if ($admin) header('X-Robots-Tag: noindex, nofollow, noarchive', true);
}

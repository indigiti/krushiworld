<?php
declare(strict_types=1);

/**
 * Local-only PHP built-in web-server router for Cloudways-style integration tests.
 * Apache mod_rewrite handles these routes in production.
 */
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$publicRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if (!is_string($path)) return false;
if (is_file($publicRoot . $path)) return false;

$prefix = '/krushiworld';
if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) return false;
$relative = substr($path, strlen($prefix));
if ($relative !== '' && $relative !== '/' && !preg_match(
    '~^/(?:en|mr|hi)(?:/mandis(?:/[a-z0-9-]{1,110})?)?/?$|^/admin/?$|^/api/(?:v1/)?(?:latest|markets|query|summary|health|discover|market|national)/?$|^/api/v1/intelligence/(?:history|compare)/?$|^/api/v1/mandis/[a-z0-9-]{1,110}/rates/?$|^/(?:openapi\.json|sitemap\.xml|robots\.txt|llms\.txt|\.well-known/apmc-data-agent\.json)$~D',
    $relative
)) return false;
$entry = $publicRoot . $prefix . '/index.php';
if (!is_file($entry)) return false;
$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $entry;
require $entry;
return true;

<?php
declare(strict_types=1);
/** Verify exact artifact layout and prevent accidental public exposure of private source. */
$root = dirname(__DIR__);
$base = $root . '/release';
function assertRelease(bool $ok, string $description): void {
    if (!$ok) { fwrite(STDERR, 'FAIL: ' . $description . "\n"); exit(1); }
}
$metadata = json_decode((string) @file_get_contents($base . '/RELEASE.json'), true);
assertRelease(is_array($metadata) && ($metadata['schema'] ?? '') === 'DIGIOPS-RELEASE/1', 'Valid release metadata');
assertRelease(($metadata['sourceSha'] ?? '') === (getenv('GITHUB_SHA') ?: 'local'), 'Exact source SHA');
assertRelease(($metadata['publicPath'] ?? '') === 'public_html/krushiworld/', 'Public target');
assertRelease(($metadata['privatePath'] ?? '') === 'private_html/krushiworld/', 'Private target');
assertRelease(($metadata['persistentPaths'] ?? []) === ['storage/'], 'Storage persistence contract');
$required = [
    'public/index.php', 'public/api.php', 'public/.htaccess',
    'public/build/krushipulse.css', 'public/build/krushipulse.js',
    'public/build/commodity-icons.js',
    'public/build/nearest-mandi.js',
    'private/app/bootstrap.php', 'private/app/NationalMarketRepository.php', 'private/app/views/home.php',
    'private/app/Http/RouteResolver.php', 'private/app/Http/PublicApiController.php',
    'private/app/Application/MarketDirectoryService.php', 'private/app/Application/MarketRatesService.php',
    'private/app/Application/PriceIntelligenceService.php',
    'private/config/app.php', 'private/config/i18n.json', 'private/config/market_coordinates.json',
    'private/config/national_market_coordinates.json', 'private/config/postal_market_references.json',
    'private/collector/refresh.py', 'private/collector/national.py', 'private/collector/history.py', 'private/collector/import_market_geocodes.py', 'private/collector/config.json',
    'private/collector/commodity_map.json', 'private/bin/server-refresh.sh',
    'private/VERSION', 'private/build/release.json',
];
foreach ($required as $file) assertRelease(is_file($base . '/' . $file), 'Required file: ' . $file);
foreach (['public/app', 'public/config', 'public/collector', 'public/storage', 'private/storage', 'public/src.php'] as $path) {
    assertRelease(!file_exists($base . '/' . $path), 'No private/persistent payload: ' . $path);
}
$boot = (string) file_get_contents($base . '/public/index.php');
assertRelease(str_contains($boot, "private_html/krushiworld/app/bootstrap.php"), 'Entry point uses sibling private root');
assertRelease(str_contains((string)file_get_contents($base.'/private/app/bootstrap.php'), "if (!defined('APMC_PUBLIC_DIR'))"), 'Public path override supported');
$allowedRoot = realpath($base);
assertRelease($allowedRoot !== false, 'Release root exists');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $item) {
    $path = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
    assertRelease(!$item->isLink(), 'No symlink: ' . $path);
    assertRelease(!preg_match('~(^|/)(?:\.env|\.git|__pycache__|\.venv|node_modules)(?:/|$)|\.(?:pem|key|log|lock|pyc)$~i', $path), 'No secrets or ephemeral files: ' . $path);
}
assertRelease(trim((string) file_get_contents($base.'/private/VERSION')) === ($metadata['version'] ?? ''), 'Matching version');
$privateMetadata = json_decode((string)file_get_contents($base.'/private/build/release.json'), true);
assertRelease($privateMetadata === $metadata, 'Private metadata matches release');
echo "DigiOps release payload: PASS\n";
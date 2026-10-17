<?php
declare(strict_types=1);
/**
 * Build KrushiPulse into the layout required by DigiOps:
 * release/public/*  -> public_html/krushiworld/
 * release/private/* -> private_html/krushiworld/
 * Never ship mutable storage, credential files or runtime logs.
 */
$root = dirname(__DIR__);
$module = $root . '/apmc-rates';
$release = $root . '/release';

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
function cleanTree(string $path): void {
    if (is_link($path)) fail('Refusing to remove a symlink: ' . $path);
    if (!file_exists($path)) return;
    if (is_file($path)) { if (!unlink($path)) fail('Failed to remove file'); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) cleanTree($item->getPathname());
    if (!rmdir($path)) fail('Failed to remove directory');
}
function ensureDir(string $path): void {
    if (is_link($path)) fail('Refusing symlink directory: ' . $path);
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) fail('Could not create: ' . $path);
}
function copySafe(string $from, string $to): void {
    if (is_link($from)) fail('Refusing symlink in release: ' . $from);
    if (is_dir($from)) {
        ensureDir($to);
        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
            $name = $item->getFilename();
            if (in_array($name, ['__pycache__', '.venv', 'node_modules', '.git', '.pytest_cache'], true)) continue;
            copySafe($item->getPathname(), $to . '/' . $name);
        }
        return;
    }
    if (!is_file($from)) fail('Required source missing: ' . $from);
    if (preg_match('/(?:\.pyc|\.pyo|\.log|\.lock|\.pem|\.key|\.env)$/i', $from)) fail('Private runtime/secret file in static source tree: ' . $from);
    ensureDir(dirname($to));
    if (!copy($from, $to)) fail('Failed to copy: ' . $from);
}
function replaceExactly(string $path, string $before, string $after): void {
    $content = file_get_contents($path);
    if ($content === false || substr_count($content, $before) !== 1) fail('Unexpected bootstrap structure in: ' . $path);
    if (file_put_contents($path, str_replace($before, $after, $content)) === false) fail('Could not modify: ' . $path);
}
foreach (['public/index.php', 'public/.htaccess', 'app/bootstrap.php', 'collector/refresh.py', 'config/app.php', 'VERSION'] as $file) {
    if (!is_file($module . '/' . $file)) fail('Missing required module file: ' . $file);
}
if (is_link($release)) fail('Release directory must not be a symlink');
cleanTree($release);
ensureDir($release . '/public');
ensureDir($release . '/private');
copySafe($module . '/public', $release . '/public');
foreach (['app', 'config', 'collector', 'bin'] as $dir) copySafe($module . '/' . $dir, $release . '/private/' . $dir);
foreach (['VERSION', 'requirements.txt'] as $file) copySafe($module . '/' . $file, $release . '/private/' . $file);

// Only public executable entrypoints are deployed. Application code is NOT served by the web server.
// Both folders are siblings beneath the SAME Cloudways application root.
$privateBootstrap = "define('APMC_PUBLIC_DIR', __DIR__);\nrequire dirname(__DIR__, 2) . '/private_html/krushiworld/app/bootstrap.php';";
replaceExactly($release . '/public/index.php', "require dirname(__DIR__) . '/app/bootstrap.php';", $privateBootstrap);
replaceExactly($release . '/public/index.php', "require dirname(__DIR__) . '/app/views/admin.php';", "require APMC_ROOT . '/app/views/admin.php';");
replaceExactly($release . '/public/index.php', "require dirname(__DIR__) . '/app/views/home.php';", "require APMC_ROOT . '/app/views/home.php';");
copySafe($module . '/api.php', $release . '/public/api.php');
replaceExactly($release . '/public/api.php', "require __DIR__ . '/app/bootstrap.php';", $privateBootstrap);
replaceExactly($release . '/public/api.php', "__DIR__ . '/collector/config.json'", "APMC_ROOT . '/collector/config.json'");

$version = trim((string) file_get_contents($module . '/VERSION'));
if (!preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9.-]+)?$/', $version)) fail('Invalid module version');
$metadata = [
    'schema' => 'DIGIOPS-RELEASE/1',
    'name' => 'KrushiWorld KrushiPulse',
    'version' => $version,
    'builtAt' => gmdate(DATE_ATOM),
    'sourceSha' => (string) (getenv('GITHUB_SHA') ?: 'local'),
    'branch' => (string) (getenv('GITHUB_REF_NAME') ?: 'local'),
    'ciRunNumber' => (string) (getenv('GITHUB_RUN_NUMBER') ?: ''),
    'ciRunId' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'ciRunAttempt' => (string) (getenv('GITHUB_RUN_ATTEMPT') ?: ''),
    'public' => 'public',
    'private' => 'private',
    'publicPath' => 'public_html/krushiworld/',
    'privatePath' => 'private_html/krushiworld/',
    'persistentPaths' => ['storage/'],
];
$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($release . '/RELEASE.json', $json) === false) fail('Could not write release metadata');
ensureDir($release . '/private/build');
if (file_put_contents($release . '/private/build/release.json', $json) === false) fail('Could not write private build metadata');
echo 'KrushiPulse DigiOps release prepared: ' . $version . ' ('. $metadata['sourceSha'] . ")\n";
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/PrivateRuntimeSettings.php';
function check_setting(bool $valid, string $label): void {
    if (!$valid) { fwrite(STDERR, "Runtime configuration regression: {$label}\n"); exit(1); }
}
$base = sys_get_temp_dir() . '/kp-private-' . bin2hex(random_bytes(8));
mkdir($base, 0700, true);
mkdir($base . '/config', 0700, true);
$path = $base . '/config/runtime.json';
register_shutdown_function(static function () use ($base, $path) {
    @unlink($path); @rmdir($base . '/config'); @rmdir($base);
});
putenv('APMC_AUTH_MODE');
putenv('APMC_DATA_GOV_IN_API_KEY');
putenv('APMC_PUBLIC_ORIGIN');
file_put_contents($path, json_encode([
    'APMC_AUTH_MODE' => 'staging',
    'APMC_PUBLIC_ORIGIN' => 'https://stage.digiti.in',
    'APMC_DATA_GOV_IN_API_KEY' => str_repeat('a', 40),
]));
chmod($path, 0600);
KrushiPulseRuntimeSettings::load($path);
check_setting(KrushiPulseRuntimeSettings::get('APMC_AUTH_MODE') === 'staging', 'private staging mode');
check_setting(KrushiPulseRuntimeSettings::get('APMC_DATA_GOV_IN_API_KEY') === str_repeat('a', 40), 'server-only key');
putenv('APMC_AUTH_MODE=production');
KrushiPulseRuntimeSettings::load($path);
check_setting(KrushiPulseRuntimeSettings::get('APMC_AUTH_MODE') === 'production', 'server environment precedence');
file_put_contents($path, '{"APMC_AUTH_MODE":"invalid"}'); chmod($path, 0600);
$failed = false;
try { KrushiPulseRuntimeSettings::load($path); } catch (RuntimeException) { $failed = true; }
check_setting($failed, 'reject unsafe auth mode');
file_put_contents($path, '{"APMC_AUTH_MODE":"staging"}'); chmod($path, 0644);
$failed = false;
try { KrushiPulseRuntimeSettings::load($path); } catch (RuntimeException) { $failed = true; }
check_setting($failed, 'reject world-readable secret file');
echo "Private runtime configuration tests: PASS\n";

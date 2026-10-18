<?php
declare(strict_types=1);
/** This test MUST be run with: php -d disable_functions=putenv */
require dirname(__DIR__) . '/app/PrivateRuntimeSettings.php';
function assert_runtime(bool $ok,string $why):void {
    if (!$ok) {fwrite(STDERR,"CLOUDWAYS RUNTIME: $why\n");exit(1);}
}
assert_runtime(!function_exists('putenv'),'simulate Cloudways disabled putenv');
$base = sys_get_temp_dir().'/krushipulse-disabled-env-'.bin2hex(random_bytes(8));
assert_runtime(mkdir($base,0700,true),'temporary storage available');
mkdir($base.'/config',0700);
$file=$base.'/config/runtime.json';
register_shutdown_function(static function () use ($base,$file) {
    @unlink($file);@rmdir($base.'/config');@rmdir($base);
});
$authKey=str_repeat('a',64);
$dataKey=str_repeat('b',40);
$json=json_encode(['APMC_AUTH_KEY'=>$authKey,'APMC_DATA_GOV_IN_API_KEY'=>$dataKey], JSON_THROW_ON_ERROR);
assert_runtime(file_put_contents($file,$json)!==false,'private file creation');
chmod($file,0600);
KrushiPulseRuntimeSettings::load($file);
assert_runtime(KrushiPulseRuntimeSettings::get('APMC_AUTH_KEY')===$authKey,'MFA key readable without putenv');
assert_runtime(KrushiPulseRuntimeSettings::get('APMC_DATA_GOV_IN_API_KEY')===$dataKey,'national API key readable without putenv');
assert_runtime(KrushiPulseRuntimeSettings::get('UNSET_NOT_IN_PRIVATE')===false,'missing value stays missing');
file_put_contents($file,'{invalid json');
$failed=false;
try {KrushiPulseRuntimeSettings::load($file);} catch(RuntimeException){$failed=true;}
assert_runtime($failed,'invalid JSON rejected');
file_put_contents($file,$json);
chmod($file,0644);
$failed=false;
try {KrushiPulseRuntimeSettings::load($file);} catch(RuntimeException){$failed=true;}
assert_runtime($failed,'world-readable settings rejected');
echo "Cloudways disabled putenv compatibility: PASS\n";

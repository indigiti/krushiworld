<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use KrushiPulse\Security\AdminAuth;
use KrushiPulse\Security\AdminIdentity;
use KrushiPulse\Security\WebBootstrap;
function check(bool $ok,string $msg): void { if (!$ok) {fwrite(STDERR, "WEB BOOTSTRAP: " . $msg . "\n");exit(1);} }
$tmp=APMC_STORAGE . '/auth/web-bootstrap.json';
@mkdir(dirname($tmp),0700,true);
if (is_file($tmp)) { echo "Skipping grant test: existing runtime grant (not a test environment)\n"; exit(0); }
register_shutdown_function(static function () use($tmp) { @unlink($tmp); });
$grant=bin2hex(random_bytes(32));
file_put_contents($tmp,json_encode(['sha256'=>hash('sha256',$grant),'used'=>false,'expires_at'=>time()+600]));
chmod($tmp,0600);
$file=tempnam(sys_get_temp_dir(),'kp-bootstrap-');
register_shutdown_function(static function () use($file) { @unlink($file); });
putenv('APMC_AUTH_MODE=staging');
putenv('APMC_AUTH_KEY='.str_repeat('dc',32));
$db=new AdminIdentity($file);
$auth=new AdminAuth($db);
$invalid=false;
try { WebBootstrap::enroll($auth,str_repeat('e',64),'aliceAdm','VerySecurePassword_1234'); }
catch(RuntimeException){ $invalid=true; }
check($invalid,'wrong grant rejected');
check(!$db->localReady(),'no local admin created');
$created=WebBootstrap::enroll($auth,$grant,'aliceAdm','VerySecurePassword_1234');
check($created['username']==='aliceAdm','pending account created');
check($db->userByName('aliceAdm')['status']==='pending_mfa','user pending MFA');
check(strlen($created['secret'])===32 && count($created['recovery'])===8,'one-time factors returned');
$replay=false;
try { WebBootstrap::enroll($auth,$grant,'otherAdm','VerySecurePassword_5678'); }
catch(RuntimeException){ $replay=true; }
check($replay,'same grant replay denied');
echo "One-time web bootstrap security: PASS\n";

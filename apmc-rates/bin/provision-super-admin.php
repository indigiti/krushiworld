<?php
declare(strict_types=1);
/**
 * Isolated, explicit production bootstrap. NEVER expose as a web-accessible route.
 * Run with Cloudways CLI, server-side env secrets and an interactive terminal.
 */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit(1);}
require dirname(__DIR__) . '/app/bootstrap.php';
use KrushiPulse\Security\AdminAuth;

if (!in_array((string)getenv('APMC_AUTH_MODE'),['staging','production'],true)) {
    fwrite(STDERR,"Configure APMC_AUTH_MODE before provisioning.\n");exit(1);
}
$name=(string)($argv[1]??'');
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/D',$name)) {
    fwrite(STDERR,"Usage: php bin/provision-super-admin.php <new-username>\n");exit(1);
}
if (!function_exists('sodium_crypto_secretbox')) {fwrite(STDERR,"PHP sodium required.\n");exit(1);}
$auth=apmc_auth();
try {
    $db=$auth->store();
    if ($db->localReady()) {
        fwrite(STDERR,"An active, verified local Super Admin exists. Manage additional accounts in the dashboard.\n");exit(1);
    }
    if (!posix_isatty(STDIN)) {fwrite(STDERR,"An interactive private terminal is required.\n");exit(1);}
    fwrite(STDERR,"Provision local Super Admin '$name' (account remains pending until MFA login).\nPassword (14+ characters): ");
    if (function_exists('shell_exec'))@shell_exec('stty -echo');
    $password=trim((string)fgets(STDIN));
    if (function_exists('shell_exec'))@shell_exec('stty echo');
    fwrite(STDERR,"\n");
    if (strlen($password)<14||strlen($password)>256)throw new RuntimeException('Password must be between 14 and 256 characters');
    $secret=AdminAuth::totpNew();
    $id=$db->createLocal($name,$password,$auth->seal($secret),'super_admin',0);
    $codes=[];
    for($i=0;$i<8;$i++){$hex=strtoupper(bin2hex(random_bytes(8)));$codes[]=substr($hex,0,8).'-'.substr($hex,8,8);}
    $db->setRecovery($id,$codes);
    fwrite(STDOUT,"Local administrator created, pending first MFA verification.\n");
    fwrite(STDOUT,"Authenticator setup key: $secret\n");
    fwrite(STDOUT,"Recovery codes (save now; never displayed again):\n".implode("\n",$codes)."\n");
    fwrite(STDOUT,"Open /admin/, sign in with this username, password and a new authenticator code.\n");
    // Best-effort reduction of in-process password lifetime.
    $password=str_repeat("\0",strlen($password));
} catch(Throwable $e) {
    if (function_exists('shell_exec'))@shell_exec('stty echo');
    fwrite(STDERR,"Provisioning rejected. Check private storage, unique username and APMC_AUTH_KEY.\n");exit(1);
}

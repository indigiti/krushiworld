<?php
declare(strict_types=1);
putenv('APMC_OWNER_SETUP_TEST=1');
putenv('APMC_AUTH_MODE=staging');
putenv('APMC_AUTH_KEY');
require dirname(__DIR__) . '/app/bootstrap.php';

use KrushiPulse\Security\AdminAuth;
use KrushiPulse\Security\AdminIdentity;
use KrushiPulse\Security\OwnerSetup;
use KrushiPulse\Security\PrivateAppSettings;

function direct_assert(bool $yes,string $label):void {
    if (!$yes){fwrite(STDERR,"DIRECT SETUP FAIL: $label\n");exit(1);}
}
$root=sys_get_temp_dir().'/kp-direct-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
$database=new AdminIdentity($root.'/auth/identity.sqlite');
$auth=new AdminAuth($database);
direct_assert(!$database->hasLocalAccounts(),'initial state');
$denied=false;
try {
    OwnerSetup::enroll($auth,'fake','ownerUser','AReallyLongPassphrase!23',static fn()=>false,$root);
} catch (RuntimeException) {$denied=true;}
direct_assert($denied && !$database->hasLocalAccounts(),'unverified token rejected');
$created=OwnerSetup::enroll($auth,'test-verification-token','ownerUser','AReallyLongPassphrase!23',static fn()=>true,$root);
direct_assert(strlen($created['secret'])===32 && count($created['recovery'])===8,'MFA setup generated');
direct_assert($database->hasLocalAccounts(),'only authenticated setup creates account');
direct_assert($database->userByName('ownerUser')['status']==='pending_mfa','MFA required');
direct_assert(preg_match('/^[a-f0-9]{64}$/',PrivateAppSettings::ensureAuthKey($root))===1,'private key created');
$again=false;
try { OwnerSetup::enroll($auth,'another','otherUser','AReallyLongPassphrase!23',static fn()=>true,$root); }
catch (RuntimeException) {$again=true;}
direct_assert($again,'cannot create second first-owner');
PrivateAppSettings::saveNationalKey($root,str_repeat('a',40));
direct_assert(PrivateAppSettings::hasNationalKey($root),'national key stored');
$settings=$root.'/config/runtime.json';
direct_assert((fileperms($settings)&0077)===0,'owner-only permissions');
direct_assert(str_contains(file_get_contents($settings),'APMC_DATA_GOV_IN_API_KEY'),'persisted for collector');
$bad=false;
try { PrivateAppSettings::saveNationalKey($root,'bad'); } catch (RuntimeException) {$bad=true;}
direct_assert($bad,'invalid keys rejected');
putenv('APMC_AUTH_MODE=production');
$blocked=false;
try { OwnerSetup::enroll($auth,'test','someone','AReallyLongPassphrase!23',static fn()=>true,$root); } catch (RuntimeException) {$blocked=true;}
direct_assert($blocked,'production blocks enrollment');
echo "Direct KrushiPulse browser setup: PASS\n";

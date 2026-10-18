<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Security/TemporarySetupCsrf.php';
use KrushiPulse\Security\TemporarySetupCsrf;
function checkTemp(bool $ok,string $desc): void {
    if (!$ok) { fwrite(STDERR,"TEMP CSRF FAIL: $desc\n"); exit(1); }
}
$key=bin2hex(random_bytes(32));
$now=1792300000;
$token=TemporarySetupCsrf::mint($key,$now);
checkTemp(TemporarySetupCsrf::validate($token,$token,$key,$now+30),'valid signed double-submit');
checkTemp(!TemporarySetupCsrf::validate($token,'',$key,$now),'missing cookie rejected');
checkTemp(!TemporarySetupCsrf::validate($token,$token,bin2hex(random_bytes(32)),$now),'wrong secret rejected');
checkTemp(!TemporarySetupCsrf::validate($token,$token,$key,$now+901),'expired token rejected');
checkTemp(!TemporarySetupCsrf::validate($token.'a',$token,$key,$now),'modified token rejected');
checkTemp(!TemporarySetupCsrf::validate('','',$key,$now),'empty form and cookie rejected');
echo "Temporary data.gov.in setup signed CSRF: PASS\n";

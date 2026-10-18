<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Security/TemporarySetupCsrf.php';
use KrushiPulse\Security\TemporarySetupCsrf;
function checkTemp(bool $ok,string $desc): void {
    if (!$ok) { fwrite(STDERR,"TEMP CSRF FAIL: $desc\n"); exit(1); }
}
$token=TemporarySetupCsrf::mint();
checkTemp(strlen($token)===64, 'cryptographically random browser token');
checkTemp(TemporarySetupCsrf::validate($token,$token), 'matching form and first-party cookie');
checkTemp(!TemporarySetupCsrf::validate($token,''), 'missing cookie rejected');
checkTemp(!TemporarySetupCsrf::validate($token,bin2hex(random_bytes(32))), 'different cookie rejected');
checkTemp(!TemporarySetupCsrf::validate($token.'a',$token), 'modified form token rejected');
checkTemp(!TemporarySetupCsrf::validate('',''), 'empty cookie and form rejected');
echo "Temporary data.gov.in cookie CSRF: PASS\n";

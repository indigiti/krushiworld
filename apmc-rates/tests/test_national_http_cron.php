<?php
declare(strict_types=1);
/** Deterministic tests: no internet, no shell commands, no credentials. */
final class KrushiPulseRuntimeSettings {
    public static string $apiKey = 'example-key-not-a-real-secret';
    public static function get(string $name): string|false {
        if ($name === 'APMC_DATA_GOV_IN_API_KEY') return self::$apiKey;
        return false;
    }
}
require dirname(__DIR__) . '/app/Application/NationalHttpCron.php';

function checkCron(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "HTTP CRON FAILED: $label\n"); exit(1); }
}
function wipeCron(string $root): void {
    if (is_dir($root)) {
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir()) wipeCron($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
}
function fakeRow(string $commodity): array {
    return [
        'state'=>'Maharashtra', 'district'=>'Nashik', 'market'=>'Lasalgaon',
        'commodity'=>$commodity, 'variety'=>'Red', 'grade'=>'FAQ',
        'arrival_date'=>'15/10/2026', 'min_price'=>1000,
        'max_price'=>1500, 'modal_price'=>1200,
    ];
}
$root=sys_get_temp_dir().'/kp-http-cron-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
register_shutdown_function(static function() use($root):void {wipeCron($root);});
$now=time();
$seen=[];
$fetch=static function(int $offset,int $limit) use (&$seen):array {
    $seen[]=$offset;
    if ($offset===0) return ['total'=>1001,'records'=>array_fill(0,$limit,fakeRow('Onion'))];
    if ($offset===1000) return ['total'=>1001,'records'=>[fakeRow('Tomato')]];
    throw new RuntimeException('unexpected offset');
};
// Sanitized transport diagnostics must never return a secret or request URL.
checkCron(NationalHttpCron::classifyHttp(401)==='upstream_http_401','401 classification');
checkCron(NationalHttpCron::classifyHttp(403)==='upstream_http_403','403 classification');
checkCron(NationalHttpCron::classifyHttp(429)==='upstream_http_429','429 classification');
checkCron(NationalHttpCron::classifyHttp(503)==='upstream_http_5xx','5xx classification');
checkCron(NationalHttpCron::classifyHttp(302)==='upstream_redirect_rejected','redirect classification');
checkCron(NationalHttpCron::classifyCurl(6)==='network_dns_failure','DNS classification');
checkCron(NationalHttpCron::classifyCurl(28)==='network_timeout','timeout classification');
checkCron(NationalHttpCron::classifyCurl(60)==='network_certificate_failure','TLS certificate classification');
checkCron(NationalHttpCron::classifyCurl(99)==='network_transport_failure','unexpected curl error redacted');
$cron=new NationalHttpCron($root,$fetch);
KrushiPulseRuntimeSettings::$apiKey='';
checkCron($cron->tick($now)['status']==='not_configured','key absence');
KrushiPulseRuntimeSettings::$apiKey='example-key-not-a-real-secret';
$first=$cron->tick($now);
checkCron($first['status']==='importing' && $first['offset']===1000,'first page staged');
checkCron(!is_file($root.'/national/current.json'),'no unverified partial publish');
checkCron($cron->tick($now+5)['status']==='throttled','duplicate URL hit is throttled');
checkCron($seen===[0],'no second upstream fetch during throttle');
$final=$cron->tick($now+60);
checkCron($final['status']==='verified' && $final['records']===2,'final page verified and deduplicated');
checkCron($seen===[0,1000],'exactly two upstream requests');
$verified=json_decode(file_get_contents($root.'/national/verified.json'),true);
checkCron($verified['verified']===true && $verified['records']===2,'verification flag written');
$published=json_decode(file_get_contents($root.'/national/current.json'),true);
checkCron(count($published['records'])===2,'snapshot contains all verified rows only');
checkCron((fileperms($root.'/national/current.json')&0077)===0,'published snapshot remains private');
checkCron($cron->tick($now+120)['status']==='fresh','fresh success suppresses repeat upstream fetch');
checkCron($seen===[0,1000],'freshness skip uses no upstream requests');

// Attempt a later failed source response; preserve last-good snapshot.
$failRoot=sys_get_temp_dir().'/kp-http-cron-fail-'.bin2hex(random_bytes(6));
mkdir($failRoot,0700,true);
register_shutdown_function(static function()use($failRoot):void{wipeCron($failRoot);});
$bad=new NationalHttpCron($failRoot,static fn(int $offset,int $limit):array => ['total'=>1001,'records'=>[fakeRow('Onion')]]);
checkCron($bad->tick($now)['status']==='failed','incomplete pagination fails closed');
checkCron(!is_file($failRoot.'/national/current.json'),'failed batch never publishes');
checkCron($bad->tick($now+70)['status']==='cooldown','failure retry cooldown');
echo "HTTP national URL cron: throttling, full verification, last-good preservation PASS\n";

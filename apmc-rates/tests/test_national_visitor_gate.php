<?php
declare(strict_types=1);
final class KrushiPulseRuntimeSettings {
    public static string $value = 'a-test-key-that-is-not-a-secret';
    public static function get(string $name): string {return self::$value;}
}
require dirname(__DIR__) . '/app/Application/NationalRefreshGate.php';
use KrushiPulse\Application\NationalRefreshGate;
function ensure(bool $condition,string $why):void {
    if (!$condition){fwrite(STDERR,"NATIONAL VISITOR GATE: $why\n");exit(1);}
}
$root=sys_get_temp_dir().'/krushipulse-national-gate-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
$now=1792300000;
$gate=new NationalRefreshGate($root,21600);
KrushiPulseRuntimeSettings::$value='';
ensure($gate->visit($now)['status']==='not_configured','missing API key never queues');
KrushiPulseRuntimeSettings::$value='configured-but-private';
$first=$gate->visit($now);
ensure($first['status']==='queued' && $first['queued']===true,'first visit queues');
ensure($gate->visit($now)['status']==='already_queued','second visit does not queue');
$path=$root.'/national/refresh-request.json';
ensure(is_file($path),'private flag created');
ensure((fileperms($path)&0077)===0,'flag permissions are private');
file_put_contents($root.'/national/last_attempt.json',json_encode(['attempted_at'=>gmdate(DATE_ATOM,$now)]));
ensure($gate->visit($now+60)['status']==='recent_attempt','backend retry cooldown honored');
file_put_contents($root.'/national/current.json',str_repeat('x',150));
file_put_contents($root.'/national/directory.json',str_repeat('y',110));
file_put_contents($root.'/national/verified.json',json_encode([
 'verified'=>true,'verified_at'=>gmdate(DATE_ATOM,$now),'records'=>12
]));
ensure($gate->visit($now+100)['status']==='verified_fresh','verified flag suppresses refresh');
$last=$gate->visit($now+21601);
ensure($last['status']==='queued' && $last['queued'],'expired verified flag queues new cycle');
foreach(glob($root.'/national/*') as $file){unlink($file);}
rmdir($root.'/national');rmdir($root);
echo "National visitor refresh gate: PASS\n";

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use KrushiPulse\Application\PriceIntelligenceService;
function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
check(class_exists(SQLite3::class), 'PHP SQLite3 is installed for historical charts');
$file = tempnam(sys_get_temp_dir(), 'krushi-history-');
check($file !== false, 'Temporary private test DB');
register_shutdown_function(static fn() => @unlink($file));
$db = new SQLite3($file);
$db->exec('CREATE TABLE prices(source TEXT,market_id TEXT,commodity_key TEXT,commodity_name TEXT,
 variety TEXT,grade TEXT,unit TEXT,report_date TEXT,min_price REAL,max_price REAL,modal_price REAL)');
$day = gmdate('Y-m-d', strtotime('-2 days'));
$other = gmdate('Y-m-d', strtotime('-6 days'));
$insert = $db->prepare('INSERT INTO prices VALUES (:s,:m,:k,:n,:v,:g,:u,:d,:lo,:hi,:md)');
$rows = [
 ['Pune APMC','main-market-yard','onion','Onion','Red','FAQ','kg',$other,45,55,50],
 ['Pune APMC','main-market-yard','onion','Onion','Red','FAQ','kg',$day,60,80,70],
 ['Pune APMC','manjari','onion','Onion','Red','FAQ','kg',$day,67,91,80],
 ['Pune APMC','moshi','onion','Onion','White','FAQ','kg',$day,80,110,95],
 ['Pune APMC','pimpri','onion','Onion','Red','FAQ','quintal',$day,900,1300,1100],
];
foreach ($rows as $values) {
    foreach (['s','m','k','n','v','g','u','d','lo','hi','md'] as $i => $key) {
        $value = $values[$i];
        $insert->bindValue(':' . $key,$value,is_numeric($value) && $i >= 8 ? SQLITE3_FLOAT : SQLITE3_TEXT);
    }
    $out = $insert->execute();
    check($out instanceof SQLite3Result, 'Insert test observation');
    $out->finalize();
}
$insert->close();$db->close();
$service = new PriceIntelligenceService($file);
$query=['market'=>'main-market-yard','commodity'=>'onion','variety'=>'Red','grade'=>'FAQ','unit'=>'kg','days'=>'30'];
$history=$service->history($query);
check($history['available'] && $history['has_trend'], 'Two real observations create a chart series');
check(count($history['points']) === 2, 'Only exact market and quality match');
check($history['points'][0]['date'] === $other, 'Chronological ordering');
check($history['points'][1]['modal'] === 70.0, 'Accurate numeric modal quote');
$comparison=$service->compare($query);
check($comparison['available'], 'Comparable reports exist');
check(count($comparison['markets']) === 2, 'No incompatible variety/unit quote is mixed into comparison');
check($comparison['markets'][0]['market_id']==='manjari', 'Sorted by reported modal price, not invented ranking');
$miss=$service->history(['market'=>'main-market-yard','commodity'=>'onion','unit'=>'quintal','variety'=>'Red','grade'=>'FAQ']);
check(!$miss['available'], 'Absent historical unit never created');
$bad=$service->history(['market'=>'../admin','commodity'=>'onion','unit'=>'kg']);
check(!$bad['available'], 'Path-like market rejected');
$empty=new PriceIntelligenceService($file.'.absent');
check(!$empty->history($query)['available'], 'Missing SQLite file has explicit unavailable state');
echo "Price intelligence SQLite and comparison contracts: PASS\n";

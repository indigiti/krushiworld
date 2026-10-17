<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use KrushiPulse\Application\MspReferenceService;
use KrushiPulse\Application\MarketDirectoryService;
use KrushiPulse\Application\MarketProfileService;
use KrushiPulse\Application\ExplainableInsightService;
use KrushiPulse\Application\PriceIntelligenceService;
use KrushiPulse\Http\RouteResolver;

function must(bool $condition, string $description): void {
    if (!$condition) { fwrite(STDERR,"FAIL: $description\n"); exit(1); }
}
$msp = new MspReferenceService();
$wheat = $msp->lookup(['commodity'=>'Wheat','season'=>'2026-27']);
must($wheat['available'] === true && count($wheat['references'])===1, 'Wheat matching');
must($wheat['references'][0]['msp_inr_per_quintal'] === 2585.0, 'PIB Rabi 2026-27 verified wheat');
must($wheat['references'][0]['season_type'] === 'Rabi', 'MSP correct marketing season');
$maize = $msp->lookup(['commodity'=>'Maize','season'=>'2026-27']);
must($maize['available'] && $maize['references'][0]['msp_inr_per_quintal']===2410.0, 'PIB Kharif maize rate');
$paddy=$msp->lookup(['commodity'=>'Paddy','season'=>'2026-27']);
must($paddy['available'] && count($paddy['references'])===2, 'Paddy common and Grade A must be separate');
$none=$msp->lookup(['commodity'=>'Onion','season'=>'2026-27']);
must(!$none['available'] && !$none['references'], 'Horticulture without MSP returns no invented quote');
$old=$msp->lookup(['commodity'=>'Wheat','season'=>'2023-24']);
must(!$old['available'], 'No fictional old-season MSP');
must(!empty($wheat['references'][0]['source_url']), 'PIB provenance');

$directory = new MarketDirectoryService(new RateRepository(), new NationalMarketRepository());
$profile = new MarketProfileService($directory);
$manjari=$profile->find('manjari');
must($manjari!==null && $manjari['facility_status']==='verified_subset', 'Official Manjari facilities');
must(in_array('Electronic scales',$manjari['verified_facilities'],true), 'Manjari exact facility evidence');
$moshi=$profile->find('moshi');
must(in_array('Farmer accommodation',$moshi['verified_facilities'],true), 'Official Moshi facility');
$pimpri=$profile->find('pimpri');
must($pimpri['facility_status']==='not_verified', 'Missing facility is unknown, not false');
must($profile->find('../../etc/passwd')===null, 'No market path traversal');
foreach ([
 '/krushiworld/api/v1/intelligence/insights'=>'v1-insights',
 '/krushiworld/api/v1/intelligence/msp'=>'v1-msp',
 '/krushiworld/api/v1/mandis/moshi/profile'=>'v1-market-profile',
] as $path=>$expected) {
    $route=RouteResolver::resolve($path,'/krushiworld/index.php');
    must($route['api']===$expected, 'Strict Phase4 route '. $path);
}

must(class_exists(SQLite3::class), 'SQLite3 extension available');
$file=tempnam(sys_get_temp_dir(),'krushi-insight-');
must(is_string($file),'Create test DB');
register_shutdown_function(static fn()=>@unlink($file));
$db=new SQLite3($file);
must($db->exec('CREATE TABLE prices(source TEXT,market_id TEXT,commodity_key TEXT,commodity_name TEXT,
 variety TEXT,grade TEXT,unit TEXT,report_date TEXT,min_price REAL,max_price REAL,modal_price REAL)'), 'Create history DB');
$stmt=$db->prepare('INSERT INTO prices VALUES (:source,:market,:key,:crop,:variety,:grade,:unit,:date,:min,:max,:modal)');
$rows=[
 ['Pune APMC','main-market-yard','maize','Maize','','','quintal',gmdate('Y-m-d',strtotime('-8 days')),1000,1200,1100],
 ['Pune APMC','main-market-yard','maize','Maize','','','quintal',gmdate('Y-m-d',strtotime('-3 days')),1100,1400,1300],
 ['Pune APMC','main-market-yard','maize','Maize','','','quintal',gmdate('Y-m-d',strtotime('-1 days')),1200,1500,1400],
 ['Pune APMC','main-market-yard','maize','Maize','','A','quintal',gmdate('Y-m-d',strtotime('-1 days')),1900,2300,2100],
];
$columns=['source','market','key','crop','variety','grade','unit','date','min','max','modal'];
foreach ($rows as $values) {
    foreach ($columns as $i=>$field) $stmt->bindValue(':'.$field,$values[$i],$i>=8?SQLITE3_FLOAT:SQLITE3_TEXT);
    $result=$stmt->execute();must($result!==false,'Insert source row');$result->finalize();
}
$stmt->close();$db->close();
$service=new ExplainableInsightService(new PriceIntelligenceService($file));
$query=['commodity'=>'Maize','market'=>'main-market-yard','variety'=>'','grade'=>'','unit'=>'quintal','days'=>30];
$insight=$service->explain($query);
must($insight['available'] && $insight['observations']===3,'Three distinct report days');
must($insight['first_modal']===1100.0 && $insight['last_modal']===1400.0,'Only matching grade is used');
must($insight['change_inr']===300.0 && $insight['direction']==='up','Deterministic movement');
must($insight['is_prediction']===false,'No predictive claims');
$missing=$service->explain(['market'=>'main-market-yard','commodity'=>'Onion','unit'=>'kg']);
must($missing['available']===false && $missing['is_prediction']===false,'No fake insight for absent observations');

echo "Phase 4 MSP, mandi facilities and explainable insight contracts: PASS\n";

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
use KrushiPulse\Http\RouteResolver;

function assertRoute(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}
$cases = [
    ['/krushiworld/mr/', '/krushiworld/index.php', 'home', 'mr'],
    ['/krushiworld/hi/', '/krushiworld/index.php', 'home', 'hi'],
    ['/krushiworld/hi/mandis/', '/krushiworld/index.php', 'directory', 'hi'],
    ['/krushiworld/hi/mandis/main-market-yard/', '/krushiworld/index.php', 'mandi', 'hi'],
    ['/krushiworld/en/', '/krushiworld/index.php', 'home', 'en'],
    ['/krushiworld/en/mandis/', '/krushiworld/index.php', 'directory', 'en'],
    ['/krushiworld/mr/mandis/main-market-yard/', '/krushiworld/index.php', 'mandi', 'mr'],
    ['/krushiworld/api/v1/latest', '/krushiworld/index.php', 'api', null],
    ['/krushiworld/api/v1/mandis/main-market-yard/rates', '/krushiworld/index.php', 'api', null],
    ['/en/mandis/manjari/', '/index.php', 'mandi', 'en'],
    ['/my/module/mr/mandis/moshi/', '/my/module/index.php', 'mandi', 'mr'],
    ['/krushiworld/admin/', '/krushiworld/index.php', 'admin', null],
    ['/krushiworld/?api=latest', '/krushiworld/index.php', 'legacy', null],
    ['/krushiworld/index.php?api=query', '/krushiworld/index.php', 'legacy', null],
    ['/krushiworld/robots.txt', '/krushiworld/index.php', 'seo', null],
];
foreach ($cases as [$uri, $script, $kind, $lang]) {
    $r = RouteResolver::resolve($uri, $script);
    assertRoute(($r['kind'] ?? '') === $kind, $uri . ' kind');
    if ($lang !== null) assertRoute(($r['lang'] ?? '') === $lang, $uri . ' locale');
}
foreach ([
    '/krushiworld/en/mandis/../admin/',
    '/krushiworld/en/mandis/%2Fadmin/',
    '/krushiworld/en/mandis/%2e%2e/',
    '/krushiworld/en/mandis/',
    '/krushiworld/api/v2/latest',
    '/krushiworld/en/mandis/BAD',
    '/krushiworld/en/mandis/generic/another/',
    '/other/en/',
] as $uri) {
    if ($uri === '/krushiworld/en/mandis/') continue;
    assertRoute(RouteResolver::resolve($uri, '/krushiworld/index.php')['kind'] === 'not_found', $uri . ' rejected');
}
$market = RouteResolver::resolve('/krushiworld/en/mandis/agmarknet-laslgaon-a1234567890b/', '/krushiworld/index.php');
assertRoute(($market['market'] ?? '') === 'agmarknet-laslgaon-a1234567890b', 'market ID captured');
$_SERVER['SCRIPT_NAME'] = '/krushiworld/index.php';
$canonical = new SeoService(new RateRepository());
$GLOBALS['apmc_route'] = ['kind' => 'mandi', 'market' => 'main-market-yard'];
assertRoute(str_ends_with($canonical->canonical('mr'), '/krushiworld/mr/mandis/main-market-yard/'), 'language preserves deep link');
assertRoute(str_ends_with($canonical->canonical('hi'), '/krushiworld/hi/mandis/main-market-yard/'), 'Hindi preserves market permalink');
$GLOBALS['apmc_route'] = ['kind' => 'directory'];
assertRoute(str_ends_with($canonical->canonical('en'), '/krushiworld/en/mandis/'), 'directory canonical');
$GLOBALS['apmc_route'] = ['kind' => 'legacy'];
assertRoute(str_ends_with($canonical->canonical('en'), '/krushiworld/en/'), 'homepage canonical');
$dir = new \KrushiPulse\Application\MarketDirectoryService(new RateRepository(), new NationalMarketRepository());
assertRoute(count($dir->all()) >= 5, 'directory retains five configured Pune markets');
assertRoute($dir->find('../bad') === null, 'directory validates path identifier');
$r = new \KrushiPulse\Application\MarketRatesService(new RateRepository(), new NationalMarketRepository(), $dir);
assertRoute($r->forMarket('unknown-id') === null, 'unknown market safely rejected');
echo "KrushiPulse pretty URL / layered service contracts: PASS\n";

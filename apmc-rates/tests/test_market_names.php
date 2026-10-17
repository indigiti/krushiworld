<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Contract: commodity name and physical market name are independent fields.
 * All 19 feed slugs must resolve to five unique known APMC locations.
 */
function checkMarket(bool $ok, string $reason): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $reason\n"); exit(1); }
}
$settings = apmc_json_read(APMC_ROOT . '/collector/config.json', []);
$feeds = $settings['markets'] ?? [];
checkMarket(count($feeds) === 19, 'Pune collector has 19 configured commodity feeds');
$normalizer = new DataNormalizer();
$unique = [];
foreach ($feeds as $feed) {
    $sample = [
        'feed_slug' => $feed['slug'],
        'market_slug' => $feed['slug'],
        'market_id' => $feed['market_id'],
        'market_name' => 'Gahu (lokavana)', // adversarial corrupted label; feed ID is authoritative
        'name_en' => 'Gahu (lokavana)',
        'name_mr' => 'गहू (लोकवन)',
        'category' => 'Grains',
        'unit' => 'qtl',
        'date' => '2026-10-17',
        'min' => 3200,
        'max' => 4200,
        'average' => 3700,
        'commodity_key' => 'known-wheat',
    ];
    $r = $normalizer->normalizeRecord($sample);
    $expected = (string)$feed['market'];
    checkMarket(($r['market_id'] ?? '') === $feed['market_id'], "Physical market ID matches feed {$feed['slug']}");
    checkMarket(($r['market_name_en'] ?? '') === $expected, "Market English label matches physical APMC location {$feed['slug']}");
    checkMarket(($r['market_name'] ?? '') === $expected, "Primary market field matches physical APMC location {$feed['slug']}");
    checkMarket(($r['market_name_mr'] ?? '') === (string)$feed['market_mr'], "Marathi market label matches physical APMC location {$feed['slug']}");
    checkMarket(($r['name_en'] ?? '') !== ($r['market_name_en'] ?? ''), "Commodity name not substituted as market label {$feed['slug']}");
    checkMarket(($r['average'] ?? null) === 3700 && ($r['unit'] ?? '') === 'qtl', 'Quoted rates/units preserved');
    $unique[$r['market_id']] = true;
}
// Older snapshots without a feed slug still carry a canonical market ID.
$legacy = $normalizer->normalizeRecord([
    'market_id' => 'manjari', 'market_name_en' => 'Gahu (lokavana)',
    'name_en' => 'Gahu (lokavana)', 'commodity_key' => 'legacy-wheat',
    'date' => '2026-10-17', 'unit' => 'qtl',
    'min' => 3200, 'max' => 4200, 'average' => 3700,
]);
checkMarket(($legacy['market_name_en'] ?? '') === 'Manjari', 'Legacy record uses verified market_id, never crop name');
checkMarket(($legacy['market_id'] ?? '') === 'manjari', 'Legacy market ID preserved');
checkMarket(count($unique) === 5, '19 commodity feeds correctly resolve to five actual markets');
$markets = (array)(apmc_i18n()['markets'] ?? []);
foreach (['Main Market Yard','Manjari','Moshi','Pimpri','Khadki'] as $english) {
    checkMarket(isset($markets[$english]['en'],$markets[$english]['mr'],$markets[$english]['hi']), 'Known market localized in EN/MR/HI: '.$english);
}
echo "Pune market labels / commodity separation: PASS\n";

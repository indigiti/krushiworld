<?php
declare(strict_types=1);

namespace KrushiPulse\Application;

use NationalMarketRepository;
use RateRepository;

/** Combines persistent market coverage with the source-backed Pune and national feeds. */
final class MarketDirectoryService {
    public function __construct(
        private RateRepository $pune,
        private NationalMarketRepository $national,
    ) {}

    public function all(): array {
        $merged = [];
        $config = apmc_json_read(APMC_ROOT . '/collector/config.json', []);
        $directory = apmc_json_read(APMC_ROOT . '/config/market_coordinates.json', []);
        // Independently audited geocodes may be supplied for national AGMARKNET
        // IDs. Never infer market coordinates from a district or PIN centroid.
        $nationalGeocodes = apmc_json_read(APMC_ROOT . '/config/national_market_coordinates.json', []);
        $postalReferences = apmc_json_read(APMC_ROOT . '/config/postal_market_references.json', []);
        $overrides = (array)($nationalGeocodes['markets'] ?? []);
        $postalMarkets = (array)($postalReferences['markets'] ?? []);
        foreach ((array)($config['markets'] ?? []) as $feed) {
            if (!is_array($feed)) continue;
            $id = (string)($feed['market_id'] ?? '');
            if ($id === '') continue;
            $merged[$id] = [
                'market_id' => $id, 'slug' => $id, 'name' => $feed['market'] ?? $id,
                'name_en' => $feed['market'] ?? $id,
                'name_mr' => $feed['market_mr'] ?? $feed['market'] ?? $id,
                'state' => 'Maharashtra', 'district' => 'Pune',
                'source' => 'Pune APMC', 'records' => 0, 'date' => null,
            ];
        }
        foreach ((array)($this->pune->latest()['markets'] ?? []) as $market) {
            if (!is_array($market)) continue;
            $id = (string)($market['market_id'] ?? '');
            if ($id === '') continue;
            $merged[$id] = array_merge($merged[$id] ?? [], $market, [
                'state' => 'Maharashtra', 'district' => 'Pune',
                'source' => 'Pune APMC',
            ]);
        }
        foreach ($this->national->directory() as $market) {
            if (!is_array($market)) continue;
            $id = (string)($market['market_id'] ?? '');
            if ($id !== '' && str_starts_with($id, 'agmarknet-')) $merged[$id] = $market;
        }
        $localizedMarkets = (array)(apmc_i18n()['markets'] ?? []);
        foreach ($merged as &$market) {
            $nameEn = (string)($market['name_en'] ?? '');
            if ($nameEn !== '' && isset($localizedMarkets[$nameEn]['hi'])) {
                $market['name_hi'] = (string)$localizedMarkets[$nameEn]['hi'];
            }
            $id = (string)($market['market_id'] ?? '');
            $geo = $directory[$id] ?? (str_starts_with($id, 'agmarknet-') ? ($overrides[$id] ?? null) : null);
            // Every postal code is explicit, source-backed and market-specific;
            // no invented region centre or PIN-to-GPS resolution.
            $postal = $postalMarkets[$id] ?? ($overrides[$id] ?? null);
            $code = is_array($postal) ? (string)($postal['pincode'] ?? '') : '';
            $market['pincode'] = preg_match('/^[1-9][0-9]{5}$/D', $code) ? $code : null;
            if (is_array($geo) && isset($geo['latitude'], $geo['longitude'])
                && is_numeric($geo['latitude']) && is_numeric($geo['longitude'])
                && is_finite((float)$geo['latitude']) && is_finite((float)$geo['longitude'])
                && abs((float)$geo['latitude']) <= 90 && abs((float)$geo['longitude']) <= 180) {
                $market['geo'] = [
                    'latitude' => (float)$geo['latitude'],
                    'longitude' => (float)$geo['longitude'],
                    'precision' => in_array($geo['precision'] ?? '', ['surveyed', 'locality', 'approximate'], true)
                        ? $geo['precision'] : 'approximate',
                    'source' => is_string($geo['source'] ?? null) && strlen((string)$geo['source']) <= 350
                        ? $geo['source'] : null,
                ];
            } else $market['geo'] = null;
        }
        unset($market);
        $markets = array_values($merged);
        usort($markets, static fn($a, $b) =>
            strcmp((string)($a['state'] ?? ''), (string)($b['state'] ?? ''))
            ?: strcmp((string)($a['district'] ?? ''), (string)($b['district'] ?? ''))
            ?: strcmp((string)($a['name_en'] ?? ''), (string)($b['name_en'] ?? ''))
        );
        return $markets;
    }

    public function find(string $id): ?array {
        if ($id === '' || strlen($id) > 110 || !preg_match('/^[a-z0-9-]+$/', $id)) return null;
        foreach ($this->all() as $market) {
            if (($market['market_id'] ?? '') === $id) return $market;
        }
        return null;
    }

    public function summary(): array {
        $markets = $this->all();
        $mapped = 0;
        $reporting = 0;
        $postal = 0;
        $states = [];
        $latest = null;
        foreach ($markets as $market) {
            if (is_array($market['geo'] ?? null)) $mapped++;
            if (!empty($market['pincode'])) $postal++;
            if ((int)($market['records'] ?? 0) > 0) $reporting++;
            if (is_string($market['state'] ?? null) && $market['state'] !== '') $states[$market['state']] = true;
            $date = (string)($market['date'] ?? '');
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date) && ($latest === null || $date > $latest)) $latest = $date;
        }
        return [
            'markets' => $markets,
            'count' => count($markets),
            'coverage' => [
                'total' => count($markets),
                'with_coordinates' => $mapped,
                'without_coordinates' => count($markets) - $mapped,
                'with_postal_reference' => $postal,
                'with_published_rates' => $reporting,
                'without_current_report' => count($markets) - $reporting,
                'states' => count($states),
                'latest_report_date' => $latest,
                'distance_type' => 'straight_line_approximate',
            ],
            'national' => $this->national->state(),
        ];
    }
}

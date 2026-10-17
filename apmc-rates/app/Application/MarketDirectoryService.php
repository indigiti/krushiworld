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
            $geo = $directory[$id] ?? ($market['geo'] ?? null);
            if (is_array($geo) && isset($geo['latitude'], $geo['longitude'])
                && is_numeric($geo['latitude']) && is_numeric($geo['longitude'])
                && is_finite((float)$geo['latitude']) && is_finite((float)$geo['longitude'])
                && abs((float)$geo['latitude']) <= 90 && abs((float)$geo['longitude']) <= 180) {
                $market['geo'] = [
                    'latitude' => (float)$geo['latitude'],
                    'longitude' => (float)$geo['longitude'],
                    'precision' => in_array($geo['precision'] ?? '', ['surveyed', 'locality', 'approximate'], true)
                        ? $geo['precision'] : 'approximate',
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
        return [
            'markets' => $markets,
            'count' => count($markets),
            'national' => $this->national->state(),
        ];
    }
}

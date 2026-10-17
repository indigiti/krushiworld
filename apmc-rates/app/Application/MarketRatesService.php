<?php
declare(strict_types=1);

namespace KrushiPulse\Application;

use NationalMarketRepository;
use RateRepository;

/** Source-specific read repositories are hidden behind one market-rates use case. */
final class MarketRatesService {
    public function __construct(
        private RateRepository $pune,
        private NationalMarketRepository $national,
        private MarketDirectoryService $markets,
    ) {}

    public function forMarket(string $id, int $limit = 1500): ?array {
        $market = $this->markets->find($id);
        if ($market === null) return null;
        $limit = max(1, min(2000, $limit));
        $records = [];
        if (str_starts_with($id, 'agmarknet-')) {
            $source = $this->national->marketRates($id, $limit);
            $records = (array)($source['records'] ?? []);
        } else {
            foreach ((array)($this->pune->latest()['records'] ?? []) as $record) {
                if (is_array($record) && ($record['market_id'] ?? '') === $id) {
                    $records[] = $record;
                }
            }
            usort($records, static fn($a, $b) =>
                strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
            $records = array_slice($records, 0, $limit);
        }
        return [
            'market' => $market,
            'count' => count($records),
            'records' => $records,
            'has_published_rates' => count($records) > 0,
            'source' => $market['source'] ?? null,
            'source_report_date' => $market['date'] ?? null,
        ];
    }
}

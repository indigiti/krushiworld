<?php
declare(strict_types=1);

final class RateRepository {
    private ?DataNormalizer $normalizer = null;

    private function normalizer(): DataNormalizer {
        return $this->normalizer ??= new DataNormalizer();
    }

    public function latest(): array {
        $fallback = [
            'meta' => [
                'generated_at' => null,
                'serving_date' => null,
                'status' => 'warming',
                'records' => 0,
                'markets_available' => 0,
                'markets_expected' => 19,
            ],
            'pulse' => ['up' => 0, 'down' => 0, 'unchanged' => 0, 'total_arrival' => 0],
            'markets' => [],
            'categories' => [],
            'featured' => [],
            'records' => [],
        ];
        return $this->normalizer()->normalizeSnapshot(apmc_json_read(APMC_STORAGE . '/current/all.json', $fallback));
    }

    public function health(): array {
        return apmc_json_read(APMC_STORAGE . '/system/health.json', [
            'status' => 'warming',
            'message' => 'No completed collector run yet.',
            'checked_at' => null,
        ]);
    }

    public function manifest(): array {
        return apmc_json_read(APMC_STORAGE . '/system/manifest.json', []);
    }

    public function market(string $slug): array {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return [];
        return $this->normalizer()->normalizeMarketSnapshot(apmc_json_read(APMC_STORAGE . '/markets/' . $slug . '.json', []));
    }
}

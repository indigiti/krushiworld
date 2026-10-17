<?php
declare(strict_types=1);

/** Read-only, server-stored national AGMARKNET data. Never fetches external APIs on web requests. */
final class NationalMarketRepository {
    private function directoryPath(): string { return APMC_STORAGE . '/national/directory.json'; }
    private function snapshotPath(): string { return APMC_STORAGE . '/national/current.json'; }

    public function directory(): array {
        $data = apmc_json_read($this->directoryPath(), []);
        return array_values(array_filter((array)($data['markets'] ?? []), 'is_array'));
    }

    public function state(): array {
        $data = apmc_json_read($this->snapshotPath(), []);
        return [
            'status' => !trim((string)getenv('APMC_DATA_GOV_IN_API_KEY')) ? 'not_configured'
                : (empty($data['records']) ? 'awaiting_import' : 'ready'),
            'updated_at' => $data['meta']['updated_at'] ?? null,
            'source' => 'AGMARKNET / data.gov.in',
            'record_count' => (int)($data['meta']['records'] ?? 0),
            'market_count' => count($this->directory()),
        ];
    }

    public function marketRates(string $marketId, int $limit = 1500): array {
        if (preg_match('/^agmarknet-[a-z0-9-]{1,90}$/', $marketId) !== 1) {
            throw new InvalidArgumentException('Invalid national market id');
        }
        $limit = max(1, min(2000, $limit));
        $data = apmc_json_read($this->snapshotPath(), []);
        $result = [];
        foreach ((array)($data['records'] ?? []) as $record) {
            if (is_array($record) && ($record['market_id'] ?? '') === $marketId) {
                $result[] = $record;
                if (count($result) >= $limit) break;
            }
        }
        usort($result, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        return [
            'records' => $result,
            'count' => count($result),
            'source' => 'AGMARKNET / data.gov.in',
            'updated_at' => $data['meta']['updated_at'] ?? null,
        ];
    }
}

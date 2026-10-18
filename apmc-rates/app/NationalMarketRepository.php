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
        $health = apmc_json_read(APMC_STORAGE . '/national/health.json', []);
        $refreshRequest = apmc_json_read(APMC_STORAGE . '/national/refresh-request.json', []);
        $verifiedFlag = apmc_json_read(APMC_STORAGE . '/national/verified.json', []);
        $requestAt = strtotime((string)($refreshRequest['requested_at'] ?? '')) ?: 0;
        $refreshQueue = $requestAt > 0 && time() - $requestAt < 3600
            ? 'pending_scheduler'
            : (($verifiedFlag['verified'] ?? false) === true ? 'verified' : 'idle');
        $records = (array)($data['records'] ?? []);
        $updatedAt = $data['meta']['updated_at'] ?? null;
        $latestReportDate = null;
        foreach ($records as $row) {
            $date = is_array($row) ? (string)($row['date'] ?? '') : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                && ($latestReportDate === null || $date > $latestReportDate)) $latestReportDate = $date;
        }
        $hasData = count($records) > 0;
        $configured = trim((string)\KrushiPulseRuntimeSettings::get('APMC_DATA_GOV_IN_API_KEY')) !== '';
        $dateAge = $latestReportDate === null ? null
            : max(0, (int)floor((time() - (strtotime($latestReportDate . ' 23:59:59 UTC') ?: time())) / 86400));
        $importAgeHours = is_string($updatedAt) && strtotime($updatedAt) !== false
            ? max(0, round((time() - strtotime($updatedAt)) / 3600, 1)) : null;
        $status = !$hasData
            ? ($configured ? 'awaiting_import' : 'not_configured')
            : (!$configured ? 'cached_unconfigured' : (($dateAge !== null && $dateAge > 3) || ($importAgeHours !== null && $importAgeHours > 18) ? 'stale' : 'ready'));
        return [
            'status' => $status,
            'configured' => $configured,
            'has_snapshot' => $hasData,
            'updated_at' => $updatedAt,
            'latest_report_date' => $latestReportDate,
            'latest_report_age_days' => $dateAge,
            'import_age_hours' => $importAgeHours,
            'source' => 'AGMARKNET / data.gov.in',
            'record_count' => (int)($data['meta']['records'] ?? count($records)),
            'market_count' => count($this->directory()),
            'last_attempt_at' => $health['last_attempt_at'] ?? null,
            'last_success_at' => $health['last_success_at'] ?? $updatedAt,
            'last_result' => $health['last_result'] ?? null,
            'refresh_queue' => $refreshQueue,
            'verified_at' => ($verifiedFlag['verified'] ?? false) === true
                ? ($verifiedFlag['verified_at'] ?? null) : null,
            // Do not leak configured API credentials, request URLs or raw errors.
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

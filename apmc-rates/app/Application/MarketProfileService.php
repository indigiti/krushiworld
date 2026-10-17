<?php
declare(strict_types=1);

namespace KrushiPulse\Application;

/** Profile extension using only curated, source-attributed facts. */
final class MarketProfileService {
    public function __construct(private MarketDirectoryService $directory) {}

    public function find(string $id): ?array {
        $market = $this->directory->find($id);
        if ($market === null) return null;
        $data = apmc_json_read(APMC_ROOT . '/config/market_profiles.json', []);
        $profile = (array)($data['markets'][$id] ?? []);
        $origin = (string)($profile['source_url'] ?? '');
        $sourceIsValid = (bool)preg_match('~^https://[a-z0-9.-]+(?:/[^\s]*)?$~i', $origin);
        $facilities = [];
        if ($sourceIsValid && is_array($profile['facilities'] ?? null)) {
            foreach ($profile['facilities'] as $facility) {
                if (!is_array($facility) || ($facility['verified'] ?? false) !== true) continue;
                $name = trim((string)($facility['name'] ?? ''));
                if ($name !== '' && mb_strlen($name) <= 90) $facilities[] = $name;
            }
        }
        $phone = (string)($profile['phone'] ?? '');
        $hours = trim((string)($profile['hours'] ?? ''));
        $website = (string)($profile['website_url'] ?? '');
        return [
            'market_id' => $id,
            'name_en' => (string)($market['name_en'] ?? $market['name'] ?? $id),
            'name_mr' => (string)($market['name_mr'] ?? ''),
            'name_hi' => (string)($market['name_hi'] ?? ''),
            'state' => (string)($market['state'] ?? ''),
            'district' => (string)($market['district'] ?? ''),
            'pincode' => $market['pincode'] ?? null,
            'geo' => $market['geo'] ?? null,
            'rate_source' => $market['source'] ?? '',
            'rate_report_date' => $market['date'] ?? null,
            'reported_rows' => (int)($market['records'] ?? 0),
            'contact_phone' => $sourceIsValid && preg_match('/^\+?[0-9()\s-]{7,20}$/', $phone) ? $phone : null,
            'hours' => $sourceIsValid && $hours !== '' ? mb_substr($hours,0,150) : null,
            'website_url' => $sourceIsValid && preg_match('~^https://[a-z0-9.-]+(?:/[^\s]*)?$~i', $website) ? $website : null,
            'verified_facilities' => array_slice($facilities,0,25),
            'facility_status' => $facilities ? 'verified_subset' : 'not_verified',
            'profile_source_url' => $sourceIsValid ? $origin : null,
            'profile_verified_at' => $sourceIsValid && preg_match('/^\d{4}-\d\d-\d\d$/', (string)($profile['verified_at'] ?? '')) ? $profile['verified_at'] : null,
            'disclaimer' => 'Market coordinates may be approximate; verify market entrance, hours, facilities, contacts and operating status before travel.',
        ];
    }
}

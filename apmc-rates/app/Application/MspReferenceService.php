<?php
declare(strict_types=1);

namespace KrushiPulse\Application;

/**
 * Provenance-first MSP references. Never predicts MSPs or uses an MSP as a
 * guaranteed sale price. Rates are per quintal for the named marketing season.
 */
final class MspReferenceService {
    private function catalog(): array {
        $raw = apmc_json_read(APMC_ROOT . '/config/msp_references.json', []);
        return array_values(array_filter((array)($raw['entries'] ?? []), 'is_array'));
    }
    public function lookup(array $params): array {
        $name = trim((string)($params['commodity'] ?? ''));
        $season = trim((string)($params['season'] ?? ''));
        if ($name === '' || strlen($name) > 160 || strlen($season) > 24) {
            return ['available'=>false,'reason'=>'Choose a commodity and marketing season.','references'=>[]];
        }
        $name = mb_strtolower($name, 'UTF-8');
        $found = [];
        foreach ($this->catalog() as $row) {
            $aliases = array_merge([(string)($row['commodity'] ?? '')], (array)($row['aliases'] ?? []));
            $matched = false;
            foreach ($aliases as $alias) {
                if ($name === mb_strtolower(trim((string)$alias), 'UTF-8')) { $matched = true; break; }
            }
            if (!$matched || ($season !== '' && $season !== (string)($row['marketing_season'] ?? ''))) continue;
            if (!is_numeric($row['msp_inr_per_quintal'] ?? null) || (float)$row['msp_inr_per_quintal'] <= 0) continue;
            if (!filter_var((string)($row['source_url'] ?? ''), FILTER_VALIDATE_URL)
                || !str_starts_with((string)$row['source_url'], 'https://www.pib.gov.in/')) continue;
            $found[] = [
                'commodity' => (string)$row['commodity'],
                'grade' => (string)($row['grade'] ?? ''),
                'marketing_season' => (string)$row['marketing_season'],
                'season_type' => (string)($row['season_type'] ?? ''),
                'msp_inr_per_quintal' => (float)$row['msp_inr_per_quintal'],
                'unit' => 'quintal', 'source' => 'Government of India / PIB',
                'source_url' => (string)$row['source_url'],
                'announced_at' => (string)($row['announced_at'] ?? ''),
            ];
        }
        return [
            'available' => count($found) > 0,
            'references' => $found,
            'reason' => $found ? null : 'No official MSP reference in the verified catalog for this exact crop and season.',
            'disclaimer' => 'MSP is an official policy reference for a named marketing season and crop/grade. It is not a guaranteed sale price, procurement offer or current mandi quote. Compare only matching grade and units.',
        ];
    }
}

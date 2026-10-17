<?php
declare(strict_types=1);

final class PublicQueryService {
    public function __construct(private RateRepository $repo) {}

    public function query(array $input): array {
        $data = $this->repo->latest();
        $records = array_values(array_filter((array)($data['records'] ?? []), 'is_array'));
        $requestedLanguage = strtolower((string)($input['lang'] ?? 'en'));
        $lang = in_array($requestedLanguage, ['en', 'mr', 'hi'], true) ? $requestedLanguage : 'en';
        $lower = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $q = $lower(trim((string)($input['q'] ?? '')));
        $market = trim((string)($input['market'] ?? ''));
        $category = trim((string)($input['category'] ?? ''));
        $limit = max(1, min(50, (int)($input['limit'] ?? 20)));

        $filtered = array_values(array_filter($records, static function(array $r) use ($q, $market, $category, $lower): bool {
            if ($market !== '') {
                $marketValues = [(string)($r['market_id'] ?? ''), (string)($r['feed_slug'] ?? $r['market_slug'] ?? ''), (string)($r['market_name'] ?? ''), (string)($r['market_name_en'] ?? ''), (string)($r['market_name_mr'] ?? '')];
                if (!in_array($market, $marketValues, true)) return false;
            }
            if ($category !== '') {
                $categoryValues = [(string)($r['category'] ?? ''), (string)($r['category_en'] ?? ''), (string)($r['category_mr'] ?? '')];
                if (!in_array($category, $categoryValues, true)) return false;
            }
            if ($q === '') return true;
            $haystack = $lower(implode(' ', [
                (string)($r['name_en'] ?? ''), (string)($r['name_mr'] ?? ''), (string)($r['name_hi'] ?? ''),
                (string)($r['market_name'] ?? ''), (string)($r['market_name_en'] ?? ''), (string)($r['market_name_mr'] ?? ''), (string)($r['market_name_hi'] ?? ''),
                (string)($r['category'] ?? ''), (string)($r['category_en'] ?? ''), (string)($r['category_mr'] ?? ''),
            ]));
            return str_contains($haystack, $q);
        }));

        usort($filtered, static fn(array $a, array $b): int => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')) ?: ((float)($b['arrival'] ?? 0) <=> (float)($a['arrival'] ?? 0)));
        $localizedCategories = (array)(apmc_i18n()['categories'] ?? []);
        $items = array_map(static function(array $r) use ($lang, $localizedCategories): array {
            $name = (string)($r['name_' . $lang] ?? '') ?: ((string)($r['name_en'] ?? '') ?: (string)($r['name_mr'] ?? ''));
            $marketName = (string)($r['market_name_' . $lang] ?? '') ?: ((string)($r['market_name_en'] ?? '') ?: (string)($r['market_name'] ?? ''));
            $categoryKey = (string)($r['category_en'] ?? $r['category'] ?? '');
            $categoryName = $lang === 'hi'
                ? (string)($localizedCategories[$categoryKey]['hi'] ?? $categoryKey)
                : ($lang === 'mr' ? ((string)($r['category_mr'] ?? '') ?: $categoryKey) : $categoryKey);
            return [
                'commodity_key' => (string)($r['commodity_key'] ?? ''),
                'commodity' => $name,
                'name_en' => (string)($r['name_en'] ?? ''),
                'name_mr' => (string)($r['name_mr'] ?? ''),
                'name_hi' => (string)($r['name_hi'] ?? ''),
                'market_id' => (string)($r['market_id'] ?? ''),
                'feed_slug' => (string)($r['feed_slug'] ?? $r['market_slug'] ?? ''),
                'market' => $marketName,
                'category' => $categoryName,
                'date' => $r['date'] ?? null,
                'unit' => $r['unit'] ?? null,
                'arrival' => $r['arrival'] ?? null,
                'min' => $r['min'] ?? null,
                'max' => $r['max'] ?? null,
                'average' => $r['average'] ?? null,
                'change_pct' => $r['change_pct'] ?? null,
            ];
        }, array_slice($filtered, 0, $limit));

        return [
            'as_of' => $data['meta']['serving_date'] ?? null,
            'generated_at' => $data['meta']['generated_at'] ?? null,
            'language' => $lang,
            'query' => ['q' => $q, 'market' => $market, 'category' => $category, 'limit' => $limit],
            'total_matches' => count($filtered),
            'returned' => count($items),
            'items' => $items,
            'source' => ['name' => 'Agricultural Produce Market Committee, Pune', 'url' => (string)apmc_config('source_base', 'https://apmcpune.in')],
        ];
    }

    public function summary(string $lang = 'en'): array {
        $lang = in_array($lang, ['en', 'mr', 'hi'], true) ? $lang : 'en';
        $data = $this->repo->latest();
        $meta = (array)($data['meta'] ?? []);
        $pulse = (array)($data['pulse'] ?? []);
        return [
            'as_of' => $meta['serving_date'] ?? null,
            'generated_at' => $meta['generated_at'] ?? null,
            'status' => $meta['status'] ?? 'warming',
            'records' => (int)($meta['records'] ?? 0),
            'markets_available' => count((array)($data['markets'] ?? [])),
            'markets_expected' => 5,
            'feeds_available' => (int)($meta['markets_available'] ?? 0),
            'feeds_expected' => (int)($meta['markets_expected'] ?? 19),
            'pulse' => [
                'up' => (int)($pulse['up'] ?? 0),
                'down' => (int)($pulse['down'] ?? 0),
                'unchanged' => (int)($pulse['unchanged'] ?? 0),
                'total_arrival' => $pulse['total_arrival'] ?? 0,
            ],
            'language' => $lang,
            'source' => ['name' => 'Agricultural Produce Market Committee, Pune', 'url' => (string)apmc_config('source_base', 'https://apmcpune.in')],
        ];
    }
}

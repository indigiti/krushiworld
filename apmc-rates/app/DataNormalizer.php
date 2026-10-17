<?php
declare(strict_types=1);

/**
 * Customer-facing semantic normalization layer.
 *
 * Raw APMC feed names remain in storage snapshots. Every public read passes
 * through this resolver so old cache files also receive canonical market IDs,
 * bilingual commodity names and a small provenance/confidence signal.
 *
 * Safety: this resolver is deterministic. It never calls a remote LLM from a
 * request path and therefore cannot hallucinate or leak feed data externally.
 */
final class DataNormalizer {
    private array $commodityMap;
    private array $sourceConfig;
    private array $commodityIndex = [];
    private array $feedToMarket = [];
    private array $marketById = [];
    private array $marketNameToId = [];

    public function __construct() {
        $this->commodityMap = apmc_json_read(APMC_ROOT . '/collector/commodity_map.json', []);
        $this->sourceConfig = apmc_json_read(APMC_ROOT . '/collector/config.json', []);
        $this->buildIndexes();
    }

    public function normalizeSnapshot(array $data): array {
        $records = [];
        foreach ((array)($data['records'] ?? []) as $record) {
            if (is_array($record)) $records[] = $this->normalizeRecord($record);
        }
        $featured = [];
        foreach ((array)($data['featured'] ?? []) as $record) {
            if (is_array($record)) $featured[] = $this->normalizeRecord($record);
        }

        $data['records'] = $records;
        $data['featured'] = $featured;
        $data['markets'] = $this->canonicalMarketsFromData((array)($data['markets'] ?? []), $records);
        $data['categories'] = $this->canonicalCategories($records, (array)($data['categories'] ?? []));
        $data['normalization'] = [
            'version' => '1.3.1',
            'mode' => 'verified_dictionary_then_safe_fallback',
            'customer_market_count' => count($data['markets']),
        ];
        return $data;
    }

    public function normalizeMarketSnapshot(array $snapshot): array {
        $market = is_array($snapshot['market'] ?? null) ? $snapshot['market'] : [];
        $feedSlug = (string)($market['slug'] ?? $snapshot['slug'] ?? '');
        $marketMeta = $this->resolveMarket($feedSlug, (string)($market['market'] ?? ''));
        $snapshot['market'] = array_merge($market, $marketMeta, ['feed_slug' => $feedSlug]);
        $normalized = [];
        foreach ((array)($snapshot['records'] ?? []) as $record) {
            if (is_array($record)) $normalized[] = $this->normalizeRecord($record);
        }
        $snapshot['records'] = $normalized;
        unset($snapshot['source_url']); // source provenance is available globally; do not leak collector internals per row/feed.
        return $snapshot;
    }

    public function normalizeRecord(array $record): array {
        $feedSlug = trim((string)($record['feed_slug'] ?? $record['market_slug'] ?? ''));
        $market = $this->resolveMarket($feedSlug, (string)($record['market_name_en'] ?? $record['market_name'] ?? ''));

        $sourceName = trim((string)($record['source_name'] ?? $record['name_mr'] ?? $record['name_en'] ?? ''));
        $resolved = $this->resolveCommodity($sourceName, (string)($record['name_en'] ?? ''), (string)($record['name_mr'] ?? ''));

        $record['feed_slug'] = $feedSlug;
        $record['market_id'] = $market['market_id'];
        $record['market_name'] = $market['market_name_en'];
        $record['market_name_en'] = $market['market_name_en'];
        $record['market_name_mr'] = $market['market_name_mr'];
        $marketLabels = (array)(apmc_i18n()['markets'] ?? []);
        $marketNameHi = (string)($marketLabels[$market['market_name_en']]['hi'] ?? '');
        if ($marketNameHi !== '') $record['market_name_hi'] = $marketNameHi;
        $record['name_en'] = $resolved['en'];
        $record['name_mr'] = $resolved['mr'];
        // Hindi display label is additive: raw source names and stable commodity IDs
        // remain unchanged, preserving history and user favorites across languages.
        $commodityLabels = (array)(apmc_i18n()['commodities'] ?? []);
        $nameHi = (string)($commodityLabels[$resolved['en']]['hi'] ?? $commodityLabels[$resolved['mr']]['hi'] ?? '');
        if ($nameHi !== '') $record['name_hi'] = $nameHi;
        // Old cached APMC records also receive the verified emoji dictionary on read.
        // Unrecognized crops retain any existing source emoji for safe UI fallback.
        if (!empty($resolved['emoji'])) $record['emoji'] = (string)$resolved['emoji'];
        $record['name_resolution'] = [
            'method' => $resolved['method'],
            'confidence' => $resolved['confidence'],
            'verified' => $resolved['verified'],
        ];
        if (($record['commodity_key'] ?? '') === '') {
            $record['commodity_key'] = substr(sha1(strtolower($resolved['en'] . '|' . $resolved['mr'])), 0, 14);
        }
        // Raw source label remains in flat-file storage for audit/reprocessing, but
        // customer-facing JSON receives canonical fields only.
        unset($record['source_name']);
        return $record;
    }

    public function resolveCommodity(string $sourceName, string $existingEn = '', string $existingMr = ''): array {
        $candidates = array_values(array_filter([$sourceName, $existingMr, $existingEn], static fn($v) => trim((string)$v) !== ''));
        foreach ($candidates as $candidate) {
            $key = $this->norm((string)$candidate);
            if ($key !== '' && isset($this->commodityIndex[$key])) {
                $entry = $this->commodityIndex[$key];
                return [
                    'en' => (string)$entry['en'], 'mr' => (string)$entry['mr'],
                    'emoji' => (string)($entry['emoji'] ?? ''),
                    'method' => (string)$entry['method'], 'confidence' => (float)$entry['confidence'], 'verified' => true,
                ];
            }
        }

        // Conservative typo recovery for Latin labels only. We deliberately do
        // not fuzzy-match Devanagari because false semantic matches are worse than
        // a visible low-confidence fallback.
        foreach ($candidates as $candidate) {
            if ($this->hasDevanagari((string)$candidate)) continue;
            $needle = $this->norm((string)$candidate);
            if (strlen($needle) < 5) continue;
            $best = null; $bestScore = 0.0;
            foreach ($this->commodityIndex as $key => $entry) {
                if ($this->hasDevanagari($key) || abs(strlen($key) - strlen($needle)) > 3) continue;
                $max = max(strlen($key), strlen($needle));
                if ($max === 0) continue;
                $score = 1 - (levenshtein($needle, $key) / $max);
                if ($score > $bestScore) { $bestScore = $score; $best = $entry; }
            }
            if ($best !== null && $bestScore >= 0.88) {
                return [
                    'en' => (string)$best['en'], 'mr' => (string)$best['mr'],
                    'method' => 'fuzzy_alias', 'confidence' => round(min(.94, $bestScore), 2), 'verified' => false,
                ];
            }
        }

        $mr = $existingMr !== '' ? $existingMr : ($this->hasDevanagari($sourceName) ? $sourceName : '');
        $en = $existingEn;
        if ($en === '' || $this->hasDevanagari($en) || $this->norm($en) === $this->norm($mr)) {
            $base = $sourceName !== '' ? $sourceName : $mr;
            $en = $this->hasDevanagari($base) ? $this->transliterate($base) : $base;
        }
        if ($en === '') $en = 'Commodity ' . strtoupper(substr(sha1($sourceName . '|' . $existingMr), 0, 6));
        if ($mr === '') $mr = $sourceName !== '' ? $sourceName : $en;
        return ['en' => $en, 'mr' => $mr, 'method' => 'safe_transliteration', 'confidence' => 0.45, 'verified' => false];
    }

    private function buildIndexes(): void {
        foreach ($this->commodityMap as $mrKey => $entry) {
            if (!is_array($entry)) continue;
            $en = trim((string)($entry['en'] ?? ''));
            $mr = trim((string)($entry['mr'] ?? $mrKey));
            if ($en === '' || $mr === '') continue;
            $keys = array_merge([$mrKey, $mr, $en], is_array($entry['aliases'] ?? null) ? $entry['aliases'] : []);
            foreach ($keys as $idx => $value) {
                $key = $this->norm((string)$value);
                if ($key === '') continue;
                $this->commodityIndex[$key] = [
                    'en' => $en, 'mr' => $mr,
                    'emoji' => trim((string)($entry['emoji'] ?? '')),
                    'method' => $idx <= 1 ? 'verified_source_map' : 'verified_alias',
                    'confidence' => $idx <= 1 ? 1.0 : 0.99,
                ];
            }
        }

        foreach ((array)($this->sourceConfig['markets'] ?? []) as $feed) {
            if (!is_array($feed)) continue;
            $feedSlug = (string)($feed['slug'] ?? '');
            $nameEn = trim((string)($feed['market'] ?? ''));
            if ($feedSlug === '' || $nameEn === '') continue;
            $marketId = trim((string)($feed['market_id'] ?? '')) ?: $this->slugify($nameEn);
            $meta = [
                'market_id' => $marketId,
                'market_name_en' => $nameEn,
                'market_name_mr' => trim((string)($feed['market_mr'] ?? '')) ?: $nameEn,
            ];
            $this->feedToMarket[$feedSlug] = $meta;
            $this->marketById[$marketId] = $meta;
            $this->marketNameToId[$this->norm($nameEn)] = $marketId;
            $this->marketNameToId[$this->norm($meta['market_name_mr'])] = $marketId;
        }
    }

    private function resolveMarket(string $feedSlug, string $name): array {
        if ($feedSlug !== '' && isset($this->feedToMarket[$feedSlug])) return $this->feedToMarket[$feedSlug];
        $key = $this->norm($name);
        $id = $key !== '' ? ($this->marketNameToId[$key] ?? '') : '';
        if ($id !== '' && isset($this->marketById[$id])) return $this->marketById[$id];
        $name = trim($name) ?: 'Unknown Market';
        return ['market_id' => $this->slugify($name), 'market_name_en' => $name, 'market_name_mr' => $name];
    }

    private function canonicalMarketsFromData(array $summaries, array $records): array {
        $grouped = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) continue;
            $feedSlug = (string)($summary['feed_slug'] ?? $summary['slug'] ?? '');
            $m = $this->resolveMarket($feedSlug, (string)($summary['name_en'] ?? $summary['name'] ?? ''));
            $id = $m['market_id'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'slug' => $id, 'market_id' => $id, 'name' => $m['market_name_en'],
                    'name_en' => $m['market_name_en'], 'name_mr' => $m['market_name_mr'],
                    'feed_slugs' => [], 'feed_count' => 0, 'records' => 0, 'date' => null,
                ];
            }
            if ($feedSlug !== '' && !in_array($feedSlug, $grouped[$id]['feed_slugs'], true)) $grouped[$id]['feed_slugs'][] = $feedSlug;
            $grouped[$id]['feed_count'] = count($grouped[$id]['feed_slugs']);
            $grouped[$id]['records'] += (int)($summary['records'] ?? 0);
            $date = (string)($summary['date'] ?? '');
            if ($date !== '' && ($grouped[$id]['date'] === null || $date > $grouped[$id]['date'])) $grouped[$id]['date'] = $date;
        }
        if (!$grouped) {
            foreach ($records as $record) {
                $id = (string)($record['market_id'] ?? '');
                if ($id === '') continue;
                if (!isset($grouped[$id])) $grouped[$id] = [
                    'slug' => $id, 'market_id' => $id, 'name' => $record['market_name_en'] ?? '',
                    'name_en' => $record['market_name_en'] ?? '', 'name_mr' => $record['market_name_mr'] ?? '',
                    'feed_slugs' => [], 'feed_count' => 0, 'records' => 0, 'date' => null,
                ];
                $grouped[$id]['records']++;
                $feed = (string)($record['feed_slug'] ?? '');
                if ($feed !== '' && !in_array($feed, $grouped[$id]['feed_slugs'], true)) $grouped[$id]['feed_slugs'][] = $feed;
                $grouped[$id]['feed_count'] = count($grouped[$id]['feed_slugs']);
                $date = (string)($record['date'] ?? '');
                if ($date !== '' && ($grouped[$id]['date'] === null || $date > $grouped[$id]['date'])) $grouped[$id]['date'] = $date;
            }
        }
        $order = array_flip(['main-market-yard', 'manjari', 'moshi', 'pimpri', 'khadki']);
        $out = array_values($grouped);
        usort($out, static fn($a,$b) => ($order[$a['market_id']] ?? 99) <=> ($order[$b['market_id']] ?? 99));
        return $out;
    }

    private function canonicalCategories(array $records, array $existing): array {
        $set = [];
        foreach ($existing as $category) if (is_string($category) && trim($category) !== '') $set[trim($category)] = true;
        foreach ($records as $record) {
            $category = trim((string)($record['category_en'] ?? $record['category'] ?? ''));
            if ($category !== '') $set[$category] = true;
        }
        $out = array_keys($set); sort($out, SORT_NATURAL | SORT_FLAG_CASE); return $out;
    }

    private function norm(string $value): string {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) $value = $normalized;
        }
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($value)) ?? trim($value);
        $value = trim($value, " \t\n\r\0\x0B.,;:()[]{}-_/");
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function hasDevanagari(string $value): bool {
        return preg_match('/\p{Devanagari}/u', $value) === 1;
    }

    private function transliterate(string $value): string {
        if (class_exists('Transliterator')) {
            $t = \Transliterator::create('Devanagari-Latin; Latin-ASCII');
            if ($t) {
                $out = $t->transliterate($value);
                if (is_string($out) && trim($out) !== '') return ucwords(trim($out));
            }
        }
        // Lightweight fallback for hosts without ext-intl. It is only used for
        // unresolved labels and never claims to be a semantic translation.
        $vowels = ['अ'=>'a','आ'=>'aa','इ'=>'i','ई'=>'ee','उ'=>'u','ऊ'=>'oo','ऋ'=>'ri','ए'=>'e','ऐ'=>'ai','ओ'=>'o','औ'=>'au'];
        $cons = ['क'=>'k','ख'=>'kh','ग'=>'g','घ'=>'gh','ङ'=>'ng','च'=>'ch','छ'=>'chh','ज'=>'j','झ'=>'jh','ञ'=>'ny','ट'=>'t','ठ'=>'th','ड'=>'d','ढ'=>'dh','ण'=>'n','त'=>'t','थ'=>'th','द'=>'d','ध'=>'dh','न'=>'n','प'=>'p','फ'=>'ph','ब'=>'b','भ'=>'bh','म'=>'m','य'=>'y','र'=>'r','ल'=>'l','व'=>'v','श'=>'sh','ष'=>'sh','स'=>'s','ह'=>'h','ळ'=>'l'];
        $matra = ['ा'=>'aa','ि'=>'i','ी'=>'ee','ु'=>'u','ू'=>'oo','ृ'=>'ri','े'=>'e','ै'=>'ai','ो'=>'o','ौ'=>'au'];
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $count = count($chars);
        for ($i=0; $i<$count; $i++) {
            $ch = $chars[$i];
            if (isset($vowels[$ch])) { $out .= $vowels[$ch]; continue; }
            if (isset($cons[$ch])) {
                $out .= $cons[$ch];
                $next = $chars[$i+1] ?? '';
                if ($next === '्') { $i++; continue; }
                if (isset($matra[$next])) { $out .= $matra[$next]; $i++; continue; }
                $out .= 'a'; continue;
            }
            if ($ch === 'ं' || $ch === 'ँ') { $out .= 'n'; continue; }
            if ($ch === 'ः') { $out .= 'h'; continue; }
            if (isset($matra[$ch]) || $ch === '्') continue;
            $out .= $ch;
        }
        $out = preg_replace('/\s+/u', ' ', trim($out)) ?? trim($out);
        return $out !== '' ? ucwords($out) : 'Commodity';
    }

    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'market';
    }
}

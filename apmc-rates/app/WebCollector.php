<?php
declare(strict_types=1);

/**
 * Web-native incremental collector.
 *
 * Purpose: Cloudways and other managed PHP hosts can disable exec/popen/proc_open.
 * In that case a Python collector can never be launched from a web request. This
 * class performs one fixed APMC feed step directly in PHP, writes the same flat
 * files, and returns quickly enough to be driven incrementally by the browser.
 *
 * Security: URLs are constructed only from collector/config.json; no caller URL
 * is accepted, so this endpoint cannot be used as a generic HTTP proxy/SSRF path.
 */
final class WebCollector {
    private array $sourceConfig;
    private array $commodityMap;

    public function __construct(private readonly RateRepository $repo) {
        $this->sourceConfig = apmc_json_read(APMC_ROOT . '/collector/config.json', []);
        $this->commodityMap = apmc_json_read(APMC_ROOT . '/collector/commodity_map.json', []);
    }

    public function capabilities(): array {
        return [
            'available' => $this->fetchAvailable(),
            'curl' => function_exists('curl_init') && function_exists('curl_exec'),
            'url_fopen' => filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
            'mode' => 'incremental_php',
            'markets_per_step' => 1,
        ];
    }

    public function step(bool $force = false): array {
        $markets = is_array($this->sourceConfig['markets'] ?? null) ? $this->sourceConfig['markets'] : [];
        if (!$markets) return ['triggered' => false, 'reason' => 'web_collector_config_missing'];
        if (!$this->fetchAvailable()) return ['triggered' => false, 'reason' => 'php_http_client_unavailable'];

        $systemDir = APMC_STORAGE . '/system';
        if (!is_dir($systemDir)) @mkdir($systemDir, 0775, true);
        $lock = @fopen($systemDir . '/refresh.lock', 'c+');
        if (!is_resource($lock)) return ['triggered' => false, 'reason' => 'refresh_lock_unavailable'];
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return ['triggered' => false, 'reason' => 'refresh_busy'];
        }

        try {
            $statePath = $systemDir . '/web-refresh-state.json';
            $state = apmc_json_read($statePath, ['cursor' => 0, 'cycle' => 0]);
            $cursor = max(0, (int)($state['cursor'] ?? 0));
            $manifestPath = $systemDir . '/manifest.json';
            $manifest = apmc_json_read($manifestPath, ['markets' => []]);

            // Pick the next feed that actually needs attention. Missing feeds are
            // immediate candidates unless they just failed; populated feeds follow
            // the same time-of-day refresh cadence as the Python runtime.
            $market = null; $marketIndex = null; $count = count($markets);
            for ($offset = 0; $offset < $count; $offset++) {
                $idx = ($cursor + $offset) % $count;
                $candidate = $markets[$idx];
                $slug = (string)($candidate['slug'] ?? '');
                $feedState = is_array($manifest['markets'][$slug] ?? null) ? $manifest['markets'][$slug] : [];
                $snapshotExists = is_file($this->marketSnapshotPath($slug));
                $last = strtotime((string)($feedState['last_checked_at'] ?? '')) ?: 0;
                $age = $last ? time() - $last : PHP_INT_MAX;
                $cooldown = (($feedState['status'] ?? '') === 'error') ? 45 : $this->intervalSeconds();
                if ($force || !$last || $age >= $cooldown) { $market = $candidate; $marketIndex = $idx; break; }
            }
            if ($market === null || $marketIndex === null) {
                return ['triggered' => false, 'reason' => 'web_collector_fresh', 'records' => (int)($this->repo->latest()['meta']['records'] ?? 0)];
            }

            $startedAt = $this->nowIso();
            $manifest['refresh_started_at'] = $startedAt;
            $manifest['refresh_trigger'] = 'php-web';
            $manifest['web_collector'] = ['active' => true, 'cursor' => $marketIndex, 'market' => (string)($market['slug'] ?? '')];
            $this->atomicJson($manifestPath, $manifest);

            $changed = $this->processMarket($market, $manifest, $force);
            [$aggregate, $aggregateHash] = $this->rebuildAggregate($manifest);

            $manifest['last_checked_at'] = $this->nowIso();
            if ($changed || ($manifest['aggregate_hash'] ?? '') !== $aggregateHash) $manifest['last_updated_at'] = $this->nowIso();
            $manifest['aggregate_hash'] = $aggregateHash;
            $manifest['refresh_finished_at'] = $this->nowIso();
            $manifest['status'] = (string)($aggregate['meta']['status'] ?? 'warming');
            $manifest['web_collector'] = ['active' => false, 'last_market' => (string)($market['slug'] ?? ''), 'last_step_at' => $this->nowIso()];
            $this->atomicJson($manifestPath, $manifest);
            $this->writeHealth($aggregate, $manifest);

            $next = ($marketIndex + 1) % count($markets);
            $state = [
                'cursor' => $next,
                'cycle' => (int)($state['cycle'] ?? 0) + ($next === 0 ? 1 : 0),
                'last_market' => (string)($market['slug'] ?? ''),
                'last_step_at' => $this->nowIso(),
            ];
            $this->atomicJson($statePath, $state);

            return [
                'triggered' => true,
                'method' => 'php_web',
                'market' => (string)($market['slug'] ?? ''),
                'changed' => $changed,
                'next_cursor' => $next,
                'cycle_complete' => $next === 0,
                'records' => (int)($aggregate['meta']['records'] ?? 0),
                'markets_available' => (int)($aggregate['meta']['markets_available'] ?? 0),
                'markets_expected' => count($markets),
                'feed_status' => (string)($manifest['markets'][(string)($market['slug'] ?? '')]['status'] ?? 'unknown'),
                'feed_error' => $manifest['markets'][(string)($market['slug'] ?? '')]['error'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['triggered' => false, 'reason' => 'php_web_error', 'error' => $this->shortError($e)];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function intervalSeconds(): int {
        $hour = (int)date('G');
        if ($hour >= 9 && $hour < 15) return (int)apmc_config('refresh.active_seconds', 300);
        if ($hour >= 6 && $hour < 9) return (int)apmc_config('refresh.morning_seconds', 900);
        if ($hour >= 15 && $hour < 18) return (int)apmc_config('refresh.evening_seconds', 600);
        return (int)apmc_config('refresh.overnight_seconds', 3600);
    }

    private function fetchAvailable(): bool {
        if (function_exists('curl_init') && function_exists('curl_exec')) return true;
        return filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    private function processMarket(array $market, array &$manifest, bool $force): bool {
        $slug = (string)($market['slug'] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) throw new RuntimeException('Invalid market slug');
        $state = is_array($manifest['markets'][$slug] ?? null) ? $manifest['markets'][$slug] : [];
        $snapshot = apmc_json_read($this->marketSnapshotPath($slug), []);
        $previousDate = (string)($state['date'] ?? ($snapshot['date'] ?? ''));
        $previousHash = (string)($state['hash'] ?? ($snapshot['source_hash'] ?? ''));
        if ($previousDate !== '' && empty($state['date'])) $state['date'] = $previousDate;
        if ($previousHash !== '' && empty($state['hash'])) $state['hash'] = $previousHash;
        if (!empty($snapshot['records']) && empty($state['records'])) $state['records'] = count((array)$snapshot['records']);
        $today = date('Y-m-d');

        try {
            $result = $this->findLatestPopulated($market, $previousDate);
            if ($result === null) {
                // Empty dated pages are a normal source state. Keep any last-good
                // snapshot and mark the feed as having no newer publication.
                $state['status'] = 'no_new_data';
                $state['last_checked_at'] = $this->nowIso();
                $state['error'] = null;
                $state['runtime'] = 'php_web';
                $manifest['markets'][$slug] = $state;
                $this->appendLog('market_no_new_data_php', ['market' => $slug, 'previous_date' => $previousDate ?: null]);
                return false;
            }

            [$latestDate, $records, $sourceUrl] = $result;
            $sourceHash = $this->normalizedHash($records);
            $changed = $force || $sourceHash !== $previousHash || $latestDate !== $previousDate;
            if ($changed || !is_file($this->marketSnapshotPath($slug))) {
                $this->saveMarketSnapshot($market, $latestDate, $records, $sourceUrl, $sourceHash);
            }
            $status = ($latestDate === $previousDate && $latestDate !== $today) ? 'no_new_data' : 'ok';
            $manifest['markets'][$slug] = [
                'status' => $status, 'date' => $latestDate, 'hash' => $sourceHash, 'records' => count($records),
                'last_checked_at' => $this->nowIso(), 'last_full_fetch_at' => $this->nowIso(), 'error' => null,
                'runtime' => 'php_web',
            ];
            $this->appendLog('market_checked_php', ['market' => $slug, 'date' => $latestDate, 'changed' => $changed, 'status' => $status]);
            return $changed;
        } catch (Throwable $e) {
            $state['status'] = 'error';
            $state['last_checked_at'] = $this->nowIso();
            $state['error'] = $this->shortError($e, 600);
            $state['runtime'] = 'php_web';
            $manifest['markets'][$slug] = $state;
            $this->appendLog('market_error_php', ['market' => $slug, 'error' => $state['error']]);
            return false;
        }
    }

    /** @return array{0:string,1:array,2:string}|null */
    private function findLatestPopulated(array $market, string $previousDate): ?array {
        foreach ($this->candidateDates($market, $previousDate) as $date) {
            [$records, $sourceUrl] = $this->fetchMarketForDateAllowEmpty($market, $date);
            if ($records) return [$date, $records, $sourceUrl];
        }
        return null;
    }

    /** @return list<string> */
    private function candidateDates(array $market, string $previousDate): array {
        $slug = (string)$market['slug'];
        $dates = [date('Y-m-d')];
        $maxCandidates = max(3, min(31, (int)($this->sourceConfig['date_probe_limit'] ?? 14)));

        try {
            $body = $this->fetchPath('/bajarbhav/daily-bajarbhav-dates/' . rawurlencode($slug));
            preg_match_all('~/view-daily-bajarbhav/' . preg_quote($slug, '~') . '/(\d{4}-\d{2}-\d{2})~u', rawurldecode($body), $m);
            foreach ($m[1] ?? [] as $date) $dates[] = (string)$date;
        } catch (Throwable $e) {
            // Listing is an optimization only. Direct date URLs remain authoritative.
            $this->appendLog('date_listing_error_php', ['market' => $slug, 'error' => $this->shortError($e)]);
        }

        // If the listing is unavailable or sparse, probe recent direct URLs too.
        for ($i = 1; $i < min($maxCandidates, 8); $i++) {
            $dates[] = date('Y-m-d', strtotime('-' . $i . ' day'));
        }
        if ($previousDate !== '') $dates[] = $previousDate;

        $dates = array_values(array_unique(array_filter($dates, fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d))));
        rsort($dates, SORT_STRING);

        if ($previousDate !== '') {
            $newer = array_values(array_filter($dates, fn($d) => $d >= $previousDate));
            if ($newer) $dates = $newer;
        }
        return array_slice($dates, 0, $maxCandidates);
    }

    private function fetchMarketForDateAllowEmpty(array $market, string $date): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Invalid source date');
        $slug = (string)$market['slug'];
        $path = '/bajarbhav/view-daily-bajarbhav/' . rawurlencode($slug) . '/' . $date;
        $sourceUrl = rtrim((string)$this->sourceConfig['source_base'], '/') . $path;
        try {
            $body = $this->fetchPath($path);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '(404)') || str_contains($message, '(410)')) return [[], $sourceUrl];
            throw $e;
        }
        $records = $this->parseRateTable($body, $market, $date);
        return [$records, $sourceUrl];
    }

    private function fetchMarketForDate(array $market, string $date): array {
        [$records, $sourceUrl] = $this->fetchMarketForDateAllowEmpty($market, $date);
        if (!$records) throw new RuntimeException('No valid 6-column rate rows found');
        return [$records, $sourceUrl];
    }

    private function fetchPath(string $path): string {
        $base = rtrim((string)($this->sourceConfig['source_base'] ?? apmc_config('source_base', 'https://apmcpune.in')), '/');
        if (!preg_match('~^https://apmcpune\.in$~i', $base)) throw new RuntimeException('Unexpected source host');
        if (!str_starts_with($path, '/bajarbhav/')) throw new RuntimeException('Unexpected source path');
        $url = $base . $path;
        $timeout = max(4, min(8, (int)($this->sourceConfig['timeout_seconds'] ?? apmc_config('refresh.source_timeout_seconds', 8))));
        $ua = (string)($this->sourceConfig['user_agent'] ?? 'APMC-Pune-Data-Engine/1.4.0');

        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = @curl_init($url);
            if ($ch !== false) {
                @curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: mr,en;q=0.9', 'Cache-Control: no-cache'],
                    CURLOPT_ENCODING => '',
                ]);
                $body = @curl_exec($ch);
                $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = (string)@curl_error($ch);
                @curl_close($ch);
                if (is_string($body) && $body !== '' && $code >= 200 && $code < 400) return $body;
                throw new RuntimeException('Source HTTP fetch failed' . ($code ? ' (' . $code . ')' : '') . ($error ? ': ' . $error : ''));
            }
        }

        if (filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
                    'header' => "User-Agent: {$ua}\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: mr,en;q=0.9\r\nCache-Control: no-cache\r\nConnection: close\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach (($http_response_header ?? []) as $line) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $line, $m)) $status = (int)$m[1];
            }
            if (is_string($body) && $body !== '' && ($status === 0 || ($status >= 200 && $status < 400))) return $body;
            throw new RuntimeException('Source stream fetch failed' . ($status ? ' (' . $status . ')' : ''));
        }

        throw new RuntimeException('No PHP HTTP client available');
    }

    private function parseRateTable(string $body, array $market, string $date): array {
        preg_match_all('~<tr\b[^>]*>(.*?)</tr>~isu', $body, $rows);
        $best = [];
        $current = [];
        foreach ($rows[1] ?? [] as $rowHtml) {
            preg_match_all('~<t[dh]\b[^>]*>(.*?)</t[dh]>~isu', $rowHtml, $cells);
            if (count($cells[1] ?? []) < 6) continue;
            $values = array_map(fn($v) => $this->cleanText((string)$v), $cells[1]);
            $arrival = $this->parseNumber($values[2] ?? '');
            $min = $this->parseNumber($values[3] ?? '');
            $max = $this->parseNumber($values[4] ?? '');
            $avg = $this->parseNumber($values[5] ?? '');
            if ($min === null || $max === null || $avg === null) continue;
            $sourceName = $values[0] ?? '';
            if ($sourceName === '' || str_contains($sourceName, 'शेतमालाचे नाव') || stripos($sourceName, 'Commodity') !== false) continue;
            [$nameMr, $nameEn, $emoji] = $this->resolveCommodityName($sourceName);
            $key = substr(sha1(strtolower($nameEn . '|' . $nameMr)), 0, 14);
            $current[] = [
                'commodity_key' => $key, 'source_name' => $sourceName, 'name_mr' => $nameMr, 'name_en' => $nameEn,
                'emoji' => $emoji, 'unit' => $values[1] ?? '', 'arrival' => $arrival ?? 0,
                'min' => $min, 'max' => $max, 'average' => $avg, 'date' => $date,
                'market_slug' => (string)$market['slug'], 'feed_slug' => (string)$market['slug'], 'market_id' => (string)($market['market_id'] ?? $market['slug']), 'market_name' => (string)$market['market'],
                'market_name_en' => (string)$market['market'], 'market_name_mr' => (string)($market['market_mr'] ?? $market['market']),
                'category' => (string)$market['category'], 'category_en' => (string)$market['category'],
                'category_mr' => (string)($market['category_mr'] ?? $market['category']),
            ];
        }
        if (count($current) > count($best)) $best = $current;
        return $best;
    }

    private function cleanText(string $value): string {
        $value = preg_replace('~<br\s*/?>~i', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function parseNumber(string $value): int|float|null {
        $value = str_replace(',', '', $this->cleanText($value));
        if (!preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) return null;
        $n = (float)$m[0];
        return floor($n) === $n ? (int)$n : $n;
    }

    private function resolveCommodityName(string $sourceName): array {
        $entry = $this->commodityMap[$sourceName] ?? null;
        if (is_array($entry)) return [(string)($entry['mr'] ?? $sourceName), (string)($entry['en'] ?? $sourceName), (string)($entry['emoji'] ?? '◦')];
        $folded = strtolower($sourceName);
        foreach ($this->commodityMap as $mr => $candidate) {
            if (!is_array($candidate)) continue;
            $en = $this->cleanText((string)($candidate['en'] ?? ''));
            $aliases = is_array($candidate['aliases'] ?? null) ? $candidate['aliases'] : [];
            if (($en !== '' && strtolower($en) === $folded) || in_array($folded, array_map(fn($a) => strtolower($this->cleanText((string)$a)), $aliases), true)) {
                return [(string)($candidate['mr'] ?? $mr), $en !== '' ? $en : $sourceName, (string)($candidate['emoji'] ?? '◦')];
            }
        }
        return [$sourceName, $sourceName, '◦'];
    }

    private function normalizedHash(array $records): string {
        $rows = array_map(fn($r) => [$r['name_mr'], $r['unit'], $r['arrival'], $r['min'], $r['max'], $r['average']], $records);
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function marketSnapshotPath(string $slug): string { return APMC_STORAGE . '/markets/' . $slug . '.json'; }
    private function dailySnapshotPath(string $date, string $slug): string {
        [$y, $m, $d] = explode('-', $date);
        return APMC_STORAGE . '/daily/' . $y . '/' . $m . '/' . $d . '/' . $slug . '.json';
    }

    private function saveMarketSnapshot(array $market, string $date, array $records, string $sourceUrl, string $sourceHash): void {
        $snapshot = ['market' => $market, 'date' => $date, 'source_url' => $sourceUrl, 'source_hash' => $sourceHash, 'captured_at' => $this->nowIso(), 'records' => $records];
        $this->atomicJson($this->marketSnapshotPath((string)$market['slug']), $snapshot);
        $this->atomicJson($this->dailySnapshotPath($date, (string)$market['slug']), $snapshot);
        foreach ($records as $record) $this->updateCommodityIndex($record);
    }

    private function updateCommodityIndex(array $record): void {
        $path = APMC_STORAGE . '/commodities/' . $record['commodity_key'] . '.json';
        $doc = apmc_json_read($path, ['commodity_key' => $record['commodity_key'], 'name_mr' => $record['name_mr'], 'name_en' => $record['name_en'], 'emoji' => $record['emoji'] ?? '◦', 'history' => []]);
        $history = array_values(array_filter(is_array($doc['history'] ?? null) ? $doc['history'] : [], fn($h) => !(($h['date'] ?? '') === $record['date'] && ($h['market_slug'] ?? '') === $record['market_slug'])));
        $history[] = [
            'date' => $record['date'], 'market_slug' => $record['market_slug'], 'market_name' => $record['market_name'],
            'market_name_en' => $record['market_name_en'], 'market_name_mr' => $record['market_name_mr'],
            'category' => $record['category'], 'category_en' => $record['category_en'], 'category_mr' => $record['category_mr'],
            'unit' => $record['unit'], 'arrival' => $record['arrival'], 'min' => $record['min'], 'max' => $record['max'], 'average' => $record['average'],
        ];
        usort($history, fn($a, $b) => (($a['date'] ?? '') <=> ($b['date'] ?? '')) ?: (($a['market_slug'] ?? '') <=> ($b['market_slug'] ?? '')));
        if (count($history) > 730) $history = array_slice($history, -730);
        $doc['history'] = $history;
        $this->atomicJson($path, $doc);
    }

    private function enrichRecord(array $record, array &$cache): array {
        $key = (string)$record['commodity_key'];
        if (!array_key_exists($key, $cache)) $cache[$key] = apmc_json_read(APMC_STORAGE . '/commodities/' . $key . '.json', ['history' => []]);
        $history = array_values(array_filter(is_array($cache[$key]['history'] ?? null) ? $cache[$key]['history'] : [], fn($h) => ($h['market_slug'] ?? '') === $record['market_slug']));
        usort($history, fn($a, $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));
        $prior = array_values(array_filter($history, fn($h) => ($h['date'] ?? '') < $record['date']));
        $prev = $prior ? $prior[count($prior) - 1] : null;
        $previousAverage = is_array($prev) ? ($prev['average'] ?? null) : null;
        $changeAmount = $changePct = null;
        if ($previousAverage !== null && (float)$previousAverage !== 0.0) {
            $changeAmount = (float)$record['average'] - (float)$previousAverage;
            $changePct = round($changeAmount / (float)$previousAverage * 100, 2);
        }
        $trend = [];
        foreach ($history as $h) if (($h['date'] ?? '') <= $record['date']) $trend[] = (float)($h['average'] ?? 0);
        $record['previous_average'] = $previousAverage;
        $record['change_amount'] = $changeAmount;
        $record['change_pct'] = $changePct;
        $record['trend'] = array_slice($trend, -7);
        return $record;
    }

    private function rebuildAggregate(array $manifest): array {
        $markets = is_array($this->sourceConfig['markets'] ?? null) ? $this->sourceConfig['markets'] : [];
        $all = []; $summaries = []; $categories = []; $cache = [];
        foreach ($markets as $market) {
            $snap = apmc_json_read($this->marketSnapshotPath((string)$market['slug']), []);
            $records = is_array($snap['records'] ?? null) ? $snap['records'] : [];
            if (!$records) continue;
            foreach ($records as $r) $all[] = $this->enrichRecord($r, $cache);
            $summaries[] = [
                'slug' => $market['slug'], 'name' => $market['market'], 'name_en' => $market['market'], 'name_mr' => $market['market_mr'] ?? $market['market'],
                'category' => $market['category'], 'category_en' => $market['category'], 'category_mr' => $market['category_mr'] ?? $market['category'],
                'date' => $snap['date'] ?? null, 'records' => count($records), 'captured_at' => $snap['captured_at'] ?? null,
            ];
            $categories[(string)$market['category']] = true;
        }

        $up = $down = $unchanged = 0; $arrival = 0.0;
        foreach ($all as $r) {
            $arrival += (float)($r['arrival'] ?? 0);
            $c = $r['change_pct'] ?? null;
            if ($c === null || abs((float)$c) < .005) $unchanged++; elseif ((float)$c > 0) $up++; else $down++;
        }
        $dates = array_values(array_filter(array_map(fn($m) => $m['date'] ?? null, $summaries)));
        $servingDate = $dates ? max($dates) : null;

        $featured = []; $used = [];
        foreach (['Tomato','Onion','Potato','Garlic','Ginger','Okra','Chilli','Peas'] as $name) {
            $matches = array_values(array_filter($all, fn($r) => ($r['name_en'] ?? '') === $name));
            usort($matches, fn($a,$b) => (($b['date'] ?? '') <=> ($a['date'] ?? '')) ?: ((float)($b['arrival'] ?? 0) <=> (float)($a['arrival'] ?? 0)));
            if ($matches) {
                $k = ($matches[0]['market_slug'] ?? '') . ':' . ($matches[0]['commodity_key'] ?? '');
                if (!isset($used[$k])) { $featured[] = $matches[0]; $used[$k] = true; }
            }
        }
        if (count($featured) < 4) {
            $candidates = $all;
            usort($candidates, fn($a,$b) => (float)($b['arrival'] ?? 0) <=> (float)($a['arrival'] ?? 0));
            foreach ($candidates as $r) {
                $k = ($r['market_slug'] ?? '') . ':' . ($r['commodity_key'] ?? '');
                if (!isset($used[$k])) { $featured[] = $r; $used[$k] = true; }
                if (count($featured) >= 8) break;
            }
        }

        $states = is_array($manifest['markets'] ?? null) ? $manifest['markets'] : [];
        $errors = 0; $stale = 0; $today = date('Y-m-d');
        foreach ($states as $s) {
            if (($s['status'] ?? '') === 'error') $errors++;
            if (!empty($s['date']) && $s['date'] !== $today) $stale++;
        }
        $status = $all ? ($errors === 0 ? 'healthy' : 'degraded') : 'warming';
        $categoryNames = array_keys($categories); sort($categoryNames, SORT_STRING);
        $aggregate = [
            'meta' => [
                'generated_at' => $this->nowIso(), 'serving_date' => $servingDate, 'status' => $status,
                'records' => count($all), 'markets_available' => count($summaries), 'markets_expected' => count($markets),
                'markets_error' => $errors, 'markets_stale' => $stale, 'collector_runtime' => 'php_web',
            ],
            'pulse' => ['up' => $up, 'down' => $down, 'unchanged' => $unchanged, 'total_arrival' => round($arrival, 2)],
            'markets' => $summaries, 'categories' => $categoryNames, 'featured' => array_slice($featured, 0, 8), 'records' => $all,
        ];
        $this->atomicJson(APMC_STORAGE . '/current/all.json', $aggregate);
        $this->writeCsv($all);
        $hash = hash('sha256', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '[]');
        return [$aggregate, $hash];
    }

    private function writeCsv(array $records): void {
        $path = APMC_STORAGE . '/exports/latest.csv';
        $dir = dirname($path); if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $path . '.tmp'; $fh = @fopen($tmp, 'wb'); if (!is_resource($fh)) return;
        fwrite($fh, "\xEF\xBB\xBF");
        $fields = ['date','market_id','market_name','market_name_en','market_name_mr','feed_slug','market_slug','category','category_en','category_mr','source_name','name_mr','name_en','unit','arrival','min','max','average','change_pct'];
        fputcsv($fh, $fields);
        foreach ($records as $r) fputcsv($fh, array_map(fn($f) => $r[$f] ?? '', $fields));
        fclose($fh); @rename($tmp, $path);
    }

    private function writeHealth(array $aggregate, array $manifest): void {
        $errors = [];
        foreach (($manifest['markets'] ?? []) as $slug => $state) if (($state['status'] ?? '') === 'error') $errors[] = ['market' => $slug, 'error' => $state['error'] ?? 'Unknown source error'];
        $health = [
            'status' => $aggregate['meta']['status'] ?? 'warming', 'checked_at' => $this->nowIso(),
            'collector_runtime' => 'php_web', 'storage_writable' => is_writable(APMC_STORAGE),
            'records' => $aggregate['meta']['records'] ?? 0, 'markets_available' => $aggregate['meta']['markets_available'] ?? 0,
            'markets_expected' => count($this->sourceConfig['markets'] ?? []), 'errors' => $errors,
        ];
        $this->atomicJson(APMC_STORAGE . '/system/health.json', $health);
    }

    private function atomicJson(string $path, array $data): void {
        $dir = dirname($path); if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Storage directory is not writable');
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Unable to write storage file');
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Unable to commit storage file'); }
    }

    private function appendLog(string $event, array $context = []): void {
        $path = APMC_STORAGE . '/system/refresh.log';
        @file_put_contents($path, (json_encode(['time' => $this->nowIso(), 'event' => $event] + $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n", FILE_APPEND | LOCK_EX);
    }

    private function nowIso(): string { return date('c'); }
    private function shortError(Throwable $e, int $max = 300): string { return substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?: 'Unknown error', 0, $max); }
}

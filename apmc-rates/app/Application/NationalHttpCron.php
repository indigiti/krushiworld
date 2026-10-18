<?php
declare(strict_types=1);

/**
 * A single, bounded HTTP cron step for the fixed official AGMARKNET resource.
 *
 * Safe for managed PHP-FPM: no exec/putenv, no caller-controlled URL, no public
 * API secret, and no unbounded requests on one web hit. Concurrent PHP/CLI
 * workers share the national-refresh flock; the last-good snapshot is retained
 * until a complete, verified dataset can be published.
 */
final class NationalHttpCron {
    private const RESOURCE = '9ef84268-d588-465a-a308-a864a43d0070';
    private const LIMIT = 1000;
    private const MAX_ROWS = 250000;
    private const STEP_GAP = 50;
    private const RETRY_GAP = 3600;
    private const IN_PROGRESS_TTL = 172800;
    private const MAX_RESPONSE_BYTES = 12582912;
    private readonly ?Closure $fetcher;

    public function __construct(private readonly string $storage, ?callable $fetcher = null) {
        $this->fetcher = $fetcher === null ? null : Closure::fromCallable($fetcher);
    }

    private function path(string $name): string { return $this->storage . '/national/' . $name; }

    private function read(string $name): array {
        $path = $this->path($name);
        if (!is_file($path) || is_link($path)) return [];
        $raw = @file_get_contents($path, false, null, 0, 65536);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private function write(string $name, array $data): void {
        $dest = $this->path($name);
        if (is_link($dest)) throw new RuntimeException('unsafe_private_path');
        $temp = @tempnam(dirname($dest), '.http-cron-');
        if ($temp === false) throw new RuntimeException('storage_unavailable');
        try {
            @chmod($temp, 0600);
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (@file_put_contents($temp, $body, LOCK_EX) !== strlen($body) || !@rename($temp, $dest)) {
                throw new RuntimeException('storage_unavailable');
            }
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    private static function date(string $raw): ?string {
        $utc = new DateTimeZone('UTC');
        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, trim($raw), $utc);
            if ($date && $date->format($format) === trim($raw) && $date->format('Y-m-d') <= gmdate('Y-m-d')) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    private static function price(mixed $value): ?float {
        if (!is_scalar($value)) return null;
        $str = str_replace(',', '', trim((string)$value));
        if ($str === '' || !is_numeric($str)) return null;
        $number = (float)$str;
        return is_finite($number) && $number >= 0 && $number < 1000000000 ? $number : null;
    }

    private static function marketId(string $state, string $district, string $market): string {
        $identity = strtolower(trim($state)) . '|' . strtolower(trim($district)) . '|' . strtolower(trim($market));
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($market)), '-');
        return 'agmarknet-' . substr($slug ?: 'market', 0, 55) . '-' . substr(sha1($identity), 0, 12);
    }

    private static function transform(array $raw): ?array {
        $fields = array_change_key_case($raw, CASE_LOWER);
        $state = trim((string)($fields['state'] ?? ''));
        $district = trim((string)($fields['district'] ?? ''));
        $market = trim((string)($fields['market'] ?? ''));
        $commodity = trim((string)($fields['commodity'] ?? ''));
        $date = self::date((string)($fields['arrival_date'] ?? ''));
        $min = self::price($fields['min_price'] ?? null);
        $max = self::price($fields['max_price'] ?? null);
        $modal = self::price($fields['modal_price'] ?? null);
        if ($state === '' || $district === '' || $market === '' || $commodity === '' || $date === null
            || $min === null || $max === null || $modal === null || $min > $modal || $modal > $max) return null;
        $id = self::marketId($state, $district, $market);
        return [
            'market_id'=>$id, 'market_name'=>$market, 'market_name_en'=>$market,
            'name_en'=>$commodity, 'name_mr'=>$commodity,
            'commodity_key'=>substr(sha1(strtolower($commodity)), 0, 14),
            'category'=>'Agricultural produce', 'state'=>$state, 'district'=>$district,
            'variety'=>trim((string)($fields['variety'] ?? '')),
            'grade'=>trim((string)($fields['grade'] ?? '')),
            'date'=>$date, 'min'=>$min, 'max'=>$max, 'average'=>$modal,
            'price_type'=>'modal', 'unit'=>'quintal', 'source'=>'AGMARKNET / data.gov.in',
            'arrival'=>null, 'change_pct'=>null,
        ];
    }

    /** Strictly one fixed HTTPS resource, one page, under a bounded timeout. */
    private function fetch(string $key, int $offset): array {
        if ($this->fetcher !== null) {
            $result = ($this->fetcher)($offset, self::LIMIT);
            if (!is_array($result)) throw new RuntimeException('invalid_source_response');
            return $result;
        }
        $url = 'https://api.data.gov.in/resource/' . self::RESOURCE . '?' .
            http_build_query(['api-key'=>$key, 'format'=>'json', 'limit'=>self::LIMIT, 'offset'=>$offset]);
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $handle = curl_init($url);
            if ($handle === false) throw new RuntimeException('http_client_unavailable');
            try {
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>18,
                    CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_HTTPHEADER=>['Accept: application/json', 'User-Agent: KrushiPulse-AGMARKNET/1.0'],
                ]);
                $body = curl_exec($handle);
                $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if (!is_string($body) || strlen($body) > self::MAX_RESPONSE_BYTES || $status !== 200) {
                    throw new RuntimeException('official_feed_unavailable');
                }
            } finally {
                curl_close($handle);
            }
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create(['http'=>[
                'timeout'=>18,'follow_location'=>0,'ignore_errors'=>false,
                'header'=>"Accept: application/json\r\nUser-Agent: KrushiPulse-AGMARKNET/1.0\r\n"
            ], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $body = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
            if (!is_string($body) || strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('official_feed_unavailable');
            }
        } else {
            throw new RuntimeException('http_client_unavailable');
        }
        $decoded = json_decode($body, true, 32);
        if (!is_array($decoded)) throw new RuntimeException('invalid_source_response');
        return $decoded;
    }

    /** Stream final candidate to disk; verify before replacing current snapshot. */
    private function publish(array $progress, string $time): array {
        $staging = $this->path('http-cron-rows.ndjson');
        $source = @fopen($staging, 'rb');
        if (!is_resource($source)) throw new RuntimeException('staging_unavailable');
        $temp = @tempnam(dirname($staging), '.http-national-');
        if ($temp === false) { fclose($source); throw new RuntimeException('storage_unavailable'); }
        @chmod($temp, 0600);
        $out = @fopen($temp, 'wb');
        if (!is_resource($out)) { fclose($source); @unlink($temp); throw new RuntimeException('storage_unavailable'); }
        $old = $this->read('current.json');
        $oldMeta = (array)($old['meta'] ?? []);
        $oldCount = (int)($oldMeta['records'] ?? 0);
        $previousLatest = null;
        if ($oldCount > 0) {
            foreach ((array)($old['records'] ?? []) as $item) {
                $d = (string)($item['date'] ?? '');
                if ($d > (string)$previousLatest) $previousLatest = $d;
            }
        }
        $count = 0; $latest = null; $markets = []; $seen = [];
        try {
            // Reserve space for metadata; rows are streamed into a second temporary file.
            fwrite($out, '{"records":[');
            while (($line = fgets($source)) !== false) {
                $row = json_decode($line, true);
                if (!is_array($row)) throw new RuntimeException('invalid_staged_record');
                $min = self::price($row['min'] ?? null);
                $max = self::price($row['max'] ?? null);
                $modal = self::price($row['average'] ?? null);
                $date = self::date((string)($row['date'] ?? ''));
                if ($min === null || $max === null || $modal === null || $min > $modal || $modal > $max || !$date) {
                    throw new RuntimeException('invalid_staged_price');
                }
                $id = (string)($row['market_id'] ?? '');
                if (!preg_match('/^agmarknet-[a-z0-9-]{1,90}$/D', $id)) throw new RuntimeException('invalid_staged_market');
                $identity = json_encode([$id,$row['commodity_key'] ?? '',$row['variety'] ?? '',$row['grade'] ?? '',$date]);
                $digest = hash('sha256', (string)$identity);
                if (isset($seen[$digest])) continue; // matches Python's deduplication
                $seen[$digest] = true;
                if ($count > 0) fwrite($out, ',');
                fwrite($out, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $count++;
                if ($latest === null || $date > $latest) $latest = $date;
                if (!isset($markets[$id])) {
                    $markets[$id] = [
                        'market_id'=>$id,'slug'=>$id,'name'=>(string)$row['market_name_en'],
                        'name_en'=>(string)$row['market_name_en'],'name_mr'=>(string)$row['market_name_en'],
                        'state'=>(string)$row['state'],'district'=>(string)$row['district'],
                        'source'=>'AGMARKNET','records'=>0,'date'=>null,
                    ];
                }
                $markets[$id]['records']++;
                if ($markets[$id]['date'] === null || $date > $markets[$id]['date']) $markets[$id]['date'] = $date;
                if ($count > self::MAX_ROWS) throw new RuntimeException('too_many_records');
            }
            if ($count < 1 || $count !== (int)($progress['valid_rows'] ?? -1) - (int)($progress['duplicates'] ?? 0) && $count < 1) {
                throw new RuntimeException('invalid_record_count');
            }
            if ($oldCount >= 100 && $count < $oldCount * 0.25) throw new RuntimeException('unexpected_record_drop');
            if ($previousLatest !== null && $latest < $previousLatest) throw new RuntimeException('source_date_regressed');
            $meta = [
                'source'=>'AGMARKNET / data.gov.in','resource'=>self::RESOURCE,
                'updated_at'=>$time,'markets'=>count($markets),'records'=>$count
            ];
            fwrite($out, '],"meta":'.json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).'}');
            fflush($out);
            $oldDirectory = $this->read('directory.json');
            $directory = [];
            foreach ((array)($oldDirectory['markets'] ?? []) as $m) {
                if (is_array($m) && !empty($m['market_id'])) $directory[$m['market_id']] = array_merge($m, ['records'=>0]);
            }
            foreach ($markets as $id=>$value) $directory[$id] = $value;
            $list = array_values($directory);
            usort($list, static fn(array $a,array $b) =>
                strcmp((string)($a['state'] ?? ''),(string)($b['state'] ?? ''))
                ?: strcmp((string)($a['district'] ?? ''),(string)($b['district'] ?? ''))
                ?: strcmp((string)($a['name_en'] ?? ''),(string)($b['name_en'] ?? '')));
            // A verified flag is only written AFTER both candidate files publish.
            $this->write('directory.json', [
                'meta'=>['source'=>'AGMARKNET / data.gov.in','updated_at'=>$time], 'markets'=>$list
            ]);
            if (!@rename($temp, $this->path('current.json'))) throw new RuntimeException('storage_unavailable');
            $fingerprint = hash_file('sha256', $this->path('current.json'));
            $this->write('verified.json', [
                'verified'=>true,'verified_at'=>$time,'source'=>'AGMARKNET / data.gov.in',
                'source_sha256'=>$fingerprint,'records'=>$count,'markets'=>count($list),
                'latest_report_date'=>$latest
            ]);
            $this->write('health.json', [
                'last_attempt_at'=>$progress['started_at'], 'last_success_at'=>$time,
                'last_result'=>'success','records'=>$count,'markets'=>count($list),
                'latest_report_date'=>$latest,'method'=>'http_cron'
            ]);
            @unlink($this->path('refresh-request.json'));
            @unlink($this->path('http-cron-state.json'));
            @unlink($staging);
            return ['status'=>'verified','records'=>$count,'markets'=>count($list),'latest_report_date'=>$latest];
        } finally {
            fclose($out);
            fclose($source);
            if (is_file($temp)) @unlink($temp);
        }
    }

    /** Single page per call, globally rate-limited and locked across requests. */
    public function tick(?int $now = null): array {
        $now ??= time();
        $key = trim((string)KrushiPulseRuntimeSettings::get('APMC_DATA_GOV_IN_API_KEY'));
        if ($key === '') return ['status'=>'not_configured'];
        $dir = $this->storage . '/national';
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            return ['status'=>'storage_unavailable'];
        }
        $lockPath = $this->path('.national-refresh.lock');
        if (is_link($lockPath)) return ['status'=>'storage_unavailable'];
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) return ['status'=>'storage_unavailable'];
        @chmod($lockPath, 0600);
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['status'=>'busy']; }
        try {
            $progress = $this->read('http-cron-state.json');
            $started = strtotime((string)($progress['started_at'] ?? '')) ?: 0;
            $lastStep = strtotime((string)($progress['last_step_at'] ?? '')) ?: 0;
            if ($started && $now - $started > self::IN_PROGRESS_TTL) {
                @unlink($this->path('http-cron-state.json'));
                @unlink($this->path('http-cron-rows.ndjson'));
                $progress = []; $lastStep = 0;
            }
            if ($lastStep > 0 && $now >= $lastStep && $now - $lastStep < self::STEP_GAP) {
                return ['status'=>'throttled','next_offset'=>(int)($progress['offset'] ?? 0)];
            }
            if (!$progress) {
                $verified = $this->read('verified.json');
                $successAt = strtotime((string)($verified['verified_at'] ?? '')) ?: 0;
                $interval = (int)KrushiPulseRuntimeSettings::get('APMC_NATIONAL_INTERVAL_HOURS');
                $interval = ($interval >= 1 && $interval <= 24 ? $interval : 6) * 3600;
                if (($verified['verified'] ?? false) === true && $successAt > 0
                    && $now - $successAt < $interval
                    && is_file($this->path('current.json')) && is_file($this->path('directory.json'))) {
                    return ['status'=>'fresh','verified_at'=>$verified['verified_at']];
                }
                $attempt = $this->read('last_attempt.json');
                $attemptAt = strtotime((string)($attempt['attempted_at'] ?? '')) ?: 0;
                if ($attemptAt > 0 && $now - $attemptAt < self::RETRY_GAP && $now >= $attemptAt) {
                    return ['status'=>'cooldown'];
                }
                $startedAt = gmdate(DATE_ATOM,$now);
                $progress = ['started_at'=>$startedAt,'last_step_at'=>null,'offset'=>0,'expected'=>null,'valid_rows'=>0,'bytes'=>0,'pages'=>0];
                $staging = $this->path('http-cron-rows.ndjson');
                if (is_link($staging) || @file_put_contents($staging, '') === false) {
                    return ['status'=>'storage_unavailable'];
                }
                @chmod($staging,0600);
                $this->write('last_attempt.json',['attempted_at'=>$startedAt]);
                $this->write('http-cron-state.json',$progress);
            }
            $offset = (int)$progress['offset'];
            $response = $this->fetch($key, $offset);
            $rows = $response['records'] ?? null;
            $total = $response['total'] ?? null;
            if (!is_array($rows) || !is_numeric($total) || (int)$total < 1 || (int)$total > self::MAX_ROWS) {
                throw new RuntimeException('invalid_source_response');
            }
            $expected = $progress['expected'];
            if ($expected !== null && (int)$expected !== (int)$total) throw new RuntimeException('changing_source_total');
            if (!$rows || count($rows) > self::LIMIT || ($offset + count($rows) < (int)$total && count($rows) < self::LIMIT)) {
                throw new RuntimeException('incomplete_source_page');
            }
            if ($offset + count($rows) > (int)$total) throw new RuntimeException('invalid_source_total');
            $target = $this->path('http-cron-rows.ndjson');
            if (is_link($target)) throw new RuntimeException('unsafe_private_path');
            $handle = @fopen($target,'c+b');
            if (!is_resource($handle)) throw new RuntimeException('storage_unavailable');
            $added = 0;
            try {
                $committed = (int)($progress['bytes'] ?? 0);
                if (fseek($handle,0,SEEK_END) !== 0 || ftell($handle) < $committed
                    || !ftruncate($handle,$committed) || fseek($handle,$committed) !== 0) {
                    throw new RuntimeException('invalid_staged_state');
                }
                foreach ($rows as $row) {
                    $valid = is_array($row) ? self::transform($row) : null;
                    if ($valid === null) continue;
                    $line = json_encode($valid, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                    if (fwrite($handle,$line) !== strlen($line)) throw new RuntimeException('storage_unavailable');
                    $added++;
                }
                fflush($handle);
                $bytes = ftell($handle);
            } finally { fclose($handle); }
            $progress['offset'] = $offset + count($rows);
            $progress['expected'] = (int)$total;
            $progress['valid_rows'] = (int)$progress['valid_rows'] + $added;
            $progress['bytes'] = $bytes;
            $progress['pages'] = (int)$progress['pages'] + 1;
            $progress['last_step_at'] = gmdate(DATE_ATOM,$now);
            $this->write('http-cron-state.json',$progress);
            if ($progress['offset'] < (int)$total) {
                return ['status'=>'importing','offset'=>$progress['offset'],'total'=>(int)$total,
                    'valid_rows'=>$progress['valid_rows'],'pages'=>$progress['pages']];
            }
            return $this->publish($progress,gmdate(DATE_ATOM,$now));
        } catch (Throwable $e) {
            $reason = $e->getMessage();
            // Never report curl messages or request URLs (the outbound URL has the API key).
            $allowed = ['invalid_source_response','changing_source_total','incomplete_source_page',
                'invalid_source_total','invalid_staged_state','invalid_staged_record','invalid_staged_price',
                'invalid_staged_market','invalid_record_count','unexpected_record_drop','source_date_regressed',
                'http_client_unavailable','official_feed_unavailable','storage_unavailable','too_many_records'];
            $failure = in_array($reason,$allowed,true) ? $reason : 'collector_error';
            try {
                $prior = $this->read('health.json');
                $this->write('health.json',[
                    'last_attempt_at'=>$this->read('last_attempt.json')['attempted_at'] ?? gmdate(DATE_ATOM,$now),
                    'last_success_at'=>$prior['last_success_at'] ?? null,
                    'last_result'=>'failed','failure_type'=>$failure,'method'=>'http_cron'
                ]);
            } catch (Throwable) {}
            @unlink($this->path('http-cron-state.json'));
            @unlink($this->path('http-cron-rows.ndjson'));
            @unlink($this->path('refresh-request.json'));
            return ['status'=>'failed','reason'=>$failure];
        } finally {
            @flock($lock,LOCK_UN);
            @fclose($lock);
        }
    }
}

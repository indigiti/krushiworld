<?php
declare(strict_types=1);

namespace KrushiPulse\Application;

/**
 * Cheap, lock-protected visitor-side national refresh check.
 *
 * Visitors NEVER fetch data.gov.in or start a long Python process. Only the
 * scheduled collector consumes requests. The final verified marker is written
 * by the collector after validating and publishing the new snapshot.
 */
final class NationalRefreshGate {
    public function __construct(private readonly string $storage, private readonly int $intervalSeconds = 21600) {}

    private function json(string $name): array {
        $file = $this->storage . '/national/' . $name;
        if (!is_file($file) || is_link($file) || filesize($file) > 8192) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** @return array{status:string,queued:bool} */
    public function visit(?int $now = null): array {
        $now ??= time();
        $key = trim((string)\KrushiPulseRuntimeSettings::get('APMC_DATA_GOV_IN_API_KEY'));
        if ($key === '') return ['status'=>'not_configured','queued'=>false];
        $dir = $this->storage . '/national';
        if (is_link($dir)) return ['status'=>'storage_unavailable','queued'=>false];
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['status'=>'storage_unavailable','queued'=>false];
        }
        $lock = @fopen($dir . '/visitor-gate.lock', 'c');
        if (!is_resource($lock)) return ['status'=>'storage_unavailable','queued'=>false];
        @chmod($dir . '/visitor-gate.lock', 0600);
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return ['status'=>'busy','queued'=>false];
        }
        try {
            $verified = $this->json('verified.json');
            $snapshot = $dir . '/current.json';
            $directory = $dir . '/directory.json';
            $lastVerified = strtotime((string)($verified['verified_at'] ?? '')) ?: 0;
            // Verified metadata is meaningful only while the two published
            // snapshot files actually remain present and nonempty.
            if ($lastVerified > 0 && $lastVerified <= $now
                && $now - $lastVerified < max(3600,$this->intervalSeconds)
                && ($verified['verified'] ?? false) === true
                && (int)($verified['records'] ?? 0) > 0
                && is_file($snapshot) && filesize($snapshot) > 100
                && is_file($directory) && filesize($directory) > 50) {
                return ['status'=>'verified_fresh','queued'=>false];
            }
            $attempt = $this->json('last_attempt.json');
            $attemptedAt = strtotime((string)($attempt['attempted_at'] ?? '')) ?: 0;
            // The backend already enforces this retry cooldown. Match it here
            // so a burst of visits cannot continuously queue the same work.
            if ($attemptedAt > 0 && $attemptedAt <= $now && $now - $attemptedAt < 3600) {
                return ['status'=>'recent_attempt','queued'=>false];
            }
            $request = $this->json('refresh-request.json');
            $requestAt = strtotime((string)($request['requested_at'] ?? '')) ?: 0;
            if ($requestAt > 0 && $requestAt <= $now && $now - $requestAt < 900) {
                return ['status'=>'already_queued','queued'=>false];
            }
            $path = $dir . '/refresh-request.json';
            if (is_link($path)) return ['status'=>'storage_unavailable','queued'=>false];
            $payload = json_encode([
                'status'=>'pending',
                'requested_at'=>gmdate(DATE_ATOM,$now),
                'reason'=>$lastVerified ? 'verification_expired' : 'first_import',
            ], JSON_THROW_ON_ERROR);
            $temp = @tempnam($dir, '.refresh-request-');
            if ($temp === false) return ['status'=>'storage_unavailable','queued'=>false];
            @chmod($temp,0600);
            $ok = @file_put_contents($temp, $payload, LOCK_EX) === strlen($payload)
                && @rename($temp,$path);
            if (is_file($temp)) @unlink($temp);
            return ['status'=>$ok?'queued':'storage_unavailable','queued'=>$ok];
        } catch (\Throwable) {
            return ['status'=>'storage_unavailable','queued'=>false];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}

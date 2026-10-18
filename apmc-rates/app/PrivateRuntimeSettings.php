<?php
declare(strict_types=1);

/**
 * Optional persistent Cloudways settings.
 *
 * Private file: APMC_STORAGE/config/runtime.json (0600, never web-accessible).
 * Hosting environment variables take precedence. Never return configuration
 * contents in an HTTP/API response or include this file in release artifacts.
 */
final class KrushiPulseRuntimeSettings {
    public static function load(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Invalid private configuration file');
        }
        $storage = realpath(dirname(dirname($path)));
        $resolved = realpath($path);
        if (!$storage || !$resolved || !str_starts_with($resolved, $storage . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private configuration must remain inside private storage');
        }
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Private configuration is accessible to other users; require chmod 600');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > 16384) {
            throw new RuntimeException('Invalid private configuration size');
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid private configuration JSON');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Private configuration must be a JSON object');
        }
        $validations = [
            'APMC_AUTH_MODE' => static fn(string $v): bool => in_array($v, ['legacy', 'staging', 'production'], true),
            'APMC_AUTH_KEY' => static fn(string $v): bool => preg_match('/^[a-f0-9]{64}$/iD', $v) === 1,
            'APMC_PUBLIC_ORIGIN' => static fn(string $v): bool => preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]{2,5})?$~D', $v) === 1,
            'APMC_DATA_GOV_IN_API_KEY' => static fn(string $v): bool => preg_match('/^[a-zA-Z0-9._-]{16,256}$/D', $v) === 1,
            'APMC_NATIONAL_INTERVAL_HOURS' => static fn(string $v): bool => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 24,
            'APMC_GITHUB_CLIENT_ID' => static fn(string $v): bool => preg_match('/^[A-Za-z0-9._-]{5,150}$/D', $v) === 1,
            'APMC_GITHUB_CLIENT_SECRET' => static fn(string $v): bool => preg_match('/^[A-Za-z0-9._-]{12,256}$/D', $v) === 1,
            'APMC_GITHUB_ADMIN_IDS' => static fn(string $v): bool => preg_match('/^\d+(?:,\d+)*$/D', $v) === 1,
        ];
        foreach ($data as $key => $value) {
            if (!isset($validations[$key]) || !is_string($value) || !$validations[$key]($value)) {
                throw new RuntimeException('Unsupported or invalid private configuration setting');
            }
        }
        foreach ($data as $key => $value) {
            if (getenv($key) === false || getenv($key) === '') {
                putenv($key . '=' . $value);
            }
        }
    }
}

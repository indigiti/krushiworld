<?php
declare(strict_types=1);
namespace KrushiPulse\Security;

final class TemporarySetupCsrf {
    public const COOKIE = 'kp_data_gov_csrf';
    public static function mint(string $secret, ?int $now = null): string {
        $base = (string)($now ?? time()) . '.' . bin2hex(random_bytes(24));
        return $base . '.' . hash_hmac('sha256', 'krushipulse-temp-setup|' . $base, $secret);
    }
    public static function validate(mixed $submitted, mixed $cookie, string $secret, ?int $now = null): bool {
        if (!is_string($submitted) || !is_string($cookie) || strlen($submitted)>150
            || !hash_equals($submitted, $cookie)) return false;
        if (!preg_match('/^(\d{10})\.([a-f0-9]{48})\.([a-f0-9]{64})$/D', $submitted, $parts)) return false;
        $time = $now ?? time();
        if ((int)$parts[1] > $time + 60 || (int)$parts[1] < $time - 900) return false;
        $expected = hash_hmac('sha256', 'krushipulse-temp-setup|' . $parts[1] . '.' . $parts[2], $secret);
        return hash_equals($expected, $parts[3]);
    }
}
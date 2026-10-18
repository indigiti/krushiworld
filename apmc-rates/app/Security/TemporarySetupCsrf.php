<?php
declare(strict_types=1);
namespace KrushiPulse\Security;

final class TemporarySetupCsrf {
    public const COOKIE = 'kp_data_gov_csrf';
    public static function mint(): string {
        return bin2hex(random_bytes(32));
    }
    public static function validate(mixed $submitted, mixed $cookie): bool {
        return is_string($submitted) && is_string($cookie)
            && preg_match('/^[a-f0-9]{64}$/D', $submitted) === 1
            && hash_equals($submitted, $cookie);
    }
}
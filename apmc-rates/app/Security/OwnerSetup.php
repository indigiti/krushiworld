<?php
declare(strict_types=1);
namespace KrushiPulse\Security;
use RuntimeException;

/**
 * Self-hosted, browser-only first owner setup. Only a verified admin of
 * indigiti/krushiworld may provision the first local account. No PAT saved.
 * Production and explicit legacy-mode deployments are never auto-migrated.
 */
final class OwnerSetup {
    public static function eligible(AdminAuth $auth): bool {
        return $auth->mode() === 'staging' && self::trustedStage()
            && !$auth->store()->hasLocalAccounts();
    }

    private static function trustedStage(): bool {
        return PHP_SAPI === 'cli'
            ? (getenv('APMC_OWNER_SETUP_TEST') === '1')
            : apmc_request_is_https()
                && strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) === 'stage.digiti.in';
    }

    private static function githubGet(string $url, string $token): array {
        if (!extension_loaded('curl')) throw new RuntimeException('GitHub verification unavailable');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: KrushiPulse-FirstOwner/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($raw) || strlen($raw) > 20000 || $status !== 200)
            throw new RuntimeException('GitHub repository administration could not be verified');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('GitHub verification failed');
        return $data;
    }

    public static function checkRepositoryAdmin(string $token): bool {
        // GitHub fine-grained PATs and equivalent short-lived user tokens.
        if (strlen($token) < 20 || strlen($token) > 512
            || preg_match('/^[A-Za-z0-9_\-]+$/D', $token) !== 1) return false;
        try {
            $user = self::githubGet('https://api.github.com/user', $token);
            $login = (string)($user['login'] ?? '');
            $id = $user['id'] ?? null;
            if (!preg_match('/^[A-Za-z0-9-]{1,39}$/D', $login) || !is_numeric($id)) return false;
            $permissions = self::githubGet(
                'https://api.github.com/repos/indigiti/krushiworld/collaborators/'
                . rawurlencode($login) . '/permission',
                $token
            );
            return ($permissions['permission'] ?? null) === 'admin'
                && ($permissions['user']['id'] ?? null) == $id;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Only a CLI test may supply a test verifier; public HTTP always uses GitHub. */
    public static function enroll(
        AdminAuth $auth, string $token, string $username, string $password,
        ?callable $testVerifier = null, ?string $testStorage = null
    ): array {
        if ($testVerifier !== null && (PHP_SAPI !== 'cli' || getenv('APMC_OWNER_SETUP_TEST') !== '1'))
            throw new RuntimeException('Invalid verifier');
        if (!self::trustedStage() || $auth->mode() !== 'staging')
            throw new RuntimeException('Direct setup is not available in this environment');
        $storage = $testStorage ?? APMC_STORAGE;
        $dir = $storage . '/auth';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)))
            throw new RuntimeException('Private administrator storage unavailable');
        $lockPath = $dir . '/owner-setup.lock';
        if (is_link($lockPath)) throw new RuntimeException('Invalid setup lock');
        $lock = fopen($lockPath, 'c');
        if (!$lock) throw new RuntimeException('Setup lock unavailable');
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Setup lock unavailable');
            if ($auth->store()->hasLocalAccounts())
                throw new RuntimeException('Initial administrator already exists');
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username)
                || strlen($password) < 14 || strlen($password) > 256)
                throw new RuntimeException('Valid username and a 14+ character password are required');
            // No network request, admin token, username or error detail is logged.
            if (!(($testVerifier ?? [self::class,'checkRepositoryAdmin'])($token)))
                throw new RuntimeException('GitHub repository administration could not be verified');
            $key = PrivateAppSettings::ensureAuthKey($storage);
            if (getenv('APMC_AUTH_KEY') === false || getenv('APMC_AUTH_KEY') === '')
                putenv('APMC_AUTH_KEY=' . $key);
            $secret = AdminAuth::totpNew();
            $cipher = $auth->seal($secret);
            $codes = [];
            for ($i=0; $i<8; $i++) {
                $value = strtoupper(bin2hex(random_bytes(8)));
                $codes[] = substr($value,0,8) . '-' . substr($value,8,8);
            }
            $id = $auth->store()->createLocal($username, $password, $cipher, 'super_admin', 0);
            $auth->store()->setRecovery($id, $codes);
            return [
                'username'=>$username, 'secret'=>$secret, 'recovery'=>$codes,
                'uri'=>'otpauth://totp/KrushiPulse:' . rawurlencode($username)
                    . '?secret=' . $secret . '&issuer=KrushiPulse&digits=6&period=30',
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

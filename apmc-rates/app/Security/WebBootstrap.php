<?php
declare(strict_types=1);
namespace KrushiPulse\Security;
use RuntimeException;

/**
 * A one-use browser enrollment grant can ONLY be minted by authenticated
 * DigiOps admin control-plane. No public-first-visitor takeover.
 */
final class WebBootstrap {
    public static function enroll(AdminAuth $auth, string $grant, string $username, string $password): array {
        if ($auth->mode() !== 'staging') throw new RuntimeException('Staging bootstrap not enabled');
        if (!preg_match('/^[a-f0-9]{64}$/iD', $grant)) throw new RuntimeException('Invalid setup grant');
        $db = $auth->store();
        $file = APMC_STORAGE . '/auth/web-bootstrap.json';
        if (is_link($file) || !is_file($file) || ((fileperms($file) & 0077) !== 0)) {
            throw new RuntimeException('Private setup grant is unavailable');
        }
        $handle = fopen($file, 'r+');
        if (!$handle) throw new RuntimeException('Private setup grant is unavailable');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock setup grant');
            $raw = stream_get_contents($handle, 8192);
            $record = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($record)
                || ($record['used'] ?? true) !== false
                || !is_string($record['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $record['sha256'])
                || (int)($record['expires_at'] ?? 0) < time()
                || !hash_equals($record['sha256'], hash('sha256', strtolower($grant)))) {
                throw new RuntimeException('Invalid or expired setup grant');
            }
            if ($db->localReady()) throw new RuntimeException('Local Super Admin already active');
            // No password or seed is ever persisted in the browser console or grant.
            $secret = AdminAuth::totpNew();
            $id = $db->createLocal($username, $password, $auth->seal($secret), 'super_admin', 0);
            $codes = [];
            for ($i=0; $i<8; $i++) {
                $hex = strtoupper(bin2hex(random_bytes(8)));
                $codes[] = substr($hex, 0, 8) . '-' . substr($hex, 8, 8);
            }
            $db->setRecovery($id, $codes);
            $consumed = json_encode(['used'=>true, 'used_at'=>time()], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $consumed) !== strlen($consumed) || !fflush($handle)) {
                throw new RuntimeException('Could not consume setup grant');
            }
            return [
                'username'=>$username, 'secret'=>$secret, 'recovery'=>$codes,
                'uri'=>'otpauth://totp/KrushiPulse:' . rawurlencode($username)
                    . '?secret=' . $secret . '&issuer=KrushiPulse&digits=6&period=30'
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

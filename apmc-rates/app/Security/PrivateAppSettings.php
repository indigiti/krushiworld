<?php
declare(strict_types=1);
namespace KrushiPulse\Security;
use RuntimeException;

/** Private settings writer; never serves secrets to browsers or commits. */
final class PrivateAppSettings {
    private static function mutate(string $storage, callable $callback): array {
        $dir = $storage . '/config';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)))
            throw new RuntimeException('Private configuration directory unavailable');
        $path = $dir . '/runtime.json';
        $lockPath = $dir . '/runtime.lock';
        if (is_link($lockPath) || is_link($path)) throw new RuntimeException('Invalid private configuration path');
        $lock = fopen($lockPath, 'c');
        if (!$lock) throw new RuntimeException('Private settings lock unavailable');
        @chmod($lockPath,0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new RuntimeException('Private settings lock unavailable');
            $settings = [];
            if (is_file($path)) {
                $mode = fileperms($path);
                if ($mode === false || ($mode & 0077) !== 0) throw new RuntimeException('Private settings permissions must be 0600');
                $raw = file_get_contents($path);
                if (!is_string($raw) || strlen($raw)>16384) throw new RuntimeException('Invalid private settings file');
                $settings = json_decode($raw, true);
                if (!is_array($settings) || array_is_list($settings)) throw new RuntimeException('Invalid private settings file');
            } elseif (file_exists($path)) throw new RuntimeException('Invalid private settings file');
            $next = $callback($settings);
            if (!is_array($next)) throw new RuntimeException('Invalid settings update');
            $tmp = tempnam($dir, '.runtime-');
            if (!is_string($tmp)) throw new RuntimeException('Private settings write failed');
            try {
                if (!chmod($tmp,0600)) throw new RuntimeException('Private settings write failed');
                $json = json_encode($next, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES) . "\n";
                if (file_put_contents($tmp,$json,LOCK_EX) !== strlen($json))
                    throw new RuntimeException('Private settings write failed');
                if (!rename($tmp,$path)) throw new RuntimeException('Private settings write failed');
            } finally { if (file_exists($tmp)) unlink($tmp); }
            return $next;
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    public static function ensureAuthKey(string $storage): string {
        $current = trim((string)(\KrushiPulseRuntimeSettings::get('APMC_AUTH_KEY') ?: ''));
        if ($current !== '') {
            if (!preg_match('/^[a-f0-9]{64}$/iD',$current)) throw new RuntimeException('Invalid server auth key');
            return $current;
        }
        $settings = self::mutate($storage, static function(array $values):array {
            if (!isset($values['APMC_AUTH_KEY'])) $values['APMC_AUTH_KEY'] = bin2hex(random_bytes(32));
            if (!preg_match('/^[a-f0-9]{64}$/iD', (string)$values['APMC_AUTH_KEY']))
                throw new RuntimeException('Invalid stored authentication key');
            return $values;
        });
        return $settings['APMC_AUTH_KEY'];
    }

    public static function saveNationalKey(string $storage, string $key): void {
        if (strlen($key) < 16 || strlen($key)>256
            || preg_match('/^[A-Za-z0-9._-]+$/D', $key)!==1)
            throw new RuntimeException('Invalid AGMARKNET API key format');
        self::mutate($storage, static function(array $values) use($key):array {
            $values['APMC_DATA_GOV_IN_API_KEY'] = $key;
            return $values;
        });
    }

    public static function hasNationalKey(string $storage): bool {
        if (trim((string)\KrushiPulseRuntimeSettings::get('APMC_DATA_GOV_IN_API_KEY')) !== '') return true;
        $path=$storage.'/config/runtime.json';
        if (is_link($path) || !is_file($path)) return false;
        $mode=fileperms($path);
        if ($mode===false || ($mode & 0077)!==0) return false;
        $data=json_decode((string)file_get_contents($path),true);
        return is_array($data) && !empty($data['APMC_DATA_GOV_IN_API_KEY']);
    }
}

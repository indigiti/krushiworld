<?php
declare(strict_types=1);

final class RefreshService {
    public function __construct(private readonly RateRepository $repo) {}

    public function intervalSeconds(): int {
        $hour = (int) date('G');
        if ($hour >= 9 && $hour < 15) return (int) apmc_config('refresh.active_seconds', 300);
        if ($hour >= 6 && $hour < 9) return (int) apmc_config('refresh.morning_seconds', 900);
        if ($hour >= 15 && $hour < 18) return (int) apmc_config('refresh.evening_seconds', 600);
        return (int) apmc_config('refresh.overnight_seconds', 3600);
    }

    public function isDue(): bool {
        $manifest = $this->repo->manifest();
        $last = $manifest['last_checked_at'] ?? null;
        if (!$last) return true;
        $ts = strtotime((string) $last);
        if (!$ts) return true;
        return (time() - $ts) >= $this->intervalSeconds();
    }

    /**
     * Server cron is the primary refresh authority. Web traffic becomes a backup
     * only when the cron heartbeat is missing for long enough that at least one
     * scheduled collection cycle should already have completed.
     */
    public function serverFallbackDue(): bool {
        if ((string)getenv('APMC_DISABLE_WEB_FALLBACK') === '1') return false;

        $manifest = $this->repo->manifest();
        if (($manifest['last_server_status'] ?? '') === 'error') return true;
        $last = $manifest['last_server_heartbeat_at'] ?? ($manifest['last_server_checked_at'] ?? null);
        if (!$last) return true;

        $ts = strtotime((string)$last);
        if (!$ts) return true;

        $grace = max(300, (int)apmc_config('refresh.server_heartbeat_grace_seconds', 900));
        return (time() - $ts) >= $grace;
    }

    public function fallbackStatus(): array {
        $manifest = $this->repo->manifest();
        $last = $manifest['last_server_heartbeat_at'] ?? ($manifest['last_server_checked_at'] ?? null);
        $ts = $last ? strtotime((string)$last) : false;
        $age = $ts ? max(0, time() - $ts) : null;
        $grace = max(300, (int)apmc_config('refresh.server_heartbeat_grace_seconds', 900));

        return [
            'due' => $this->serverFallbackDue(),
            'last_server_heartbeat_at' => $last,
            'last_server_checked_at' => $manifest['last_server_checked_at'] ?? null,
            'last_server_status' => $manifest['last_server_status'] ?? 'unknown',
            'server_age_seconds' => $age,
            'grace_seconds' => $grace,
        ];
    }

    /**
     * Page-request safety net. It never competes with a healthy server cron.
     * When cron is stale, try to launch the Python collector in the background.
     * If PHP process spawning is unavailable, the browser bootstrap endpoint can
     * take over one feed at a time through WebCollector.
     */
    public function maybeTrigger(): array {
        if ((string)getenv('APMC_DISABLE_REFRESH') === '1') {
            return ['triggered' => false, 'reason' => 'disabled_by_environment'];
        }
        if (!$this->serverFallbackDue()) {
            return ['triggered' => false, 'reason' => 'server_scheduler_healthy', 'interval' => $this->intervalSeconds()];
        }
        if (!$this->isDue()) {
            return ['triggered' => false, 'reason' => 'recent_fallback_refresh', 'interval' => $this->intervalSeconds()];
        }

        $result = $this->spawn(['--check', '--trigger', 'web-fallback']);
        $result['fallback_due'] = true;
        return $result;
    }

    /**
     * Reports the process functions PHP can actually call. PHP 8 removes disabled
     * internal functions from function_exists(), so never call one before checking.
     */
    public function runtimeCapabilities(): array {
        $disabled = $this->disabledFunctions();
        $available = [];
        foreach (['exec', 'popen', 'proc_open'] as $name) {
            $available[$name] = $this->functionAvailable($name, $disabled);
        }

        return [
            'process_spawn_available' => in_array(true, $available, true),
            'functions' => $available,
            'disabled_functions' => array_values($disabled),
            'python_binary' => (string) apmc_config('python_binary', 'python3'),
            'server_fallback' => $this->fallbackStatus(),
        ];
    }

    public function spawn(array $args): array {
        $python = trim((string) apmc_config('python_binary', 'python3'));
        $script = APMC_ROOT . '/collector/refresh.py';

        if (!is_file($script)) {
            return ['triggered' => false, 'reason' => 'collector_missing'];
        }
        if ($python === '' || !$this->validExecutable($python)) {
            return ['triggered' => false, 'reason' => 'invalid_python_binary'];
        }

        $cleanArgs = [];
        foreach ($args as $arg) {
            $arg = (string) $arg;
            if (str_contains($arg, "\0") || str_contains($arg, "\n") || str_contains($arg, "\r")) {
                return ['triggered' => false, 'reason' => 'invalid_collector_argument'];
            }
            $cleanArgs[] = $arg;
        }

        $disabled = $this->disabledFunctions();
        $command = $this->shellQuote($python) . ' ' . $this->shellQuote($script);
        foreach ($cleanArgs as $arg) {
            $command .= ' ' . $this->shellQuote($arg);
        }
        $backgroundCommand = $command . ' > /dev/null 2>&1 &';
        $errors = [];

        if ($this->functionAvailable('exec', $disabled)) {
            try {
                $output = [];
                $exitCode = 0;
                @exec($backgroundCommand, $output, $exitCode);
                if ($exitCode === 0) {
                    return ['triggered' => true, 'method' => 'exec', 'interval' => $this->intervalSeconds()];
                }
                $errors[] = 'exec_exit_' . $exitCode;
            } catch (Throwable $e) {
                $errors[] = 'exec:' . $this->shortError($e);
            }
        }

        if ($this->functionAvailable('popen', $disabled)) {
            try {
                $handle = @popen($backgroundCommand, 'r');
                if (is_resource($handle)) {
                    @pclose($handle);
                    return ['triggered' => true, 'method' => 'popen', 'interval' => $this->intervalSeconds()];
                }
                $errors[] = 'popen_failed';
            } catch (Throwable $e) {
                $errors[] = 'popen:' . $this->shortError($e);
            }
        }

        if ($this->functionAvailable('proc_open', $disabled)) {
            try {
                $descriptors = [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'a'],
                    2 => ['file', '/dev/null', 'a'],
                ];
                $process = @proc_open($backgroundCommand, $descriptors, $pipes, APMC_ROOT);
                if (is_resource($process)) {
                    @proc_close($process);
                    return ['triggered' => true, 'method' => 'proc_open', 'interval' => $this->intervalSeconds()];
                }
                $errors[] = 'proc_open_failed';
            } catch (Throwable $e) {
                $errors[] = 'proc_open:' . $this->shortError($e);
            }
        }

        return [
            'triggered' => false,
            'reason' => in_array(true, $this->runtimeCapabilities()['functions'], true)
                ? 'php_process_spawn_failed'
                : 'php_process_functions_disabled',
            'errors' => $errors,
            'interval' => $this->intervalSeconds(),
        ];
    }

    private function disabledFunctions(): array {
        $raw = (string) ini_get('disable_functions');
        if ($raw === '') return [];
        return array_values(array_filter(array_map(static fn(string $v): string => strtolower(trim($v)), explode(',', $raw))));
    }

    private function functionAvailable(string $name, ?array $disabled = null): bool {
        $name = strtolower($name);
        $disabled ??= $this->disabledFunctions();
        return function_exists($name) && !in_array($name, $disabled, true);
    }

    private function validExecutable(string $value): bool {
        return (bool) preg_match('~^[A-Za-z0-9_./-]+$~', $value);
    }

    private function shellQuote(string $value): string {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function shortError(Throwable $e): string {
        return substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?: get_class($e), 0, 180);
    }
}

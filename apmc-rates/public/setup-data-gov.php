<?php
declare(strict_types=1);

/**
 * TEMPORARY, SINGLE-USE staging installer for a data.gov.in API key.
 * Never put secrets into source control. After success this page refuses
 * further changes, even if it is restored by an old release artifact.
 */
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
if (PHP_SAPI === 'cli' || $host !== 'stage.digiti.in' || !$https) {
    http_response_code(404);
    exit('Not found');
}

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$deployedBootstrap = dirname(__DIR__, 2) . '/private_html/krushiworld/app/bootstrap.php';
$localBootstrap = dirname(__DIR__) . '/app/bootstrap.php';
$bootstrap = is_file($deployedBootstrap) ? $deployedBootstrap : $localBootstrap;
if (!is_file($bootstrap)) {
    http_response_code(503);
    exit('KrushiWorld private application is not installed');
}
if (!defined('APMC_PUBLIC_DIR')) define('APMC_PUBLIC_DIR', __DIR__);
require_once $bootstrap;

use KrushiPulse\Security\OwnerSetup;
use KrushiPulse\Security\TemporarySetupCsrf;
require_once APMC_ROOT . '/app/Security/TemporarySetupCsrf.php';
use KrushiPulse\Security\PrivateAppSettings;

$storage = APMC_STORAGE;
$completed = $storage . '/config/data-gov-setup.completed';
$error = '';
$success = false;
$alreadySaved = is_file($completed) || PrivateAppSettings::hasNationalKey($storage);

if (!is_dir($storage) || is_link($storage)) {
    http_response_code(503);
    exit('Private storage is not available');
}
// Signed, short-lived cookie is independent of PHP session persistence.
$csrfSecret = PrivateAppSettings::ensureAuthKey($storage);
$csrfForm = TemporarySetupCsrf::mint($csrfSecret);
setcookie(TemporarySetupCsrf::COOKIE, $csrfForm, [
    'expires'=>time()+900,
    'path'=>'/krushiworld/',
    'secure'=>true,
    'httponly'=>true,
    'samesite'=>'Strict',
]);
header('Vary: Cookie');
session_name('kp_data_gov_setup');
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/krushiworld/',
    'secure'=>true,
    'httponly'=>true,
    'samesite'=>'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();
if (!isset($_SESSION['data_gov_csrf'])) {
    $_SESSION['data_gov_csrf'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['attempt_window']) || time() - (int)$_SESSION['attempt_window'] > 900) {
    $_SESSION['attempt_window'] = time();
    $_SESSION['attempts'] = 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($alreadySaved) {
        http_response_code(409);
        $error = 'Setup has already been completed. Use the authenticated KrushiWorld admin dashboard for future changes.';
    } elseif ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        http_response_code(413);
        $error = 'Request too large.';
    } elseif (!in_array(strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')), ['', 'none', 'same-origin'], true)
        || (isset($_SERVER['HTTP_ORIGIN']) && strtolower(rtrim(trim((string)$_SERVER['HTTP_ORIGIN']), '/')) !== 'https://stage.digiti.in')) {
        http_response_code(403);
        $error = 'Request origin did not match server settings. Open the staging setup page directly and retry.';
    } elseif (!TemporarySetupCsrf::validate(
        $_POST['_csrf'] ?? null,
        $_COOKIE[TemporarySetupCsrf::COOKIE] ?? null,
        $csrfSecret
    )) {
        http_response_code(403);
        $error = 'Browser session or form expired. Reload the setup page with first-party cookies enabled.';
    } elseif ((int)$_SESSION['attempts'] >= 5) {
        http_response_code(429);
        $error = 'Too many verification attempts from this browser. Try later.';
    } else {
        $_SESSION['attempts']++;
        $token = trim((string)($_POST['github_token'] ?? ''));
        $key = trim((string)($_POST['data_gov_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{16,256}$/D', $key)) {
            http_response_code(400);
            $error = 'Invalid API key format. Use the key issued by data.gov.in.';
        } elseif (!OwnerSetup::checkRepositoryAdmin($token)) {
            http_response_code(403);
            $error = 'GitHub verification failed. Use a short-lived token from an administrator of indigiti/krushiworld, scoped to that repository.';
        } else {
            // A server-side lock prevents simultaneous attempts from both saving.
            $configDir = $storage . '/config';
            if (is_link($configDir) || (!is_dir($configDir) && !mkdir($configDir, 0700, true) && !is_dir($configDir))) {
                http_response_code(503);
                $error = 'Private configuration directory is not writable.';
            } else {
                $lockPath = $configDir . '/data-gov-setup.lock';
                if (is_link($lockPath)) {
                    http_response_code(503);
                    $error = 'Private configuration path is unsafe.';
                } else {
                    $lock = fopen($lockPath, 'c');
                    if ($lock === false) {
                        http_response_code(503);
                        $error = 'Could not lock private configuration.';
                    } else {
                        @chmod($lockPath, 0600);
                        try {
                            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Lock failed');
                            if (is_file($completed) || PrivateAppSettings::hasNationalKey($storage)) {
                                throw new RuntimeException('Setup already completed');
                            }
                            PrivateAppSettings::saveNationalKey($storage, $key);
                            // Persistent marker: even a later Git deploy cannot re-enable this page.
                            if (file_put_contents($completed, "completed\n", LOCK_EX) === false) {
                                throw new RuntimeException('Could not finalize installer');
                            }
                            @chmod($completed, 0600);
                            $success = true;
                            $alreadySaved = true;
                            $_SESSION['data_gov_csrf'] = bin2hex(random_bytes(32));
                        } catch (Throwable $exception) {
                            http_response_code(503);
                            $error = 'Could not complete setup. Check private storage permissions and existing configuration.';
                        } finally {
                            flock($lock, LOCK_UN);
                            fclose($lock);
                        }
                    }
                }
            }
        }
        // Do not render or store either submitted credential.
        unset($token, $key, $_POST['github_token'], $_POST['data_gov_key']);
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>KrushiPulse · Temporary data.gov.in setup</title>
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
:root{font-family:system-ui,-apple-system,sans-serif;color:#143628;background:#f3f6f1}
*{box-sizing:border-box}body{margin:0;padding:clamp(16px,4vw,40px)}
main{max-width:670px;margin:28px auto}article{background:white;border:1px solid #dbe8dd;border-radius:20px;padding:clamp(20px,4vw,36px);box-shadow:0 10px 45px #18392212}
h1{font-size:clamp(1.65rem,4vw,2.3rem);margin:10px 0}p{line-height:1.65;color:#52685e}
.eyebrow{color:#15764b;font-weight:750;font-size:.85rem;text-transform:uppercase;letter-spacing:.07em}
label{display:block;font-weight:650;margin:20px 0 7px}
input{width:100%;padding:14px 16px;border:1px solid #afc9b6;border-radius:11px;font:inherit;min-height:48px}
button{margin-top:24px;width:100%;border:0;border-radius:12px;min-height:50px;background:#075c36;color:white;font:inherit;font-weight:700;cursor:pointer}
.message{padding:14px;border-radius:12px;background:#e9f7ef;color:#175f3c;margin:20px 0}
.error{background:#fff1ec;color:#923920}code{font-size:.88rem}a{color:#075c36}
small{color:#64776b;line-height:1.6;display:block;margin-top:20px}
</style>
</head><body>
<main><article>
<div class="eyebrow">🌾 KrushiWorld / KrushiPulse</div>
<h1>Connect the data.gov.in API</h1>
<p>Temporary, single-use staging setup. Your API key is saved to private Cloudways storage, not GitHub or the public website.</p>
<?php if ($success): ?>
<div class="message" role="status"><strong>API key saved.</strong> This setup page is now disabled. The scheduled AGMARKNET collector can use the private key.</div>
<p><a href="/krushiworld/?api=markets">Check mandi import status</a> · <a href="/krushiworld/admin/">Open KrushiWorld admin</a></p>
<?php elseif ($alreadySaved): ?>
<div class="message" role="status"><strong>Setup is closed.</strong> A private API key is already configured, or this one-time setup has been used.</div>
<p><a href="/krushiworld/admin/">Manage settings in KrushiWorld admin</a></p>
<?php else: ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
<form method="post" action="/krushiworld/setup-data-gov.php" autocomplete="off">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfForm, ENT_QUOTES, 'UTF-8') ?>">
<label for="github_token">GitHub repository-admin verification token</label>
<input id="github_token" type="password" name="github_token" required minlength="20" maxlength="512" autocomplete="off" spellcheck="false" placeholder="Temporary GitHub fine-grained token">
<label for="data_gov_key">data.gov.in API key</label>
<input id="data_gov_key" type="password" name="data_gov_key" required minlength="16" maxlength="256" autocomplete="off" spellcheck="false" placeholder="Paste the API key provided by data.gov.in">
<button type="submit">Verify ownership &amp; save API key</button>
</form>
<small>GitHub verification: use a short-lived fine-grained token restricted to <strong>indigiti/krushiworld</strong>, with Metadata (read) permission. The GitHub account must have repository Admin access. Revoke the token immediately after saving. Never send either secret in a URL, chat message, or repository commit.</small>
<?php endif; ?>
</article></main>
</body></html>

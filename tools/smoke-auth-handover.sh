#!/usr/bin/env bash
# Validate that deployment mode never treats the legacy token as local identity.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
pid=''
cleanup(){ if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi; rm -rf "$tmp"; }
trap cleanup EXIT
mkdir -p "$tmp/public_html/krushiworld" "$tmp/private_html/krushiworld/storage"
cp -a "$root/release/public/." "$tmp/public_html/krushiworld/"
cp -a "$root/release/private/." "$tmp/private_html/krushiworld/"
start(){
 local mode="$1" port="$2"
 APMC_AUTH_MODE="$mode" APMC_ADMIN_TOKEN='test-legacy-token-do-not-use' APMC_DISABLE_WEB_FALLBACK=1 \
 php -d opcache.jit=0 -S "127.0.0.1:$port" -t "$tmp/public_html" "$root/tools/php-dev-router.php" > "$tmp/php-$mode.log" 2>&1 &
 pid="$!"
 local ready=0
 for i in {1..40}; do
  if curl -fsS --max-time 2 "http://127.0.0.1:$port/krushiworld/admin/" > "$tmp/$mode.html"; then ready=1;break;fi
  sleep 0.15
 done
 if [[ "$ready" != "1" ]];then cat "$tmp/php-$mode.log"; exit 1;fi
}
start staging 18742
if ! grep -q 'Admin authentication' "$tmp/staging.html" || ! grep -q 'name="auth_action" value="local_login"' "$tmp/staging.html" || ! grep -q 'Authenticator or recovery code' "$tmp/staging.html" || grep -q 'name="admin_login"' "$tmp/staging.html";then
 echo "Staging must show local MFA login, not the legacy token form";exit 1
fi
login_status="$(curl --silent --output "$tmp/legacy-staging.html" --write-out '%{http_code}' \
 --data-urlencode 'admin_login=1' --data-urlencode 'token=test-legacy-token-do-not-use' \
 'http://127.0.0.1:18742/krushiworld/?view=admin')"
if [[ "$login_status" == "303" ]] || grep -q 'Sign out</button>' "$tmp/legacy-staging.html";then
 echo "Legacy token unexpectedly granted staging identity access";exit 1
fi
kill "$pid" 2>/dev/null || true;wait "$pid" 2>/dev/null || true;pid=''
start production 18743
if ! grep -q 'Admin authentication' "$tmp/production.html" || ! grep -q 'name="auth_action" value="local_login"' "$tmp/production.html" || ! grep -q 'Authenticator or recovery code' "$tmp/production.html" || grep -q 'name="admin_login"' "$tmp/production.html";then
 echo "Production login unexpectedly fell back to legacy token";exit 1
fi
github_status="$(curl --silent --output "$tmp/no-gh.html" --write-out '%{http_code}' \
 'http://127.0.0.1:18743/krushiworld/admin/?auth=github-start')"
if [[ "$github_status" != "200" ]] || grep -q 'github.com/login/oauth/authorize' "$tmp/no-gh.html";then
 echo "Production GitHub login must be blocked by backend policy";exit 1
fi
if [[ -e "$tmp/public_html/krushiworld/app/Security" || -e "$tmp/public_html/krushiworld/bin/provision-super-admin.php" ]];then
 echo "Private identity or bootstrap helper exposed in document root";exit 1
fi
if grep -q 'PHP Fatal error\|Uncaught Error' "$tmp"/php-*.log;then
 echo "Admin identity boundary caused PHP runtime failure";grep -E 'Fatal|Uncaught' "$tmp"/php-*.log;exit 1
fi
echo 'Staging / production admin auth boundary smoke: PASS'

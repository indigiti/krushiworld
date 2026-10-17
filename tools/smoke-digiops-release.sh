#!/usr/bin/env bash
# Exercise the DigiOps two-root layout through HTTP before accepting a release.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
pid=''
cleanup() { if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi; rm -rf "$tmp"; }
trap cleanup EXIT
mkdir -p "$tmp/public_html/krushiworld" "$tmp/private_html/krushiworld/storage"
cp -a "$root/release/public/." "$tmp/public_html/krushiworld/"
cp -a "$root/release/private/." "$tmp/private_html/krushiworld/"
export APMC_DISABLE_WEB_FALLBACK=1
php -S 127.0.0.1:18739 -t "$tmp/public_html" > "$tmp/php.log" 2>&1 &
pid=$!
url='http://127.0.0.1:18739/krushiworld'
ready=0
for attempt in {1..35}; do
  if curl --fail --silent --max-time 2 "$url/?api=health" > "$tmp/health.json"; then ready=1; break; fi
  sleep 0.2
done
if [[ "$ready" != 1 ]]; then cat "$tmp/php.log"; exit 1; fi
php -r '$a=json_decode(file_get_contents($argv[1]),true); if(!is_array($a) || ($a["ok"]??false)!==true || !isset($a["health"]["status"])) exit(1);' "$tmp/health.json"
curl --fail --silent --show-error --max-time 15 "$url/?lang=mr" > "$tmp/mr.html"
curl --fail --silent --show-error --max-time 15 "$url/?lang=en" > "$tmp/en.html"
curl --fail --silent --show-error --max-time 15 "$url/?view=admin" > "$tmp/admin.html"
curl --fail --silent --show-error --max-time 15 "$url/build/krushipulse.js" > "$tmp/krushipulse.js"
curl --fail --silent --show-error --max-time 15 "$url/build/nearest-mandi.js" > "$tmp/nearest-mandi.js"
for page in mr en; do
  if ! grep -q 'KrushiPulse' "$tmp/$page.html"; then cat "$tmp/php.log"; echo "Missing KrushiPulse in $page"; exit 1; fi
done
if ! grep -q '/krushiworld/build/krushipulse.css' "$tmp/mr.html"; then
  cat "$tmp/php.log"; echo 'Incorrect asset URL for /krushiworld'; exit 1
fi
if ! grep -q 'APMC' "$tmp/admin.html"; then cat "$tmp/php.log"; echo 'Admin did not render'; exit 1; fi
if [[ ! -s "$tmp/krushipulse.js" || ! -s "$tmp/nearest-mandi.js" ]]; then
  echo 'Missing frontend JS assets'; exit 1
fi
if [[ -e "$tmp/public_html/krushiworld/collector" || -e "$tmp/public_html/krushiworld/config" ]]; then
  echo 'Private source exposed through document root'; exit 1
fi
if grep -q 'Fatal error\|PHP Warning' "$tmp/php.log"; then cat "$tmp/php.log"; exit 1; fi
echo 'DigiOps Cloudways-style HTTP smoke tests: PASS'
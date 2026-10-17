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
php -d opcache.jit=0 -S 127.0.0.1:18739 -t "$tmp/public_html" "$root/tools/php-dev-router.php" > "$tmp/php.log" 2>&1 &
pid=$!
url='http://127.0.0.1:18739/krushiworld'
ready=0
for attempt in {1..35}; do
  if curl --fail --silent --max-time 2 "$url/?api=health" > "$tmp/health.json"; then ready=1; break; fi
  sleep 0.2
done
if [[ "$ready" != 1 ]]; then cat "$tmp/php.log"; exit 1; fi
php -r '$a=json_decode(file_get_contents($argv[1]),true); if(!is_array($a) || ($a["ok"]??false)!==true || !isset($a["health"]["status"])) exit(1);' "$tmp/health.json"
curl --fail --silent --show-error --max-time 15 "$url/?api=markets" > "$tmp/markets.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["ok"]??false)!==true || count($j["data"]["markets"]??[]) < 5 || !isset($j["data"]["national"]["status"])) exit(1);' "$tmp/markets.json"
curl --fail --silent --show-error --max-time 15 "$url/?api=national&market=agmarknet-test" > "$tmp/national.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["ok"]??false)!==true || !is_array($j["data"]["records"]??null)) exit(1);' "$tmp/national.json"
curl --fail --silent --show-error --max-time 15 "$url/?lang=mr" > "$tmp/mr.html"
curl --fail --silent --show-error --max-time 15 "$url/?lang=en" > "$tmp/en.html"
curl --fail --silent --show-error --max-time 15 "$url/?view=admin" > "$tmp/admin.html"
curl --fail --silent --show-error --max-time 15 "$url/build/krushipulse.js" > "$tmp/krushipulse.js"
curl --fail --silent --show-error --max-time 15 "$url/build/commodity-icons.js" > "$tmp/commodity-icons.js"
curl --fail --silent --show-error --max-time 15 "$url/build/nearest-mandi.js" > "$tmp/nearest-mandi.js"
for page in mr en; do
  if ! grep -q 'KrushiPulse' "$tmp/$page.html"; then cat "$tmp/php.log"; echo "Missing KrushiPulse in $page"; exit 1; fi
done
if ! grep -q '/krushiworld/build/commodity-icons.js' "$tmp/mr.html"; then
  echo 'Commodity emoji resolver missing in rendered document'; exit 1
fi
if ! grep -q '/krushiworld/build/krushipulse.css' "$tmp/mr.html"; then
  cat "$tmp/php.log"; echo 'Incorrect asset URL for /krushiworld'; exit 1
fi
if ! grep -q 'APMC' "$tmp/admin.html"; then cat "$tmp/php.log"; echo 'Admin did not render'; exit 1; fi
if [[ ! -s "$tmp/commodity-icons.js" || ! -s "$tmp/krushipulse.js" || ! -s "$tmp/nearest-mandi.js" ]]; then
  echo 'Missing frontend JS assets'; exit 1
fi
if [[ -e "$tmp/public_html/krushiworld/collector" || -e "$tmp/public_html/krushiworld/config" ]]; then
  echo 'Private source exposed through document root'; exit 1
fi
if grep -q 'PHP Fatal error\|Uncaught Error' "$tmp/php.log"; then cat "$tmp/php.log"; exit 1; fi

# Versioned API + multilingual pretty URLs work under /krushiworld prefix.
curl --fail --silent --show-error --max-time 15 "$url/mr/" > "$tmp/mr-pretty.html"
curl --fail --silent --show-error --max-time 15 "$url/en/mandis/" > "$tmp/mandis-pretty.html"
curl --fail --silent --show-error --max-time 15 "$url/en/mandis/main-market-yard/" > "$tmp/market-pretty.html"
curl --fail --silent --show-error --max-time 15 "$url/api/v1/markets" > "$tmp/v1-markets.json"
curl --fail --silent --show-error --max-time 15 "$url/api/v1/mandis/main-market-yard/rates" > "$tmp/v1-rates.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["ok"]??false)!==true||count($j["data"]["markets"]??[])<5)exit(1);' "$tmp/v1-markets.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["ok"]??false)!==true||!isset($j["data"]["records"]))exit(1);' "$tmp/v1-rates.json"
if ! grep -q '/krushiworld/en/mandis/main-market-yard/' "$tmp/market-pretty.html"; then
  echo 'Incorrect canonical permalink or language alternate on market page'; exit 1
fi
if ! grep -q '/krushiworld/build/krushipulse.js' "$tmp/market-pretty.html"; then
  echo 'Subfolder assets missing on nested route'; exit 1
fi
status="$(curl --silent --output "$tmp/missing.html" --write-out '%{http_code}' --max-time 15 "$url/en/mandis/no-such-market/")"
if [[ "$status" != 404 ]]; then echo "Unknown mandi must return 404, got $status"; exit 1; fi
if ! grep -q '/krushiworld/mr/' "$tmp/mr-pretty.html"; then echo 'Pretty Marathi home route missing'; exit 1; fi
echo 'DigiOps Cloudways-style HTTP smoke tests: PASS'
# APMC Rates — daily mandi prices

Self-contained PHP module in **KrushiWorld**, initially reading published Pune APMC HTML reports. Main entrypoint: `apmc-rates/index.php`.

## Requirements
- PHP 8.1+ with DOM, mbstring, openssl; `allow_url_fopen=On` for HTTPS retrieval.
- Server must permit writes to `apmc-rates-data/` **outside the web public root**. Set `APMC_DATA_DIR=/private/path/apmc-rates-data` on deployments where project root is public. Never make price snapshots publicly writable.
- Ensure outbound HTTPS access to `https://apmcpune.in`.

## Daily cron
```sh
APMC_DATA_DIR=/private/apmc-rates-data /usr/bin/php /path/to/krushiworld/apmc-rates/refresh.php
```
Run at least once per day after the official publication window using cron (Asia/Kolkata). Cron is **not configured** by this repo and hosting deployment is required.

## Endpoints
- `/apmc-rates/`: responsive browser page.
- `/apmc-rates/api.php?category=main-fruit&date=YYYY-MM-DD`: JSON market rates.
- Categories currently configured: `main-fruit`, `main-veg`, `main-gul-bhusar-dhanya`, `keli-paan`.
- `refresh.php YYYY-MM-DD`: CLI-only retrieval and archival for one date.

The source parser requires a table with a commodity header and minimum/maximum price columns. Source site changes may require updating labels or category paths. No rates are invented. If a fetch fails and a previously verified snapshot exists, the API reports `stale: true`, `requested_date` and the actual `date`. Empty/error responses are not saved over good snapshots.

**Before public production:** validate each category against actual official HTML and confirm units (often commodity-specific), market names and date. The prototype is not a verified all-India feed.

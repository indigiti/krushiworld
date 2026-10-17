# KrushiPulse Phases 2 + 3 — v1.13.0 operations

## Architecture and data boundaries
The collector processes real Pune APMC and AGMARKNET rate snapshots on the existing cron. After both source refreshes, `collector/history.py` imports only validated historical rate observations into the private, persistent `storage/history/rates.sqlite` file. Deduplication key: source + mandi + crop key + variety + grade + original rate unit + reported date. Source report date, not the time of import, is used for the chart x-axis.

PHP SQLite3 must be enabled on Cloudways to serve the two read-only intelligence routes. When absent, they return an unavailable state, not fabricated trends.

- `GET /krushiworld/api/v1/intelligence/history?market=<id>&commodity=<official-name>&variety=<variety>&grade=<grade>&unit=<unit>&days=30`
- `GET /krushiworld/api/v1/intelligence/compare?market=<id>&commodity=<official-name>&variety=<variety>&grade=<grade>&unit=<unit>&days=30`

Supported history windows: 7, 30 and 90 days. Comparison requires matching crop, variety, grade and original unit, and shows each mandi's actual report date. The result is a reported quotation, not a confirmed sale, net return or forecast. No trend line is drawn when fewer than two observations exist.

For the first deployment, **backfill older Pune reports where the private storage still contains archived daily source files**:

    python3 collector/history.py --backfill-days 90

This runs from `private_html/krushiworld/`. The collector is idempotent; running it again does not create duplicate prices. Historical data cannot be recovered from dates that were never collected. National history accumulates as AGMARKNET snapshots are imported; a single national snapshot is insufficient for a 30- or 90-day trend.

## Phase 3 — usable capabilities and important limits
The net-selling calculator multiplies the selected report's modal price by the farmer's quantity **in that exact published rate unit**, then subtracts user-entered transport and handling/fees. It cannot estimate unknown commissions, grading loss, or guaranteed transaction proceeds. No automated "best mandi" claim is made.

Target-price alerts are stored on this browser under `krushipulse.price-alerts.v1`, and match market + crop + variety + grade + unit. They are checked when the application is open/visited, deduplicated per reported date/source/price, and can optionally show a browser Notification after the user grants permission. **This is not an unattended push, SMS, WhatsApp or email notification system.** Those require a separately authorized notification backend and delivery integrations.

The Progressive Web App provides a manifest, local install prompt where supported, and a service worker at `/krushiworld/sw.js`. Only the public shell and static assets are cached. Price/health/weather APIs are deliberately **not cached** as current market data. Offline screens may contain an older public HTML snapshot and the interface warns that an internet connection is needed for new source reports. Browser and HTTPS support are required for service-worker installation; SVG manifest icon compatibility varies by device.

## Weather data: explicit source integration contract
`GET /krushiworld/api/v1/intelligence/weather` reads an **authorized, vetted**, server-side advisory snapshot from `storage/weather/latest.json`. A fresh snapshot looks like:

    {
      "source": "Authorized official weather provider",
      "location": "Published forecast area",
      "issued_at": "2026-10-17T07:00:00+05:30",
      "summary": "Text taken from the authorized source",
      "warning": "Optional verified warning",
      "source_url": "https://mausam.imd.gov.in/"
    }

These are **field illustrations, not real forecast data**. Never copy that sample file into live storage or publish sample weather alerts. Records require source URL, location, issued time and summary, and are suppressed if 12+ hours old. Production forecasting depends on a separately approved IMD/provider feed and an authorized collector, which are **not supplied** by this release. Until that integration is configured, the dashboard links to the official IMD site.

## Release/deployment validation
DigiOps public/private artifact keeps all collectors, SQLite/history/weather storage and server source separate from the public document root. The existing `storage/` directory must survive new deployments. New public PWA files (manifest, service worker, SVG icon), intelligence.js and offline.js must be present in `public_html/krushiworld/`.

CI validates PHP syntax and SQLite query contracts, Python history/archival tests, JavaScript syntax, and HTTP smoke tests for the three endpoints and static assets. These checks do **not** verify the production server's actual SQLite extension, authorized weather provider, national API key, notification privileges, HTTPS service-worker registration, or live deployment.

For a production check after installation: verify `php -m | grep -i sqlite`, run the history archiver under the private app folder, check the historical API's `available` and `points`, and compare only rates with identical variety/grade/unit and original report dates.

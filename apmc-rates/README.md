# KrushiWorld KrushiPulse — APMC Rates v1.6.0

> For the KrushiWorld integration, read [KRUSHIWORLD_INTEGRATION.md](KRUSHIWORLD_INTEGRATION.md). The v1.6.0 public frontend is Marathi-first, green/orange and framework-free, while existing APMC collectors and APIs are retained.

# Pune APMC Data Engine v1.4.0 (source base)

## v1.3.2 market tape + sponsor layer

- Added a horizontal **Market Tape** that continuously displays canonical commodity name, market, average rate/unit and movement.
- Market Tape follows the selected physical market and deduplicates commodity/market records.
- Uses CSS animation rather than deprecated `<marquee>`; pauses on hover/focus and becomes manually scrollable when reduced motion is requested.
- Added optional **Sponsored by** and **Co-sponsored by** brand tags alongside the tape.
- Sponsor links use `rel="sponsored noopener noreferrer"`.
- Sponsor logos are restricted to local **WebP** files under `public/media/sponsors/`; no advertiser image domain is added to CSP.
- Sponsor branding stays out of market APIs, Dataset JSON-LD and agent data.
- See `SPONSOR_TICKER_GUIDE.md` for configuration.


## v1.3.1 canonical data layer

Customer-facing data now separates 5 physical `market_id` values from the 19 internal source `feed_slug` values. Public reads pass through a deterministic bilingual `DataNormalizer`, so old cached snapshots receive clean English/Marathi names without deleting storage. See `NAME_NORMALIZATION.md`.
## v1.3.1 baseline

Folder-portable, no-database Pune APMC daily market intelligence with English/Marathi UI, light/dark themes, Cloudways-safe collection, secure admin controls, SEO/AI-search metadata and a read-only agent API.

## v1.3.1 highlights

- Google Fonts directly from Google: **Noto Sans** + **Noto Sans Devanagari**, variable weights 400–900.
- No self-hosted font files.
- Tight Content Security Policy with nonces; no `unsafe-eval`.
- Full package has **no default usable admin token**.
- Query-string admin token disabled by default.
- Admin writes are POST + same-origin + CSRF protected.
- English/Marathi canonical + hreflang + bilingual sitemap.
- WebSite/WebPage/Dataset JSON-LD with source/provider attribution.
- Read-only OpenAPI 3.1 interface for search systems and future conversational agents.
- Optional `llms.txt` + custom agent discovery manifest.
- Admin/refresh/runtime operations intentionally excluded from the public agent tool surface.
- v1.2.1 web-native startup recovery retained for Cloudways hosts that block process functions.

Read:

- `TYPOGRAPHY_GOOGLE_FONTS_GUIDE.md`
- `SECURITY_GUIDE.md`
- `SEO_AI_AGENT_GUIDE.md`
- `V1.3.0_AUDIT.md`

## Runtime architecture

**Pune APMC public source → server-scheduled Python collector → PHP/page failover only when server heartbeat is stale → atomic JSON/CSV cache → PHP read API → Tailwind UI + Alpine CSP runtime**

There is no database and no Redis requirement. Existing last-good data is served while refresh work happens. Server cron is primary; the PHP recovery collector is activated by page traffic only when the server heartbeat is stale/error and is especially useful on hosts where `exec`, `popen` and `proc_open` are disabled.

## Bilingual interface

Public and Admin/System views support persistent **EN / मराठी**. Commodity names, markets, categories, units, dates, status text, filters and core controls are bilingual. Search accepts English and Marathi terms regardless of display language.

Mappings live in `config/i18n.json`, `collector/commodity_map.json` and `collector/config.json`. See `I18N.md`.

## Typography

The runtime requests Google Fonts directly:

- `Noto Sans` — English/Latin UI
- `Noto Sans Devanagari` — Marathi/Devanagari UI
- variable weights 400–900
- `display=swap`

No WOFF/WOFF2/TTF/OTF files are included. See `TYPOGRAPHY_GOOGLE_FONTS_GUIDE.md` for the weight/spacing rules.

## Production environment

Recommended environment variables:

```text
APMC_PUBLIC_ORIGIN=https://your-domain.example
APMC_ADMIN_TOKEN=<strong random secret, at least 32 bytes>
```

If Python is installed under a different executable, optionally set:

```text
APMC_PYTHON=/path/to/python3
```

The full package leaves `APMC_ADMIN_TOKEN` empty by default; Admin remains disabled until configured. The old v1.0–v1.2 placeholder token is also treated as disabled after upgrade.

## Install / upgrade

### Existing v1.2.1 installation

Use the v1.4.0 **upgrade** ZIP. Overlay it on the existing application and keep `storage/` and your current `config/app.php`. Then set the production environment variables above and hard-refresh once.

### Clean installation

Use the v1.4.0 **full** ZIP. Upload anywhere under the site, make `storage/` writable by PHP, configure the environment variables and open the application URL.

Supported deployment forms include:

- `/apmc/`
- `/ver/apmc/`
- `/tools/market/apmc/`
- a virtual host whose document root points to `public/`

Pointing the document root to `public/` is the preferred security layout where hosting permits it. The root wrapper + supplied `.htaccess` remains supported for portable subfolder deployment.

## Admin

Open:

```text
?view=admin
```

Enter the `APMC_ADMIN_TOKEN` in the POST login form. Successful login becomes an HttpOnly, SameSite=Strict cookie scoped to the application path. URL/query-string token login is off by default.

Admin actions include Check now, Retry missing, Rebuild indexes and Backfill. Mutating actions are POST-only and protected by same-origin and CSRF checks.

## Public data/API surface

Clean rewrite forms (when Apache rewrite is available):

```text
/api/latest
/api/query?q=tomato&lang=en&limit=20
/api/summary?lang=en
/api/market?slug=<market-slug>
/api/health
/api/discover
/openapi.json
/sitemap.xml
/robots.txt
/llms.txt
/.well-known/apmc-data-agent.json
```

Equivalent query-string routes continue to work if rewrites are unavailable.

The public health response is intentionally redacted. Detailed process/runtime/collector health requires Admin authorization.

## SEO / AI-search readiness

Both language variants are server-rendered and expose canonical/hreflang, localized title/description, semantic explanatory content, sitemap entries and JSON-LD. The public OpenAPI/query endpoints provide stable factual retrieval for agentic conversation without giving the agent write/administrative capabilities.

`llms.txt` is an optional discovery aid; it is not treated as a Google ranking mechanism. See `SEO_AI_AGENT_GUIDE.md`.

## Agent protocol status

v1.3.x is **agent-ready but not an A2A server**. It deliberately does not publish `/.well-known/agent-card.json`. The custom discovery manifest describes only the current read-only API. Implement A2A transport/operations first before advertising A2A compatibility.

## Collection / freshness

Server cron is the primary refresh authority. Install `bin/server-refresh.sh` as a cron command every 5 minutes; the collector writes a heartbeat on each invocation and self-throttles actual source checks by Pune local time (5/10/15/60-minute windows). See `SERVER_SCHEDULER.md`.

Page traffic is a safety net, not the primary scheduler. If the server heartbeat is overdue for 15 minutes or the server collector reports a fatal error, a page request first tries the Python collector and then uses the same-origin PHP one-feed-at-a-time fallback when process launching is unavailable. Python and PHP share the same refresh lock.

Each feed verifies direct dated APMC URLs. If the newest dated page is empty, recent earlier dates are checked and the last valid populated snapshot is retained. Empty tables are treated as `no_new_data`, not destructive errors.

Source failures do not delete the last valid local snapshot.

## Storage

- `storage/current/all.json` — aggregate latest snapshot
- `storage/markets/*.json` — current market feed snapshots
- `storage/daily/YYYY/MM/DD/*.json` — archived daily snapshots
- `storage/commodities/*.json` — commodity indexes/history
- `storage/exports/latest.csv` — latest CSV export
- `storage/system/manifest.json` — collector/feed state
- `storage/system/health.json` — internal health data
- `storage/system/refresh.log` — bounded diagnostic log

Writes use temporary files + atomic rename.

## Raster media policy

Use WebP for new photographic/raster imagery. Keep true vector interface icons as SVG. See `MEDIA_POLICY.md`.

## Frontend development

The production package contains built CSS/JS; Node is not required on the server. Source lives under `resources/`. The optional Vite development path uses Alpine CSP. Google Fonts remain external by design.

## Versions

- **1.3.0** — Google Fonts typography system, security hardening, bilingual SEO, Dataset JSON-LD, OpenAPI/read-agent layer.
- **1.2.1** — Cloudways web-native startup recovery + cache-busting.
- **1.2.0** — EN/मराठी naming/interface layer.
- **1.1.0** — light/dark visual and code audit.
- **1.0.1** — Cloudways disabled-function fatal protection.
- **1.0.0** — initial folder-portable release.

## KrushiPulse personal dashboard (v1.6.0)

The frontend provides three instant-switchable visual themes: Harvest, Midnight and Saffron. The header palette icon cycles themes. The globe icon switches Marathi and English (server-side localized markup). Open Personalize to choose a default mandi and favorite crops; starring a commodity saves it immediately. These settings persist **locally in the current browser** via localStorage; no account and no cross-device synchronization. When arriving again, the stored market and crops are loaded without a new search.

The data shown comes from the preserved collector/API and displays the source report date and unit. If the source has not published data, the page shows an unavailable state rather than fabricated quotations. The deployment still needs server cron, private storage and a live-source validation check.

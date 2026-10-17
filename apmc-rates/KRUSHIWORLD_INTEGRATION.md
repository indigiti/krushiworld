# KrushiWorld / KrushiPulse — APMC Rates module

This directory is a drop-in application folder under the KrushiWorld master project:

```
krushiworld/
  apmc-rates/
    public/                 # Only directory to expose via web server, if possible
      index.php             # PHP router + read API
      build/krushipulse.css    # Responsive Marathi-first UI
      build/krushipulse.js     # Vanilla browser UI (no Alpine/CDN dependency)
    app/                    # PHP ingestion and normalization services
    collector/              # Configured Python collection of 19 Pune APMC feeds
    bin/server-refresh.sh   # Server cron entrypoint
    config/                 # Locale and server settings; PRIVATE
    storage/                # Private data snapshots; writable, outside public document root
    tests/                  # Collector and UI contract tests
    index.php               # Optional subfolder/Apache compatibility wrapper
```

## Get started

1. Deploy the contents of this directory to `apmc-rates/`. **Preferred:** route `https://YOURDOMAIN/apmc-rates/` to this directory's `public/` with a web-server alias/vhost, keeping `app/`, `config/`, `collector/`, `storage/` outside publicly served routes. A root wrapper works only when the server (including any Nginx proxy) denies direct access to non-public paths.
2. Configure `APMC_PUBLIC_ORIGIN=https://YOURDOMAIN` and a strong `APMC_ADMIN_TOKEN` environment secret (32+ random bytes). No default admin password/token is provided.
3. Install Python requirements from `requirements.txt` and ensure writable `storage/`. PHP 8.1+, Python 3.10+, HTTPS outbound access and cron are required.
4. Install server cron `*/5 * * * * /absolute/path/to/krushiworld/apmc-rates/bin/server-refresh.sh > /dev/null 2>&1` (server timezone does not matter; collector uses Pune/Asia-Kolkata).
5. Open the app (`?lang=mr` or `?lang=en`). Use `?api=health` for redacted status and `?view=admin` for authenticated diagnostics.
6. Validate the 19 source feed mappings and units against original Pune APMC reports **before** declaring the site production ready. Access to the official source cannot be independently verified as part of this offline package build.

## Design decisions

- The supplied `apmc.zip` was treated as the v1.4.0 source engine. Its collector/normalizer/SEO/admin/agent API logic is retained.
- KrushiPulse is the personalized Marathi/English frontend under the KrushiWorld parent brand, using Harvest, Midnight and Saffron themes and browser-local saved mandis/crops.
- Dynamic market rates come only from the source engine, never invented hardcoded numbers. Each featured card and each table row shows its own report date. No unimplemented app-download or alerts claim is displayed.
- Public UI uses a small first-party JavaScript module instead of remote Alpine. Original admin view may still depend on its existing CSP Alpine runtime; review that separately if full offline/self-hosted dependency freedom is required.
- Clean distribution includes no pasted/cached price rows; it fetches first verified data after deployment or via existing recovery logic. To migrate existing price history, preserve the deployed `storage/` directory instead of overwriting it.

## Existing API (unchanged)

- `?api=latest` — complete saved rates snapshot
- `api.php?category=main-fruit&date=YYYY-MM-DD` — compatibility adapter for the first KrushiWorld prototype (last verified archive on or before requested date, max 30-day lookback). Returns `stale: true` when dates differ; it does not fetch rates directly.
- `?api=query&q=tomato&lang=en&limit=20` — canonical bilingual commodity search
- `?api=summary`, `?api=market&slug=main-fruit`, `?api=health` — read-only views
- `?seo=openapi`, `?seo=sitemap`, `?seo=robots` — discovery and SEO

## State and limitations

- Source engine's Python tests pass and PHP syntax lints pass in the package environment; external collector access and hosted deployment are not yet tested.
- The user-provided screenshot was used as **visual direction**, not embedded directly as a full-page bitmap.
- `KrushiPulse` here is a mobile-responsive website, **not yet a native Android or iOS application**. No push notification or distance-to-market service is advertised.
- Check Cloudways Nginx rules carefully. `.htaccess` is not sufficient to protect private folders if Nginx can serve them directly.

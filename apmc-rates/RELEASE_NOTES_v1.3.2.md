# APMC Pune Data Engine v1.3.2 — Market Tape + Sponsor Layer

## Added

- Horizontal market-value tape below the hero.
- Canonical bilingual commodity and market names in the tape.
- Average price/unit and saved-session movement per ticker item.
- Market-aware ticker filtering.
- Primary sponsor and co-sponsor slots.
- Local WebP sponsor-logo policy.
- `rel=sponsored` link disclosure.
- Reduced-motion and hover/focus pause behavior.

## Security / data separation

- No remote sponsor image domains are allowed.
- Sponsor URLs are HTTPS-only.
- Sponsor branding is never added to public market APIs or agent/SEO Dataset data.
- Existing CSP remains unchanged for images (`img-src 'self' data:`).

## Upgrade safety

The overlay package excludes `storage/` and `config/app.php`. Existing APMC data, history and server-specific configuration remain untouched. Sponsor settings can be supplied through environment variables, so the upgrade works with an older config file.

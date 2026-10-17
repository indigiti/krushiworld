# Market Tape + Sponsor / Co-sponsor Guide

## Market tape

The public page renders a horizontal market tape directly below the hero. It uses the same normalized customer data as the cards and APIs, so English mode receives English commodity names and Marathi mode receives Marathi names. Each item shows:

- canonical commodity name
- physical market name
- average price and unit
- saved-session movement

The tape follows the selected market filter, pauses on hover/focus, and respects `prefers-reduced-motion`. No deprecated `<marquee>` tag or extra animation library is used.

## Primary sponsor

Set these production environment values:

```text
APMC_SPONSOR_ENABLED=1
APMC_SPONSOR_NAME=Company Name
APMC_SPONSOR_URL=https://example.com/
APMC_SPONSOR_LOGO=company-name.webp
```

Copy the logo to:

```text
public/media/sponsors/company-name.webp
```

## Co-sponsor

```text
APMC_COSPONSOR_ENABLED=1
APMC_COSPONSOR_NAME=Co Sponsor Name
APMC_COSPONSOR_URL=https://example.org/
APMC_COSPONSOR_LOGO=co-sponsor.webp
```

Copy that logo to `public/media/sponsors/co-sponsor.webp`.

## Logo rules

- WebP only.
- Recommended canvas: 128×128 or 256×128, transparent or white background.
- Keep the source file under 100 KB when practical.
- File names may use letters, numbers, dot, underscore and hyphen.
- The application deliberately rejects remote logo images. This keeps `img-src 'self' data:` intact and prevents third-party image-request tracking.
- If a configured logo is missing, the UI safely shows sponsor initials instead of a broken image.

## SEO / disclosure

Sponsor links are rendered with `rel="sponsored noopener noreferrer"`. Sponsorship is a presentation layer only and is not added to Pune APMC Dataset JSON-LD, `/api/latest`, `/api/query`, OpenAPI market facts or the agent discovery payload. This keeps commercial branding separate from factual market data.

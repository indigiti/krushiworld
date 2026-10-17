# APMC Pune Data Engine v1.3.1 — Security / SEO / Agent Audit

## Typography
PASS — Google-hosted Noto Sans + Noto Sans Devanagari selected as a coherent bilingual family system.
PASS — No self-hosted font binaries are required.
PASS — Variable weight range 400–900 + `display=swap`.
PASS — Marathi-specific line-height/tracking rules retained.

## Browser/security
PASS — CSP nonce for inline boot/config/schema scripts.
PASS — CSP-compatible Alpine runtime; no `unsafe-eval` requirement.
PASS — Google font origins narrowly listed in CSP.
PASS — HSTS on HTTPS, frame denial, nosniff, referrer/permissions/cross-origin headers.
PASS — Admin token has no usable default; legacy placeholder token is rejected on upgrade.
PASS — Query-string admin token disabled by default.
PASS — HttpOnly/SameSite=Strict admin cookie.
PASS — Admin write actions POST-only + same-origin + CSRF.
PASS — Anonymous health response is redacted.
PASS — Public agent surface is read-only.

## SEO
PASS — server-rendered English/Marathi title/description.
PASS — canonical + `hreflang` + x-default.
PASS — bilingual sitemap with alternate links.
PASS — robots generator aware of app base path.
PASS — visible semantic SEO copy instead of hidden crawler-only text.
PASS — WebSite/WebPage/Dataset JSON-LD with source/provider attribution.

## AI/agent readiness
PASS — OpenAPI 3.1 public read interface.
PASS — deterministic bilingual query endpoint.
PASS — compact summary endpoint.
PASS — agent discovery manifest.
PASS — optional `llms.txt` discovery text.
PASS — admin/refresh/runtime capabilities excluded from agent tool contract.
PASS — package explicitly does not claim A2A protocol support.

## Deployment actions still required
- Set `APMC_PUBLIC_ORIGIN=https://lexicon.digiti.in`.
- Set a strong `APMC_ADMIN_TOKEN` in the hosting environment.
- Ensure domain-root `/robots.txt` references the APMC sitemap when deployed in `/ver/apmc/`.
- Validate production CSP in browser developer tools after upload.
- Submit sitemap/canonical URLs in Search Console.

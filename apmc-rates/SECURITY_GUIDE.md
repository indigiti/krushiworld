# APMC Pune v1.3.0 — Production Security Guide

## Required production settings

Set environment variables in Cloudways / PHP-FPM rather than putting secrets into a public URL:

```text
APMC_PUBLIC_ORIGIN=https://lexicon.digiti.in
APMC_ADMIN_TOKEN=<a random secret of at least 32 bytes>
```

Generate a strong admin token with a secure password manager or a cryptographic generator. Example on a trusted machine:

```bash
openssl rand -hex 32
```

The full v1.3.0 package ships with no default usable admin token. Admin access remains disabled until `APMC_ADMIN_TOKEN` is configured. On upgrades, the old placeholder `APMC-CHANGE-ME-9K7X2Q` is also treated as disabled rather than accepted as a credential.

## Admin authentication

- Login token is submitted by POST.
- Successful login is promoted to an HttpOnly, SameSite=Strict cookie.
- Query-string token login is disabled by default because URLs can leak into logs, history and referrers.
- State-changing admin operations are POST-only.
- Admin actions require a same-origin check and CSRF token.
- Admin pages send `X-Robots-Tag: noindex, nofollow, noarchive`.
- Rotate `APMC_ADMIN_TOKEN` to invalidate the derived admin session cookie.

Do not enable `admin.allow_legacy_query_token` in production unless temporarily needed for migration.

## Browser security headers

The application sends:

- `Content-Security-Policy`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- restrictive `Permissions-Policy`
- `Cross-Origin-Opener-Policy: same-origin`
- `Cross-Origin-Resource-Policy: same-origin`
- `Origin-Agent-Cluster: ?1`
- HSTS on HTTPS requests

CSP allows local application resources, Google Fonts stylesheet/font origins, and the pinned Alpine CSP distribution URL. It does not require `unsafe-eval`.

## Third-party runtime policy

Fonts are intentionally loaded from Google Fonts. JavaScript uses the CSP-compatible Alpine build pinned to an exact release path. GSAP is not required by the production runtime in v1.3.0.

For an even smaller future supply-chain surface, Alpine can be compiled into the application build while leaving Google Fonts external. That change is independent of the font policy.

## Public vs private API boundary

Public agent/search APIs are read-only:

- `/api/latest`
- `/api/query`
- `/api/summary`
- `/api/market`
- `/api/health` (redacted public view)
- `/api/discover`
- `/openapi.json`

Admin refresh, retry, rebuild/backfill, detailed health, collector runtime, tokens, logs and server configuration are intentionally not declared as public agent tools.

The public bootstrap recovery endpoint is POST-only, uses a fixed source list, accepts no arbitrary URL, is lock-protected and requires a same-origin browser signal.

## PHP / server recommendations

Production PHP should use:

```text
display_errors = Off
log_errors = On
expose_php = Off
session.cookie_secure = On   # if PHP sessions are enabled elsewhere
```

Keep HTTPS enforced. If available, use Cloudways/WAF rate limiting for `/index.php?view=admin` and public APIs. Back up `storage/` before major upgrades.

The preferred deployment model is a document root that exposes only `public/`. If the portable wrapper must remain under `/ver/apmc/`, retain the supplied `.htaccess` rules so `config/`, `storage/`, collector configuration, logs and lock files cannot be downloaded directly.

## Cloudways process restrictions

The application remains safe when PHP process functions are disabled. The PHP web-native collector is the fallback and must never accept a caller-supplied upstream URL. Do not re-enable dangerous process functions merely to make collection work.

## Deployment verification

After deployment verify:

1. HTTPS redirects correctly.
2. `APMC_PUBLIC_ORIGIN` exactly matches the production origin.
3. Admin is inaccessible until the configured token is supplied.
4. `?api=health` does not expose process-function or server internals anonymously.
5. `/config/`, `/storage/` and collector configuration are not web-readable.
6. Browser console has no CSP violations during normal light/dark and EN/मराठी usage.
7. Admin actions fail without a valid CSRF header/cookie session.
8. Google Fonts load only from `fonts.googleapis.com` and `fonts.gstatic.com`.

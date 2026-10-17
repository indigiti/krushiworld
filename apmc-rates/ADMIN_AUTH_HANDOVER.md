# KrushiPulse administrator authentication — staged migration

## Important: deployment does not enable or switch logins by itself

The default remains `APMC_AUTH_MODE=legacy`. Existing `APMC_ADMIN_TOKEN` authentication continues until the server owner explicitly changes the environment. The upgrade is included in the DigiOps private build but **does not create accounts, GitHub credentials, or modify Cloudways production configuration** automatically.

| Mode | Allowed login | Notes |
|---|---|---|
| `legacy` (default) | Existing APMC_ADMIN_TOKEN | Migration compatibility, no GitHub/local identity storage used |
| `staging` | Approved GitHub App identities and individual local password+MFA users | GitHub toggle is available only to a locally verified Super Admin |
| `production` | Individual local password+MFA users only | GitHub endpoints and prior GitHub sessions are rejected server-side |
| Unknown/invalid value | None | Fail closed rather than inadvertently exposing the legacy login |

## Stage 1: prepare an isolated staging site

Set these **server-side** environment variables in isolated staging (never commit them or place them in a query string):
- `APMC_AUTH_MODE=staging`
- `APMC_PUBLIC_ORIGIN=https://<your-staging-domain>` — the verified origin
- `APMC_AUTH_KEY=<64 hex characters>` — distinct 32-byte secret for encrypting MFA seeds. Generate with `php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`; store securely in your secret manager and backups.
- `APMC_GITHUB_CLIENT_ID=<GitHub App client ID>`
- `APMC_GITHUB_CLIENT_SECRET=<GitHub App client secret>`
- `APMC_GITHUB_ADMIN_IDS=<comma-separated numeric GitHub user IDs>` — use **stable numeric IDs**, not GitHub login names.

Register a GitHub App in GitHub Developer settings with its callback URL exactly `https://<your-staging-domain>/<deployment-prefix>/admin/`. Use the **GitHub App user authorization web flow**, not pasted fine-grained PATs. Grant only the minimum permissions needed to read the current user identity. OAuth state is bound to the browser session, validated on callback and expires after 5 minutes; PKCE S256 is used. Temporary GitHub access tokens are used solely to retrieve the numeric ID and are **not persisted**. Callback requires HTTPS and an allowlisted user.

Ensure the current PHP runtime has **PDO SQLite, Sodium, cURL, Argon2id, PHP sessions** and writable, persistent **private** `storage/auth/` (not the public web root). The application runs in Cloudways' established public/private DigiOps split.

## Stage 2: create and verify a local Super Admin

1. Sign in from the staging admin page using an approved GitHub identity.
2. In **Security & Access → Create administrator**, create an individual account with role **Super Admin** and a strong passphrase.
3. Save the one-time TOTP setup key and eight recovery codes shown on the authenticated response. The TOTP seed is encrypted with Sodium secretbox and the server-only APMC_AUTH_KEY. Recovery codes are stored only as irreversible hashes, each usable once after a valid password.
4. Enroll a TOTP app and sign in as the new local user **in a separate private browser** with username, password and a fresh authenticator code. This activates the pending account and records a successful local verification.
5. Log back in as the verified local Super Admin. The dashboard now permits **Disable GitHub**; this action is rejected until a local Super Admin is verified. On disable it revokes GitHub sessions and records a security event.
6. Verify local login, logout, administrative actions, session expiration, account suspension and recovery in staging. Keep at least one recovery route.

Account roles: Super Admin manages accounts and providers; Admin/Operator can run collector actions; Viewer can access read-only admin metrics. Sessions are per-user, revocable, and have a 30-minute idle and 8-hour absolute expiry. All sensitive mutations require same-origin requests and CSRF validation.

## Stage 3: production — explicit handover, **not** staging DB cloning

**Do not clone staging OAuth identity data or secrets to production.** Production must have its own APMC_AUTH_KEY, private `storage/auth/`, trusted origin, and locally verified Super Admin.

An isolated production bootstrap helper is bundled **under the private application root only**:

    export APMC_AUTH_MODE=production
    export APMC_AUTH_KEY=<unique-production-64-hex-secret>
    php bin/provision-super-admin.php <local-admin-username>

Run from the private `krushiworld` folder using an **interactive SSH shell**. The helper refuses web requests, and refuses to create an additional bootstrap Super Admin once a locally verified one exists. Save its one-time authenticator secret and recovery codes securely. Use a fresh browser to log in, complete TOTP verification, and confirm the account works **before** retiring the legacy APMC_ADMIN_TOKEN.

**Critical sequencing:** provision and verify production local login in a protected environment, then switch the production configuration to `APMC_AUTH_MODE=production` and remove `APMC_ADMIN_TOKEN` from the environment. Do not perform the switch before provisioning, and do not leave both shared-token and individual login enabled. Ensure a tested backup/restoration process for the private identity database **and its corresponding encryption key**. Replacing APMC_AUTH_KEY without re-enrolling MFA locks out accounts.

In production, GitHub sign-in is blocked on the backend regardless of any prior staging dashboard toggle. Staging and production identities are intentionally separate.

## Auditing, emergency procedures and limitations

- Authentication data never enters the public DigiOps artifact. Source updates do not delete the private identity SQLite file.
- Security events record identity creation, local MFA verification, successful login, provider changes and account status changes without storing passwords, TOTP values or GitHub bearer tokens.
- Accounts can be disabled and their sessions revoked; the current actor cannot disable their own account and the last verified local Super Admin cannot be disabled.
- Login throttling enforces five failed attempts per username during a 15-minute window. This does not replace server/WAF rate limits.
- This implementation uses TOTP rather than passkeys; as a separate hardening milestone, use a security-key/WebAuthn MFA factor and independently verified password recovery if required by production policy.
- A green DigiOps CI verifies code and simulated HTTP behavior; it does not prove GitHub App registration, real OAuth authorization, SSH setup, production configuration, device authenticator integration or an actual Cloudways login.
- Never place a GitHub personal access token in an admin login form, JS bundle, URL, or repository.

# KrushiPulse: direct browser setup (staging)

After deploying the matching KrushiWorld `digiops-release` package, open **https://stage.digiti.in/krushiworld/admin/**.

The page displays **Activate KrushiPulse Admin** for a first-time local administrator, when the HTTPS staging host has no explicit `APMC_AUTH_MODE` setting. Existing explicit `legacy`, `staging`, or `production` server settings are honored, and this direct bootstrap is unavailable on production hosts.

1. In GitHub browser settings, create a **short-lived fine-grained PAT** scoped **only** to `indigiti/krushiworld` with repository **Metadata: read**. The GitHub user must have **Admin** permission on this repository. This PAT proves ownership *once*; it is not your regular login credential. Revoke it after setup.
2. On the KrushiPulse admin page, enter the GitHub verification token, choose a username and strong password (14+ characters), and press **Verify GitHub and create Super Admin**.
3. Save the authenticator secret and one-use recovery codes generated on-screen. Add the secret to an authenticator application and sign in with username, password, and six-digit MFA code. Credentials and recovery codes must be saved securely.
4. In **Security & Access → India mandi data**, paste the data.gov.in API key and select **Save API key securely**. It is stored privately at `private_html/krushiworld/storage/config/runtime.json` with restrictive permissions; it does not go to GitHub, browser JavaScript or a deployment artifact.
5. Use the existing Cloudways web cron configuration to schedule `bin/server-refresh.sh` if it isn't already scheduled. The national collector reads the saved private API key on its next run. Check `/krushiworld/?api=markets` for national status and actual source-report dates.

**Security:** Only staging and a verified repository administrator may create the initial local account. No default admin, public open registration, or production auto-bootstrap. The PAT is sent in an HTTPS POST with CSRF and same-origin checks, used solely for two GitHub API calls, and neither saved nor logged by application code. Do not put it into GitHub URLs, repository files or this chat. For persistent credentials, use individual local accounts with MFA. Secure storage must be preserved across DigiOps releases and backups.

**Limitations:** CI cannot validate a real Cloudways HTTPS request, outbound GitHub calls, PAT permissions, national feed quotas or cron execution. An existing explicit legacy authentication setting must be changed through the hosting owner; this code does not override it. Private storage must be writable and PHP must support PDO SQLite, cURL and Sodium.

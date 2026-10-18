# KrushiWorld: one-time secure Cloudways setup

**For DigiOps two-folder installations**, configure the app once using a protected persistent file in Cloudways private storage. This file is **not** tracked in Git and is **not** in the `digiops-release` payload. Existing host environment variables take precedence.

## 1. Place private settings (SFTP or SSH)

Create exactly this file in the deployed application root:

```
private_html/krushiworld/storage/config/runtime.json
```

Use this example with your own **private** values (do not commit, paste into chat, or add to `public_html`):

```json
{
  "APMC_AUTH_MODE": "staging",
  "APMC_PUBLIC_ORIGIN": "https://stage.digiti.in",
  "APMC_AUTH_KEY": "REPLACE_WITH_64_HEX_CHARACTERS",
  "APMC_DATA_GOV_IN_API_KEY": "REPLACE_WITH_YOUR_DATA_GOV_IN_API_KEY",
  "APMC_NATIONAL_INTERVAL_HOURS": "6"
}
```

Generate the MFA encryption key **once** in a private shell:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

On the server, restrict the runtime file to the application's account (mode `0600`; directories `0700`). A weak/invalid or world/group-accessible file is rejected before use. Save `APMC_AUTH_KEY` separately in your protected backups: changing it without reenrollment invalidates existing authenticator registrations. Keep a backup of `storage/auth/identity.sqlite` with its corresponding key.

The app's PHP bootstrap and Python AGMARKNET collector now read this same private file. You can still use actual process environment variables instead; they take precedence. Nothing secret appears in GitHub Actions artifacts, browser JavaScript or the JSON price API.

## 2. Enable admin login and create the initial local Super Admin

Once `APMC_AUTH_MODE=staging` is active, `https://stage.digiti.in/krushiworld/admin/` must show **Admin authentication**, fields for username/password/authenticator, **not** the old token form.

The code **deliberately does not create a default admin**. Create your first user with an **interactive SSH terminal** (commands from the deployed private directory):

```sh
cd /ABSOLUTE/CLOUDWAYS/APPLICATION/ROOT/private_html/krushiworld
php bin/provision-super-admin.php ownerAdmin
```

The helper prompts for a password with terminal echo disabled. It prints one-time TOTP enrollment details and recovery codes; securely save them. Use an authenticator app to obtain a six-digit code and complete your first login at `/krushiworld/admin/`.

If your Cloudways CLI prevents safe terminal password entry, the helper intentionally refuses to run; do not bypass that check or create an insecure public web setup route. Use the alternate allowlisted GitHub App bootstrap process in [ADMIN_AUTH_HANDOVER.md](ADMIN_AUTH_HANDOVER.md). **A fine-grained PAT is not a browser login.**

## 3. Activate official nationwide mandi imports

The importer uses the official AGMARKNET resource `9ef84268-d588-465a-a308-a864a43d0070`. The received data.gov.in key is used server-side only. To perform the first import:

```sh
cd /ABSOLUTE/CLOUDWAYS/APPLICATION/ROOT/private_html/krushiworld
python3 collector/national.py --force
```

Then add or retain the existing Cloudways application scheduler (runs every five minutes, while the national fetch itself is throttled):

```cron
*/5 * * * * /bin/sh /ABSOLUTE/CLOUDWAYS/APPLICATION/ROOT/private_html/krushiworld/bin/server-refresh.sh
```

Use your **actual application directory** from Cloudways; the paths above are examples, not working absolute paths.

Confirm `https://stage.digiti.in/krushiworld/?api=markets` returns `data.national.status=ready` or `stale` with a populated reporting-date field. If it says `awaiting_import`, check cron, API response, quotas, HTTPS access and `storage/national/health.json` on the private server; do not post your key or private logs with secrets. Old reports must be labeled by their actual date. The importer retains previous verified records on failure.

## 4. DigiOps deployment protection

Release contents go to `public_html/krushiworld/` and `private_html/krushiworld/`. **Preserve `private_html/krushiworld/storage/`** on every deploy/rollback. This is required for the runtime settings file, imported mandi prices and local Super Admin identity. Verify the deployed DigiOps agent's persistence behavior independently, and back up persistent state.

The staging local Super Admin and key are separate from production. Changing to `APMC_AUTH_MODE=production` is a **deliberate** handover after a verified local MFA login, not an automatic step.

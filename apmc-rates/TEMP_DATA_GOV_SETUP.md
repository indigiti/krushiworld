# Temporary data.gov.in API key setup

Use this **only on the staging Cloudways site**, after deploying the matching KrushiWorld DigiOps release.

1. Open **https://stage.digiti.in/krushiworld/setup-data-gov.php**.
2. Create a short-lived GitHub fine-grained PAT at GitHub, scoped only to **indigiti/krushiworld** with **Metadata: Read**. Your GitHub account must have repository **Admin** permission.
3. Enter the PAT and your data.gov.in API key into the two browser form fields and select **Verify ownership & save API key**.
4. Confirm the success message. The page disables itself after a successful save, including after subsequent redeployments, because a persistent marker remains in private storage.
5. Revoke the temporary PAT in GitHub settings.
6. Verify the AGMARKNET importer is scheduled and inspect **/krushiworld/?api=markets** for its actual import status and reporting dates. A saved key alone does not prove a successful import.

The key is stored in \`private_html/krushiworld/storage/config/runtime.json\` (0600, server owner only), and cannot be read back through this webpage. **Preserve private storage across deployments and backups.** If a key already exists, the page is closed rather than overwriting it. Future changes belong in the authenticated KrushiWorld Admin → Security & Access dashboard.

**Safety conditions:** only an HTTPS request to the exact staging host is supported; no default credentials; POST + CSRF + origin validation; GitHub repository administrator verification; no PAT persistence; private atomic JSON storage; one-time setup marker; retry limits per browser session; no tokens in URLs, output or app logs. The server and your reverse-proxy security configuration must still protect request bodies and private files.

**Remove the temporary public entrypoint from the repository and release after completing setup.** Its one-time marker prevents reuse in the meantime.

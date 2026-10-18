# KrushiWorld: URL-based national AGMARKNET refresh

Use the HTTPS endpoint on a Cloudways Scheduled Cron / External Cron service:

**https://stage.digiti.in/krushiworld/?api=national-cron**

This endpoint runs **one bounded import batch per hit in native PHP**. It does not call shell execution, \`exec\`, \`putenv\`, or Python from the web request. The official data.gov.in API key stays in private \`storage/config/runtime.json\`; no key or token appears in this URL.

## Scheduling

- Recommended: send a GET every **1 minute** (HTTP cron). Every five minutes also works, but large national datasets take longer.
- In Cloudways Cron Job Management (Advanced), a URL-based command may use:
  \`curl --fail --silent --show-error --max-time 25 'https://stage.digiti.in/krushiworld/?api=national-cron' >/dev/null\`
- If using an external HTTP cron monitor, set its target URL to the same address and method GET.
- The route is deliberately fixed to the official AGMARKNET resource. No visitor-supplied target URLs, API credentials, offset, force flag or market IDs are accepted.
- URL is publicly callable because it performs only rate-limited, globally locked, fixed-source refresh work. Do not treat it as an authenticated admin endpoint. For restricted deployment, use Cloudways edge allowlists for the scheduler.
- **Choose HTTP or Python as your scheduled importer.** Both share the same \`.national-refresh.lock\`, but avoid enabling both schedulers at the same time to prevent competing refresh cycles.
- If you only want the Python collector, set the server environment \`APMC_DISABLE_NATIONAL_HTTP_CRON=1\` (PHP URL will report \`disabled\`).

## What one hit does

First it verifies that the key exists and checks the verified flag, completed snapshot, previous failed attempt and import progress.

| Runner status | Meaning |
| --- | --- |
| \`not_configured\` | API key missing |
| \`fresh\` | Completed verified import still within the six-hour interval; **no upstream request** |
| \`throttled\` | Another hit advanced the same import within the last 50 seconds |
| \`busy\` | A PHP or Python importer holds the global import lock |
| \`importing\` | Fetched one official page, stored verified-format rows privately, more pages pending |
| \`verified\` | Every page completed, candidate passed validations, snapshots published, flag set |
| \`cooldown\` | Import attempt failed recently; avoid retry storms |
| \`failed\` | Fetch, structure, completeness, or verification failed; retain previous good data |
| \`storage_unavailable\` | Cannot safely write private state |
| \`disabled\` | HTTP mode disabled through environment |

The collector caps each page to 1,000 rows and overall response size, requires TLS, checks the official feed's total count remains stable across pages and rejects incomplete pagination. It never publishes a partially imported national dataset.

At final verification it validates numerical min/modal/max prices, actual publication dates, stable market references, deduplicated record identities and no major drop versus previously verified national record count, and avoids regressing the newest report date. It then atomically replaces individual national snapshot files and marks completion only after both are written.

Data is served from the last published snapshot while import continues. Changes to published snapshots are **not transactionally atomic across both files**, so the completion flag is authoritative for a fully verified pair.

The PHP implementation uses \`storage/national/http-cron-state.json\`, \`http-cron-rows.ndjson\`, \`last_attempt.json\` and the shared \`.national-refresh.lock\`. All are private and excluded from Git/DigiOps artifacts. \`refresh_queue\`, \`verified_at\`, \`last_result\`, and \`record_count\` are visible from \`?api=markets\`.

**No Cloudways deployment is implied by this document**. Deploy a successful \`digiops-release\` artifact and ensure its private \`storage/\` directory is preserved.

> The national portal may have many pages. One HTTP hit does not always finish the first full import. Keep scheduling requests until \`status: verified\`, then subsequent requests return \`fresh\` without refetching.

## Safe failure diagnostics

After this release, a failed HTTP cron request returns a specific sanitized `runner.reason`, and the markets status API shows `national.last_failure_type` for failed attempts. No API keys, upstream URLs, response bodies, or HTTP error text are included.

- `upstream_http_401` or `upstream_http_403`: the upstream server rejects this request; check the API key/account and whether Cloudways IP is blocked. A 403 does not alone prove the key is invalid.
- `upstream_http_429`: upstream quota or rate limiting.
- `upstream_http_5xx`: upstream service error.
- `network_dns_failure`, `network_connect_failure`, `network_timeout`: connectivity problems between Cloudways and data.gov.in.
- `network_tls_failure`, `network_certificate_failure`: verify TLS/certificates; do not disable certificate verification.
- `invalid_source_json` or `upstream_unexpected_schema`: data.gov.in returned an unexpected payload.

Keep the normal one-hour retry cooldown; do not force repeated requests or expose the key in browser URLs.

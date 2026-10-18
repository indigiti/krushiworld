# National market refresh: visitor-triggered check, verified publication

KrushiPulse uses **stale-while-revalidate**. A visitor sees the latest *verified* stored rates immediately. The page does not download the data.gov.in feed or run long Python work inside a PHP request.

## When someone opens the site
- On the storefront or `?api=markets`, `NationalRefreshGate` checks the private national snapshot, latest verified refresh flag and timestamp.
- **Fresh success** (default six hours): no new request; return cached records.
- **Worker attempted within an hour**: no retry. A failed source fetch does not cause a visitor storm.
- **Request already queued within 15 minutes**: do not queue again.
- **Absent/expired successful refresh**: write a small private `storage/national/refresh-request.json` pending flag under a nonblocking lock and serve the existing verified data immediately.
- The gate is local-file-only and has no user-controlled network destination or user-controlled refresh URL.

## Who performs the import?
The existing Cloudways scheduler calls `bin/server-refresh.sh`; this invokes `collector/national.py` using the protected API key. The collector uses an OS file lock, checks its one-hour retry cooldown and the six-hour completed snapshot interval. It does not repeat an import when the verified snapshot is still fresh.

**Important:** A visitor refresh *requests* work; it cannot complete national imports without the Python scheduler. On Cloudways, PHP process execution may be disabled. Keep the every-five-minute cron enabled using the Cloudways web dashboard. No public visitor is allowed to force or bypass worker cadence.

## Verification and completion
The national worker retains existing safeguards: complete pagination, dated source records, finite modal/min/max prices, valid source structure, and detection of major coverage drops. It now also checks:
- exact record totals, valid market references and uniqueness;
- nonfuture report dates and prices inside verified min/max ranges;
- a latest official report date that does not regress behind the previous verified snapshot.

Only after candidate verification and publication of both `current.json` and `directory.json` does the worker write `storage/national/verified.json`. It records completion time, record count, market count, latest source-report date and a source-content SHA-256 digest, **never the API key**. Last-good data is retained on validation failure, with a safe error code in private health state.

A successful or failed actual import clears the queued visitor request. A successful recent report prevents another import until the configured interval. A failed import receives the normal retry cooldown.

## Status endpoint
`/krushiworld/?api=markets` exposes `data.national` fields:
- `status`: awaiting_import / ready / stale;
- `refresh_queue`: pending_scheduler / verified / idle;
- `verified_at`, `last_attempt_at`, `last_success_at`, `last_result`.

Dates shown to visitors come from the publisher's actual report date; the time of fetching does not turn an old price into a current quote.

## What is preserved?
The verified data, refresh flags and retry state live only in `private_html/krushiworld/storage/national/`, which must survive DigiOps deployments. This workflow does not store secrets in GitHub, add a new database or require visitor login.

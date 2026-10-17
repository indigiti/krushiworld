# Server-driven refresh + page fallback

Version 1.4.0 makes the server scheduler the primary refresh path. Browser/page traffic is only a fallback when the server heartbeat is overdue or the server collector reports a fatal error.

## Cloudways cron

Run the bundled scheduler wrapper every 5 minutes. It writes a heartbeat every time it is invoked, but the Python collector self-throttles source polling by Pune time:

- 06:00–09:00: every 15 minutes
- 09:00–15:00: every 5 minutes
- 15:00–18:00: every 10 minutes
- 18:00–06:00: every 60 minutes

Example cron entry:

```cron
*/5 * * * * /absolute/path/to/apmc/bin/server-refresh.sh >/dev/null 2>&1
```

If Cloudways requires the Python executable explicitly:

```cron
*/5 * * * * APMC_PYTHON=/usr/bin/python3 /absolute/path/to/apmc/bin/server-refresh.sh >/dev/null 2>&1
```

Replace `/absolute/path/to/apmc` with the deployed private application path.

## Failover

The manifest stores:

- `last_server_heartbeat_at` — proves the cron command is still executing.
- `last_server_checked_at` — last completed source collection by server cron.
- `last_server_status` — `healthy`, `running`, or `error`.

A normal page request checks that heartbeat. If the cron heartbeat is stale for the configured heartbeat grace window (15 minutes by default), or the last server status is `error`, it first tries to launch the Python collector in the background. If PHP process execution is disabled, the browser uses the same-origin PHP collector one feed at a time.

Both collectors use `storage/system/refresh.lock`; they cannot write snapshots concurrently.

## Source-date behavior

Each feed verifies direct dated URLs. If today's page exists but contains no valid rate rows, the collector checks recent earlier dates and retains the newest populated feed snapshot. An empty page is recorded as `no_new_data`, not as a destructive failure.

## Optional environment controls

- `APMC_DISABLE_REFRESH=1` disables page-triggered Python refresh.
- `APMC_DISABLE_WEB_FALLBACK=1` disables browser/PHP fallback while leaving server cron available.
- `APMC_PYTHON=/path/to/python3` overrides the Python executable used by page-triggered background refresh and the shell wrapper.

# Scheduler + Startup Recovery — v1.4.0

## Primary path

The server cron is the normal refresh authority. Install `bin/server-refresh.sh` every 5 minutes as described in `SERVER_SCHEDULER.md`. Each invocation writes a server heartbeat, while actual source polling is self-throttled by Pune time.

## Page fallback

If the server heartbeat is more than 15 minutes old, missing, or the last server collector state is `error`:

1. A page request first tries to launch the Python collector in the background.
2. If PHP cannot use `exec`, `popen`, or `proc_open`, the same-origin browser recovery endpoint activates.
3. `WebCollector.php` processes one due configured feed per step.
4. Python and PHP both use `storage/system/refresh.lock`, so only one collector can write at a time.
5. Open dashboards re-check cached data every 60 seconds and can detect a newly stale server heartbeat.

## Source-date recovery

APMC may expose a date before publishing rows for that date. The collector now verifies direct dated pages per feed. If the newest page is empty, it checks recent earlier dates and retains the newest populated snapshot. Empty tables are `no_new_data`; they do not delete or invalidate last-good data.

## Safety

- No caller-supplied upstream URL.
- Upstream host is pinned to `https://apmcpune.in`.
- Only `/bajarbhav/...` paths are permitted.
- Atomic file replacement remains in use.
- Failed source checks never delete the last good snapshot.
- Admin state-changing operations remain POST-only.

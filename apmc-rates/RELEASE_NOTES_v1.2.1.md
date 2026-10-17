# APMC Pune Data Engine v1.2.1 — Startup Recovery Hotfix

## Fixes
- Fixes the permanent `Warming Up · 0 rates · 0/19 feeds` state on managed hosts where PHP cannot launch Python.
- Adds `WebCollector.php`: a fixed-source, incremental PHP collector that fetches one configured APMC feed per browser-driven POST step.
- Public bootstrap endpoint accepts no source URL or arbitrary market input; upstream is pinned to `https://apmcpune.in/bajarbhav/...`.
- First successful feed immediately rebuilds `storage/current/all.json`, so the dashboard becomes useful before all 19 feeds finish.
- Remaining feeds continue incrementally without blocking initial page rendering.
- Admin Check/Retry falls back to the PHP collector when `exec`, `popen`, and `proc_open` are unavailable.
- Adds asset version query strings (`?v=1.2.1`) to eliminate stale CSS/JS after an overlay deployment.
- Keeps EN/मराठी and light/dark functionality from v1.2.0.
- Does not require deleting existing `storage/`.

## Deployment
Overlay this release on v1.2.0. Keep `storage/` intact. Then hard-refresh once. The page should move from Warming Up to a partial live dataset after the first successful source feed and progressively fill toward 19/19.

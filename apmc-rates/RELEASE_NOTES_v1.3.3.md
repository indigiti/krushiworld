# v1.3.3

- Server cron is now the primary refresh authority.
- Added `bin/server-refresh.sh` for a Cloudways/server cron scheduled every 5 minutes.
- Server cron writes a heartbeat on every invocation and self-throttles source checks by Pune time.
- Page requests now act only as failover when the server heartbeat is stale or server collection is in an error state.
- Python and PHP fallback collectors now share `storage/system/refresh.lock`.
- Direct dated APMC pages are verified per feed; empty newest pages fall back to earlier populated dates.
- Empty source tables are represented as `no_new_data` while retaining last-good snapshots.
- Browser data polling now refreshes the open dashboard every 60 seconds.

# v1.3.1 — Canonical Market + Bilingual Name Intelligence

- Fixes repeated market entries in the market filter.
- Separates 5 physical markets from 19 internal source feeds.
- Adds canonical `market_id` and explicit `feed_slug`.
- Groups public market summaries by physical market.
- Adds `DataNormalizer` so old cached snapshots are cleaned at read time; no storage rebuild is required.
- Expands verified Marathi ↔ English commodity mapping and aliases, including the leafy-vegetable names visible in the live APMC source.
- Prevents known Marathi commodity names from leaking into English customer display/API output.
- Adds conservative alias/fuzzy recovery and safe transliteration fallback for unknown labels.
- Removes raw `source_name` from customer-facing latest/query payloads while retaining it in storage for audit.
- Adds `GET /api/markets` for canonical market discovery.
- Updates OpenAPI/agent documentation to distinguish `market_id` from feed slug.
- Preserves v1.3.0 security, SEO/AI readiness, Google Fonts, bilingual UI, Cloudways web collector and disabled-process-function recovery.

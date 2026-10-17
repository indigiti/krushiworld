# KrushiPulse — national AGMARKNET integration

## Why only a few Pune markets were listed
The old dropdown reflected only markets with price rows in the most recent Pune collector snapshot. The updated directory includes all five configured Pune market locations, even if some lack current rates. Once configured, it also includes distinct mandis discovered from India's official AGMARKNET data.

## Configure official all-India price imports
1. Register at https://data.gov.in and obtain an API key for the AGMARKNET current daily mandi price dataset.
2. Configure the server environment secret APMC_DATA_GOV_IN_API_KEY. Do not put the key in public PHP, browser JavaScript or Git.
3. Keep the existing server cron: */5 * * * * /absolute/private_html/krushiworld/bin/server-refresh.sh
4. Run a one-off initial ingestion if desired: python3 collector/national.py --force
5. Check /krushiworld/?api=markets (look for data.national.status = ready) and /krushiworld/?api=national&market=<market-id>. The homepage now has State, District and Mandi selectors.

The AGMARKNET source is https://api.data.gov.in/resource/9ef84268-d588-465a-a308-a864a43d0070 . Collection is performed only server-side. All paginated results are validated; partial results, API failures or empty feeds never overwrite last-good data. Refresh is throttled by default to once every six hours, while failed requests back off for one hour. Records include the actual reporting date and modal price per quintal, not a fictitious live quotation.

Directory coverage is **reported AGMARKNET mandis only**, not a certified list of every registered APMC market in India. Markets with no current record remain listed as no current report. State and district filters narrow the available market directory. National price records are fetched per selected mandi rather than loading all prices into the page.

Location-based ranking requires verified coordinates; the release only has approximate coordinates for five Pune-area markets. Do not claim national nearest-mandi coverage without a nationwide geocoded market directory. Users outside geo-covered areas can still use State > District > Mandi.

On Cloudways, preserve private_html/krushiworld/storage/ during deployment. A configured API key plus at least one successful national import is necessary before markets across India appear. Green CI alone does not guarantee live source connectivity.

API directory exposes national status: not_configured, awaiting_import, or ready. If it remains not_configured, provide the missing server secret. If awaiting_import, inspect the server command output, outbound HTTPS and API quotas.

Source attribution: AGMARKNET / Directorate of Marketing & Inspection, Ministry of Agriculture & Farmers Welfare, via data.gov.in.

## Phase 1: audited nationwide discovery (v1.11.0)

AGMARKNET provides reported commodity prices, not certified GPS positions of mandi gates or postal centroids. Nationwide location coverage cannot be responsibly generated from district names.

The public /api/v1/markets endpoint adds coverage metrics: total, with_coordinates, without_coordinates, with_postal_reference, with_published_rates, without_current_report, states and latest_report_date. The national source status also gives last_attempt_at, last_success_at, last imported timestamp and latest underlying report date. States include ready, stale, not_configured, awaiting_import and cached_unconfigured.

The farmer can discover a mandi by opting into device GPS (150-km approximate straight-line search among audited coordinates), searching a six-digit PIN where an actual source-backed mandi postal reference exists, searching mandi or district names, or selecting state and district. PIN searching **does not calculate distance** and does not imply a PIN centroid. Initially, only two postal references are verified: Pune Main Market Yard (411037) and Pimpri submarket (411017), both sourced to the official Pune APMC website. No made-up nationwide postal directory has been shipped.

### Expand audited market coordinates

First complete the official national AGMARKNET data import. Prepare an operator-reviewed CSV with these columns:

    market_id,latitude,longitude,precision,source_url,pincode

Use actual IDs returned from /api/v1/markets and real source-verified points, not guessed sample coordinates. Each entry requires an HTTPS source, numeric geographic coordinates, and the correct market ID. Precision is one of surveyed, locality, or approximate. The PIN column is optional and only for verified mandi addresses.

Validate, then import the reviewed file on the private server:

    python3 collector/import_market_geocodes.py --csv /safe/path/reviewed.csv --dry-run
    python3 collector/import_market_geocodes.py --csv /safe/path/reviewed.csv

The importer rejects unknown market IDs, duplicates, unreasonable coordinates, unsupported precision, malformed PINs and missing HTTPS provenance. It merges approved points atomically into storage/national/geocodes.json without removing earlier verified coordinates. Protect this file like other storage; do not deploy a guessed national geocoding dataset.

### Import integrity and operations

The AGMARKNET collector retains the last verified records if pagination is incomplete or the latest response has a suspicious 75%+ drop from an earlier dataset with 100+ rows. Import attempt/success status is stored under storage/national/health.json with sanitized failure types. It never logs exception strings containing secret-bearing API URLs. Records retain the actual report date and source; they are **not live market quotes**.

Production still requires APMC_DATA_GOV_IN_API_KEY in the Cloudways server environment, a successful initial national import and tested cron connectivity. CI does not confirm live API credentials, upstream connectivity or deployment.

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

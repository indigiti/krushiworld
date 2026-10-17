# KrushiPulse Phase 4 · v2.0.0

## Delivered in this release

The Phase 4 integration builds on the existing PHP/Python multi-layer stack and private SQLite price-history store:

1. **Optional Hindi, Marathi and English voice-assisted crop search.** A microphone button is available beside crop search. It starts the browser's `SpeechRecognition` / `webkitSpeechRecognition` only after a real click; sets `hi-IN`, `mr-IN`, or `en-IN`; reads the recognized crop name from the existing translation dictionary; and submits it to the existing text search field. Unsupported browsers show an explicit type-to-search fallback. The app does not send a microphone stream to KrushiWorld or retain audio. Some browsers may send audio to their **browser provider** and therefore voice recognition is not necessarily offline/private from that provider. The user must consent to microphone access.

2. **Explainable historical price observations** at `GET /api/v1/intelligence/insights?market=<id>&commodity=<crop>&variety=<v>&grade=<g>&unit=<u>&days=30`. Uses private history, computes the difference between the first/last actual published modal quotation on distinct dates, observed modal range, count of observed dates, and stale-data flag. The `observation_quality` field refers only to the **number of published dates**, not model confidence or predictive accuracy. It returns an explicit unavailable state when fewer than two source-report dates exist. No forecast, missing-day interpolation, best-mandi recommendation or trading signal.

3. **Official seasonal MSP reference** at `GET /api/v1/intelligence/msp?commodity=Wheat&season=2026-27`. Curated official Government of India **Kharif and Rabi 2026–27** rates from Press Information Bureau releases. MSP values are in **INR per quintal**, include the marketing season, crop category/grade and original announcement link, and are not a guaranteed transaction or an offer of procurement. The frontend compares an observed modal quote against MSP **only** if the reported rate unit is quintal/qtl and the explicit grade matches. Most fruits and vegetables (for example Onion) do not have an MSP entry, and are shown as not available. No invented MSP.

   Government provenance:
   - Kharif MSP 2026–27: https://www.pib.gov.in/PressReleasePage.aspx?PRID=2260617&lang=2&reg=3
   - Rabi MSP 2026–27: https://www.pib.gov.in/newsite/erelcontent.aspx?lang=2&reg=48&relid=276863

4. **Mandi facility and contact profiles** at `GET /api/v1/mandis/{market-id}/profile`. The data layer combines the known directory, original rate-report date, available curated contacts, specific departmental trading schedules, facilities and per-market source links. It includes genuinely documented facts for **Main Market Yard, Manjari, Moshi, Pimpri and Khadki**, drawing from the official Pune APMC site:
   - https://apmcpune.in/en/market-en/main-market-yard-en
   - https://apmcpune.in/en/market-en/sub-market-en/late-annasaheb-magar-manjari-sub-market-yard
   - https://apmcpune.in/en/market-en/sub-market-en/moshi-sub-market-yard-en
   - https://apmcpune.in/en/auction-timetable-en

   Published hours may differ between sections, weekdays and market holidays; farmers should verify before travelling. Facilities not individually source-verified remain `facility_status=not_verified`. The code does not claim unverified nationwide mandi opening hours or amenities.

## Compatibility and privacy

All existing Pune/National APIs, market and language URLs, saved crops, themes, PWA, price alerts and data collectors remain intact. Phase 4 uses **only GET read-only public endpoints** and never gives a browser permission to refresh a source database. The market and seasonal MSP tables are private configuration files bundled with DigiOps and served only through validated APIs.

The service-worker cache version is changed to `krushipulse-shell-v2.0.0` so the updated public assets replace old installs. Market-price, MSP and insight API requests are **not cached** by the service worker. The site continues to warn that offline pages are not evidence of current prices.

## Production activation

1. Deploy the CI-verified DigiOps artifact to `public_html/krushiworld` plus private code to `private_html/krushiworld`, preserving `private_html/krushiworld/storage/`.
2. Verify PHP 8.3 with **SQLite3** and **mbstring**, and confirm the history archive has at least two real report dates for the selected crop/market/quality/unit. Otherwise insights show an explicit insufficient-data state.
3. Confirm `/krushiworld/hi/`, `/krushiworld/mr/`, and `/krushiworld/en/` load the Phase 4 panels. Test mic support on a secure HTTPS browser and review the microphone access prompt; unsupported browsers should fall back to typing.
4. Test MSP Wheat 2026–27 (₹2,585 per quintal, officially published), Maize 2026–27 (₹2,410 per quintal), and Onion 2026–27 (no MSP catalog match). These are **policy references**, not live offers.
5. Test market profile for Moshi with source-backed farmer accommodation and for a national mandi without a vetted profile with clearly labeled unverified facilities.

CI validates PHP/JS syntax, multilingual copy, official MSP fixtures and season/grade constraints, exact historical SQLite observations, verified facility records, and a Cloudways-style HTTP deployment. CI green **does not prove** that the application has already been installed on Cloudways or that upstream national market data is currently available.

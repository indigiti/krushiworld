# KrushiPulse v1.6.0 — Personalized Farmer Dashboard

- New independent responsive frontend (`krushipulse.css` / `krushipulse.js`) with a warm, editorial market dashboard rather than the older BhavLive design.
- Brand: **KrushiPulse**, under **KrushiWorld**.
- Click the palette icon to cycle Harvest, Midnight and Saffron themes. Choice persists locally.
- Click the language control for Marathi / English, using existing server-side localization.
- Personalize button opens an accessible panel for choosing a default mandi and favorite crops; starred commodities and saved market persist across visits in the same browser, no account required.
- Dashboard shows saved crop rates from the existing read-only published-source API, with original report dates and units. No fabricated current rates, app-store links or notifications.
- On the first visit, no crops are preselected, and users are prompted to choose favorites.
- Guest preferences are saved in browser localStorage only: no cross-device synchronization or login provided.
- Collector, scheduler and JSON API are retained from earlier versions.

## Limitations and production gates
- Verify source connectivity, storage write permissions, scheduler and live rates after Cloudways deployment.
- Screen content should not be advertised as live exchange quotes; report dates remain explicit.
- For synced accounts or real alerts, a future authenticated preference API and notification consent workflow are required.

# APMC Pune v1.3.1 — SEO, AI Search & Agentic Readiness Guide

## Search architecture

The public dashboard renders crawlable English and Marathi variants:

- `?lang=en`
- `?lang=mr`

Each variant has a server-rendered title, meta description, canonical URL, `hreflang` links (`en-IN`, `mr-IN`, `x-default`), Open Graph locale metadata and visible explanatory content. The language selector is made of normal links, so crawlers do not need JavaScript to discover either language.

## Structured data

The page publishes JSON-LD containing:

- `WebSite`
- `WebPage`
- `Dataset`
- language information
- `dateModified` when a serving date exists
- source/provider attribution to Agricultural Produce Market Committee, Pune
- JSON distributions for the public read API

Structured data must reflect visible/current content. Do not generate fake ratings, reviews, FAQs, organizations, prices or freshness values solely to obtain rich results.

## Sitemap and robots

Generated endpoints:

- `/sitemap.xml`
- `/robots.txt`

The sitemap includes both language URLs and alternate-language relationships.

### Important subfolder deployment note

If the application lives at `https://lexicon.digiti.in/ver/apmc/`, its generated `/ver/apmc/robots.txt` can be useful for humans/tools, but standard crawler robots rules for the host are read from the **domain-root** `https://lexicon.digiti.in/robots.txt`. Merge the APMC directives and sitemap URL into the domain-root robots file or configure the server to route the root robots request appropriately.

## AI search / GEO / AEO posture

Build for the same fundamentals that make pages useful in conventional search:

- crawlable server-rendered text
- clear provenance
- fresh and accurate data
- strong page experience
- descriptive headings
- bilingual canonical/hreflang consistency
- accessible semantic markup
- structured data that matches visible content
- stable public URLs and machine-readable data

Do not create hidden AI-only blocks or keyword-stuffed passages. AI-search readiness should improve factual retrievability, not manufacture claims.

`llms.txt` is included as an auxiliary discovery document for systems that choose to read it. Do not treat it as a Google ranking mechanism.

## Public machine-readable surfaces

- `/api/latest` — complete latest cached snapshot
- `/api/query?q=tomato&lang=en&limit=20` — constrained bilingual search
- `/api/summary?lang=en` — compact status/pulse
- `/api/market?slug=<slug>` — one market feed
- `/api/discover` — discovery metadata
- `/openapi.json` — OpenAPI 3.1 definition
- `/.well-known/apmc-data-agent.json` — project-specific read-agent manifest
- `/llms.txt` — optional textual discovery guide

All declared agent tools are read-only.

## Agentic conversation design

A future conversational assistant should use the public API as its tool layer instead of scraping the HTML dashboard. Recommended tool routing:

1. “What is today’s tomato rate?” → `queryMarketRates(q="tomato")`.
2. “Show Manjari onion rates” → `queryMarketRates(q="onion", market="...")` after resolving the market slug/name.
3. “Is the market feed current?” → `getMarketSummary()` / public health.
4. “Give me all rows from one market” → `getMarketBySlug()`.

The assistant should always preserve `as_of`, market, unit and source context in answers. It should not infer a live rate if the dataset is warming/stale.

### Privilege boundary

Never expose these as conversational tools:

- admin login/token
- refresh/rebuild/backfill
- collector process spawning
- server health internals
- errors/log files
- filesystem paths
- source override URLs

This keeps prompt injection or agent mistakes from becoming operational actions.

## A2A / agent protocol status

v1.3.1 is **agent-ready**, not a full A2A protocol server. It intentionally does not publish `/.well-known/agent-card.json` because doing so would imply protocol operations/transports that are not implemented. Add an A2A card only when the complete A2A service contract is implemented and tested.

## Recommended next SEO expansion

The next search-growth slice should create stable, indexable landing pages for important entities rather than infinite query combinations:

- `/commodity/tomato/`
- `/commodity/onion/`
- `/market/manjari/`
- `/market/moshi/`
- selected commodity × market pages where enough unique data/context exists

Each page should contain current rates, historic context, source/freshness information, bilingual alternate URL and unique explanatory copy. Avoid automatically indexing thin combinations with no additional value.

## Search Console / validation checklist

After production deployment:

1. Submit the production sitemap in Google Search Console.
2. Inspect both English and Marathi canonical URLs.
3. Validate rendered HTML, canonical and hreflang.
4. Test Dataset structured data against current Google/schema validation tooling.
5. Monitor indexing, crawl errors and duplicate canonical selection.
6. Confirm admin URLs remain excluded/noindex.
7. Compare server logs with feed freshness so stale pages are not mistaken for current market data.


## Canonical entity rule (v1.3.1)
Agents should treat `market_id` as the physical market identity and `feed_slug` as internal/source provenance. Use `/api/markets` to discover canonical markets. Commodity answers should prefer `name_en`/`name_mr`; `name_resolution.confidence` can be used to avoid overclaiming on a low-confidence fallback.

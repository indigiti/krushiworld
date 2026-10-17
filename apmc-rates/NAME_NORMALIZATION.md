# APMC Pune Data Engine v1.3.1 — Name Intelligence & Canonical Data

## Goal
Raw APMC source labels are Marathi-first and the 19 configured collector feeds are not 19 physical markets. v1.3.1 separates source/feed identity from customer-facing market and commodity identity.

## Canonical market model
The collector keeps 19 `feed_slug` values for acquisition and health checks, but customer data uses 5 `market_id` values:

| market_id | English | Marathi | Source feeds |
|---|---|---|---:|
| `main-market-yard` | Main Market Yard | मुख्य बाजार आवार | 9 |
| `manjari` | Manjari | मांजरी | 4 |
| `moshi` | Moshi | मोशी | 3 |
| `pimpri` | Pimpri | पिंपरी | 2 |
| `khadki` | Khadki | खडकी | 1 |

`market_slug` remains in raw storage for backward compatibility and identifies the source feed. New records also include `feed_slug` and `market_id` explicitly.

## Commodity name resolution
Customer reads pass through `DataNormalizer` in this order:

1. Verified exact Marathi/English dictionary match — confidence 1.00.
2. Verified alias match — confidence 0.99.
3. Conservative Latin typo/fuzzy match — only at >=0.88 similarity.
4. Safe transliteration fallback for an unknown Devanagari label — confidence 0.45.

No remote LLM is called while rendering a page or answering a public API request. This prevents hallucinated commodity translations and avoids transmitting rate data to third parties.

Raw `source_name` is retained in storage for audit/reprocessing but removed from customer-facing `/api/latest` output. Public records expose canonical `name_en`, `name_mr`, `market_id`, `feed_slug`, market names, category and rate fields.

## English-mode guarantee
When `lang=en`, a known Marathi source label must resolve to an English semantic label. Examples:

- कोथिंबीर → Coriander Leaves
- मेथी → Fenugreek Leaves
- शेपू → Dill Leaves
- पालक → Spinach
- कांदापात → Spring Onion
- पुदिना → Mint Leaves
- राजगिरा → Rajgira Greens
- नारळ → Coconut
- फ्लॉवर → Cauliflower
- दुधी भोपळा → Bottle Gourd
- भुईमूग शेंग → Groundnut Pods

The dictionary also covers common vegetables, fruit, grains, flowers, banana/betel-leaf items and spelling variants. Unknown source labels never intentionally replace a verified English label.

## Public market API
`GET /api/markets` returns the deduplicated canonical market list. `GET /api/query?market=main-market-yard` filters across all underlying Main Market Yard feeds.

## Agent/SEO behavior
AI agents should query canonical `market_id` and bilingual commodity names. `feed_slug` is provenance/collector context, not the customer market identity. This separation avoids duplicate entities in search, structured data, and conversational answers.

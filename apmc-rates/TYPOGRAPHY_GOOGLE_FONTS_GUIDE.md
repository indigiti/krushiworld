# APMC Pune v1.3.0 — Google Fonts Typography Guide

## Selected families

### 1. Noto Sans — English / Latin UI
Use for English headings, body copy, labels, filters, tables, numbers and system messages.

- 400 — body copy, descriptions, help text
- 500 — inputs, filters, compact controls
- 600 — table headings, chips, status labels
- 700 — section headings, navigation emphasis
- 800 — hero headings and major KPI values
- 900 — rare high-emphasis KPI/status display text (`font-black`)

Recommended line-height: 1.45–1.55 for body copy and 1.10–1.25 for display headings.

### 2. Noto Sans Devanagari — Marathi UI
Use for all Marathi/Devanagari text. It is paired with Noto Sans because their visual proportions and weight system are compatible.

- 400 — Marathi body copy
- 500 — controls and filters
- 600 — table headings and labels
- 700 — headings
- 800 — hero headings / major KPI values
- 900 — rare high-emphasis KPI/status display text

Recommended line-height: at least 1.50 for normal Marathi copy. The application uses approximately 1.55 in Marathi mode so matras and conjunct forms do not feel cramped.

Do not apply artificial uppercase transformations to Marathi. Avoid wide letter-spacing on Devanagari labels/headings; use normal tracking unless a specific visual test proves otherwise.

## Runtime Google Fonts request

The application deliberately does **not** ship local WOFF/WOFF2/TTF/OTF font files. Fonts are requested directly from Google Fonts:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link
  href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400..900&family=Noto+Sans+Devanagari:wght@400..900&display=swap"
  rel="stylesheet"
  referrerpolicy="no-referrer">
```

The variable 400–900 range avoids requesting a separate family declaration for every individual weight. `display=swap` keeps text visible while web fonts load.

## CSS stacks

English/default:

```css
font-family: "Noto Sans", "Noto Sans Devanagari", system-ui, -apple-system,
  BlinkMacSystemFont, "Segoe UI", sans-serif;
```

Marathi:

```css
html[data-lang="mr"] body {
  font-family: "Noto Sans Devanagari", "Noto Sans", "Nirmala UI", "Mangal", sans-serif;
  line-height: 1.55;
}
```

System fallbacks ensure the interface remains readable if Google Fonts is temporarily unreachable.

## Security / privacy controls for external fonts

Only these font origins are allowed by the application Content Security Policy:

- `https://fonts.googleapis.com` — stylesheet
- `https://fonts.gstatic.com` — font files

The stylesheet uses `referrerpolicy="no-referrer"`. No other font CDN is allowed. There are no bundled font binaries in this package.

External Google Fonts remain a third-party network dependency, so client requests can reach Google infrastructure. This is an explicit product choice for this release; it should not be described as inherently more private than self-hosting.

## Visual QA checklist

Validate each release in both English and Marathi at 320px, 375px, 768px, 1024px and desktop widths. Check: heading wrapping, Marathi matras, table row height, KPI number alignment, button height, search placeholder clipping, dark-mode contrast, browser zoom at 200%, and fallback-font behavior with Google Fonts blocked.

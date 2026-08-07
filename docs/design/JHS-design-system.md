# JHS Load & Scheduling System — Design System

Date: 2026-08-07. Visual identity for the JHS Teaching Load & Scheduling System, derived from Ateneo de Zamboanga University's "Anuncio Atenista" brand materials. Companion to `docs/design/style-guide.html` (the living, rendered reference). Feeds the Blade + Tailwind implementation in `docs/superpowers/plans/2026-08-07-admin-chair-milestone.md`.

> The ADZU seal is a brand reference only. **Do not embed the official logo.** The app expresses ADZU through color, type, and the frame motif — not the crest.

## Direction — "Institutional Atenista"

The source posters are loud, maximalist, saturated. This app is a data-dense administrative tool (dashboards, teacher-load tables, plantilla review grids). The direction resolves that tension: **the brand's cobalt-and-canary energy lives in the chrome — sidebar, page titles, primary actions, status — while the data canvas stays calm, white, and legible.** Blue is identity; white is workspace; canary is the rare, deliberate highlight. Loud where it announces, quiet where you work.

## Color

Brand-anchored, role-mapped. Every value carries a job — do not use a color outside its role.

| Token | Hex | Role |
|---|---|---|
| `cobalt` | `#1E22C4` | Primary brand blue — primary buttons, key chrome, active fills |
| `electric` | `#3B5BFF` | Links, focus rings, bright accents, info |
| `navy` | `#0B0B45` | Sidebar & darkest chrome; headings on light |
| `navy-800` | `#12124F` | Elevated navy surfaces (sidebar hover, footer) |
| `canary` | `#FFD60A` | **Signature accent** — active-nav indicator, one hero CTA, "attention" |
| `canary-ink` | `#5A4B00` | Text/icon on canary fills (never canary as text on white) |
| `parchment` | `#F6EFD8` | Warm soft surface, used sparingly (empty states, identity panels) |
| `ink` | `#14152E` | Primary text |
| `slate` | `#565A78` | Secondary text, captions, table meta |
| `mist` | `#EEF1F8` | App canvas background (cool near-white) |
| `line` | `#DCE0EC` | Hairline borders, dividers |
| `white` | `#FFFFFF` | Cards, inputs, data surfaces |
| `jade` | `#1E9E6A` | Success (submitted, valid) |
| `amber` | `#E8A400` | Warning (needs review) — distinct from canary |
| `rose` | `#D64550` | Danger (deactivate, delete, overload) |

**Contrast rules.** White text on `cobalt`/`navy` only. `ink` text on `canary`/`parchment`/light. **Canary is never text on white** — it is a fill, indicator, or underline only. All body text meets WCAG AA (≥4.5:1).

## Typography

Four roles. Production loads these from Google Fonts (the Blade layout adds the `<link>`); the style guide falls back gracefully where a face is absent.

| Role | Face | Usage |
|---|---|---|
| **Display** | `Archivo Black` | Page titles (UPPERCASE), app wordmark, big stat numbers. The poster-headline voice — used with restraint. |
| **Heading / UI** | `Montserrat` (600/700) | Card titles, buttons, nav items, section labels |
| **Body** | `Montserrat` (400/500) | Paragraphs, form labels, helper text |
| **Data** | `Inter` (tabular-nums) | Table cells, hours, counts, IDs, dates — anything columnar |
| **Script** | `Great Vibes` | **One place only:** the login welcome. The institutional-script moment from the posters. Never in the working UI. |

**Type scale.** Page title 28px / Archivo Black / uppercase / tracking −0.01em. Stat number 40px / Archivo Black. Card title 18px / Montserrat 700. Body 15px / Montserrat 400 / line-height 1.55. Data 14px / Inter / tabular-nums. Eyebrow label 12px / Montserrat 700 / UPPERCASE / tracking 0.12em / slate or electric.

## Signature devices

1. **The Anuncio frame.** A thin rounded rectangle with an inset second stroke — the posters' most-repeated motif. Used on the login card and dashboard identity panels only, never around every card (that would cheapen it). Outer stroke `line`/white, inset ring at ~6px offset.
2. **Page-title rule.** Uppercase Archivo Black title above a short, thick **canary underline tick** (~48px × 4px). This is the "ANUNCIO" eyebrow energy compressed into a title system — every page wears it.
3. **Navy sidebar, canary active mark.** Full-bleed `navy` sidebar (the one place saturated brand blue owns the surface). The active nav item gets a canary left-indicator bar + lighter fill — the only canary in the chrome at rest.
4. **Poster-number stat tiles.** Big Archivo Black numerals on white, blue label above — the posters' big-number drama, restrained to blue-on-white.

## Core components

- **Buttons.** Primary = `cobalt` fill, white text, radius 10px. Hero (one per page max) = `canary` fill, `ink` text. Secondary = white fill, `line` border, `ink` text. Ghost = transparent, `slate` text. Danger = `rose` fill. All: Montserrat 600, focus ring = 2px `electric` + 2px offset.
- **Cards.** White, radius 16px, `line` hairline border, soft low shadow `0 1px 2px rgba(11,11,69,.06), 0 10px 30px rgba(11,11,69,.05)`. Card title (Montserrat 700) + optional eyebrow.
- **Inputs.** White, radius 10px, `line` border; focus → `electric` border + ring. Label above (Montserrat 600, 13px). Error → `rose` border + `rose` helper text. Select mirrors input.
- **Status pills** (submission state). Draft = slate on `mist`; Submitted = white on `jade`; Returned = `ink` on `canary`; Locked = white on `navy`. Radius 999px, 12px Montserrat 700 uppercase, 0.06em tracking.
- **Flag badges** (data quality). Small `amber`/`rose`-tinted chips: "No sections", "Hour mismatch", "Overloaded", "No service load". Icon + label, humanized (never raw enum keys in the UI).
- **Data table.** White card wrapper; header row `mist` bg, Montserrat 700 12px uppercase slate; body Inter tabular-nums; row hover `mist`; right-aligned numeric columns; zebra optional off (hairlines preferred).

## Layout

- **App shell:** fixed `navy` sidebar (wordmark top, role-scoped nav, user chip bottom) + `mist` content area. Content max-width ~1200px, 32px gutters.
- **Page header:** title rule (Archivo Black + canary tick) left, primary actions right.
- **Density:** generous — 24px card padding, 16px control gaps. This is a tool people read carefully, not a landing page.

## Quality floor

Responsive to mobile (sidebar collapses to a top bar + drawer). Visible keyboard focus everywhere (`electric` ring). `prefers-reduced-motion` respected — transitions ≤150ms, no essential motion. Light-first; a dark mode is out of scope for this milestone.

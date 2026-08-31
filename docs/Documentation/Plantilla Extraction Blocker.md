4# Plantilla Extraction Blocker — Grade Attribution & Format Variance

Status: **Open blocker**, documented 2026-08-08. Deferred for a later work-around.
Related: [[JHS System SRS]] §6.2, [[JHS Sections Directory]], [[JHS Department Directory]].
Code: `app/Services/Plantilla/PdfExtractionService.php`, `app/Services/Plantilla/PlantillaReviewService.php`.

---

## 1. Summary

The Review & Correct screen extracts only **Teacher name** and **Employment status** from an
uploaded plantilla PDF. Sections, Class Moderator, Honor's Class, Service Load, and Other
Assignment come through blank, even though all of that data is printed in the source PDF and the
downstream import pipeline is already built to consume it.

The extractor is the only broken link. Two distinct problems are tangled together here:

1. **A false limitation (the ~80%).** Section names, moderator, service load, and other-assignment
   text all survive PDF text extraction and are simply never read. This is fixable.
2. **A real limitation (the ~20%).** *Which grade column* (G7/G8/G9/G10) a teaching-section block
   sits under is lost when the PDF table is flattened to text, and section names repeat so
   pervasively across grades that a name→grade lookup cannot recover it. This is the actual blocker.

---

## 2. Where the data is discarded

`PdfExtractionService::finalize()` parses the name and `(status)`, then hardcodes every other field
to `null`:

```php
// app/Services/Plantilla/PdfExtractionService.php  (finalize)
'sections' => null,   // grade/section columns don't survive flat extraction
'cm' => null,
'hc' => null,
'service_load' => null,
'other_assignment' => null,
```

That inline comment is **mostly false** — see §4. The names, moderator, service load, and other
assignment all survive; only the teaching-section *grade prefix* is genuinely lost.

The rest of the pipeline already expects these fields:

- `UpdateExtractionRowRequest` validates and accepts all seven fields.
- `PlantillaReviewService::confirmImport()` reads `sections`, `cm`, `hc`, `service_load`,
  `other_assignment`, parses `"G7: Ignatius, Xavier; G9: Kostka"` strings via `parseSections()`,
  and writes them into the authoritative tables.

So improving the extractor requires **no downstream schema changes** — it only needs to populate the
staging fields in the format `parseSections()` already understands.

---

## 3. Proof that the raw text carries the data

Raw text as the parser (`Smalot\PdfParser`) actually sees it, Filipino sheet:

```
1.​Leah Angelic C. Bilbar
 (Permanent)
 1 (Ignatius)          <- teaching section
 4 3  Department Chair  <- teaching-hours, service-load=3, other assignment
15 18 22 0.33

3.​Cristie R. Delos Reyes
 (Permanent)
 4 (Arrowsmith, Jogues, Campion, Rubio)   <- four sections, names intact
 G7 Class Moderator (Rubio)                <- moderator, grade STATED
 20 3   3 23 0.67                          <- service load = 3
```

Section names, moderator (with its grade), service load, and other assignment are all present.

---

## 4. Format variance across the 7 sheets

The extractor is currently tuned to one department's quirks. The seven sheets fall into ~3 families
and no single regex fits all of them.

| Sheet | Section-list style | Grade recoverable from text? | Honor's col | Status format |
|---|---|---|---|---|
| **Filipino** | count + parens: `1 (Ignatius)` | ❌ column only | no | `(Permanent)` |
| **CLE** | count + newline names: `5 ⏎ Berchmans ⏎ …` | ❌ column only | no | `(Retiree)`, `(FT Permanent)` |
| **Math** | count + newline names | ❌ column only | yes (header) | `(FT Probationary 1)` |
| **Science** | `3 sections ⏎ Arrowsmith ⏎ …` | ❌ column / ✅ honors `G8 Magis` | yes | `(FT Permanent)` |
| **MAPEH** | newline names, no count: `Borgia ⏎ Briant ⏎ …` | ❌ column only | yes `(Magis)` | `(Permanent Teacher)` + `(MAPEH)` line |
| **Social Studies** | count + names, sometimes **no status line** | ❌ column only | no | often **absent** |
| **TLE** | **grade-prefixed**: `5 (10Colombiere, 10Faber…)` | ✅ **from text** | no | `(Permanent Teacher)`, `(New Teacher)` |

### Structural findings

1. **The section-count marker varies five ways:** `1 (Ignatius)`, `5` alone, `1 section`,
   `3 sections`, `1 moderating class`, or *no count at all* (MAPEH just lists names). The current
   regex handles none of these.
2. **Grade attribution is recoverable in more cases than the code assumes:**
   - **TLE** glues the grade into the name — `10Colombiere` = G10 Colombiere, `9Rodriguez` = G9
     Rodriguez. Fully parseable from flat text.
   - **Science / MAPEH honors** state grade explicitly — `G8 Magis`, `G10 Magis`.
   - **Filipino / CLE / Math / Science / Social Studies teaching sections** rely purely on column
     position — this is the real blank spot.
3. **Class Moderator has its own zoo:** `G7 Class Moderator (Rubio)` (Filipino — grade stated),
   `Class Moderator Ogilvie` (MAPEH), `1 moderating class` (Math — count only, no section name),
   or a bare section name in the CM column.
4. **Status format varies and is sometimes missing.** `(Permanent)`, `(FT Permanent)`,
   `(Permanent Teacher)`, `(Retiree)`, `(New Teacher)`, `(FT Probationary 1)` — and some Social
   Studies rows (e.g. Omega, Lomocso) have **no parenthetical at all**, breaking the
   "name = text before first `(`" logic.
5. **Other Assignment is free text** interleaved with the trailing number cluster:
   `Department Chair`, `FDP`, `Sports Club`, `SAO Coordinator`, `Grade Level Leader`, `None`,
   `Eagle's Eye Club (Honoraria)`, `Faculty Dev. (Thesis Writing)`. Separating it from the
   `teaching-hrs / service-load / equiv / total / overload` numbers is the messiest parse.

---

## 5. The core blocker — grade attribution

Section identity is `(grade, name)`, never name alone. Grade has exactly three reliable signals, in
order of preference:

1. **Grade stated/embedded in the text** — TLE `10Colombiere`, Science `G8 Magis`, Filipino
   `G7 Class Moderator`. Parse directly; free and unambiguous.
2. **Column geometry** — a coordinate-aware PDF reader recovers which of the four grade columns a
   block sat under. The only reliable signal for the five sheets that carry grade purely by column
   position.
3. **Chair confirmation** — when neither is available, flag the row and let the Chair set the grade.

A **name → grade lookup cannot serve as the backbone** — see §6.

---

## 6. Repeating sections across grade levels

Built from [[JHS Sections Directory]] per-grade tables, plus the explicit `G# Magis` honors tags in
the Science sheet. **37 of 43 distinct section names (86%) repeat across grades**; 9 span three
grades. Only 3 are genuinely unique to one grade.

### 6.1 Sections appearing in multiple grades (37)

| Section | Grades |
|---|---|
| Anchieta | G8, G9 |
| Arrowsmith | G7, G8 |
| Bellarmine | G7, G8 |
| **Berchmans** | **G8, G9, G10** |
| Borgia | G7, G8 |
| Brebeuf | G8, G9 |
| Briant | G7, G8 |
| **Campion** | **G7, G8, G9** |
| **Canisius** | **G8, G9, G10** |
| **Chabanel** | **G8, G9, G10** |
| Claver | G7, G8 |
| **Colombiere** | **G8, G9, G10** |
| Daniel | G9, G10 |
| De Britto | G7, G8 |
| Evans | G8, G9 |
| **Faber** | **G8, G9, G10** |
| Garnet | G8, G9 |
| Goupil | G8, G9 |
| **Hurtado** | **G8, G9, G10** |
| Ignatius | G7, G8 |
| Jerome | G8, G9 |
| **Jogues** | **G7, G8, G10** |
| Kostka | G8, G9 |
| Lewis | G7, G8 |
| **Magis** (honors) | **G8, G9, G10** |
| **Mayer** | **G8, G9, G10** |
| Miki | G7, G8 |
| Morse | G8, G9 |
| Ogilvie | G7, G8 |
| Owen | G8, G9 |
| Pignatelli | G7, G8 |
| Pongracz | G7, G8 |
| Realino | G7, G8 |
| Regis | G7, G8 |
| Rodriguez | G8, G9 |
| **Southwell** | **G8, G9, G10** |
| Xavier | G7, G8 |

**Bold = spans three grades (9 names).** All others span two.

### 6.2 Sections unique to a single grade — only 3 genuine

The directory yields six "unique" names, but three are spelling-variant artifacts of names that DO
repeat, so they must not be treated as unique:

| Reported | Grade | Verdict |
|---|---|---|
| Paul | G7 | ✅ genuinely unique |
| Rubio | G7 | ✅ genuinely unique |
| Loyola | G8 | ✅ genuinely unique |
| De Brito | G7 | ✗ variant of **De Britto** (G7/G8) |
| Anchietta | G8 | ✗ variant of **Anchieta** (G8/G9) |
| Colombierre | G9 | ✗ variant of **Colombiere** (G8/G9/G10) |

Only **Paul, Rubio, Loyola** are safe for a name→grade lookup — 3 sections school-wide.

### 6.3 Why name-lookup fails as a strategy

- 86% of names collide across grades; a name like *Colombiere* maps to G8, G9, **and** G10.
- Inconsistent spellings (`De Britto`/`De Brito`, `Anchieta`/`Anchietta`, `Colombiere`/`Colombierre`)
  make even exact-match lookup fragile for the same physical section.
- Net: name-lookup can only confidently resolve 3 sections, so it can at best be a narrow helper
  ("auto-fill only when the name is school-wide unique, else flag"), never the backbone.

### 6.4 Confidence note

This map is derived from the Sections Directory, which **self-flags CLE and MAPEH grade attribution
as unreliable**. A handful of two-grade entries could shift. But any correction can only *add*
collisions, never remove them — so the "names repeat pervasively" conclusion is robust regardless.

The strongest collision proof is independent of the directory: the Science sheet's own honor's-class
column explicitly tags **Magis** as G8, G9, and G10 — one name, three grades, stated in the source.

---

## 7. Candidate directions (for the later work-around)

- **A — Flat-text + per-family rules.** Tolerant regex per format family; recover TLE embedded
  grades and stated honor's grades for free; leave column-only grades for the Chair. Lowest effort,
  stays with the current parser. Fastest path to "Chair isn't retyping everything."
- **B — Coordinate-aware parser.** Switch to a positional PDF reader so grade *columns* are recovered
  for all seven sheets. Highest fidelity, bigger rewrite. Column geometry is the one signal that
  unifies grade attribution across every sheet.
- **C — Hybrid.** Coordinate-aware for grade columns + text rules for the messy
  Other/Service/Moderator cells.

Leaning **A** for a first pass, **B/C** as a follow-up if column-grade accuracy proves worth it.

Whatever the approach, the return shape and downstream import do not change — the extractor only has
to populate the existing staging fields (`sections` as `"G7: Ignatius; G9: Kostka"`, etc.), flagging
rows whose grade could not be resolved.

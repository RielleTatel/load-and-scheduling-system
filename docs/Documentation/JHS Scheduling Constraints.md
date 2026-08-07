# JHS Teaching Load & Scheduling — Constraints and Construction Logic

Scope: **Junior High School (JHS) only**, per project prioritization decision (2026-08-04). Grade School is out of scope for now, despite being named in the original system overview.

Source data: the seven department "Plantilla" sheets in `Plantillas/` — `KAGAWARAN PLANTILLA .docx.pdf` (Filipino), `CLE PLANTILLA.docx.pdf`, `TLE PLATILLA.docx.pdf`, `SCIENCE PLATILLA.pdf`, `MATHEMATICS PLANTILLA.pdf`, `MAPEH PLANTILLA.docx.pdf`, `SOCIAL STUDIES PLATILLA.docx.pdf` — all for SY 2026-2027. (Updated 2026-08-04 to add Social Studies.)

---

## 1. Core Entities

Based on recurring structure across every department sheet, the data model needs at least:

- **Teacher** — name, employment status, department affiliation (1 department per teacher; no teacher appears in more than one department's sheet).
- **Department** (= subject area) — Filipino, CLE, TLE, Science, MAPEH, Mathematics, Social Studies observed (7 total). Possibly still incomplete (see §6).
- **Grade Level** — Grade 7–10 only (JHS band).
- **Section** — named after saints/Jesuit figures (Ignatius, Xavier, Borgia, Rubio, Rodriguez, Arrowsmith, etc.), scoped to a grade level. **Sections are shared across departments** — the same section name (e.g. "Xavier", Grade 10) recurs in MAPEH, CLE, and TLE sheets referring to the same physical class. Sections must be a single canonical table (`grade`, `name`), not free text per department.
- **Assignment types** (see §3):
  - Subject-teaching assignment (Teacher × Subject × Section)
  - Class Moderator (homeroom adviser) assignment (Teacher × Section)
  - Other Assignment/Role (Department Chair, Coordinator, Club Moderator, etc.)

---

## 2. Hard Constraints

| Constraint                                                | Value                                                       | Notes                                                                                                                   |
| --------------------------------------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Full teaching load                                        | **21 hours/week**                                           | Constant across all departments. Basis for the "Overload in units" calculation on every sheet.                          |
| Hours per section/week — Filipino, CLE, TLE, MAPEH        | **4 hrs**                                                   |                                                                                                                         |
| Hours per section/week — Science, Mathematics             | **5 hrs**                                                   | Subject-dependent, not a global constant — the scheduling engine must look this up per department.                      |
| Class Moderator hours                                     | **3 hrs** (4 hrs in TLE's Honor's Class rows — see anomaly) | Counted toward Total Teaching Hours in most sheets.                                                                     |
| Honor's Class                                             | **8 hrs**                                                   | Only appears as a column in TLE, Science, Mathematics, MAPEH sheets — not Filipino or CLE.                              |
| Service Load (remediation/attendance at school functions) | **3 hrs**, near-universal flat value                        | Appears on almost every teacher row regardless of department; behaves like a fixed add-on rather than a computed value. |

### Assignment exclusivity rules (inferred)
- One subject teacher per (Section × Department) — a section has exactly one Filipino teacher, one Science teacher, etc., simultaneously.
- One Class Moderator per Section, drawn from **any** department — moderator role is cross-departmental, not tied to the moderator's own subject.
- A teacher's subject-teaching load is confined to their own department; specialization = department affiliation (matches the original system overview's "teaching specialization" language).

### Non-teaching / equivalent-hours roles
"Other Assignment/s" is a lookup of role → equivalent hours, **not a formula** — same role names recur with the same hour values across sheets:

| Role | Equivalent Hours observed |
|---|---|
| Department Chair / Chairperson | 15 |
| Various Coordinator roles (Facilities, OPD, Admission & Aid, TLE Coordinator) | 15–21 |
| Grade Level Leader | 6 |
| AMEP (Advisory/Mentoring?) | 6 |
| Faculty Development | 15 |
| Quality Assurance Officer | 6 |
| HSR Coordinator | 21 |

**Important exclusion rule:** roles explicitly marked "(Honoraria)" or "(Honorarium only)" — club moderators (Sports Club, Culinaria, Eagle's Eye, Animo Aguila, etc.), research advisers — have a **blank Equivalent Hours cell**. They are compensated separately and do **not** count toward non-teaching hours or the overload calculation. This distinction (equivalent-hours-bearing role vs. honorarium-only role) must be an explicit flag on the Role entity, not inferred from hour value alone.

### Employment status (needs canonicalization)
Observed labels, inconsistent by department but referring to the same underlying categories:
`Permanent` / `FT Permanent` / `Permanent Teacher`, `Probationary 1/2/3` / `FT Probationary N`, `New Teacher`, `Substitute Teacher`, `Retiree`.
Recommend collapsing to a fixed enum: `Permanent`, `Probationary (1–3)`, `Substitute`, `Retiree`, with "New Teacher" mapped to `Probationary 1` unless stakeholders say otherwise.

---

## 3. How a Total Load Is Constructed (per teacher row)

```
Total Teaching Hours = Σ(sections taught × hrs/section for that department)
                      + Class Moderator hours (if assigned)
                      + Honor's Class hours (if assigned)

Total Non-teaching Hours = Service Load
                          + Equivalent Hours (from non-honorarium Other Assignments)

Total Number of Hours = Total Teaching Hours + Total Non-teaching Hours

Overload (units) = (Total Number of Hours − 21) / 3   ← inferred, see §5
```

The `/3` divisor is inferred from observed pairs (e.g. 23 total hours → 0.67 overload; 24 → 1.0; 26 → 1.67), not stated explicitly anywhere in the source documents. **This should be confirmed with the department chairs/registrar before being hard-coded.**

---

## 4. Scheduling Construction Order (implied workflow)

The plantilla layout suggests load-building happens in this sequence, which the system's assignment logic should mirror:

1. Assign subject sections to a teacher within their department (bounded by department's hours/section rate).
2. Assign at most one Class Moderator role per teacher, and ensure every Section has exactly one moderator across the whole school (cross-department constraint — requires querying outside the teacher's own department).
3. Optionally assign Honor's Class hours (only in TLE, Science, Math, MAPEH).
4. Add fixed Service Load (near-universal 3 hrs).
5. Add Equivalent Hours for any formal (non-honorarium) Other Assignment role, from the role lookup table.
6. Compute totals and overload; flag anything below/above the 21h full-load baseline for admin review.
7. Honorarium-only extracurricular roles are recorded for compensation purposes but excluded from steps 3–6's load math.

Per the system overview, this generated draft is not final — **administrators review, modify, and approve** before implementation, so the system needs an editable draft state, not just a compute-and-lock pipeline.

---

## 5. Open Questions / Data Quality Issues to Resolve with Stakeholders

- **CLE, Rica Amor (#3):** listed sections imply 8 teaching hours (2 × 4h), but sheet states 12. Unreconciled — confirm actual section count.
- **Mathematics sheet:** nearly every row's stated Total Teaching Hours is ~1 higher than `(sections × 5h) + moderator hours` would produce. Looks systematic rather than a typo — need the actual formula from the Math coordinator before encoding load rules for this department.
- **Science, Gino Calumpang (#11):** zero teaching sections, only a moderator role + HSR Coordinator (21 equivalent hrs). Valid edge case — system must support "all non-teaching load" teacher profiles without assuming every teacher has ≥1 section.
- **Overload formula's divisor (÷3)** is inferred, not documented — confirm with registrar.
- **TLE's Class Moderator is listed as 3 hrs in the header but 4 hrs in at least one used cell** — inconsistent within the same sheet; confirm the true value.

---

## 6. Scope Gaps

- All seven populated source files are **JHS only** — consistent with the current prioritization decision, so no action needed unless scope changes back.
- Social Studies (Araling Panlipunan) plantilla has been added (2026-08-04) and is now fully incorporated into the department count, entity model, and load rules above.
- No sheet exists yet for **English**, **Values Education** (if distinct from CLE at ADZU), or **Computer/ICT** — if these are graduation requirements at the JHS level, their plantillas are still needed before the system can cover the full JHS curriculum.

### Social Studies-specific notes
- Hours/section is **4 hrs** like Filipino/CLE/TLE, but several teachers carry sections in two different grade levels simultaneously (e.g. Sheila Mae Alas-as: G7 ×4 + G9 ×1), and the sheet's Total Teaching Hours for double-grade rows (20h) is consistent with `(sections × 4h)`, not 5h — confirms Social Studies sits in the 4-hr group, not the 5-hr (Science/Math) group.
- Social Studies has a **Class Moderator (3 hrs)** and **Honor's Class (8 hrs)** column like the other sheets, but no row in this sheet actually uses the Honor's Class column — can't yet confirm the department participates in Honor's Class assignments at all, or if it's simply unused this year.
- Row 4 (Janelle J. Cañete-Asjali) has a **dash (`-`) in the Service Load cell** instead of the near-universal 3 hrs — second observed exception to the "near-universal" Service Load rule, alongside Rodelyn Omega (#1) who also shows `-`. Both also carry Equivalent Hours (21) from Coordinator-tier roles, suggesting Service Load may be waived for teachers with heavy non-teaching assignments — worth confirming with the registrar rather than assuming Service Load is always additive.
- Total department teacher count is now **7 departments / 65 teacher rows** across all sheets (Filipino 10, CLE 9, TLE 9, Science 13, Mathematics 14, MAPEH 9, Social Studies 11).

---

## 7. Period-Level / Cycle Scheduling Constraints (added 2026-08-05)

Source: manual-process notes supplied by the project owner, cross-checked against a sample Grade 10 Day-5 class schedule image, plus a clarifying Q&A. This is new information layered on top of §1–§6 — the plantillas describe *load* (who teaches how much), this section describes *when* that load actually happens. **This materially changes earlier scope assumptions** — see the superseding note at the end of this section.

### 7.1 The cycle, not the week

- The school runs a **5-day academic cycle**, not a calendar week. Academic days are **Monday, Tuesday, Thursday, Friday only** — Wednesday is a non-academic **Formation Day** (homeroom + club activities only, no subject periods).
- A cycle's 5 days therefore span more than 5 calendar days: e.g. Cycle Day 1 = Mon, Day 2 = Tue, Day 3 = Thu, Day 4 = Fri, Day 5 = the *following* Monday, then Cycle 2 begins on that Tuesday. The system must model "Cycle Day 1–5" as its own sequence, decoupled from the calendar's day-of-week.
- One quarter = 5 cycle-days (per the notes) — worth confirming this literally means "one quarter is exactly one cycle," which would be unusually short; flagged for stakeholder confirmation rather than assumed.

### 7.2 Subject frequency per cycle

- **5×/cycle subjects:** Science, Mathematics, English.
- **4×/cycle subjects:** CLE, Filipino, Social Studies, TLE, MAPEH.
- All of a subject's sessions must be spread across the cycle's academic days, not clustered — exact distribution algorithm unspecified, flagged as an open design question for the Scheduling Engine.

### 7.3 Daily structure

Seven academic periods per day, 7:30 AM – 3:00 PM, with a mid-morning recess and midday lunch:

| Period | Time |
|---|---|
| 1 | 7:30–8:20 |
| 2 | 8:20–9:10 |
| — Recess — | 9:10–9:50 |
| 3 | 9:50–10:40 |
| 4 | 10:40–11:30 |
| 5 | 11:30–12:20 |
| — Lunch — | 12:20–1:20 |
| 6 | 1:20–2:10 |
| 7 | 2:10–3:00 |

After Period 7, the sample schedule shows additional non-teaching blocks: a 5-minute **Examen** (3:00–3:05, likely the Jesuit daily reflection practice, not an exam), **Classroom Clean-up** (3:05–3:20), and on Day 5 specifically, **Remediation (Social Studies & TLE)** (3:20–4:10). **Working hypothesis, not yet confirmed:** this rotating remediation block is plausibly where the near-universal "Service Load (3 hrs)" figure on every plantilla comes from — i.e. Service Load may represent scheduled remediation duty in this end-of-day slot, rotating by department across the cycle, rather than a flat unscheduled add-on. Needs confirmation before being modeled that way.

### 7.4 Teacher section-load caps (resolved 2026-08-05)

- **Every department:** maximum **4 sections** per teacher if they do **not** hold a Class Adviser (homeroom moderator) or paid club-moderator load.
- **Math, Science, and English specifically:** maximum **3 sections** if the teacher **does** hold a Class Adviser or club-moderator load. This stricter cap is *not* stated to apply to the other four departments (CLE, Filipino, Social Studies, TLE, MAPEH) — confirmed scope, per stakeholder answer. Whether those four departments have *any* reduced cap when a teacher also advises/moderates is unspecified; absent other guidance, assume no reduction (i.e. still 4) unless told otherwise.

### 7.5 TLE / MAPEH / Grade 10 facility constraint (resolved 2026-08-05)

- TLE (Grades 7–9) and MAPEH (Grades 7–9) each occupy **2 consecutive periods** per session, due to shared, limited facilities (a computer lab for TLE; implicitly a gym/facility for MAPEH).
- **Grade 10 English, TLE, and MAPEH also use 2 consecutive periods** — confirmed; the "three periods" figure in the original raw notes was an error, superseded by the cleaned notes.
- **The shared-facility constraint is school-wide, not per grade level** (confirmed): at any given period, **only one TLE class and only one MAPEH class may be running across all of Grades 7–10 combined** — i.e. there is effectively one computer lab and one MAPEH-relevant facility for the entire JHS, not one per grade. This is a much tighter constraint than "no conflict within a grade" and should be modeled as a single shared resource/facility entity that the Scheduling Engine treats as a global mutual-exclusion lock per period.
- Corollary: on any single day, TLE and MAPEH (both 2-period, facility-bound subjects) should not be scheduled in a way that creates a facility clash — the exact rule ("not on the same day at all" vs. "just not the same period") needs a follow-up confirmation; the raw notes suggest same-day avoidance, the cleaned notes only say "would create laboratory conflicts," which is narrower.

### 7.6 Teaching-assignment and break constraints

- A teacher may only teach within their own department's subject area — matches the existing "one department per teacher" exclusivity already established in §1.
- A teacher cannot be scheduled for a class in the period **immediately before and immediately after** recess, and likewise cannot be scheduled immediately before and after lunch — some break must fall adjacent to those breaks.
- A teacher cannot be scheduled for **3 consecutive periods** without a break, regardless of recess/lunch adjacency.
- Teachers holding administrative/coordinator positions may request special scheduling accommodations, reviewable before and after generation, with the ability to submit comments/reports on the generated schedule — this is a formal exception-handling path the Scheduling Engine must support, not just a manual override after the fact.

### 7.7 Meetings

- **Departmental meetings** occur every other academic day, with (per the notes) two departments meeting per applicable day (example given: TLE and Science on "Day 1").
- A **GLL (Grade Level Leader) meeting** block appears in the sample schedule's footnote area (~1:00–3:00), consistent with the "Grade Level Leader" role already tracked in the Other-Assignment-role lookup (§2).
- A recurring meeting referred to as "**PLE**" in the original raw notes appears as "**PLT**" in the sample schedule image — likely the same meeting under two different renderings/typos of the acronym. Needs a one-line confirmation of the correct term and what it stands for.
- The exact row-by-row placement of departmental/GLL/PLT meetings in the sample image was not fully legible from the rotated photo — flagged in §7.8 rather than guessed at.

### 7.8 Confidence note & what's still open

The sample schedule was read from a rotated, single-page photo; column/period alignment for the two dozen or so individual cells was not verified with full confidence (unlike the plantilla PDFs, which were run through direct text extraction). Findings in §7.1–7.7 that are marked "confirmed" came from the explicit Q&A with the project owner, not from the image alone. Still open:

- **English has no plantilla anywhere in the vault yet**, despite this schedule showing real English teachers (Ceballos, Aurestila, Mondido, and possibly others) with a 5×/cycle load equal to Math and Science. The project owner has confirmed an English plantilla will be provided — treat English as a near-term 8th department once it arrives, and revisit every "7 departments" count in this vault (§1, §2, §6) at that point.
- Exact departmental-meeting and PLT-meeting placement within the period grid.
- Whether "PLE" or "PLT" is the correct term.
- Whether the Service Load ↔ Remediation-block hypothesis (§7.3) is correct.
- Whether "one quarter = one cycle" (§7.1) is literal.
- The precise same-day-avoidance rule for TLE vs. MAPEH (§7.5 corollary).

### 7.9 Impact on the SRS and architecture — superseding note

[[JHS System SRS]] and [[JHS Laravel System Architecture]] were both written on the assumption that this system stops at *load* data (teacher × section × department, no periods/times/rooms) and that period-level timetabling was explicitly out of scope ("that data doesn't exist," per the SRS's Department Chair boundary note). **This section shows that assumption was incomplete** — a real, constraint-heavy cycle/period timetable does exist and is presumably part of what "the system is for" per the project owner's own framing (Department Chairs assign load; "it does not assign the scheduling, that's what the system is for"). The SRS and architecture doc's Scheduling Engine sections (SRS §5.4, Architecture §4 `schedule_items`) will need a follow-up revision to add cycle/day/period/facility entities and the constraints in §7.4–7.7 as formal functional requirements, once the still-open items in §7.8 are resolved. Not done in this pass to avoid encoding unconfirmed rules into the SRS.

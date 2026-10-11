# Scheduling feature — current requirements and implementation

Updated: 2026-10-02. This is the current scheduling reference. It supersedes earlier scheduling proposals in the SRS, architecture notes, constraints analysis, and October 1 design/plan where they conflict. Unrelated account, import, submission, and teaching-load features retain their existing behavior.

## Source data and test data

The seven supplied SY 2026–2027 department PDFs remain unchanged under `docs/Plantillas/`. They cover Filipino, CLE, TLE, Science, Mathematics, MAPEH, and Social Studies. The real English plantilla has not been received. English is nevertheless one of the eight required scheduling subjects.

`docs/Testing Plantillas/` contains fabricated scheduling-test PDFs, including English. They are not official source documents and must not be mistaken for corrected institutional assignments. Real imports can legitimately fail readiness checks when assignments are missing or teachers exceed the agreed section cap. Do not silently fill those gaps, remove caps, or overwrite the original PDFs.

The generator uses reviewed/imported database assignments, not PDFs sitting in a folder. Each department must review its import, correct its teacher/section assignments, and submit its plantilla for the active school year. A department with a Submitted or Locked submission is ready; Draft or Returned is not.

## Roles and workflow

Only System Admin can generate either kind, edit drafts, manage unavailable times, stop generation, create an editable revision, or approve/publish. Department Chairs see only approved current-year schedules. Their default view is their department, with an optional whole-school view and grade/section/subject/teacher/day filters. Both roles can download PDF and Excel. No new Academic Coordinator, teacher, or Principal permissions are granted by this feature.

1. Review and submit all eight departments' plantillas; resolve the generation-readiness checklist.
2. Open **Admin → Schedules**, choose **Normal** or **Special review**, then choose Quarter 1–4.
3. Generate a schedule. Run `composer run dev` while using the local project; it starts the web server, queue worker, and frontend together. MySQL must also be running.
4. Wait for the draft. The spinner and elapsed time indicate work is active; the percentage represents class periods without conflicts, not elapsed search time or a promised finish time.
5. Review grades in order 7, 8, 9, 10. Select All days to see Day 1–5 inside each grade. Edit and undo as needed.
6. Resolve all hard errors, then approve/publish. Chairs can then view and export that kind of schedule.

Normal and special schedules have independent generation, draft superseding, and publication. Publishing a special schedule does not replace an approved normal schedule, or vice versa. Version numbers remain globally increasing within a school year/quarter; gaps in a kind's version list are expected. Existing versions default to Normal. Failed, stopped, and superseded versions remain as audit history, not permanently deletable records.

## Shared hard checks

- The eight subjects are ENG, MATH, SCI, CLE, FIL, SOC, TLE, MAPEH. Display names are English, Mathematics, Science, CLE, Filipino, Social Studies, TLE, MAPEH; underlying department records are unchanged.
- A section belongs to the timetable's school year. Every day/period contains exactly one subject per section.
- A teacher cannot teach two sections at the same time.
- Every saved teacher must match the current section/subject assignment and belong to the subject's department.
- All required departments have Submitted or Locked plantillas for that school year.
- Maximum four distinct sections per teacher. For English, Mathematics, and Science, a class moderator assignment or an honorarium-only other assignment in that year reduces the maximum to three. The scheduling cap is separate from teaching-load/overload hour calculations.
- Admin-entered unavailable slots are hard constraints. They are scoped to school year and quarter and shared between schedule kinds; the first seven subject-period times are identical. Period 8 affects only special schedules.
- Draft edits may temporarily break rules. Errors are visible and approval is blocked until every hard error is resolved. There is no approval override.
- Drafts are freshly revalidated when Admin opens them, after edits/undo or availability changes, and during approval. Approval now rechecks current submissions, assignment eligibility, stored teachers, and section caps—not only the saved timetable's geometry. Already-approved historical versions are not silently modified or unpublished.

## Normal schedule rules

One normal Day 1–5 timetable repeats for all five cycles in a quarter. These labels are not weekdays; there is no calendar/weekday mapping.

Each section has 35 subject periods per cycle: English, Mathematics, Science each occupy five periods; CLE, Filipino, Social Studies, TLE, MAPEH each occupy four. These are period counts, not distinct-day counts for double-block subjects.

| Subject / grade | Required daily distribution across a cycle |
| --- | --- |
| English, Grades 7–9; Mathematics; Science | One period on each of the five days |
| CLE; Filipino; Social Studies | One period on four different days; never twice on a day |
| TLE; MAPEH, Grades 7–10 | One double block plus two singles on three different days |
| English, Grade 10 | Two double blocks plus one single on three different days |

Only TLE, MAPEH, and Grade 10 English can meet twice in a day, and those two periods must be consecutive. Valid double blocks are 1–2, 3–4, 4–5, 6–7; a block cannot cross recess or lunch. A subject cannot appear in separated periods on the same day.

Teachers need a free subject period adjacent to recess and lunch: they cannot occupy both periods 2 and 3 or both 5 and 6. They also cannot occupy all of 3, 4, 5. Together these checks prohibit three consecutive teaching periods anywhere in the seven-period day.

The normal generator starts from the supplied workbook's subject placement where available, adapting to current assignments and constraints. An existing valid normal version can be used as a fallback. It works in short queued search sessions and continues the same version when a session's time budget is used, rather than failing solely because a total timer expires. Its best candidate is checkpointed. A draft is saved only after fresh authoritative validation reports no hard errors. Proven impossible availability, invalid source data, or an unexpected worker error can still stop generation; no algorithm can promise a valid timetable for impossible inputs.

## Special review schedule rules

The October 2 sample resolves the earlier hold: use **eight 50-minute subjects**, not the earlier tentative 40-minute proposal. Each of the eight subjects meets every section exactly once each day. Each section's subject order repeats identically on Day 1–5. Teachers come from current plantilla assignments; the sample's names/cell assignments are not imported as source data.

Normal cycle frequencies, double-block patterns, teacher-break rules, and remediation rotation do not apply. Shared readiness, assignment eligibility, section/teacher conflicts, and unavailable times still apply. No meeting rules or unconfirmed facility capacities are introduced.

Special generation arranges one day as a section–teacher assignment graph with eight time slots, then repeats it on all five days. With no unavailable slots and at most eight daily classes for any teacher, bipartite edge coloring constructs a conflict-free arrangement without a timed heuristic search. Current section caps are stricter. If unavailable slots restrict the choices, an exact constrained search is used. Insufficient available periods are reported immediately; search-session exhaustion requeues work rather than declaring failure. An unavailable period on any day must be avoided on all five days because the order repeats.

Special replacements and swaps apply to the corresponding period(s) on all five days together. A swap moves subject and teacher together; undo restores the entire multi-day change. Errors from temporary duplicates remain visible and block approval.

## Bell schedule and exports

All grids retain the workbook structure: **TIME** only, **Grade - Section** columns, subject/teacher lines, full-width non-instructional rows, and grades 7–10 in order. Recess/lunch letters are spaced across their rows. All-days PDF exports use one grade/day per landscape page; Excel uses four grade tabs with days stacked inside each tab. Draft downloads are clearly marked not approved. The space reserved for future meeting information remains unfilled.

Scheduling dropdowns use the existing navy/blue branding with rounded menus, highlighted selections, and checkmarks. They enhance the original form fields rather than replacing submission or Alpine editing bindings. Arrow keys, Home/End, type-ahead, Enter/Tab selection, and Escape cancellation follow the [WAI select-only combobox pattern](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/examples/combobox-select-only/). Without JavaScript, native selects remain available. Special editing shows a target period only; its day is always synchronized across Day 1–5.

| Row | Normal and special time |
| --- | --- |
| Subject 1 | 7:30–8:20 |
| Subject 2 | 8:20–9:10 |
| Recess | 9:10–9:50 |
| Subject 3 | 9:50–10:40 |
| Subject 4 | 10:40–11:30 |
| Subject 5 | 11:30–12:20 |
| Lunch | 12:20–1:20 |
| Subject 6 | 1:20–2:10 |
| Subject 7 | 2:10–3:00 |

| End-of-day row | Normal | Special |
| --- | --- | --- |
| Subject 8 | — | 3:00–3:50 |
| Examen | 3:00–3:05 | 3:50–3:55 |
| Classroom clean-up | 3:05–3:20 | 3:55–4:10 |
| Remediation | 3:20–4:10 | — |
| Dismissal label | — | 4:10 |

Normal remediation labels: Day 1 Science; Day 2 English; Day 3 Mathematics; Day 4 CLE & Filipino; Day 5 Social Studies & TLE. These are informational rows only. They do not create service-load duty assignments or alter existing teaching-load calculations.

## Explicitly deferred / not certified by zero errors

- Departmental, GLL, and PLT/PLE meeting cadence, placement, participant membership, and acronym meaning.
- Facility inventory/capacity and the exact TLE/MAPEH same-time or same-day restrictions. Earlier one-class-at-a-time facility statements conflict with the whole-school workbook and are not implemented as confirmed capacities.
- Teacher-level remediation duties and any automatic connection to Service Load hours.
- Replacing fabricated English assignments with the real English plantilla once received, and obtaining corrections to genuine source-data gaps rather than treating fabricated test fixtures as official.

Zero hard errors means the implemented checks above pass; it does not certify deferred meeting/facility/duty rules or the authenticity of test data.

## Implementation map and verification

`TimetableKind` and the additive October 2 migration separate normal/special versions. `ScheduleReadinessService` checks inputs; `TimetableValidationService` combines source-data integrity with the kind-aware `ScheduleConstraintValidator`. `TimetableVersionService` owns lifecycle/approval, `GenerateTimetable` owns queued persistence/checkpoints, `SpecialScheduleGenerator` owns review-day generation, and `TimetableEditor` records reversible edits. `TimetableLayout` supplies shared web/PDF/Excel bells and labels.

Normal/special version allocation and publication take a shared transaction lock on the existing current-school-year setup row, because version numbers are shared. The setting itself is not changed. `branded-select.js` progressively enhances only dropdowns inside scheduling pages.

Run `php artisan migrate` with MySQL running before opening Schedules after this update. The migration adds a Normal default to existing versions without changing their entries, statuses, or version numbers. Restart `composer run dev` after backend changes so the queue worker loads the new code.

Verification commands: `php vendor/phpunit/phpunit/phpunit` (SQLite in-memory, not the real MySQL data) and `npm run build`. Coverage includes stale assignments/submissions/department membership/role caps at approval, separate publication and generation by kind, special eight-subject frequency and repeated order, teacher conflicts and availability, all-day edits/undo, and special web/PDF/Excel bells.

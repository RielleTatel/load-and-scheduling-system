# Plantilla-first teacher identity and directory reconciliation

Status: ready-for-agent
Execution: resumed 2026-10-13 on branch `feature/teacher-identity-reconciliation`, reconciled with the partner's scheduling update (`7a89e3e`). See [checkpoint](checkpoint.md).

## Problem Statement

Department Chairs often receive their plantillas before an official Teacher Directory is available. They need to encode and correct loads immediately, then reconcile the people on those sheets with later staff information without losing work or waiting indefinitely for a complete directory.

An empty Teacher Directory does not currently prevent plantilla confirmation when the canonical Section Roster exists. The bottleneck is reliable identity reconciliation afterward. The plantilla and registrar import paths match names differently, so reversing the order of two sources can create two records for the same person. Some spelling variants are joined automatically; other possible matches and department disagreements create additional records with warnings. There is no verification state or runtime merge workflow to resolve these cases safely. Separate IDs can split one person's workload and eventually undermine schedule conflict detection.

The registrar's List of Class Moderators and Teacher-Partners is a partial staff source. It must not be treated as a complete official Teacher Directory or used to conclude that an unlisted teacher should be removed.

## Solution

A Chair-reviewed plantilla can establish a teacher record with pending verification and support continued load encoding. Admin can verify the person's identity and department using the reviewed plantilla and Chair confirmation, recording the evidence, reviewer, and time. Receiving an official Teacher Directory is optional evidence rather than a prerequisite.

Later staff information links to the same teacher through unique exact matches or previously approved aliases. Spelling variations, ambiguous matches, and department disagreements enter a persistent review queue. Admin resolves these cases with Chair context and can merge existing duplicate records through a preview that preserves assignments and requires explicit decisions about incompatible values. A teacher missing from a later complete directory remains recorded with their assignments while Admin investigates the discrepancy.

A school-year identity readiness report identifies participating teachers who still need verification or conflict resolution. It gives the future load-and-schedule generator a dependable input boundary without requiring the official directory to arrive first.

## User Stories

1. As a Department Chair, I want to upload and review my plantilla before the official Teacher Directory arrives, so that staffing paperwork does not delay load encoding.
2. As a Department Chair, I want a genuinely new teacher on my reviewed plantilla to receive a pending-verification record, so that I can attach loads to a stable identity immediately.
3. As a Department Chair, I want to continue correcting loads while teacher verification is pending, so that identity review does not stop ordinary preparation.
4. As a Department Chair, I want to see each teacher's verification state and outstanding identity questions, so that I know what still needs attention.
5. As a Department Chair, I want to supply confirmation and context for my department's teachers, so that Admin can verify them without waiting for an official directory.
6. As a Department Chair, I want an unresolved match to remain visible in the review screen, so that I do not accidentally assign work to the wrong person.
7. As an Admin, I want unique exact matches to reuse existing teacher records across import paths, so that import order does not create duplicate identities.
8. As an Admin, I want previously approved spelling variants to reuse the same teacher, so that recurring source differences do not require repeated decisions.
9. As an Admin, I want unapproved spelling variants to appear as suggestions requiring review, so that a name similarity alone cannot join different people.
10. As an Admin, I want multiple possible matches to require an explicit choice, so that the application does not select whichever record it finds first.
11. As an Admin, I want to identify genuinely different people with similar or identical names, so that deduplication does not erase a legitimate teacher.
12. As an Admin, I want an exact name with conflicting department information to become a review case, so that the system does not silently create or move a teacher.
13. As an Admin, I want Chair context and the competing source observations in one place, so that I can make an informed identity or department decision.
14. As an Admin, I want to verify identity and department using a reviewed plantilla and Chair confirmation, so that a missing official directory is not a scheduling bottleneck.
15. As an Admin, I want verification and resolution decisions to record the reviewer, time, supporting source, and explanation, so that decisions remain attributable.
16. As an Admin, I want the provenance of each teacher observation preserved separately from verification, so that a source label cannot imply approval that never occurred.
17. As an Admin, I want to reconcile a later official-directory entry with an existing teacher through the directory review actions, so that existing IDs and loads remain intact.
18. As an Admin, I want an absent entry in a confirmed complete directory to create an investigation case, so that incomplete lists, new appointments, and source errors can be distinguished.
19. As a Department Chair, I want teachers under investigation to keep their assignments, so that reconciliation does not discard encoded work.
20. As an Admin, I want the partial moderator/partner roster to avoid absence warnings for other staff, so that unrelated teachers are not falsely marked as missing.
21. As an Admin, I want a merge preview showing the surviving identity and affected assignments, so that I can assess the consequences before confirming a duplicate.
22. As an Admin, I want conflicting service loads and repeated roles to require explicit handling during a merge, so that workloads are neither doubled nor silently overwritten.
23. As an Admin, I want a confirmed merge to preserve compatible assignments across school years and retain the former identity's history, so that both current work and historical records remain traceable.
24. As an Admin, I want repeated confirmations and stale review actions to be safe, so that retries or concurrent staff activity cannot recreate duplicates or apply outdated decisions.
25. As a Department Chair, I want access limited to my department's teachers and relevant review context, so that another department's records remain protected.
26. As an Admin, I want verification, identity decisions, and merges restricted to Admin actions, so that approval authority is clear.
27. As an Admin, I want existing teachers migrated without fabricated verification, so that old source labels do not become false evidence.
28. As a Coordinator, I want an identity readiness report for the selected school year, so that I can see which participating teachers require attention before trusting generator inputs.
29. As a Coordinator, I want unused teachers and historical-only assignments excluded from current-year readiness blockers, so that unrelated directory entries do not hold up preparation.
30. As a Coordinator, I want readiness to explain pending verification, department problems, and unresolved identity cases, so that the next action and responsible role are clear.
31. As a Coordinator, I want teacher identity readiness kept distinct from submission state and other scheduling requirements, so that a verified teacher is not mistaken for a complete schedule input.
32. As a Department Chair, I want a newly submitted source or ordinary load correction to preserve a valid identity verification unless it contradicts that identity, so that repeated imports do not create unnecessary approval work.

## Implementation Decisions

- **Shared identity boundary.** A single Teacher Identity module handles matching, source observations, verified aliases, and resolution decisions for plantilla confirmation, registrar roster confirmation, and manual teacher entry. Its outcomes distinguish an existing identity, an eligible new record, and a required review. Imports cannot bypass this boundary by creating a teacher directly after a failed match.
- **Stable identity and safe matching.** The internal teacher ID is the identity key; normalized names are candidate lookup keys. A unique exact normalized-name or approved-alias match can link automatically when department information is compatible. A previously unclaimed teacher can acquire a non-conflicting department from a reviewed plantilla while remaining pending verification. Multiple candidates, unapproved fuzzy variants, and department contradictions require review. Similarity ranking only supplies suggestions. A confirmed different-person decision is retained in source context; it is not a global permission to duplicate a name on every import.
- **Verification state.** Teachers have pending-verification, verified, or needs-review state. Verification attests to the canonical name and department and records Admin, time, supporting evidence, and Chair confirmation where the plantilla is the basis. No official directory is required. A material identity or department contradiction creates a case and suspends reliance on the previous verification; the prior decision remains in history. Load-only changes do not invalidate identity verification. A matching late source does not erase an existing verification.
- **Provenance and evidence.** Retain source type, source reference, school year or declared coverage where relevant, observed name and department, and reviewed row-to-teacher mappings. Preserve original observations separately from edited review values. Approved aliases and decisions point back to evidence. Existing source labels remain legacy provenance and do not prove review or verification. Every new teacher creation path supplies accurate provenance.
- **Confirmation and retry behavior.** Identity preflight precedes authoritative import changes. An unresolved identity row keeps the confirmation batch staged and identifies the required review; existing canonical names, departments, sections, and assignments remain unchanged. Recording a case that challenges a verified identity can suspend its verification/readiness without changing those canonical fields or loads. An otherwise valid batch with genuinely new teachers can confirm without an official directory. Successful confirmation commits mappings, teacher changes, and imported assignments together. Repeating a confirmation reuses its decisions and identities. Editing identity-bearing staged values invalidates that row's stale resolution, while retaining its source history. Existing reference-data validation and submission edit locks continue to apply.
- **Admin review workflow.** Add persistent cases and verification actions to the Teacher Directory. Chair context is scoped to the Chair's department and recorded as evidence; it does not itself grant verification authority. Admin can choose an existing person, confirm a distinct new person, settle a department discrepancy, or merge duplicate records. Identity-changing edits use this decision workflow rather than silently changing verified data. Cases and their outcomes are visible after redirect and on subsequent visits, beyond temporary warning messages.
- **Late sources and absence.** Existing registrar import remains explicitly partial. Its absence of a teacher never creates a missing-from-directory case. Admin can record observations or absence from a later official directory using a source reference and explicit completeness, scope, and school-year coverage. Unknown coverage is treated as partial. An applicable complete-source discrepancy requires investigation and retains the teacher and assignments; it never automatically deletes, deactivates, or reassigns the person. Admin records the eventual resolution, including any separately reviewed participation change.
- **Merge contract.** Admin selects the canonical surviving teacher ID and approves an assignment preview. Move compatible section, moderator, honors, service, and other-role assignments to that ID across all school years. Scheduling references also move: timetable entries, teacher unavailability, and validation issues, in every timetable version, because the survivor is the same person. Identical unavailability slots collapse. If both records are scheduled in the same slot of the same timetable version, the merge is rejected until that double booking is resolved. Equivalent duplicate service observations and other-role assignments collapse without double counting; incompatible service values require an explicit evidence-backed choice rather than automatic addition or overwrite. Conflicting departments or section ownership must be settled before applying the merge. Update source mappings, aliases, and cases atomically. Retain the redundant record as a merged, inactive identity with a survivor reference and history; exclude it from ordinary matching, selection, and workload/readiness lists. Stale-ID access resolves to the survivor or returns a clear validation response instead of accepting new assignments to the redundant record.
- **Schema and migration.** Add explicit verification metadata, source observations and row mappings, reviewed aliases, resolution cases, and a merged-identity reference. Backfill existing teachers conservatively as pending verification with available legacy provenance, retaining their IDs and foreign keys. Do not auto-merge legacy candidates. The current department/name uniqueness rule must allow explicitly reviewed distinct people while preserving duplicate protection through the identity boundary, durable source mappings, and transactional concurrency checks. No institutional staff identifier is assumed because none has been supplied.
- **Identity readiness contract.** For a school year, report the distinct active teacher IDs referenced by section, moderator, honors, service, or other-role assignments for that year, with readiness and actionable reasons. An identity is ready when verified, assigned a valid department, and free of applicable unresolved identity/department cases. Missing official-directory delivery is never a reason. A source discrepancy that challenges a participating identity remains a review reason until resolved. Unassigned teacher records and historical-only assignments do not block that year. This is an identity report, not a claim that workload, sections, rooms, or scheduling constraints are complete. Generator integration (decided 2026-10-13): identity readiness is advisory except for conflicts. `ScheduleReadinessService` reports a participating teacher with an open identity/department case or a needs-review state as a blocking `teacher_identity_conflict`, so generation and approval refuse to proceed until Admin resolves it. Pending verification alone is reported as a non-blocking warning and never stops generation. This keeps generation available for legacy teachers backfilled as pending while preventing a timetable built on an identity that may be two people.

## Testing Decisions

- The user confirmed the existing authenticated upload/review/confirm and Admin actions as the primary test boundary, including teacher IDs, preserved assignments, permissions, and warnings. Prefer HTTP feature tests at that boundary over tests mirroring matching internals. Test identity readiness directly until a generator entry point exists.
- Reuse the existing plantilla upload/review, registrar upload/review, Admin reference-data, Chair scope, and submission-flow feature-test patterns. Existing resolver, roster review, plantilla review, load calculation, and audit tests supply supporting fixtures and regression coverage; revise assertions that presently expect automatic fuzzy joins or duplicate creation on conflicts.
- Exercise a reviewed plantilla with no pre-existing teachers but a valid Section Roster. Assert pending teachers, imported assignments, stable IDs on repeat confirmation, and no official-directory prerequisite.
- Exercise both source orders with exact names and the observed spelling variation. An unapproved variant must remain staged for review without creating a second identity; after Admin confirms the same person, both orders must leave one canonical teacher and unchanged existing assignments. Subsequent use of the approved alias must not recreate the case.
- Exercise ambiguous names, genuinely distinct people, another department's exact name, stale review decisions, and identity-bearing edits. Assert review is required, no unsafe identity/load changes occur, and the recorded resolution controls the next confirmation.
- Exercise Admin verification without an official directory, durable evidence and audit records, protected role boundaries, and preservation of existing submission locks.
- Exercise a later exact match, a reviewed variant, a complete-source absence, and absence from the partial moderator/partner roster. Assert the first two preserve IDs, the complete discrepancy retains work and appears for investigation, and the partial source creates no absence case.
- Exercise merge preview and confirmation through Admin actions. Assert all five assignment families and multiple school years survive; equivalent observations do not double count; conflicting values require explicit choices; department conflicts prevent unsafe merges; merged IDs cannot take new assignments; stale or repeated requests do not partially apply.
- Exercise conservative legacy backfill and the readiness report with verified participants, pending participants, active identity conflicts, no-department participants, unassigned teachers, and historical-only teachers. Assert that directory delivery does not affect readiness and that the report does not alter submission state.

## Out of Scope

- Changing the timetable generator, conflict detector, or scheduling jobs beyond the identity blocker and warning added to `ScheduleReadinessService`.
- A new complete Teacher Directory file parser or a new directory file format. The existing partial registrar import and Admin evidence/reconciliation actions are the supported entry points in this feature.
- Replacing the Section Roster requirement, changing employment-status or load formulas, relaxing submission locks, or redesigning existing section/room validation.
- Automatically deciding that similar names are one person, automatically removing absent teachers, or automatically merging existing records during migration.
- A wider institutional person registry, externally assigned staff IDs, or additional approval roles.

## Further Notes

The accepted business decisions are: Chair-reviewed plantillas can create pending teachers and support load preparation; unique exact late matches reuse the existing identity while spelling variants require review; Admin decides identity/department conflicts; Admin can verify using plantilla plus Chair confirmation without an official directory; and absence from a later directory retains the teacher and assignments for investigation. Batch preflight, durable aliases, and merged-record retention are implementation choices supporting those decisions.

The diagnosis established a working empty-directory import and a remaining order-dependent duplicate for a plantilla/registrar spelling variation. It did not demonstrate production assignment corruption. The future risk is that two IDs divide one person's true workload or evade identity-based timetable checks. Implementing this specification addresses the identity bottleneck; it does not establish that every other generator input is ready.

The partner's update (`7a89e3e`) added the timetable generator, gated by `ScheduleReadinessService`. The 2026-10-13 decision settles the earlier open question: pending verification warns but does not block generation; open identity conflicts block it.

Implementation sequence and concrete code touchpoints are in [the implementation plan](implementation-plan.md).

## Implementation and validation (2026-10-13)

Implemented on branch `feature/teacher-identity-reconciliation` (base `7a89e3e`), commits `e4f9c75`..`cf28d56`. Not merged or pushed.

- Phase 1 regressions: `tests/Feature/TeacherIdentity/TeacherIdentityReconciliationTest.php` (empty directory, exact names in both orders, spelling variants in both orders). Seen RED before the identity boundary existed.
- Phase 2 schema: `2026_10_13_000001_add_teacher_identity_reconciliation.php`. Unique key is now `(department_id, normalized_name, name_ordinal)`; legacy rows backfill to pending. `TeacherIdentityMigrationTest` rolls back, inserts legacy look-alikes with loads, and migrates again.
- Phase 3: `app/Services/Teachers/TeacherIdentityService.php` replaces `TeacherResolver` (similarity moved to `NameSimilarity`, used only for suggestions). Plantilla and roster confirmation preflight and stay staged on any open case.
- Phase 4: `TeacherReconciliationService`, Admin identity cases, verification (plantilla + Chair confirmation, directory, or other), Chair confirmation and case context, directory recording with complete/partial coverage.
- Phase 5: `TeacherMergeService` with preview token; the five assignment families plus `timetable_entries`, `teacher_unavailabilities`, `timetable_validation_issues`.
- Phase 6: `TeacherIdentityReadinessService`; `ScheduleReadinessService::check()` adds `teacher_identity_conflict` blockers and a `warnings` list.
- Validation: focused selection 123/123; full suite 306/306 (2,150+ assertions), up from the 274-test baseline.

## Comments

- 2026-10-11: Implementation paused at the user's request while awaiting their partner's more recent update. Before executing the plan, refresh the checkout and reconcile these documents with that update. See [checkpoint](checkpoint.md).
- 2026-10-13: Resumed. Reconciled with the partner's scheduling update: added the advisory-with-conflict-blocking readiness decision and scheduling tables to the merge contract.
- 2026-10-13: All seven phases implemented and verified on the feature branch; awaiting human review before merge.

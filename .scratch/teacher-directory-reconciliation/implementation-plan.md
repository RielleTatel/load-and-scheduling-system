# Implementation plan: teacher directory reconciliation

Status: ready-for-agent
Execution: resumed 2026-10-13 on branch `feature/teacher-identity-reconciliation`. Reconciled with `7a89e3e`; baseline suite 274/274 passing. See [checkpoint](checkpoint.md).
Specification: [Plantilla-first teacher identity and directory reconciliation](spec.md)

When the user resumes implementation, first reconcile this plan with the partner's latest update, then implement the following phases in dependency order. Do not execute the phases while paused. This document is a plan; application changes have not been implemented. Use the specification for behavior and this document for code touchpoints and validation.

## Current behavior and constraints

- `app/Services/Plantilla/TeacherResolver.php` automatically adopts some fuzzy matches but creates a second teacher on an exact name in another department or some uncertain matches. Its null-department branch is unsuitable for reuse as the registrar resolver.
- `app/Services/Roster/RosterReviewService.php` uses a separate exact-name lookup. A late spelling variant can create an unclaimed duplicate. Its complete 36-section validation and transaction must remain intact. The staff named by this roster are only moderators and teacher-partners.
- `app/Models/Teacher.php` has a stable database ID and five assignment relationships, but no verification or reviewed aliases. The `source` field is inconsistent across creation paths and must not be promoted into verification evidence.
- `app/Services/Plantilla/PlantillaReviewService.php` imports loads by resolved teacher ID. Staged rows contain names in JSON rather than durable identity mappings. Confirmed mappings must survive retry and retain the original observed name.
- `app/Http/Controllers/Admin/TeacherDirectoryController.php` provides list/add/edit actions; no runtime merge exists. `app/Http/Requests/Admin/StoreTeacherRequest.php` rejects normalized duplicate names and will need a reviewed distinct-person route rather than an unrestricted validation bypass.
- `database/migrations/2026_09_02_000005_add_identity_fields_to_teachers_table.php` contains a historical one-time merge and a department/name uniqueness constraint. Add forward migrations; do not edit this migration or use its bulk foreign-key rewrite as the new merge implementation.
- The partner's update added the generator: `POST admin/schedules/generate` → `TimetableController::generate` → `App\Jobs\GenerateTimetable`, both gated by `app/Services/Scheduling/ScheduleReadinessService::check()` (blockers only, from section assignments, submissions, and section caps). Identity readiness feeds it per the 2026-10-13 decision: open conflicts block, pending verification warns. Preserve `app/Services/Plantilla/SubmissionService.php`; submission locks remain separate from readiness.
- Since `33ec484`, no teacher identity, import, request, or controller code changed; only roster/plantilla review views switched to `data-confirm` dialogs. Code touchpoints below remain accurate.

## 1. Establish regression coverage at the accepted boundary

Extend the existing authenticated feature tests before changing matching behavior:

- `tests/Feature/Chair/PlantillaUploadTest.php` and `PlantillaReviewTest.php`: empty teacher directory with a canonical Section Roster, pending teacher creation, repeat confirmation, preserved assignments, and continued Chair load edits.
- `tests/Feature/Admin/RosterImportUploadTest.php` and `RosterReviewTest.php`: registrar-first and plantilla-first ordering using unique exact names and a spelling variation.
- `tests/Feature/Admin/ReferenceDataTest.php` and Chair scope tests: existing role restrictions and duplicate-entry behavior, then new resolution/verification actions as their routes are added.

Use the existing PDF fixtures and reviewed row helpers. In both spelling-variant orderings, expect a persistent suggestion without an extra teacher, followed by an explicit Admin same-person decision, then successful confirmation retaining the original ID. Do not make the test pass by restoring automatic fuzzy adoption. Assert assignments and warning/review state, not only teacher counts.

Keep existing `tests/Unit/TeacherResolverTest.php`, `RosterReviewServiceTest.php`, and `PlantillaReviewServiceTest.php` as regression support. Identify and replace their conflicting expectations for automatic fuzzy joins and duplicate creation during department conflicts as the new boundary lands.

Completion condition: the empty-directory baseline is captured, and the order/ambiguity regressions expose the behavior this feature must change.

## 2. Add identity state and durable evidence

Add forward migrations, model casts, and relationships. Proposed additions are a teacher verification enum plus source-observation, alias, and identity-case models under `app/Enums/` and `app/Models/`.

- Extend teachers with verification state, current verification attribution/evidence, and nullable merged-into teacher reference. Keep verification history in durable decision records and existing audit logs.
- Store source observations with a stable source-row reference, raw observed identity, reviewed values, source type/reference, relevant school year/coverage, and resolved teacher ID. Handle the two people fields in a registrar row as separate observations. Enforce uniqueness for the same source row and person field so retries cannot multiply observations.
- Store approved aliases and source-scoped distinct-person decisions with their approving Admin and evidence. A name can refer to more than one legitimate person; aliases must not turn a shared name into a global one-owner identity key.
- Store persistent review cases with reason, candidate IDs, related observations, Chair context, state, and Admin resolution. Repeat processing of the same unresolved observation should reuse its case.
- Index normalized names for candidate lookup. Replace `teachers_dept_name_unique` only alongside the shared identity boundary and its transaction/concurrency protection, so explicit different-person decisions can support real name collisions without allowing ordinary imports to create uncontrolled duplicates. Serialize overlapping identity decisions using database-backed identity-key locks and revalidate candidates before committing; the key locks do not assert that a name belongs to only one teacher.
- Backfill every legacy teacher as pending verification while preserving IDs, assignments, and the existing source value as legacy provenance. Do not fabricate reviewer/time evidence or merge candidates. Provide Admin visibility of this pending set so existing records can be verified through the same workflow.

Update factories and seed defaults so new records begin pending verification. System-created records must not appear Admin-verified. Validate the migration against a populated test database as well as a fresh schema; include a legacy duplicate candidate and null-department teachers.

Completion condition: existing workloads survive migration and the schema can retain observations, decisions, merged identities, and attribution without treating legacy source labels as approval.

## 3. Introduce shared matching and adapt all creation paths

Create a Teacher Identity service under `app/Services/Teachers/`. Its public operations inspect observations and commit reviewed outcomes. It returns an existing identity, a proposed new identity, or a required review with candidates and reasons. Keep name normalization in one place and reuse the current comparison heuristics only to rank suggestions.

Matching order:

1. Reuse a current, previously confirmed source-row mapping if its identity-bearing inputs are unchanged.
2. Find exact normalized-name and approved-alias candidates, checking all eligible active identities and department compatibility. Link only a unique compatible match.
3. Return review for multiple candidates, another department's exact match, or an unapproved likely spelling variant.
4. For a genuinely unmatched reviewed observation, create one pending teacher on confirmation. A reviewed plantilla supplies the department; registrar observations can remain unclaimed.

Adapt these paths to the shared boundary:

- `app/Services/Plantilla/TeacherResolver.php` and `PlantillaReviewService.php`.
- `app/Services/Roster/RosterReviewService.php`.
- Admin and Chair teacher creation/update controllers and requests.

Preflight all identities before a confirmation transaction changes canonical teacher fields, sections, or assignments. If any identity requires a decision, persist the case and keep the batch staged. A case challenging an existing verified identity can suspend its verification/readiness while retaining its canonical name, department, and loads. Report actionable review messages without marking the batch imported. If all identities are clear, revalidate under the identity locks and commit identities, observations, mappings, and existing assignment imports together. Preserve the roster's all-or-nothing section validation and existing plantilla validation/warnings.

Update `PlantillaExtractionRow` and `RosterExtractionRow` handling as needed for durable observation references. Review edits must retain raw evidence; changing a name or department invalidates its resolved mapping. An unchanged repeat confirmation reuses decisions and assignments. Do not silently migrate loads to another teacher because a name was edited.

Update the Chair and registrar review views to distinguish ordinary extraction/load warnings from unresolved identities and show the next action. Keep `source` updates accurate and avoid overwriting the earlier registrar provenance when a plantilla later supplies a department.

Completion condition: exact matches behave consistently in both orders, fuzzy matches require review, missing directories do not prevent valid new plantilla records, and unresolved batches retain existing canonical identities and assignments while recording the review state.

## 4. Add Admin verification and reconciliation actions

Extend `routes/web.php`, the Admin Teacher Directory controller/requests, and `resources/views/admin/teachers/`. Put authoritative operations in a verification/reconciliation service under `app/Services/Teachers/`, rather than in controllers.

Provide actions to:

- Verify a teacher's canonical name and department using reviewed evidence. Plantilla-based verification requires recorded Chair confirmation; it does not require an official directory.
- Resolve a candidate as the existing person and approve the spelling alias, or record that it is a distinct person before creating the pending record.
- Resolve a department conflict with supporting source and explanation. Preview consequences for existing department-scoped assignments; an identity decision must not silently rewrite a submitted department's loads.
- Record a late official-directory observation or an absence discrepancy with a source reference and explicit completeness, scope, and school-year coverage. The existing registrar roster is always partial and cannot generate absence cases.
- Close an investigated discrepancy with a recorded outcome. Keep records and assignments while the case is open. A participation change requires a separate explicit, reviewed action; do not add automatic deactivation to the discrepancy handler.

Add department-scoped Chair context actions to existing Chair teacher/review screens. Admin alone can approve identities, verify, and merge. Keep current Chair submission edit restrictions. Enforce authorization on request handling rather than only hiding buttons.

Identity-changing edits create or resolve a case through this service; ordinary load edits leave verification intact. A new contradiction sets the current identity to needs-review while preserving its earlier verification history. Matching observations leave the verification unchanged. Expose pending and needs-review filters and durable case details in the directory and scoped Chair views.

Use `app/Services/Audit/AuditLogService.php` for attributable before/after decisions, with evidence references retained in domain records. Reject stale resolutions after a related teacher, observation, or case has materially changed.

Completion condition: Admin can verify and settle conflicting information without an official directory, Chairs can contribute context within their scope, and a subsequent request can retrieve the decision and evidence.

## 5. Implement a previewed, transactional duplicate merge

Create a merge service and Admin preview/confirm actions. Require canonical survivor selection, settled department compatibility, and explicit choices for incompatible assignments. Preserve the survivor ID; retain the redundant teacher as merged with its survivor reference and history.

Inventory and handle all current teacher references (five assignment families plus three scheduling tables added by `7a89e3e`):

| Assignment table | Merge rule |
| --- | --- |
| `teacher_section_assignments` | Transfer teacher references without changing section, department, school year, or hours; reject unresolved department/ownership conflicts. |
| `class_moderator_assignments` | Preserve the section/year owner and hours when transferring the teacher reference. |
| `honors_class_assignments` | Preserve section/year uniqueness and hours; require review of any conflicting ownership. |
| `service_loads` | Respect teacher/year uniqueness. Collapse equivalent observations; require an evidence-backed retained value for differing hours rather than adding them. |
| `teacher_other_assignments` | Preserve different roles and years, and collapse duplicate teacher/role/year memberships so the role is not counted twice. |
| `timetable_entries` | Transfer in every version. Reject the merge if both teachers occupy the same day/period in one version (pre-existing double booking). |
| `teacher_unavailabilities` | Transfer; collapse identical teacher/year/quarter/day/period slots to respect `teacher_unavailable_slot_unique`. |
| `timetable_validation_issues` | Transfer the nullable teacher reference; issue history is kept. |

In the same transaction, update observations, reviewed aliases, current source mappings, and related cases to the survivor. Preserve original source IDs/names and the merge decision in history. Invalidate or remap staged candidates so later confirmation cannot recreate the retired identity.

Exclude merged records from normal directory selections, candidate matching, workload lists, and readiness. Audit all assignment create/update request paths so a stale merged ID resolves safely or fails with a clear validation message. Repeated confirmation must return the recorded outcome; stale previews must be refreshed without partial changes.

Validate through Admin actions using populated records in all five families, multiple years, equivalent and conflicting service values, repeated roles, and stale requests. Assert calculated workload after reconciliation, not only foreign-key counts.

Completion condition: a real existing duplicate can be reconciled without lost assignments, double-counted load, silent conflicts, or reuse of the redundant identity.

## 6. Expose school-year identity readiness

Add a Teacher Identity Readiness service under `app/Services/Teachers/`. For the requested school year, collect the distinct non-merged teacher IDs referenced by any of the five assignment families and return an overall result with per-teacher reasons.

Readiness requires verified identity, a valid department, and no applicable unresolved identity/department case. Reasons should identify pending verification, an unassigned department, or the case that requires Admin review. Missing official-directory delivery is not a reason. Historical-only assignments and unassigned directory entries do not affect the selected year.

Expose the report in existing directory/review context with appropriate role scope. Integrate with `ScheduleReadinessService::check()`: participating teachers with an open identity/department case or needs-review state add a `teacher_identity_conflict` blocker; pending-only teachers appear in a new non-blocking `warnings` list (additive key; existing `ready`/`blockers` contract unchanged). Show the warnings on `scheduling/index`. Do not add generator endpoints or alter submission status transitions. Extend `tests/Feature/Scheduling/GenerateTimetableTest.php`: a pending-only teacher still generates; a conflicted teacher blocks generation and approval.

Test the identity readiness service directly with the verified/pending/conflicted/current-year/historical/unassigned matrix. Continue exercising its displayed warnings and permissions through the existing HTTP feature boundary where the report is shown.

Completion condition: readiness reports the correct participating identities and actionable reasons without creating a directory-delivery gate or changing submission locks.

## 7. Complete verification and handoff

Use a focused PHPUnit pass while implementing each phase, then run the full suite once the integrated feature is complete. The repository's PHPUnit configuration uses an in-memory SQLite test database; do not run these scenarios against ordinary application data. Format changed PHP files with the project's installed formatter.

Suggested final commands:

```sh
php vendor/bin/phpunit --filter 'PlantillaUploadTest|PlantillaReviewTest|RosterImportUploadTest|RosterReviewTest|ReferenceDataTest|TeacherRosterTest|ScopeBoundaryTest|SubmitFlowTest|TeacherIdentity|TeacherVerification|TeacherMerge|TeacherResolverTest|PlantillaReviewServiceTest|RosterReviewServiceTest|LoadCalculationServiceTest|AuditLogServiceTest|GenerateTimetableTest|TimetableEditingTest' --no-progress
php vendor/bin/phpunit --no-progress
```

Readiness tests should use a name covered by the `TeacherIdentity` filter or be included explicitly. Add the final implemented test names to the focused command rather than relying on placeholder names to select them.

Verify these acceptance outcomes before marking implementation complete:

- A valid reviewed plantilla imports pending teachers with no pre-existing Teacher Directory and supports further permitted Chair load edits.
- Both source orders retain one teacher for a confirmed same-person variation, with no automatic fuzzy approval and no changes to prior assignments.
- Admin can verify identity and department with plantilla plus Chair confirmation; the actor, time, and source remain retrievable.
- Exact late matches retain verification and ID; conflicting sources create persistent cases; partial roster absence creates no false case.
- Merge preview protects all five assignment families, incompatible values require decisions, and confirmation is atomic and retry-safe.
- Role boundaries and submission locks still hold; no ordinary creation path bypasses identity resolution or accepts a merged teacher ID.
- Conservative migration preserves legacy IDs and workloads; current-year identity readiness excludes unrelated staff and does not depend on official-directory delivery.

Update the feature's spec/plan with actual implementation and validation results. If implementation tickets are requested later, create one file per ticket under `issues/` in dependency order according to the local tracker conventions.

## Existing diagnostic evidence

Before this plan, the focused resolver/plantilla review/upload selection passed 36 tests with 87 assertions. An isolated in-memory HTTP probe confirmed an empty-directory plantilla upload with 10 teachers and 36 section assignments when the Section Roster was present.

A separate order probe exercised the real resolver and valid roster confirmation. Registrar-first spelling variation yielded one teacher; reversing those service operations yielded two teachers, including one with no department. This is evidence of the remaining identity defect, not proof of production workload corruption. Reproduce it at the agreed authenticated feature boundary and keep the regression after implementing the fix. The diagnostic failure has not been fixed by writing this plan.

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

- 2026-10-11: All implementation phases are unstarted and paused at the user's request pending their partner's update. Refresh the checkout and reconcile the spec/plan before executing any phase. See [checkpoint](checkpoint.md).
- 2026-10-13: Resumed and reconciled with `7a89e3e`. Added the readiness integration decision (conflicts block, pending warns) and the three scheduling tables to the merge inventory.
- 2026-10-13: All seven phases implemented and verified on the feature branch; awaiting human review before merge.

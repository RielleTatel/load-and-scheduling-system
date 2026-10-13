# Checkpoint: teacher directory reconciliation

Date: 2026-10-11 (Asia/Manila)
Execution: implemented 2026-10-13 on `feature/teacher-identity-reconciliation`; awaiting review and merge decision

Implementation has not started. The specification and implementation plan are saved, but their execution is on hold. Do not start implementation, migrations, or feature changes while this pause is in effect. Resume only when the user asks to continue; then incorporate the partner's update before executing the plan.

## Saved artifacts

- [Specification](spec.md): the agreed plantilla-first workflow, teacher identity and verification rules, reconciliation, merge behavior, identity readiness, and testing boundaries.
- [Implementation plan](implementation-plan.md): seven ordered phases with current code touchpoints and acceptance checks.

Both documents retain the canonical `ready-for-agent` triage label because the feature is specified. Their separate execution notice records this explicit pause and overrides any instruction to begin work. All seven implementation phases remain unstarted.

## Decisions to preserve

1. A Chair-reviewed plantilla can create pending-verification teachers and support load encoding before an official Teacher Directory arrives.
2. A unique exact late match reuses the existing teacher ID. Unapproved spelling variations are suggestions requiring review; an approved match preserves existing assignments.
3. Admin decides conflicting identity or department information using Chair context and supporting sources.
4. Admin can verify identity and department using a reviewed plantilla plus Chair confirmation without an official directory. Record the reviewer, time, and supporting evidence.
5. A teacher missing from a later applicable complete directory keeps their record and assignments while Admin investigates. Do not automatically remove or reassign them.
6. The registrar's moderator/teacher-partner roster is a partial staff source. Absence from it does not mean a teacher is missing from the official directory.

The plan also specifies durable observations and aliases, identity preflight before import changes, a previewed transactional merge, and a school-year identity readiness report. These are documented implementation choices; none has been built yet.

## Findings and validation already completed

- An empty teacher directory alone did not block the existing plantilla import when the canonical Section Roster was present. An isolated in-memory HTTP probe imported 10 teachers and 36 section assignments.
- The existing resolver/plantilla review/upload test selection passed 36 tests with 87 assertions during the diagnosis. These are previous diagnostic results, not a new verification of the planned feature.
- Matching is order-dependent across the plantilla and registrar paths. A service-level probe using the real resolver and valid roster confirmation produced one teacher for registrar-first spelling variation, but two teachers, including one without a department, in the reverse order. The duplicate defect remains unfixed. Production workload corruption was not demonstrated.
- Current teachers have no explicit verification state or reviewed alias history, and the Admin directory has no runtime merge workflow. The legacy `source` field is not reliable verification evidence.
- No generator application entry point was found at the inspected revision. Identity readiness can be implemented and tested independently.
- The user accepted authenticated upload/review/confirm and Admin actions as the main test boundary, checking IDs, preserved assignments, permissions, and warnings. Test readiness directly until the generator exists.

## Repository state at pause

Branch: `main`

Inspected commit: `33ec484aaa23feee975aa5680f532bc393a4d8ed` — `fix: propose short names by the plantillas' own convention`.

The spec was written from the local checkout without first pulling. In the preceding turn, a GitHub API check verified that remote `main` had the same commit. A normal SSH fetch failed with `Permission denied (publickey)`. The API comparison established the remote head at that time; it did not perform a successful pull. Recheck the remote after the partner's update.

The working tree contains the feature documents under `.scratch/`, untracked `AGENTS.md` and `docs/agents/`, and a modified `docs/.DS_Store`. Preserve these local changes during any later synchronization. No application code, migration, or feature test was changed for this implementation. The feature documents have not been committed or pushed.

## Resume sequence

1. Wait for the partner's update and the user's instruction to resume.
2. Check the working tree, fetch the latest relevant branch through a working authenticated transport, and integrate it without discarding local documents or other work. Do not rely only on the current cached `origin/main`.
3. Compare the update with the inspected commit, especially teacher identity, import/review flows, assignment constraints, permissions, and any newly implemented generator behavior.
4. Reconcile the saved spec and plan with those changes. Preserve the accepted business decisions and testing boundary; remove obsolete code references or tasks already completed by the partner.
5. Start the implementation plan with regression coverage for empty-directory import and both source orders, then proceed through the remaining phases. Report new validation results separately from the diagnostic baseline above.

## Deferred scope

The generator engine and its policy for unverified exploratory drafts versus final release remain outside this feature. That policy was not settled. A new complete Teacher Directory file parser is also outside the saved plan; existing partial roster import and Admin evidence/reconciliation actions are the planned entry points.

## Comments

- 2026-10-11: The user requested a checkpoint and stopped implementation pending a more recent update from their partner. The feature remains paused until the user resumes it.
- 2026-10-13: Resumed. The partner's update (`7a89e3e`, scheduling feature) was already on remote `main`; no identity code had changed. Decision: identity readiness is advisory except open conflicts, which block generation. All phases implemented; suite 306/306. See the spec's Implementation and validation section.

# Plan — Issue #38: Reconcile README after Media Storage completion

## Context

Issue #35/PR #36 completed M2 Media Storage. PR #37 was supposed to reconcile project-state.md and roadmap.md. However, inspection reveals that project-state.md and roadmap.md still contain stale milestone state (M1 "in progress" / "current", M2 "planned" / "next"). README.md was also stale but has since been updated by a human (Orchestrator cannot edit README.md due to permission restrictions).

This plan reconciles all three documentation files to accurately reflect the true repository state: M0 Completed, M1 Completed, M2 Completed, M3 not started.

## Implementation Steps

### Phase 1: Verify Documentation State

1. **Verify docs/project-state.md current state**
   - Read `docs/project-state.md`
   - Confirm it lists M2 Media Storage as completed in "Completed Capabilities"
   - Confirm "Current Milestone" does not show M1 as current
   - Confirm "Next Architectural Goal" does not show M2 as upcoming
   - If stale: record exact corrections needed

2. **Verify docs/roadmap.md current state**
   - Read `docs/roadmap.md`
   - Confirm M1 section says "completed" (not "in progress")
   - Confirm M1 completed slices include auth and project management
   - Confirm M2 is listed under completed milestones (not "Planned Milestones")
   - Confirm M1 has no "Next slice" referencing authentication
   - If stale: record exact corrections needed

3. **Verify README.md current state**
   - Read `README.md`
   - Confirm status table shows M1: Completed and M2: Completed
   - Confirm no "In progress" labels remain
   - Confirm Sanctum is labeled "SPA" not "planned"
   - Confirm M1 delivered text reflects completed auth and project management
   - If already corrected by human: confirm no further changes needed

### Phase 2: Human-Edits README.md (Agent Cannot Edit)

4. **Human edits README.md**
   - The Orchestrator does NOT have write permission to README.md
   - A human must apply any remaining corrections to README.md
   - Agent records what corrections are needed but does NOT modify README.md directly
   - If README.md is already correct (human already applied fixes): skip

### Phase 3: Documentation Reconciliation (If Needed)

5. **Reconcile docs/project-state.md if stale**
   - Update "Completed Capabilities" to include M2 Media Storage
   - Update "Current Milestone" to reflect M2 completion
   - Update "Next Architectural Goal" to reference M3 only if authorized
   - Keep all other content unchanged

6. **Reconcile docs/roadmap.md if stale**
   - Change M1 status from "in progress" to "completed"
   - Add auth and project management slices to M1 completed list
   - Remove M1 "Next slice" section
   - Move M2 from "Planned Milestones" to completed section
   - Keep all other milestones in Planned Milestones

### Phase 4: Verification

7. **Inspect resulting README diff**
   - Run `git diff README.md` to verify only milestone status changes
   - Confirm no unrelated content changed

8. **Verify documentation-only scope**
   - Run `git diff --name-only` to confirm only documentation files changed
   - Confirm no application files, agent files, or governance tests modified

9. **Run governance test suite**
   - Execute: `python -m unittest discover -s tests/governance -p 'test_*.py' -v`
   - Require complete GREEN (all tests pass)
   - Record result in evidence.md

10. **Verify cross-document consistency**
    - Confirm README.md, project-state.md, and roadmap.md all agree on:
      - M0: Completed
      - M1: Completed
      - M2: Completed
      - M3: Not started

### Phase 5: Independent Review

11. **Tester review**
    - If documentation changes were made: request independent Tester review
    - Tester verifies all acceptance criteria from spec.md
    - Tester inspects actual file contents against claimed state

### Phase 6: Git Operations

12. **Commit**
    - Stage changed documentation files
    - Conventional Commit: `docs: reconcile README and state docs after M2 completion`
    - Include `Refs #38` in commit body

13. **Push**
    - Push branch to remote

14. **Open Pull Request**
    - Title: `docs: reconcile README and state docs after M2 completion`
    - Body follows PR template with scope, TDD evidence (N/A), tests (governance only)
    - References Issue #38

15. **CI validation**
    - Wait for CI to report GREEN
    - Do not merge if any check fails

16. **Merge**
    - After Tester approval and CI GREEN: merge PR

17. **Close Issue #38**
    - Close the GitHub issue
    - Update project-state.md if it tracks active issues

18. **Return to NO_ACTIVE_ISSUE**
    - Stop. Await explicit authorization before any next issue.

## Risk Assessment

- **Low risk**: Documentation-only change with no application impact
- **Scope risk**: Ensure no unintended content changes sneak in
- **Permission risk**: README.md requires human edit; agent cannot bypass

## Success Criteria

All three documentation files consistently reflect:
- M0: Completed
- M1: Completed (including auth and project management)
- M2: Completed (media storage)
- M3: Not started
- No future work represented as current

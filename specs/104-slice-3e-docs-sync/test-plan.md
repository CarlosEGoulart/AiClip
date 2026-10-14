# Test Plan: M6.2 Slice 3E — Documentation Synchronization

## Overview

This test plan verifies that all 3 documentation files have been correctly updated to reflect the merged state of M6.2 Slice 3E (Issue #101 / PR #103). All tests are **manual verification** — no automated test execution is required for this documentation-only issue.

---

## Test Cases

### TC-DOC-01: README.md Verification

**Objective**: Verify README.md correctly reflects M6.2 Slices 3A–3E completion.

**Steps**:
1. Open `README.md`
2. Locate the "Development Status" table
3. Verify the M6 row contains: `M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3E Complete (Issues #81, #81B, #87, #89, #101)`
4. Locate the "Architecture" section (after the stack diagram)
5. Verify it contains the Slice 3E E2E testing paragraph mentioning:
   - `Slice 3E adds end-to-end integration testing`
   - `7 E2E scenarios`
   - `happy path, missing transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change detection`
   - `real infrastructure (MinIO, FFmpeg, Python worker, FFprobe)`

**Expected Result**: ✅ Both Development Status table and Architecture section updated correctly.

**Commands**:
```bash
grep -A 1 "M6" README.md
grep -A 5 "Slice 3E adds end-to-end" README.md
```

---

### TC-DOC-02: docs/project-state.md Verification

**Objective**: Verify project-state.md correctly reflects M6.2 Slice 3E completion in Completed Capabilities.

**Steps**:
1. Open `docs/project-state.md`
2. Locate "Completed Capabilities" section (after "Important Decisions")
3. Verify the M6.2 Slices paragraph includes Slice 3E:
   - Header shows: `M6.2 Slices 3A–3E — Caption Flow Integration`
   - Issues referenced: `#81, #81B, #87, #89, #101`
   - PRs referenced: `#83, #85, #88, #90, #103`
   - Slice 3E bullet exists with: `End-to-end caption flow integration test`, `7 E2E test scenarios`, `happy path, no transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change triggering new render`
4. Locate "Current Milestone" section
5. Verify it already correctly shows Slice 3E as merged and closed (no regression)
6. Locate REC-04 entry
7. Verify it states: `Slice 3E implemented and tested` (no regression)
8. Locate "Next Architectural Goal" section
8. Verify it correctly omits Slice 3E (only shows M6.2+ remaining and M7)

**Expected Result**: ✅ Completed Capabilities includes Slice 3E; other sections unchanged and correct.

**Commands**:
```bash
grep -A 20 "M6.2 Slices 3A" docs/project-state.md
grep -A 10 "Current Milestone" docs/project-state.md
grep "REC-04" docs/project-state.md
grep -A 5 "Next Architectural Goal" docs/project-state.md
```

---

### TC-DOC-03: docs/roadmap.md Verification

**Objective**: Verify roadmap.md correctly reflects M6.2 Slice 3E completion.

**Steps**:
1. Open `docs/roadmap.md`
2. Locate the M6 section header (`## M6 — Vertical Clip Rendering`)
3. Verify header shows: `M6.1 completed; M6.2 Slices 3A–3E completed`
4. Locate the completed slices list under M6
5. Verify Slice 3E is listed as completed with:
   - `Issue #101, PR #103 — merged; issue closed`
   - `End-to-end caption flow integration test`
   - `7 E2E test scenarios covering happy path, no transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change triggering new render`
6. Locate "Planned Milestones" table
7. Verify M6 row shows: `M6.2 Slices 3A–3E completed`

**Expected Result**: ✅ M6 header updated; M6 section and Planned Milestones table already correct (no regression).

**Commands**:
```bash
grep -A 2 "## M6" docs/roadmap.md
grep -A 3 "Slice 3E" docs/roadmap.md
grep "M6.2 Slices 3A" docs/roadmap.md
```

---

### TC-DOC-04: Cross-Document Consistency

**Objective**: Verify all three primary documents (README.md, project-state.md, roadmap.md) are internally consistent on M6.2 state.

**Verification Matrix**:

| Aspect | README.md | project-state.md | roadmap.md | Consistent? |
|--------|-----------|------------------|------------|-------------|
| M6.1 status | Complete | Complete | Complete | ☐ |
| M6.2 Slices 3A-3E | Complete | Completed, merged, closed | Completed | ☐ |
| Issues referenced | #81, #81B, #87, #89, #101 | #81, #81B, #87, #89, #101 | #81, #81B, #87, #89, #101 | ☐ |
| PRs referenced | #83, #85, #88, #90, #103 | #83, #85, #88, #90, #103 | #83, #85, #88, #90, #103 | ☐ |

**Steps**:
1. Compare the M6.2 status across all three documents
2. Verify no document states contradictory information
3. Verify issue and PR numbers match across all documents
4. Verify Slice 3E is consistently "completed/merged/closed" in all documents

**Expected Result**: ✅ All three documents agree completely on M6.2 Slices 3A–3E state.

**Commands**:
```bash
# Quick cross-check
echo "=== README ===" && grep -A 1 "M6" README.md
echo "=== project-state ===" && grep -A 2 "M6.2 Slices" docs/project-state.md | head -20
echo "=== roadmap ===" && grep -A 2 "M6.2 Slices" docs/roadmap.md
```

---

### TC-DOC-05: No Regressions in Already-Correct Sections

**Objective**: Verify that sections which were already correct have not been accidentally modified.

**Sections to Verify Unchanged**:
1. `README.md` Architecture section (Slice 3E paragraph intact)
2. `docs/project-state.md` Current Milestone (Slice 3E already shown as completed)
3. `docs/project-state.md` REC-04 entry (already says "implemented and tested")
4. `docs/project-state.md` Next Architectural Goal (already omits Slice 3E)
5. `docs/roadmap.md` M6 completed slices list (Slice 3E already listed)
6. `docs/roadmap.md` Planned Milestones table (already shows 3A-3E completed)

**Steps**:
1. For each section above, verify the content matches the "Current (should remain unchanged)" blocks in the plan
2. Confirm no unintended deletions or modifications

**Expected Result**: ✅ All previously-correct sections remain unchanged.

**Commands**:
```bash
# README Architecture
grep -A 8 "Slice 3E adds end-to-end" README.md

# project-state Current Milestone
grep -A 12 "M6.2 Slices 3A" docs/project-state.md | tail -15

# project-state REC-04
grep "REC-04" docs/project-state.md

# project-state Next Architectural Goal
grep -A 6 "Next Architectural Goal" docs/project-state.md

# roadmap M6 completed slices
grep -A 4 "Slice 3E" docs/roadmap.md

# roadmap Planned Milestones
grep "M6.2 Slices 3A" docs/roadmap.md
```

---

## Test Execution Summary

| Test Case | Description | Status |
|-----------|-------------|--------|
| TC-DOC-01 | README.md verification | ☐ Pass / ☐ Fail |
| TC-DOC-02 | project-state.md verification | ☐ Pass / ☐ Fail |
| TC-DOC-03 | roadmap.md verification | ☐ Pass / ☐ Fail |
| TC-DOC-04 | Cross-document consistency | ☐ Pass / ☐ Fail |
| TC-DOC-05 | No regressions in already-correct sections | ☐ Pass / ☐ Fail |

**Overall**: All test cases must **Pass** for the issue to be considered complete.

---

## Acceptance Criteria Mapping

| AC ID | Test Case |
|-------|-----------|
| AC-01 | TC-DOC-01 (Development Status table) |
| AC-02 | TC-DOC-01 (Architecture section) |
| AC-03 | TC-DOC-02 (Completed Capabilities) |
| AC-04 | TC-DOC-02 (Current Milestone - no regression) |
| AC-05 | TC-DOC-02 (REC-04 - no regression) |
| AC-06 | TC-DOC-02 (Next Architectural Goal - no regression) |
| AC-07 | TC-DOC-03 (M6 header) |
| AC-08 | TC-DOC-03 (M6 section - no regression) |
| AC-09 | TC-DOC-03 (Planned Milestones - no regression) |
| AC-10 | TC-DOC-04 (Cross-document consistency) |

---

## Notes

- This is a **documentation-only** issue — no code tests, no CI runs, no Playwright validation required
- Verification is entirely manual via file inspection and grep commands
- If any test case fails, the corresponding file must be corrected and re-verified
- The `SPEC_READY` status in the spec indicates the specification is complete; this test plan validates the implementation against that specification
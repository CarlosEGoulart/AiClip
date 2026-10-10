# Test Plan: REC-03 — Documentation Synchronization for M6.2 Slices 3A–3D

## Overview

This test plan verifies that all 7 documentation files have been correctly updated to reflect the merged state of M6.2 Slices 3A–3D. All tests are **manual verification** — no automated test execution is required for this documentation-only issue.

---

## Test Cases

### TC-DOC-01: README.md Verification

**Objective**: Verify README.md correctly reflects M6.2 Slices 3A–3D completion.

**Steps**:
1. Open `README.md`
2. Locate the "Development Status" table
3. Verify the M6 row contains: `M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3D Complete (Issues #81, #81B, #87, #89)`
4. Locate the "Architecture" section
5. Verify it contains the caption flow integration paragraph:
   - Mentions `RenderMediaClip` job fetches completed transcripts
   - Mentions `CaptionProjection` for segment projection
   - Mentions `SrtGenerator` for SRT generation
   - Mentions `StorageKeyBuilder::captionFile()` for storage
   - Mentions `caption_file` in worker contract (`media_processing_v1.json`)
   - Mentions optional caption files (omitted when no transcript, status not completed, or empty projection)

**Expected Result**: ✅ Both Development Status table and Architecture section updated correctly.

**Commands**:
```bash
grep -A 2 "M6" README.md
grep -A 10 "caption flow integration" README.md
```

---

### TC-DOC-02: docs/project-state.md Verification

**Objective**: Verify project-state.md correctly reflects M6.2 Slices 3A–3D completion.

**Steps**:
1. Open `docs/project-state.md`
2. Locate "Completed Capabilities" section
3. Verify it contains the M6.2 Slices 3A–3D paragraph with:
   - Issues #81, #81B, #87, #89
   - PRs #83, #85, #88, #90
   - All four slices described (3A: CaptionProjection + SrtGenerator, 3B: SrtGenerator, 3C: StorageKeyBuilder + Contract, 3D: RenderMediaClip integration)
4. Locate "Current Milestone" section
5. Verify it states: `M6.2 Slices 3A–3D — Caption Flow Integration (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90) are completed, merged, and closed. No implementation issue is active. Slice 3E (end-to-end integration test) remains planned but not authorized.`
6. Locate "Next Architectural Goal" section
7. Verify it states: `M6.2 Slice 3E — End-to-end caption flow integration test (planned, not authorized).`

**Expected Result**: ✅ All three sections updated correctly with accurate slice descriptions.

**Commands**:
```bash
grep -A 15 "M6.2 Slices 3A" docs/project-state.md
grep -A 5 "Current Milestone" docs/project-state.md
grep -A 5 "Next Architectural Goal" docs/project-state.md
```

---

### TC-DOC-03: docs/roadmap.md Verification

**Objective**: Verify roadmap.md correctly reflects M6.2 Slices 3A–3D completion.

**Steps**:
1. Open `docs/roadmap.md`
2. Locate the M6 section (## M6 — Vertical Clip Rendering)
3. Verify header shows: `M6.1 completed; M6.2 Slices 3A–3D completed`
4. Verify completed slices listed:
   - M6.1 baseline (Issue #69, PR #73)
   - Slice 3A (Issue #81, PR #83) — CaptionProjection + SrtGenerator, 18 unit tests
   - Slice 3B (Issue #81B, PR #85) — Dedicated SrtGenerator, 6 unit tests
   - Slice 3C (Issue #87, PR #88) — StorageKeyBuilder::captionFile(), contract extension, 15 tests
   - Slice 3D (Issue #89, PR #90) — RenderMediaClip integration, 6 integration tests
5. Verify "Planned slices (not authorized, not implemented)" section exists
6. Verify Slice 3E listed as: `End-to-end caption flow integration test (full pipeline: upload → transcribe → detect scenes → recommend → render with captions)`

**Expected Result**: ✅ M6 section accurately lists all 5 completed slices plus planned Slice 3E as unauthorized.

**Commands**:
```bash
grep -A 25 "## M6" docs/roadmap.md
```

---

### TC-DOC-04: Evidence Files Verification (4 files)

**Objective**: Verify all 4 evidence files have correct "Final Lifecycle State" section appended.

**Files to Verify**:
1. `specs/081-m6-2-slice3-caption-flow/evidence.md`
2. `specs/081B-m6-2-slice3b-srt-generator/evidence.md`
3. `specs/087-m6-2-slice3c-storage-contract/evidence.md`
4. `specs/089-m6-2-slice3d-render-caption-integration/evidence.md`

**For Each File, Verify**:
1. ✅ "## Final Lifecycle State" section exists (after existing "Lifecycle State" section)
2. ✅ Contains: "The issue was merged and closed following Tester approval."
3. ✅ Contains correct PR number:
   - 081: PR #83
   - 081B: PR #85
   - 087: PR #88
   - 089: PR #90
4. ✅ Contains correct merge commit:
   - 081: b71e11e
   - 081B: ea23565
   - 087: 743cb85
   - 089: 3b54f31
5. ✅ Contains correct merge date:
   - 081: 2026-10-08
   - 081B: 2026-10-09
   - 087: 2026-10-10
   - 089: 2026-10-10
6. ✅ Contains GitHub PR URL and Issue URL
7. ✅ Contains "Lifecycle Transition" with: `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`
8. ✅ Contains "Historical Preservation" note stating original `TESTER_APPROVED` decision is preserved
9. ✅ Historical `TESTER_APPROVED` decision section remains intact above (not deleted or modified)

**Expected Result**: ✅ All 4 evidence files have complete Final Lifecycle State sections with correct data.

**Commands**:
```bash
grep -A 15 "Final Lifecycle State" specs/081-m6-2-slice3-caption-flow/evidence.md
grep -A 15 "Final Lifecycle State" specs/081B-m6-2-slice3b-srt-generator/evidence.md
grep -A 15 "Final Lifecycle State" specs/087-m6-2-slice3c-storage-contract/evidence.md
grep -A 15 "Final Lifecycle State" specs/089-m6-2-slice3d-render-caption-integration/evidence.md

# Verify historical TESTER_APPROVED preserved
grep -B 5 "TESTER_APPROVED" specs/081-m6-2-slice3-caption-flow/evidence.md
grep -B 5 "TESTER_APPROVED" specs/081B-m6-2-slice3b-srt-generator/evidence.md
grep -B 5 "TESTER_APPROVED" specs/087-m6-2-slice3c-storage-contract/evidence.md
grep -B 5 "TESTER_APPROVED" specs/089-m6-2-slice3d-render-caption-integration/evidence.md
```

---

### TC-DOC-05: Cross-Document Consistency

**Objective**: Verify all three primary documents (README.md, project-state.md, roadmap.md) are internally consistent on M6.2 state.

**Verification Matrix**:

| Aspect | README.md | project-state.md | roadmap.md | Consistent? |
|--------|-----------|------------------|------------|-------------|
| M6.1 status | Complete | Complete | Complete | ✅/❌ |
| M6.2 Slices 3A-3D | Complete | Completed, merged, closed | Completed | ✅/❌ |
| Slice 3E status | Not mentioned | Planned, not authorized | Planned, not authorized | ✅/❌ |
| Issues referenced | #81, #81B, #87, #89 | #81, #81B, #87, #89 | #81, #81B, #87, #89 | ✅/❌ |
| PRs referenced | #83, #85, #88, #90 | #83, #85, #88, #90 | #83, #85, #88, #90 | ✅/❌ |

**Steps**:
1. Compare the M6.2 status across all three documents
2. Verify no document states contradictory information (e.g., one says "in progress" another says "completed")
3. Verify issue and PR numbers match across all documents
4. Verify Slice 3E is consistently "planned, not authorized" in project-state.md and roadmap.md

**Expected Result**: ✅ All three documents agree completely on M6.2 state.

**Commands**:
```bash
# Quick cross-check
echo "=== README ===" && grep -A 1 "M6" README.md
echo "=== project-state ===" && grep -A 2 "M6.2 Slices" docs/project-state.md
echo "=== roadmap ===" && grep -A 2 "M6.2 Slices" docs/roadmap.md
```

---

## Test Execution Summary

| Test Case | Description | Status |
|-----------|-------------|--------|
| TC-DOC-01 | README.md verification | ☐ Pass / ☐ Fail |
| TC-DOC-02 | project-state.md verification | ☐ Pass / ☐ Fail |
| TC-DOC-03 | roadmap.md verification | ☐ Pass / ☐ Fail |
| TC-DOC-04 | Evidence files verification (4 files) | ☐ Pass / ☐ Fail |
| TC-DOC-05 | Cross-document consistency | ☐ Pass / ☐ Fail |

**Overall**: All test cases must **Pass** for the issue to be considered complete.

---

## Acceptance Criteria Mapping

| AC ID | Test Case |
|-------|-----------|
| AC-01 | TC-DOC-01 (Development Status table) |
| AC-02 | TC-DOC-01 (Architecture section) |
| AC-03 | TC-DOC-02 (Completed Capabilities) |
| AC-04 | TC-DOC-02 (Current Milestone) |
| AC-05 | TC-DOC-02 (Next Architectural Goal) |
| AC-06 | TC-DOC-03 (M6 section completed slices) |
| AC-07 | TC-DOC-03 (Slice 3E planned) |
| AC-08 | TC-DOC-04 (specs/081 evidence) |
| AC-09 | TC-DOC-04 (specs/081B evidence) |
| AC-10 | TC-DOC-04 (specs/087 evidence) |
| AC-11 | TC-DOC-04 (specs/089 evidence) |
| AC-12 | TC-DOC-04 (TESTER_APPROVED preserved) |
| AC-13 | TC-DOC-05 (Cross-document consistency) |

---

## Notes

- This is a **documentation-only** issue — no code tests, no CI runs, no Playwright validation required
- Verification is entirely manual via file inspection and grep commands
- If any test case fails, the corresponding file must be corrected and re-verified
- The `SPEC_READY` status in the spec indicates the specification is complete; this test plan validates the implementation against that specification
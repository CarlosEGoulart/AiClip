# REC-04 Test Plan — Documentation Verification

## 1. Purpose

This test plan defines verification scenarios for the REC-04 spec package. Since REC-04 is a **documentation/risk-registration task only**, all scenarios validate the **completeness and accuracy of the documentation artifacts**, not application behavior.

---

## 2. Verification Scenarios

### V-01: Missing E2E Path Documented
**Objective**: Confirm `spec.md` explicitly enumerates the complete missing integration path.

**Procedure**:
1. Read `specs/094-rec-04-e2e-caption-gap/spec.md`
2. Locate Section 2.1 "Missing Verification Path"
3. Verify the path is documented as:
   ```
   Laravel → Storage (caption SRT) → Worker contract → FFmpeg subtitles filter → Output video → FFprobe validation → Laravel persistence
   ```

**Pass Criteria**: Path appears verbatim with all 7 components.

---

### V-02: Existing Coverage Cataloged with Gaps
**Objective**: Confirm `spec.md` Section 4 catalogs all 4 test layers and their limits.

**Procedure**:
1. Read `spec.md` Section 4 "Existing Test Coverage — Limits"
2. Verify table with 4 rows:
   - Laravel Feature Tests (mock worker)
   - Worker Unit Tests (mock FFmpeg)
   - Worker Integration Tests (real FFmpeg, no captions)
   - PHP Contract/Unit Tests (fake storage, static fixtures)
3. Verify each row identifies the specific mock/fixture limitation.

**Pass Criteria**: All 4 layers present with accurate gap description.

---

### V-03: Dependency Status Recorded
**Objective**: Confirm `spec.md` Section 3 records status of REC-01, REC-02, and `request_sha256`.

**Procedure**:
1. Read `spec.md` Section 3 "Current State of Dependencies"
2. Verify:
   - REC-01: "CLOSED ✅" with PR #96 reference
   - REC-02: "CLOSED ✅" with PR #97 reference
   - Worker `request_sha256`: "IMPLEMENTED ✅" with file location (`rendering.py` lines 530–886, 969–1039)
   - Cross-language tests: CL-01 through CL-04 table with "✅ Passing"

**Pass Criteria**: All 4 dependencies documented with correct status and references.

---

### V-04: Slice 3E Explicitly Out of Scope
**Objective**: Confirm `spec.md` Section 6.2 declares Slice 3E out of scope.

**Procedure**:
1. Read `spec.md` Section 6.2 "Explicitly OUT OF SCOPE"
2. Verify all 5 items marked with ❌:
   - Slice 3E specification
   - Slice 3E implementation
   - Test code creation
   - CI pipeline changes
   - Any code changes

**Pass Criteria**: All 5 items present with ❌ and "OUT OF SCOPE" heading.

---

### V-05: Plan Identifies Zero Code Changes
**Objective**: Confirm `plan.md` states no code changes required.

**Procedure**:
1. Read `plan.md` Section 1 "Overview"
2. Verify: "**No code changes are required.** This is a documentation/risk-registration task only."
2. Read `plan.md` Section 3 "Documentation Files to Update"
3. Verify only `docs/project-state.md` (one-line update) and spec package files listed.
3. Read `plan.md` Section 9 "Out of Scope"
4. Verify all 7 items marked ❌.

**Pass Criteria**: Zero code changes stated in 3+ locations.

---

### V-06: Test Plan Covers All Documentation Scenarios
**Objective**: Confirm `test-plan.md` (this file) defines scenarios V-01 through V-06.

**Procedure**:
1. Read this file Section 2 "Verification Scenarios"
2. Verify scenarios V-01 through V-06 each have:
   - Objective
   - Procedure (numbered steps)
   - Pass Criteria

**Pass Criteria**: All 6 scenarios defined with complete structure.

---

### V-07: Evidence File Captures Current State
**Objective**: Confirm `evidence.md` (to be created by Orchestrator) captures required state.

**Procedure** (Orchestrator executes):
1. Read `specs/094-rec-04-e2e-caption-gap/evidence.md`
2. Verify content includes:
   - Issue reference (#94)
   - Spec package location
   - Dependency verification with PR numbers and file locations
   - Coverage gap confirmation
   - Out-of-scope declaration
   - Authorization gate statement

**Pass Criteria**: All 6 evidence items present.

---

### V-08: Project State Updated
**Objective**: Confirm `docs/project-state.md` includes REC-04 status line.

**Procedure**:
1. Read `docs/project-state.md`
2. Search for "REC-04" or "Issue #94"
3. Verify line similar to:
   ```
   REC-04 (Issue #94): E2E caption flow integration test gap documented as risk; Slice 3E implementation not authorized.
   ```

**Pass Criteria**: Line present in "Known Limitations" or "Next Architectural Goal" section.

---

## 3. Test Execution Order

| Order | Scenario | Type |
|-------|----------|------|
| 1 | V-01 | Spec content |
| 2 | V-02 | Spec content |
| 3 | V-03 | Spec content |
| 4 | V-04 | Spec content |
| 5 | V-05 | Plan content |
| 6 | V-06 | Test-plan content (self-check) |
| 7 | V-07 | Evidence content (Orchestrator) |
| 8 | V-08 | Project state (Orchestrator) |

---

## 4. Pass/Fail Criteria

**Overall PASS**: All 8 scenarios pass.

**Overall FAIL**: Any scenario fails → return to Planner for correction.

---

## 5. Environment Requirements

- Read access to `/workspaces/AiClip/specs/094-rec-04-e2e-caption-gap/`
- Read access to `/workspaces/AiClip/docs/project-state.md`
- No running application, database, or FFmpeg required
- No Laravel artisan, PHPUnit, pytest, or Playwright required

---

## 6. Traceability

| Scenario | Spec Section | Plan Section | Evidence Item |
|----------|--------------|--------------|---------------|
| V-01 | 2.1 | — | Coverage gap |
| V-02 | 4 | — | Coverage gap |
| V-03 | 3 | 6 | Dependency verification |
| V-04 | 6.2 | 9 | Out-of-scope declaration |
| V-05 | — | 1, 3, 9 | Zero code changes |
| V-06 | — | — | Self-contained |
| V-07 | — | 4 | Evidence file content |
| V-08 | — | 3.2 | Project state update |

---

## 7. Notes

- This test plan is **self-referential** (V-06 verifies the test plan itself)
- V-07 and V-08 require Orchestrator action after SPEC_READY
- No automated test execution — all verification is manual document review
- Results recorded in `evidence.md` by Orchestrator
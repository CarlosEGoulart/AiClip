# REC-04 Implementation Plan — Documentation Only

## 1. Overview

This plan covers the creation of the REC-04 spec package (spec.md, plan.md, test-plan.md, evidence.md) to register the missing E2E caption integration test gap as a documented risk.

**No code changes are required.** This is a documentation/risk-registration task only.

---

## 2. Deliverables

| File | Purpose | Status |
|------|---------|--------|
| `spec.md` | Documents missing E2E path, existing coverage, risk, dependencies, out-of-scope | ✅ Created |
| `plan.md` | This file — identifies documentation artifacts and verification | ✅ Created |
| `test-plan.md` | Defines documentation verification scenarios | ✅ To create |
| `evidence.md` | Records current state for Orchestrator traceability | ✅ To create |

---

## 3. Documentation Files to Update

### 3.1 Primary Artifacts (This Spec Package)
- `/workspaces/AiClip/specs/094-rec-04-e2e-caption-gap/spec.md`
- `/workspaces/AiClip/specs/094-rec-04-e2e-caption-gap/plan.md`
- `/workspaces/AiClip/specs/094-rec-04-e2e-caption-gap/test-plan.md`
- `/workspaces/AiClip/specs/094-rec-04-e2e-caption-gap/evidence.md`

### 3.2 Project State Document
**File**: `/workspaces/AiClip/docs/project-state.md`

**Update Required**: Minor — ensure "Known Limitations" or "Next Architectural Goal" reflects that REC-04 is documented but Slice 3E remains unauthorized.

**Change**: Add one line under "Known Limitations" or "Next Architectural Goal":
```markdown
REC-04 (Issue #94): E2E caption flow integration test gap documented as risk; Slice 3E implementation not authorized.
```

### 3.3 No Other Files
- No application code
- No test code
- No CI configuration
- No schema changes

---

## 4. Evidence File Content

The `evidence.md` will capture:

1. **Issue reference**: #94
2. **Spec package location**: `specs/094-rec-04-e2e-caption-gap/`
3. **Dependency verification**:
   - REC-01 (Issue #91): CLOSED — PR #96
   - REC-02 (Issue #92): CLOSED — PR #97
   - Worker `request_sha256`: IMPLEMENTED in `rendering.py` (render_singular + render_clips)
   - Cross-language tests: CL-01 through CL-04 passing
4. **Coverage gap confirmation**: All 4 test layers verified as mock/fixture-only
5. **Out-of-scope declaration**: Slice 3E not authorized
6. **Authorization gate**: Human authorization required before any Slice 3E work

---

## 5. Test Plan Reference

See `test-plan.md` for verification scenarios V-01 through V-06.

---

## 6. Dependencies

| Dependency | Status | Required For |
|------------|--------|--------------|
| REC-01 (render validation) | ✅ CLOSED | Baseline for worker response validation |
| REC-02 (idempotency) | ✅ CLOSED | Baseline for safe retries |
| Worker `request_sha256` | ✅ IMPLEMENTED | Canonical hash includes `caption_file` |
| Cross-language hash tests | ✅ PASSING | PHP/Python canonicalization parity |
| Human authorization | ❌ PENDING | Any future Slice 3E work |

---

## 7. Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Spec package incomplete | Verify all 4 files created with required content |
| Out-of-scope ambiguity | Explicit "OUT OF SCOPE" section in spec.md |
| Missing dependency status | Cross-reference PR numbers and file locations |
| Evidence not traceable | evidence.md links to all verifiable artifacts |

---

## 8. Acceptance Criteria for This Plan

- [ ] `spec.md` created with all required sections
- [ ] `plan.md` created (this file)
- [ ] `test-plan.md` created with scenarios V-01 through V-06
- [ ] `evidence.md` created with current state
- [ ] `docs/project-state.md` updated with one-line REC-04 status
- [ ] No code changes proposed or implied
- [ ] SPEC_READY returned

---

## 9. Out of Scope (Reiterated)

- ❌ Slice 3E specification
- ❌ Slice 3E implementation plan
- ❌ Any test code (unit, integration, E2E, Playwright)
- ❌ CI pipeline changes
- ❌ Application code changes
- ❌ Schema changes
- ❌ Infrastructure changes

---

## 10. Next Steps (After SPEC_READY)

1. Orchestrator reviews spec package
2. Orchestrator creates evidence.md
3. Orchestrator updates `docs/project-state.md`
4. Issue #94 marked as documentation-complete
5. **STOP** — No further work until explicit human authorization for Slice 3E
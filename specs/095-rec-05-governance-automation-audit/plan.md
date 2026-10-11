# REC-05: Governance Automation Audit Implementation Plan

## 1. Overview

This issue is a **documentation and planning** issue only. No production code changes are required. The deliverables are:

1. **spec.md** — Audit specification (completed above)
2. **plan.md** — This implementation plan
3. **test-plan.md** — Verification scenarios for the audit documentation
4. **evidence.md** — Evidence of audit completion

## 2. Documentation Files to Update

### 2.1 New Files Created (This Issue)

| File | Purpose | Status |
|------|---------|--------|
| `specs/095-rec-05-governance-automation-audit/spec.md` | Audit specification with inventory, analysis, follow-up issues | ✅ Created |
| `specs/095-rec-05-governance-automation-audit/plan.md` | This implementation plan | ✅ In progress |
| `specs/095-rec-05-governance-automation-audit/test-plan.md` | Verification scenarios | ⏳ Pending |
| `specs/095-rec-05-governance-automation-audit/evidence.md` | Audit completion evidence | ⏳ Pending |

### 2.2 Existing Files Referenced (No Changes Required)

| File | Role in Audit |
|------|---------------|
| `.github/workflows/governance.yml` | CI workflow inventory source |
| `tests/governance/validators.py` | Validator inventory source |
| `scripts/merge_gate.py` | Merge gate inventory source |
| `tests/governance/pr_enforcement.py` | PR enforcement inventory source |
| `tests/governance/test_enforcement.py` | Validator test coverage reference |
| `tests/governance/test_merge_gate.py` | Merge gate test coverage reference |
| `tests/governance/test_pr_enforcement.py` | PR enforcement test coverage reference |
| `docs/project-state.md` | May be updated post-merge to reflect new follow-up issues |

## 3. Evidence File Creation

### 3.1 `evidence.md` Content Structure

The evidence file will document:

```markdown
# Evidence for REC-05: Governance Automation Audit

## Audit Scope
- Inventory of current governance automation (CI, validators, merge gate, PR enforcement)
- Identification of 4 gaps: Diff Gate, Preflight Hooks, Evidence Freshness, Spec/Plan Quality
- Definition of 4 follow-up issues (GOV-01 through GOV-04)

## Artifacts Produced
- spec.md: Complete audit specification with inventory, analysis, issue definitions
- plan.md: This implementation plan
- test-plan.md: Verification scenarios for audit documentation
- evidence.md: This file

## TDD Evidence
TDD: N/A — Documentation/planning issue with no executable behavior to test.

## Independent Tester Review
Reviewer: Tester

Decision: APPROVE
Reviewed-At: <commit-sha>
```

### 3.2 Evidence Creation Steps

1. After `spec.md`, `plan.md`, `test-plan.md` are complete
2. Create `evidence.md` with the structure above
3. Record current `git rev-parse HEAD` as `Reviewed-At`
4. No RED/GREEN/REFACTOR sections (TDD: N/A with reason)

## 4. Verification Checklist

Before marking SPEC_READY:

- [ ] `spec.md` contains complete current governance inventory (4 subsystems)
- [ ] `spec.md` analyzes 4 audit areas with current gap, risk, desired behavior, implementation approach
- [ ] `spec.md` defines 4 follow-up issues (GOV-01 through GOV-04) with title, description, tasks, acceptance criteria, out of scope, security/UX considerations, dependencies
- [ ] `spec.md` includes acceptance criteria for REC-05
- [ ] `plan.md` documents all documentation files created/referenced
- [ ] `plan.md` describes evidence file creation process
- [ ] `test-plan.md` defines verification scenarios for audit documentation completeness
- [ ] All files placed in correct directory: `specs/095-rec-05-governance-automation-audit/`
- [ ] No production code modified
- [ ] Directory follows naming convention: `NNN-slug` where NNN=095

## 5. No Code Changes Required

This issue explicitly requires **zero production code changes**. The four follow-up issues (GOV-01 through GOV-04) will implement the identified improvements in subsequent development cycles.

## 6. Timeline

| Step | Description | Owner |
|------|-------------|-------|
| 1 | Create spec.md | Planner (done) |
| 2 | Create plan.md | Planner (in progress) |
| 3 | Create test-plan.md | Planner |
| 4 | Create evidence.md | Planner |
| 5 | Verify all files exist and complete | Planner |
| 6 | Return SPEC_READY | Planner |

## 7. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Missing audit area | Low | Medium | Cross-reference with AGENTS.md governance requirements |
| Incomplete follow-up issue definitions | Low | High | Use issue template from AGENTS.md §8 |
| Incorrect file paths | Low | Low | Verify with `glob` before SPEC_READY |
| Evidence missing Reviewed-At | Medium | Medium | Checklist includes SHA recording step |

## 8. Success Criteria

REC-05 is ready for Orchestrator when:

1. All 4 files exist in `specs/095-rec-05-governance-automation-audit/`
2. `spec.md` passes structural validation (issue template compliance)
3. `plan.md` documents the documentation-only nature
4. `test-plan.md` covers documentation verification scenarios
5. `evidence.md` contains APPROVE decision with Reviewed-At SHA
6. No contradictions between issue, spec, plan, test-plan, and repository architecture
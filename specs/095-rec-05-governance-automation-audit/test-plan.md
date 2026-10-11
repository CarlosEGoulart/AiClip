# REC-05: Governance Automation Audit Test Plan

## 1. Test Purpose

Verify that the audit documentation (spec.md, plan.md, test-plan.md, evidence.md) is complete, internally consistent, and accurately reflects the current governance automation state in the repository.

## 2. Test Scope

**In Scope**:
- Documentation completeness and structure validation
- Inventory accuracy against actual repository files
- Follow-up issue definition compliance with AGENTS.md §8 template
- Cross-artifact consistency (issue ↔ spec ↔ plan ↔ test-plan ↔ evidence)
- No production code changes verification

**Out of Scope**:
- Testing the four follow-up issues (GOV-01 through GOV-04) — they are separate future issues
- Running governance CI workflows — this audit documents them, doesn't modify them
- Testing merge gate or PR enforcement — they are documented, not changed

## 3. Verification Scenarios

### 3.1 Specification Completeness (spec.md)

| Scenario | Verification Method | Expected Result |
|----------|---------------------|-----------------|
| **SC-01**: Current governance inventory covers all 4 subsystems | Read spec.md §1, compare with actual files | All 4 subsystems documented: CI workflows, validators, merge gate, PR enforcement |
| **SC-02**: Each validator listed with purpose and enforcement point | Read spec.md §1.2 table | 7 validators documented with correct mappings |
| **SC-03**: Merge gate 5 required checks enumerated | Read spec.md §1.3 | All 5 checks listed exactly as in merge_gate.py |
| **SC-04**: Four audit areas analyzed with gap, risk, desired behavior, implementation approach | Read spec.md §2 | GOV-01 through GOV-04 each have all 4 analysis components |
| **SC-05**: Four follow-up issues defined with all required sections | Read spec.md §3 | Each GOV-XX has: Title, Description, Technical Tasks, Acceptance Criteria, Out of Scope, Security Considerations, UX Considerations (where applicable), Dependencies |
| **SC-06**: REC-05 acceptance criteria defined | Read spec.md §4 | 6+ criteria covering all deliverables |

### 3.2 Plan Completeness (plan.md)

| Scenario | Verification Method | Expected Result |
|----------|---------------------|-----------------|
| **PL-01**: Documents all new files created | Read plan.md §2.1 | 4 files listed with purpose and status |
| **PL-02**: References existing files without requiring changes | Read plan.md §2.2 | 8 existing files listed, all marked "No Changes Required" |
| **PL-03**: Describes evidence.md content structure | Read plan.md §3.1 | Template with all required sections shown |
| **PL-04**: Lists evidence creation steps | Read plan.md §3.2 | 4 steps including SHA recording |
| **PL-05**: Includes verification checklist | Read plan.md §4 | 10+ checklist items covering all deliverables |
| **PL-06**: Explicitly states no code changes required | Read plan.md §5 | Clear statement "zero production code changes" |
| **PL-07**: Includes timeline with owners | Read plan.md §6 | 6 steps with Planner as owner |
| **PL-08**: Documents risks and mitigations | Read plan.md §7 | At least 4 risks with likelihood/impact/mitigation |
| **PL-09**: Defines success criteria for SPEC_READY | Read plan.md §8 | 6 criteria covering all files and consistency |

### 3.3 Test Plan Completeness (test-plan.md)

| Scenario | Verification Method | Expected Result |
|----------|---------------------|-----------------|
| **TP-01**: Defines test purpose and scope | Read test-plan.md §1-2 | Clear purpose, in-scope/out-of-scope lists |
| **TP-02**: Covers spec.md verification scenarios | Read test-plan.md §3.1 | 6 scenarios (SC-01 through SC-06) |
| **TP-03**: Covers plan.md verification scenarios | Read test-plan.md §3.2 | 9 scenarios (PL-01 through PL-09) |
| **TP-04**: Covers evidence.md verification scenarios | Read test-plan.md §3.4 | 5 scenarios (EV-01 through EV-05) |
| **TP-05**: Covers cross-artifact consistency scenarios | Read test-plan.md §3.5 | 5 scenarios (CC-01 through CC-05) |
| **TP-06**: Covers negative/error scenarios | Read test-plan.md §3.6 | At least 3 negative scenarios |
| **TP-07**: Defines pass/fail criteria | Read test-plan.md §4 | Clear criteria for each scenario |
| **TP-08**: Documents execution method | Read test-plan.md §5 | Manual review process described |

### 3.4 Evidence Completeness (evidence.md)

| Scenario | Verification Method | Expected Result |
|----------|---------------------|-----------------|
| **EV-01**: Contains audit scope summary | Read evidence.md | Lists inventory, 4 gaps, 4 follow-up issues |
| **EV-02**: Lists all artifacts produced | Read evidence.md | 4 artifacts: spec.md, plan.md, test-plan.md, evidence.md |
| **EV-03**: Contains TDD: N/A with valid reason | Read evidence.md | "Documentation/planning issue with no executable behavior to test" |
| **EV-04**: Contains Tester review with Decision: APPROVE | Read evidence.md | "Decision: APPROVE" present, no REJECT |
| **EV-05**: Contains Reviewed-At with valid SHA | Read evidence.md | `Reviewed-At: <40-char-hex>` matching `git rev-parse HEAD` |

### 3.5 Cross-Artifact Consistency

| Scenario | Verification Method | Expected Result |
|----------|---------------------|-----------------|
| **CC-01**: Issue number (095) matches directory name | Check directory path | `specs/095-rec-05-governance-automation-audit/` |
| **CC-02**: Slug matches issue title | Compare issue #95 title with directory slug | "governance-automation-audit" matches |
| **CC-03**: Follow-up issue numbers (GOV-01 to GOV-04) are sequential and unique | Read spec.md §3 | GOV-01, GOV-02, GOV-03, GOV-04 defined once each |
| **CC-04**: Acceptance criteria in spec.md match deliverables in plan.md | Cross-reference spec.md §4 with plan.md §4 | All 6 spec criteria have corresponding plan verification |
| **CC-05**: No production code changes claimed in any artifact | Search all 4 files for code change references | Zero references to implementing GOV-01 through GOV-04 in this issue |

### 3.6 Negative/Error Scenarios

| Scenario | Verification Method | Expected Result |
|----------|---------------------|-----------------|
| **NE-01**: Missing required file detected | Remove one file, run verification | Checklist in plan.md §4 catches missing file |
| **NE-02**: Incomplete follow-up issue definition detected | Remove "Out of Scope" from one GOV issue | Cross-artifact consistency check fails |
| **NE-03**: Evidence missing Reviewed-At SHA detected | Remove Reviewed-At line from evidence.md | EV-05 fails |
| **NE-04**: Evidence contains both APPROVE and REJECT | Add both decisions to evidence.md | EV-04 fails (exactly one required) |

## 4. Pass/Fail Criteria

### Per-Scenario Pass Criteria
Each scenario passes if:
- Verification method can be executed
- Expected result matches actual observation
- No contradictory evidence found

### Overall Pass Criteria
REC-05 documentation passes if:
- All 25 positive scenarios (SC-01 to SC-06, PL-01 to PL-09, TP-01 to TP-08, EV-01 to EV-05, CC-01 to CC-05) pass
- All 4 negative scenarios (NE-01 to NE-04) correctly detect the injected defect
- No critical inconsistencies found

### Failure Handling
If any scenario fails:
1. Document the failure with specific location and expected vs actual
2. Return to Planner for correction
3. Re-run full verification after correction

## 5. Execution Method

### 5.1 Manual Review Process
This test plan is executed via **manual document review** by the Tester agent:

1. **Read all 4 files** in `specs/095-rec-05-governance-automation-audit/`
2. **Execute each scenario** by checking the specified sections
3. **Record results** in a verification checklist (can be inline in evidence.md or separate)
4. **For negative scenarios**: Temporarily modify files, verify detection, restore files

### 5.2 Automation Potential (Future)
The following could be automated in GOV-04 (Spec/Plan Quality Gates):
- `SC-01` through `SC-06`: Spec structure linting
- `PL-01` through `PL-09`: Plan structure linting
- `CC-01` through `CC-05`: Cross-artifact consistency checks
- `EV-01` through `EV-05`: Evidence schema validation

### 5.3 Tools Required
- Text editor / `cat` / `grep` for file inspection
- `git rev-parse HEAD` for SHA verification
- `ls` / `glob` for directory structure verification

## 6. Test Data / Fixtures

### 6.1 Reference Files (Repository State)
| File | Purpose |
|------|---------|
| `.github/workflows/governance.yml` | Ground truth for CI workflow inventory |
| `tests/governance/validators.py` | Ground truth for validator inventory |
| `scripts/merge_gate.py` | Ground truth for merge gate checks |
| `tests/governance/pr_enforcement.py` | Ground truth for PR enforcement flow |
| `AGENTS.md` §8 | Required issue template structure |

### 6.2 Audit Artifacts (Under Test)
| File | Role |
|------|------|
| `specs/095-rec-05-governance-automation-audit/spec.md` | Primary specification |
| `specs/095-rec-05-governance-automation-audit/plan.md` | Implementation plan |
| `specs/095-rec-05-governance-automation-audit/test-plan.md` | This test plan |
| `specs/095-rec-05-governance-automation-audit/evidence.md` | Completion evidence |

## 7. Traceability Matrix

| Requirement Source | Spec Section | Plan Section | Test Scenario |
|-------------------|--------------|--------------|---------------|
| AGENTS.md §8 (Issue Template) | §3 (GOV-01..04) | §4 (checklist) | SC-05, CC-03 |
| AGENTS.md §26 (Definition of Done) | §4 (Acceptance) | §8 (Success) | SC-06, PL-05, PL-09 |
| Issue #95 Description | §1 (Inventory), §2 (Gaps) | §2 (References) | SC-01..04, PL-02 |
| Issue #95 Deliverables | §4 (Criteria) | §1 (Overview) | SC-06, PL-01 |

## 8. Sign-Off

Test plan review complete when:

- [ ] All 33 scenarios defined with clear verification methods
- [ ] Coverage includes spec, plan, test-plan, evidence, cross-artifact, negative
- [ ] Execution method feasible for manual review
- [ ] Traceability to issue requirements established
- [ ] No production code test scenarios included (correctly scoped as documentation-only)

---

**Note**: This test plan itself is subject to the same verification scenarios it defines (TP-01 through TP-08). The Tester should apply the scenarios recursively to ensure test-plan.md meets its own quality bar.
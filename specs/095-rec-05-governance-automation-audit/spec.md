# REC-05: Governance Automation Audit Specification

## 1. Current Governance Inventory

### 1.1 CI Workflows (`.github/workflows/governance.yml`)

| Job | Trigger | Purpose |
|-----|---------|---------|
| `governance` | `pull_request`, `push` to `master` | Runs all governance unit tests: `python -m unittest discover -s tests/governance -p 'test_*.py' -v` |
| `pr-enforcement` | `pull_request` only | Runs PR enforcement orchestration: `python tests/governance/pr_enforcement.py` |

### 1.2 Governance Validators (`tests/governance/validators.py`)

| Validator | Purpose | Enforcement Point |
|-----------|---------|-------------------|
| `validate_commit_message` | Conventional Commits format (`<type>(<scope>): <subject>`) | PR enforcement |
| `validate_branch_name` | Branch naming `@carlosegoulart/{issue}/{type}/{description}` | PR enforcement, Merge gate |
| `validate_pr_body` | PR body structure: `Closes #N`, required sections (Summary, Scope, TDD Evidence, Tests) | PR enforcement |
| `validate_evidence_decision` | Evidence file contains exactly one of `Decision: APPROVE` or `Decision: REJECT` | PR enforcement |
| `validate_tdd_sections` | Evidence contains `### RED`, `### GREEN`, `### REFACTOR` headings OR explicit `TDD: N/A — <reason>` | PR enforcement |
| `validate_sdd_bundle` | Every `specs/NNN-*` directory contains non-empty `spec.md`, `plan.md`, `test-plan.md`, `evidence.md` | PR enforcement |
| `validate_merge_approval` | Evidence file contains `Decision: APPROVE` (rejects REJECT, missing, or both) | Merge gate |

### 1.3 Merge Gate (`scripts/merge_gate.py`)

Fail-closed deterministic merge gate validating:

1. **PR State**: Open, not draft, targeting `master`, mergeable
2. **Branch Naming**: Matches `@carlosegoulart/{issue}/{type}/{description}`
3. **Issue Reference**: Exactly one `Closes #N` in PR body, matches branch issue number
4. **Tester Approval**: Evidence file contains `Decision: APPROVE` (last decision wins)
5. **Required CI Checks** (5 checks):
   - `Backend CI / tests`
   - `Frontend CI / test`
   - `E2E CI / e2e`
   - `governance / governance`
   - `governance / pr-enforcement`

Dual-validation pattern: validates once for `--check`, re-validates before merge, blocks if `head_sha` changed.

### 1.4 PR Enforcement (`tests/governance/pr_enforcement.py`)

Orchestrates all validators against GitHub event payload:

- Validates all commit messages in PR (non-merge)
- Validates branch name from `GITHUB_HEAD_REF`
- Validates PR body structure and `Closes #N`
- Cross-validates branch issue number vs `Closes #N` issue number
- Finds and validates `evidence.md` for the issue
- Validates SDD bundle completeness across entire `specs/` directory

---

## 2. Four Audit Areas Analysis

### GOV-01: Diff Gate — Automated Scope Verification

**Current Gap**: No automated verification that PR diff stays within the declared issue scope. A PR could modify files unrelated to the active issue (e.g., touching `app/Http/Controllers/AuthController.php` while implementing a media worker feature) and pass all current checks.

**Risk**: Scope creep, unreviewed changes, architectural drift, security exposure from unauthorized modifications.

**Desired Behavior**: 
- PR enforcement validates that every changed file maps to the active issue's declared scope
- Scope definition lives in the issue's `plan.md` (e.g., `Affected Components` section)
- Fail PR if diff includes files outside declared scope
- Allow explicit override via `SCOPE_OVERRIDE: <reason>` in PR body for exceptional cases

**Implementation Approach**:
1. Extend `plan.md` schema with machine-readable `Affected Components` section (glob patterns)
2. Add `validate_diff_scope(plan_path, changed_files)` validator
3. Integrate into `pr_enforcement.py` after PR body validation
4. Use `git diff --name-only origin/master...HEAD` to get changed files

### GOV-02: Preflight Hooks — Local Developer Guardrails

**Current Gap**: All governance checks run only in CI. Developers discover violations after pushing, causing CI failures, rebase cycles, and delayed feedback.

**Risk**: Wasted CI minutes, context switching, frustration, potential for force-pushing to bypass checks.

**Desired Behavior**:
- `pre-commit` hook: validates commit message format, staged file linting
- `pre-push` hook: validates branch name, runs quick governance checks (commit messages, branch name, local evidence existence)
- Hooks installed via `make install-hooks` or `composer install`/`npm install` lifecycle
- Must be bypassable with `--no-verify` for emergency situations (audit logged)

**Implementation Approach**:
1. Create `.pre-commit-config.yaml` or shell-based hooks in `.git/hooks/`
2. `pre-commit`: `validate_commit_message` on staged commit, basic syntax checks
3. `pre-push`: `validate_branch_name`, verify local `evidence.md` exists, run `pr_enforcement.py` in `--dry-run` mode
3. Document installation in `CONTRIBUTING.md` and `README.md`

### GOV-03: Evidence Freshness — PR Head Alignment Verification

**Current Gap**: Merge gate validates evidence file exists and contains `APPROVE`, but does not verify the evidence corresponds to the **current PR head commit**. Evidence could be stale (approved for an earlier commit, then new commits pushed without re-review).

**Risk**: Merging code that was never reviewed/tested at the current HEAD. Tester approval becomes meaningless if commits are added post-approval.

**Desired Behavior**:
- Evidence file must reference the commit SHA it was reviewed against
- Merge gate compares evidence `Reviewed-At: <sha>` with PR `headRefOid`
- Block merge if SHA mismatch (requires Tester re-review)
- PR enforcement warns if evidence SHA doesn't match current HEAD

**Implementation Approach**:
1. Extend evidence.md schema: add `Reviewed-At: <sha>` line in Tester review section
2. Update `validate_merge_approval` to parse and verify `Reviewed-At` matches PR head SHA
3. Update `validate_evidence_decision` to require `Reviewed-At` when decision is APPROVE
4. Tester workflow: after review, run `git rev-parse HEAD` and record in evidence

### GOV-04: Spec/Plan Quality Gates — Beyond Existence Checks

**Current Gap**: `validate_sdd_bundle` only verifies file existence and non-emptiness. It does not validate:
- Spec completeness (acceptance criteria, security considerations, out-of-scope)
- Plan actionability (task breakdown, dependencies, test strategy)
- Test plan coverage (unit, integration, E2E, Playwright scenarios)
- Evidence completeness (RED/GREEN/REFACTOR with actual content, not placeholders)
- Internal consistency (issue ↔ spec ↔ plan ↔ test-plan ↔ evidence alignment)

**Risk**: "Paper compliance" — bundles exist but are low quality, missing critical sections, or internally inconsistent. Defeats the purpose of SDD.

**Desired Behavior**:
- `validate_spec_quality(spec_path)` — checks required sections, acceptance criteria count, security/UX considerations
- `validate_plan_quality(plan_path)` — checks task breakdown, dependencies, test strategy mapping
- `validate_test_plan_quality(test_plan_path)` — checks test type coverage, scenario specificity
- `validate_evidence_quality(evidence_path)` — checks RED/GREEN/REFACTOR have substantive content, not just headings
- `validate_cross_artifact_consistency(issue_dir)` — verifies issue number, scope, and acceptance criteria align across all four files
- Integrated into PR enforcement with configurable severity (warn → fail over time)

**Implementation Approach**:
1. Define quality rubrics as code (not just documentation)
2. Add quality validators to `validators.py`
3. Phase 1: Warning mode (CI passes, logs quality issues)
4. Phase 2: Fail mode after grace period
5. Provide `make spec-lint` for local quality checking

---

## 3. Four Follow-Up Issue Definitions

### GOV-01: Implement Diff Gate for Automated Scope Verification

**Title**: `feat(governance): implement diff gate for automated scope verification`

**Description**: Add automated verification that PR diffs stay within the declared issue scope by validating changed files against the issue's `plan.md` Affected Components declaration.

**Technical Tasks**:
- [ ] Extend `plan.md` schema with machine-readable `Affected Components` section (glob patterns)
- [ ] Implement `validate_diff_scope(plan_path: Path, changed_files: list[str]) -> list[str]` in `validators.py`
- [ ] Integrate diff scope validation into `pr_enforcement.py` after PR body validation
- [ ] Add `SCOPE_OVERRIDE: <reason>` PR body escape hatch for exceptional cases
- [ ] Update `validate_sdd_bundle` to verify `Affected Components` section exists in plan.md
- [ ] Add unit tests for diff scope validator (valid, invalid, override, missing section)
- [ ] Add integration test in `test_pr_enforcement.py` for diff scope enforcement
- [ ] Document scope declaration format in `docs/governance.md`

**Acceptance Criteria**:
- [ ] PR modifying files outside declared scope fails PR enforcement
- [ ] PR with valid `SCOPE_OVERRIDE` reason passes with warning logged
- [ ] Missing `Affected Components` section in plan.md fails validation
- [ ] All existing governance tests continue to pass

**Out of Scope**: IDE integration, automatic scope inference from code, historical scope analysis.

**Security Considerations**: Prevents unauthorized modifications to auth, payment, or infrastructure code. Override reason is auditable in PR history.

**Dependencies**: Requires plan.md schema change (backward compatible — missing section = fail).

---

### GOV-02: Install Preflight Hooks for Local Governance Checks

**Title**: `feat(governance): install preflight hooks for local governance checks`

**Description**: Add Git pre-commit and pre-push hooks that run governance validations locally before code reaches CI, reducing feedback loop from minutes to seconds.

**Technical Tasks**:
- [ ] Create `scripts/pre-commit` hook: validates commit message format, runs basic syntax checks on staged files
- [ ] Create `scripts/pre-push` hook: validates branch name, verifies local evidence.md exists, runs `pr_enforcement.py --dry-run`
- [ ] Add `make install-hooks` target that installs hooks to `.git/hooks/`
- [ ] Add `make uninstall-hooks` target for cleanup
- [ ] Document hook installation in `CONTRIBUTING.md` and `README.md`
- [ ] Ensure hooks respect `--no-verify` bypass (with audit log entry)
- [ ] Add CI job to verify hooks are present and executable
- [ ] Test hooks work on Linux, macOS, and Windows (Git Bash)

**Acceptance Criteria**:
- [ ] `make install-hooks` installs both hooks successfully
- [ ] Invalid commit message rejected at `git commit` time
- [ ] Invalid branch name rejected at `git push` time
- [ ] Missing local evidence.md warned at `git push` time
- [ ] `--no-verify` bypasses hooks but logs warning
- [ ] CI verifies hook installation on every PR

**Out of Scope**: Husky/pre-commit.com framework (prefer zero-dependency shell hooks), auto-fix capabilities, hook performance optimization beyond current scope.

**UX Considerations**: Hooks must complete in <5 seconds. Clear error messages with fix guidance. No false positives.

**Dependencies**: None.

---

### GOV-03: Enforce Evidence Freshness with Commit SHA Binding

**Title**: `feat(governance): enforce evidence freshness with commit SHA binding`

**Description**: Bind Tester approval to the specific commit SHA reviewed, preventing merge of commits added after approval without re-review.

**Technical Tasks**:
- [ ] Extend evidence.md schema: add `Reviewed-At: <sha>` in Tester review section
- [ ] Update `validate_merge_approval` to parse `Reviewed-At` and compare with PR `headRefOid`
- [ ] Update `validate_evidence_decision` to require `Reviewed-At` when decision is `APPROVE`
- [ ] Update merge gate `validate_tester_approval` to verify SHA match (block on mismatch)
- [ ] Add PR enforcement warning when evidence SHA != current HEAD
- [ ] Document Tester workflow: `git rev-parse HEAD` → record in evidence after review
- [ ] Add unit tests for SHA validation (match, mismatch, missing, malformed)
- [ ] Add integration test for stale evidence rejection in merge gate

**Acceptance Criteria**:
- [ ] Evidence without `Reviewed-At` fails merge gate when decision is APPROVE
- [ ] Evidence with mismatched `Reviewed-At` fails merge gate
- [ ] Evidence with matching `Reviewed-At` passes merge gate
- [ ] PR enforcement warns (not fails) on SHA mismatch for early feedback
- [ ] Tester workflow documented and tested

**Out of Scope**: Automatic SHA injection (manual step preserves intentionality), evidence signing/cryptographic verification.

**Security Considerations**: Prevents "approval replay" attacks. SHA binding is auditable in evidence history.

**Dependencies**: Requires Tester workflow change (documentation + habit).

---

### GOV-04: Implement Spec/Plan Quality Gates Beyond Existence Checks

**Title**: `feat(governance): implement spec/plan quality gates beyond existence checks`

**Description**: Add content quality validation for SDD artifacts (spec.md, plan.md, test-plan.md, evidence.md) to prevent "paper compliance" where files exist but lack substance.

**Technical Tasks**:
- [ ] Define quality rubrics as code in `validators.py`:
  - `validate_spec_quality(spec_path: Path) -> list[str]`
  - `validate_plan_quality(plan_path: Path) -> list[str]`
  - `validate_test_plan_quality(test_plan_path: Path) -> list[str]`
  - `validate_evidence_quality(evidence_path: Path) -> list[str]`
  - `validate_cross_artifact_consistency(issue_dir: Path) -> list[str]`
- [ ] Spec quality checks: required sections present, ≥3 acceptance criteria, security/UX considerations addressed
- [ ] Plan quality checks: ≥3 tasks, dependencies identified, test strategy mapped to acceptance criteria
- [ ] Test plan quality checks: unit/integration/E2E/Playwright coverage declared, scenarios specific
- [ ] Evidence quality checks: RED/GREEN/REFACTOR have substantive content (>50 chars each), no placeholder text
- [ ] Cross-artifact consistency: issue number matches, acceptance criteria ↔ test scenarios traceable, scope aligned
- [ ] Integrate quality validators into `pr_enforcement.py` (Phase 1: warning mode)
- [ ] Add `make spec-lint` target for local quality checking
- [ ] Add comprehensive unit tests for each quality validator
- [ ] Document quality rubrics in `docs/governance.md`

**Acceptance Criteria**:
- [ ] `make spec-lint` reports quality issues for current specs
- [ ] PR enforcement logs quality warnings (Phase 1) without failing CI
- [ ] All quality validators have unit tests with valid/invalid fixtures
- [ ] Cross-artifact consistency catches mismatched issue numbers and orphaned acceptance criteria
- [ ] Documentation enables developers to self-assess before PR

**Out of Scope**: AI-assisted quality scoring, automatic spec generation, historical quality trends.

**Dependencies**: Phase 2 (fail mode) requires team calibration period (2-3 sprints) after Phase 1 deploy.

---

## 4. Acceptance Criteria for REC-05

REC-05 is complete when:

- [ ] **spec.md** created with current governance inventory, four audit areas analysis, four follow-up issue definitions
- [ ] **plan.md** created documenting documentation updates and evidence file creation (no code changes)
- [ ] **test-plan.md** created with verification scenarios for the audit documentation
- [ ] All three files placed in `/workspaces/AiClip/specs/095-rec-05-governance-automation-audit/`
- [ ] `evidence.md` created documenting the audit completion
- [ ] No production code changes made (this is a documentation/planning issue only)
# Implementation Plan — Issue #127

## Objective

Implement a deterministic spec-plan quality validator that enforces a versioned objective contract over `spec.md`, `plan.md`, and `test-plan.md`, detecting missing files/sections, placeholders, empty sections, non-objective acceptance criteria, and contradictory requirements, without rewriting specs or making subjective implementation decisions. Follow SDD/TDD.

## Phase 1: Quality contract and validator tests (TDD RED)

### Step 1: Write RED unit tests for `scripts/spec_plan_quality.py`

Create `tests/governance/test_spec_plan_quality.py` covering scenarios Q1–Q19 from the spec. Tests fail because `scripts/spec_plan_quality.py` does not exist yet (import/execution errors).

Test cases (RED expectations):

- Valid compliant bundle (all required files, sections, objective criteria) → no errors, exit 0.
- Missing spec.md → error naming missing file.
- Missing plan.md → error naming missing file.
- Missing test-plan.md → error naming missing file.
- Empty spec.md → error naming file and "empty".
- spec.md missing required section → error naming missing section.
- plan.md missing required section → error naming missing section.
- test-plan.md missing required section → error naming missing section.
- Placeholder content (TODO/FIXME/TBD) → error naming file, section, line.
- Empty required section → error naming file and section.
- Acceptance criteria not in `- [ ]` format → error naming file and criterion.
- Acceptance criteria with untestable subjective adjectives → error naming file and criterion.
- Contradictory acceptance criteria → error naming file and criteria.
- Contract version `spec-plan-quality v1.0` reported in output.
- Validator is read-only (no file modification).
- No secrets read or printed.
- Existing governance tests still pass (no regression).

### Step 2: Implement pure validator functions

Create deterministic pure functions:

- `load_bundle(specs_dir, issue_number)` — resolves `specs/{NNN-slug}/` and locates spec.md, plan.md, test-plan.md.
- `check_required_files(files)` — verifies all three required files exist as non-empty regular files.
- `check_required_sections(content, required_sections)` — verifies case-insensitive headings present.
- `detect_placeholders(content)` — flags TODO/FIXME/TBD/XXX/lorem ipsum outside code fences.
- `detect_empty_sections(content, required_sections)` — flags required headings with no substantive body.
- `check_acceptance_criteria(content)` — verifies `- [ ]` checklist format and flags non-objective subjective adjectives.
- `detect_contradictions(criteria_list)` — best-effort lexical opposites on same subject.
- `validate_bundle(specs_dir, issue_number)` — aggregates all checks; returns errors with file/section/line diagnostics.
- `run_spec_plan_quality(specs_dir, issue_number)` — entry point returning errors + exit code; reports contract version.

No file writes, no network, no secrets, no `.env` access.

## Phase 2: Single command entry point

### Step 3: Wire `python scripts/spec_plan_quality.py`

Add `if __name__ == "__main__": main()` that prints violations with remediation and exits non-zero on any failure. Prints "Spec-plan quality checks passed (spec-plan-quality v1.0)" on exit 0.

## Phase 3: Fixtures

### Step 4: Add valid and invalid fixtures

Create fixture bundles under `specs/127-governance-spec-plan-quality/fixtures/`:

- `fixtures/valid/{spec.md, plan.md, test-plan.md}` — compliant bundle used by happy-path tests.
- `fixtures/invalid/missing-sections/spec.md` — missing required sections.
- `fixtures/invalid/placeholder/spec.md` — contains TODO/FIXME placeholders.
- `fixtures/invalid/empty-section/plan.md` — empty required section.
- `fixtures/invalid/subjective-criteria/test-plan.md` — non-objective criteria.
- `fixtures/invalid/contradictory/spec.md` — contradictory acceptance criteria.

Fixtures are read-only test data; they are not applied to real issue bundles.

## Phase 4: CI integration

### Step 5: Integrate into preflight/governance CI

Wire `python scripts/spec_plan_quality.py` into the governance CI workflow (or preflight) as an authoritative check for the active issue's spec bundle. Keep CI authoritative; do not duplicate unrelated policy (branch names, commit messages, evidence freshness remain separate checks).

## Phase 5: Verification and regression

### Step 6: Execute RED → GREEN → REFACTOR

- Run `tests/governance/test_spec_plan_quality.py` → RED (fail, module missing).
- Implement `scripts/spec_plan_quality.py` → GREEN (all new tests pass).
- Refactor for clarity/consistency with `tests/governance/validators.py`; all tests remain green.
- Run full governance suite `python -m unittest discover -s tests/governance -p 'test_*.py' -v` → no regression.

### Step 7: Builder writes evidence.md

Builder creates `specs/127-governance-spec-plan-quality/evidence.md` with TDD sections (RED, GREEN, REFACTOR) and a valid `Decision: APPROVE` line. Planner does not write this file.

### Step 8: Document legacy exceptions

Document any legacy bundle exceptions in this spec's Out of Scope / Dependencies rather than silently bypassing validation.

## Expected Final State

- `scripts/spec_plan_quality.py` — deterministic spec-plan quality validator.
- `tests/governance/test_spec_plan_quality.py` — unit tests covering Q1–Q19.
- `specs/127-governance-spec-plan-quality/fixtures/` — valid and invalid example bundles.
- `specs/127-governance-spec-plan-quality/evidence.md` — written by Builder/Tester.
- All governance + spec-plan quality tests pass; no regression.
- No application code introduced.
- Validator is read-only; no files modified automatically.
- Merge gate not weakened; agent permissions unchanged.
- Contract version `spec-plan-quality v1.0` documented and reported.

## Commits (Conventional Commits, Refs #127)

- `feat(governance): add spec-plan quality validator` Refs #127
- `test(governance): add spec-plan quality unit tests` Refs #127
- `test(governance): add spec-plan quality fixtures` Refs #127
- `docs(spec): record spec-plan quality contract` Refs #127

## Verification

- `PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v` passes.
- `python scripts/spec_plan_quality.py` exits 0 on compliant bundle; non-zero with file/section/line diagnostics on violation.
- `git diff --exit-code` confirms validator modifies no files.
- No secrets read or printed.
- CI governance workflow green.
- Merge gate behavior unchanged (fail-closed preserved).

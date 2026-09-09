# Implementation Plan — Issue #7

## Objective

Implement a small Python validation layer for governance enforcement, integrated with existing tests and CI.

## Phase 1: Validators (TDD)

### Step 1: Write RED tests for `validate_commit_message`

Create `tests/governance/test_enforcement.py` with tests that will fail because `validators.py` doesn't exist yet.

Test cases:
- Valid: `feat(clips): generate ranked clip candidates`
- Valid: `docs(architecture): resolve MVP inconsistencies`
- Valid: `ci(playwright): upload traces on test failure`
- Invalid: `docs: resolve MVP inconsistencies` (no scope)
- Invalid: `feat(): empty scope`
- Invalid: `feat(Scope): uppercase scope`
- Invalid: `random(text): invalid type`
- Invalid: `feat(clips): ` (empty subject)
- Invalid: empty string

### Step 2: Implement `validate_commit_message`

Create `tests/governance/validators.py` with the commit message validator.

Regex: `^(feat|fix|chore|refactor|docs|test|perf|ci)\([a-z0-9][a-z0-9-]*\): .+`

Return `[]` for valid, `["error message"]` for invalid.

### Step 3: Write RED tests for `validate_branch_name`

Test cases:
- Valid: `@carlosegoulart/07/chore/governance-enforcement`
- Valid: `@carlosegoulart/15/feat/audio-chunk-manager`
- Valid: `@carlosegoulart/01/chore/init-project-structure`
- Invalid: `feat/branch-without-prefix`
- Invalid: `@carlosegoulart/abc/chore/desc` (non-numeric issue)
- Invalid: `@carlosegoulart/7/random/desc` (bad type)
- Invalid: `@carlosegoulart/7/chore/Description-With-Uppercase`
- Invalid: `@carlosegoulart/7/chore/has_underscore`

### Step 4: Implement `validate_branch_name`

Regex: `^@carlosegoulart/(\d+)/(feat|fix|chore|refactor|docs|test)/([a-z0-9][a-z0-9-]*)$`

### Step 5: Write RED tests for `validate_pr_body`

Test cases:
- Valid: Body with `Closes #7` + all required sections
- Invalid: Body missing `Closes #N`
- Invalid: Body missing `## TDD Evidence`
- Valid: Body with `closes #7` (lowercase)
- Invalid: empty body

### Step 6: Implement `validate_pr_body`

Check for:
- `Closes #N` pattern (case-insensitive)
- `## Summary` heading
- `## Scope` heading
- `## TDD Evidence` heading
- `## Tests` heading

### Step 7: Write RED tests for `validate_evidence_decision`

Test cases:
- Valid: Contains `Decision: APPROVE`
- Valid: Contains `Decision: REJECT`
- Invalid: Contains both APPROVE and REJECT
- Invalid: No decision line

### Step 8: Implement `validate_evidence_decision`

Check for exactly one of `Decision: APPROVE` or `Decision: REJECT`.

### Step 9: Write RED tests for `validate_tdd_sections`

Test cases:
- Valid: Contains `### RED`, `### GREEN`, `### REFACTOR`
- Valid: Contains `TDD: N/A` with reason
- Invalid: No TDD sections, no N/A

### Step 10: Implement `validate_tdd_sections`

Check for either all three TDD headings or explicit N/A.

## Phase 2: CI Integration

### Step 11: Extend `.github/workflows/governance.yml`

Add a step that:
1. Detects if running in PR context (env vars present)
2. When in PR context, extracts commit messages, branch name, PR body
3. Runs validators against extracted data
4. Fails CI if any validator returns errors

Environment variables to use:
- `GITHUB_HEAD_REF` — PR branch name
- `GITHUB_BASE_REF` — target branch
- Commit messages via `git log` on PR commits

### Step 12: Test CI integration locally

Verify the workflow logic works with mock data.

## Phase 3: Evidence and State

### Step 13: Write evidence file

Create `specs/007-governance-enforcement-automation/evidence.md` with:
- Structured TDD sections (RED, GREEN, REFACTOR)
- `Decision: APPROVE`
- Implementation summary

### Step 14: Update `docs/project-state.md`

Add completed capability for governance enforcement.

## Expected Final State

- `tests/governance/validators.py` — Five validator functions
- `tests/governance/test_enforcement.py` — 25+ test methods
- `.github/workflows/governance.yml` — Extended with PR validation
- `docs/project-state.md` — Updated
- All governance tests pass
- No application code introduced

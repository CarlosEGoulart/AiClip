# Test Plan — Issue #7

## Overview

Test scenarios for validating the governance enforcement validators. This is test infrastructure work, so tests validate the validators themselves.

## Test Scenarios

### T1: Commit Message Validator

**Valid inputs:**
- `feat(clips): generate ranked clip candidates`
- `docs(architecture): resolve MVP inconsistencies`
- `ci(playwright): upload traces on test failure`
- `fix(auth): preserve csrf session`
- `chore(deps): update pyyaml`
- `refactor(media): isolate ffmpeg command builder`
- `test(api): add health endpoint coverage`
- `perf(clips): optimize ranking algorithm`

**Invalid inputs:**
- `docs: resolve MVP inconsistencies` (no scope)
- `feat(): empty scope`
- `feat(Scope): uppercase scope`
- `random(text): invalid type`
- `feat(clips): ` (empty subject)
- empty string
- `feat(clips):subject` (missing space after colon)

### T2: Branch Name Validator

**Valid inputs:**
- `@carlosegoulart/07/chore/governance-enforcement`
- `@carlosegoulart/15/feat/audio-chunk-manager`
- `@carlosegoulart/01/chore/init-project-structure`
- `@carlosegoulart/31/feat/dashboard-grid`

**Invalid inputs:**
- `feat/branch-without-prefix`
- `@carlosegoulart/abc/chore/desc` (non-numeric issue)
- `@carlosegoulart/7/random/desc` (bad type)
- `@carlosegoulart/7/chore/Description-With-Uppercase`
- `@carlosegoulart/7/chore/has_underscore`
- `@carlosegoulart/7/chore/has space`

### T3: PR Body Validator

**Valid inputs:**
- Body with `Closes #7` + all required sections
- Body with `closes #7` (lowercase)
- Body with `CLOSES #7` (uppercase)

**Invalid inputs:**
- Body missing `Closes #N`
- Body missing `## TDD Evidence`
- Body missing `## Summary`
- empty body

### T4: Evidence Decision Validator

**Valid inputs:**
- Contains `Decision: APPROVE`
- Contains `Decision: REJECT`

**Invalid inputs:**
- Contains both APPROVE and REJECT
- No decision line
- `Decision: PENDING`

### T5: TDD Sections Validator

**Valid inputs:**
- Contains `### RED`, `### GREEN`, `### REFACTOR`
- Contains `TDD: N/A — governance-only change`
- Contains `N/A — documentation-only work`

**Invalid inputs:**
- No TDD sections, no N/A
- Only `### RED` (missing GREEN and REFACTOR)

### T6: Existing Governance Tests

Verify all 9 existing tests in `test_governance.py` continue to pass.

### T7: CI Workflow Validation

Verify `.github/workflows/governance.yml` has correct structure for PR validation.

### T8: Language Validation

Verify all test files are in English.

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue.

## Test Execution

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Document reason: This issue adds test infrastructure for governance enforcement; no application behavior exists to test with E2E or Playwright.

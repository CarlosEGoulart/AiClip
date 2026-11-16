# Test Plan — Issue #125

## Overview

Unit tests for the deterministic preflight validation command. This is governance/test infrastructure work; no application or UI changes exist in this issue, so Playwright/E2E validation is N/A.

## Test Scenarios

### T1: Branch/issue association (Rule P1)

**Valid inputs:**
- `@carlosegoulart/125/feat/governance-preflight-hooks` with existing `specs/125-governance-preflight-hooks/` → pass
- `@carlosegoulart/01/chore/init-project-structure` with existing bundle → pass

**Invalid inputs:**
- `feat/branch-without-prefix` → fail, names P1
- `@carlosegoulart/abc/chore/desc` (non-numeric issue) → fail, names P1
- `@carlosegoulart/7/random/desc` (bad type) → fail, names P1
- `@carlosegoulart/7/chore/Description-With-Uppercase` → fail, names P1
- `@carlosegoulart/7/chore/has_underscore` → fail, names P1
- `@carlosegoulart/7/chore/has space` → fail, names P1
- empty branch name → fail, names P1
- `@carlosegoulart/999/feat/nonexistent` (no matching specs dir) → fail, names P1

### T2: Required spec bundle (Rule P2)

**Valid inputs:**
- spec.md, plan.md, test-plan.md all present and non-empty → pass

**Invalid inputs:**
- missing spec.md → fail, names P2 and missing file
- missing plan.md → fail, names P2 and missing file
- missing test-plan.md → fail, names P2 and missing file
- empty spec.md → fail, names P2 and "empty"

`evidence.md` is intentionally not required (produced during lifecycle).

### T3: Working-tree hygiene (Rule P3)

**Valid inputs:**
- clean working tree → pass

**Invalid inputs:**
- unrelated modified tracked file → fail, names P3 and the file
- unrelated untracked file → fail, names P3 and the file

### T4: Generated artifacts (Rule P4)

**Valid inputs:**
- no build artifacts present → pass

**Invalid inputs:**
- `node_modules/` present → fail, names P4
- `__pycache__/` present → fail, names P4
- `dist/` or `build/` present → fail, names P4
- `*.pyc` present → fail, names P4
- `.pytest_cache/` present → fail, names P4

### T5: Required metadata / commit messages (Rule P5)

**Valid inputs:**
- `feat(governance): add preflight hooks` → pass
- `fix(governance): correct branch check` → pass
- `docs(project-state): record preflight capability` → pass

**Invalid inputs:**
- `fix governance` (no scope) → fail, names P5
- `feat(governance): ` (empty subject) → fail, names P5
- `random(text): invalid type` → fail, names P5
- empty commit message → fail, names P5

### T6: Single command behavior

- `python scripts/preflight.py` exits 0 and prints "Preflight checks passed" when all rules pass.
- `python scripts/preflight.py` exits non-zero and prints each failing rule + remediation when any rule fails.
- Command modifies no files.
- Command reads/prints no secrets.

### T7: No conflicts with AGENTS.md or CI

- Preflight contract does not alter agent permissions.
- CI remains authoritative; local preflight optional.

### T8: Existing governance tests

- All existing tests in `tests/governance/` continue to pass.

### T9: Language validation

- All test files and scripts are in English.

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue.

## Test Execution

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v
python scripts/preflight.py
```

Document reason: This issue adds governance preflight infrastructure; no application behavior exists to test with E2E or Playwright.

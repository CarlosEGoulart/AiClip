# Test Plan — Issue #128

## Overview

Documentation-only governance issue: sync `README.md` and `docs/roadmap.md` with `docs/project-state.md`. No application code or UI changes exist in this issue, so application unit/API, E2E, Playwright, visual, and accessibility interaction are N/A (reason: documentation validation only). Tests are deterministic documentation-consistency checks plus the existing governance suite. No network, no `gh`, no git execution required for unit level.

## Test Scenarios

### D1: README milestone table matches docs/project-state.md

**Input:** `README.md` milestone table.
**Expected:**
- M7 Slice 2 marked completed with Issue #110 / PR #111 evidence.
- M8 Slices 1–2 marked completed with Issue #113/#115, PR #114/#116 evidence.
- M9 marked completed with Issue #118 / PR #130 evidence.
- M14 Production Hardening listed as CI green/mergeable, not closed (Issue #123, PR #141).

### D2: roadmap.md M7 section includes Slice 2

**Input:** `docs/roadmap.md` M7 section.
**Expected:** Slice 1 includes Issue #106 / PR #107; Slice 2 includes Issue #110 / PR #111 (merged via `scripts/merge_gate.py`).

### D3: roadmap.md M9 marked completed

**Input:** `docs/roadmap.md`.
**Expected:** M9 Social Connection Framework marked completed (Issue #118, PR #130), not listed as pending under Planned Milestones.

### D4: roadmap.md Planned Milestones table has no duplicate M14 entries

**Input:** `docs/roadmap.md` Planned Milestones table.
**Expected:** Single coherent planned-milestones listing; M10/M11/M12 publishing and Production Hardening marked planned/not implemented; no duplicate M14 rows.

### D5: Future work explicitly labeled planned/not implemented

**Input:** `README.md` and `docs/roadmap.md`.
**Expected:** Remaining M8/M7/M6 slices and M10/M11/M12 publishing are described as future work requiring separate explicit authorization; no implication that they are implemented.

### D6: No production-readiness or release claims for future/unimplemented items

**Input:** `README.md` and `docs/roadmap.md`.
**Expected:** No statement that a release is production-ready or that future roadmap items are implemented, absent evidence.

### D7: Repository language policy (English only)

**Input:** `README.md`, `docs/roadmap.md`, and all new spec-bundle files.
**Expected:** All content in English; no Portuguese artifacts (AGENTS.md §19).

### D8: Governance test suite passes (no SDD bundle regression)

**Input:** Full `tests/governance/` suite including `validate_sdd_bundle`.
**Expected:** All existing tests pass; `specs/128-governance-roadmap-readme-sync/` bundle (`spec.md`, `plan.md`, `test-plan.md`) is valid and non-empty; no new errors introduced.

### D9: Each completed milestone links to traceable issue/PR evidence

**Input:** `README.md` and `docs/roadmap.md`.
**Expected:** M7 Slice 2 → Issue #110 / PR #111; M8 Slice 1 → Issue #113 / PR #114; M8 Slice 2 → Issue #115 / PR #116; M9 → Issue #118 / PR #130; M14 → Issue #123 / PR #141.

## Acceptance Criteria Coverage

| AC | Covered by |
|----|------------|
| README milestone table agrees with project-state.md | D1 |
| Each completed milestone links to issue/PR evidence | D9 |
| Future work marked planned/not implemented | D5 |
| No unsupported completion/test/security/production-readiness claims | D6 |
| README and roadmap agree with authoritative project-state records | D1–D4 |
| Documentation checks and governance tests pass | D8 |
| English-only repository content preserved | D7 |

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue. Governance/documentation only.

## Test Execution

```bash
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Plus deterministic documentation-consistency checks (README/roadmap vs. docs/project-state.md) covering D1–D9.

## Document Reason

This issue is documentation-only governance sync; no application behavior exists to test with E2E or Playwright.

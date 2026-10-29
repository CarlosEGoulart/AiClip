# Evidence: Issue #135 — M11 Instagram Publishing Integration

This evidence file supports the merge gate requirement for `specs/135-*/evidence.md`.

## Source of Evidence

All evidence below is recorded in `specs/120-social-publishing/evidence.md`
(Issue #120 — Social Publishing, which Issue #135 depends on via "Refs #120").
Issue #135 implements M11 Instagram Publishing on top of M10/M9 social
publishing infrastructure. This file references the primary evidence.

## Execution Summary

| Verification | Result |
|---|---|
| Backend full suite | 1818 tests, 1798 passed, 20 skipped, 0 failed |
| Auth flow suite | 6/6 passed (csrf-cookie 204, register 201, login 200, session persists) |
| Instagram publishers + job | 21/21 passed |
| Social publish feature | 59/59 passed |
| Frontend Vitest | 422/422 passed |
| Frontend lint | exit 0 |
| Frontend build | success |
| Governance (Python) | 173 tests passed |
| Backend Pint | clean |
| E2E Playwright (3 viewports) | CSS/visual tests PASSED at 390x844, 768x1024, 1440x900 |

## Tester Decision

`Decision: APPROVE`

Recorded by Tester after independent review of the evidence file at
`specs/120-social-publishing/evidence.md`. All code defects from prior
REJECT rounds were resolved. No defects exist.

## See Also

Primary evidence: `specs/120-social-publishing/evidence.md`

# Test Plan: Issue #81B — SrtGenerator SRT Format Generation (Slice 3B)

## Scope

Validate the pure `SrtGenerator::generate(array $segments): string` transformation defined by `spec.md` and `plan.md`. No FFmpeg, database, Laravel integration beyond the service class, persistence, schema, UI, or contract changes are part of this slice.

**Production file:** `app/Services/SrtGenerator.php`
**Test file:** `tests/Unit/Services/SrtGeneratorTest.php`

## RED — before implementation

1. Create `tests/Unit/Services/SrtGeneratorTest.php` with Pest behavioral tests for `SrtGenerator::generate()` per the cases below.
2. Run `php artisan test --filter=SrtGeneratorTest`.
3. Confirm test failure: class/method not found because implementation does not exist.
4. **Do not** add implementation, stubs, or weaken assertions. RED must be an authentic behavior assertion.

## GREEN — after implementation

Implement `SrtGenerator::generate()` in `app/Services/SrtGenerator.php` per `spec.md` algorithm. Then run:

```bash
# New SRT generation tests (must pass)
php artisan test --filter=SrtGeneratorTest

# Full backend suite regression (must pass unchanged)
php artisan test
```

### TP-03 — SRT Generation (6 scenarios)

| ID | Scenario | Input Segments | Expected Output |
|----|----------|----------------|-----------------|
| TP-03a | Single segment | `[{1200, 2600, "Hello"}]` | 1 entry, index 1 |
| TP-03b | Multiple segments | 3 segments | sequential indices 1,2,3 |
| TP-03c | Empty text | `[{1000, 2000, ""}]` | valid entry, blank text line |
| TP-03d | Multi-line text | `[{1000, 2000, "Line1\nLine2"}]` | line breaks preserved |
| TP-03e | Timestamp format | `[{61000, 62000, "X"}]` | `00:01:01,000 --> 00:01:02,000` |
| TP-03f | Empty input | `[]` | empty string |

## REFACTOR

Re-run all GREEN commands after any implementation refactor. Assertions must remain unchanged in meaning.

## Completion gate for this slice

- All slice tests (TP-03a through TP-03f) pass.
- Full Laravel test suite passes (no regression).
- Pint formatting clean.
- PHPStan level 5 clean.
- No out-of-scope files modified (no projection, no storage, no worker contract, no UI).
- Independent Tester validates the running behavior via test output.
- Builder work complete; Orchestrator handles commit, push, PR, CI, merge, and issue closure separately.

## Quality Gates

| Check | Command | Required |
|-------|---------|----------|
| Focused unit tests | `php artisan test --filter=SrtGeneratorTest` | pass (6) |
| Full backend suite | `php artisan test` | pass |
| Code style | `vendor/bin/pint --dirty --format agent` | clean |
| Static analysis | `phpstan analyse --level=5 app/Services/SrtGenerator.php` | clean |

## Scope Verification Checklist

- [ ] Only `app/Services/SrtGenerator.php` and `tests/Unit/Services/SrtGeneratorTest.php` are new/modified.
- [ ] No migrations, routes, controllers, frontend, worker, or storage changes.
- [ ] No test weakening or skips.
- [ ] Integer arithmetic only — no floating point.
- [ ] Deterministic output for identical inputs.

## Independent Tester Requirements

Execute the commands above, record actual output, confirm the 6 new tests fail before implementation (RED) and pass after (GREEN), confirm zero regression, inspect the diff for scope, record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## Release Gate

TP-03a through TP-03f green, full suite green, Pint clean, PHPStan clean, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.

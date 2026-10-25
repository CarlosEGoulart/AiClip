# Test Plan: Issue #81A — CaptionProjection Pure Function (Slice 3A)

## Scope

Validate the pure `CaptionProjection::project(array $segments, int $clipStartMs, int $clipEndMs): array` transformation defined by `spec.md` and `plan.md`. No FFmpeg, database, Laravel integration beyond the service class, persistence, schema, UI, or contract changes are part of this slice.

**Production file:** `app/Services/CaptionProjection.php`
**Test file:** `tests/Unit/Services/CaptionProjectionTest.php`

## RED — before implementation

1. Create `tests/Unit/Services/CaptionProjectionTest.php` with Pest behavioral tests for `CaptionProjection::project()` per the cases below.
2. Run `php artisan test --filter=CaptionProjectionTest`.
3. Confirm test failure: class/method not found because implementation does not exist.
4. **Do not** add implementation, stubs, or weaken assertions. RED must be an authentic behavior assertion.

## GREEN — after implementation

Implement `CaptionProjection::project()` in `app/Services/CaptionProjection.php` per `spec.md` algorithm. Then run:

```bash
# New projection tests (must pass)
php artisan test --filter=CaptionProjectionTest

# Full backend suite regression (must pass unchanged)
php artisan test
```

### TP-02 — Segment Projection (12 scenarios)

| ID | Scenario | Input Segments | Clip Range | Expected Output |
|----|----------|----------------|------------|-----------------|
| TP-02a | Normal projection | `[{11200, 12600, "Hello"}]` | 10000-15000 | `[{1200, 2600, "Hello"}]` |
| TP-02b | Segment before clip | `[{5000, 6000, "Before"}]` | 10000-15000 | `[]` |
| TP-02c | Segment after clip | `[{20000, 21000, "After"}]` | 10000-15000 | `[]` |
| TP-02d | Partial overlap start | `[{9000, 11000, "Start"}]` | 10000-15000 | `[{0, 1000, "Start"}]` |
| TP-02e | Partial overlap end | `[{14000, 20000, "End"}]` | 10000-15000 | `[{4000, 5000, "End"}]` |
| TP-02f | Empty input | `[]` | 10000-15000 | `[]` |
| TP-02g | Determinism | Same as TP-02h | Same | 3 identical calls → identical output |
| TP-02h | Mixed segments | 5 segments (before, start-overlap, inside, end-overlap, after) | 10000-15000 | 3 segments (clamped start, normal, clamped end) |
| TP-02i | Zero-duration clip | `[{10000, 11000, "X"}]` | 10000-10000 | `[]` |
| TP-02j | Segment exactly at bounds | `[{10000, 10000, "X"}, {15000, 15000, "Y"}]` | 10000-15000 | `[]` |
| TP-02k | Empty text preserved | `[{11000, 12000, ""}]` | 10000-15000 | `[{1000, 2000, ""}]` |
| TP-02l | Integer arithmetic only | `[{10001, 10003, "X"}]` | 10000-15000 | `[{1, 3, "X"}]` (ints) |

## REFACTOR

Re-run all GREEN commands after any implementation refactor. Assertions must remain unchanged in meaning.

## Completion gate for this slice

- All slice tests (TP-02a through TP-02l) pass.
- Full Laravel test suite passes (no regression).
- Pint formatting clean.
- PHPStan level 5 clean.
- No out-of-scope files modified (no SRT, no storage, no worker contract, no UI).
- Independent Tester validates the running behavior via test output.
- Builder work complete; Orchestrator handles commit, push, PR, CI, merge, and issue closure separately.

## Quality Gates

| Check | Command | Required |
|-------|---------|----------|
| Focused unit tests | `php artisan test --filter=CaptionProjectionTest` | pass (12) |
| Full backend suite | `php artisan test` | pass |
| Code style | `vendor/bin/pint --dirty --format agent` | clean |
| Static analysis | `phpstan analyse --level=5 app/Services/CaptionProjection.php` | clean |

## Scope Verification Checklist

- [ ] Only `app/Services/CaptionProjection.php` and `tests/Unit/Services/CaptionProjectionTest.php` are new/modified.
- [ ] No migrations, routes, controllers, frontend, worker, or storage changes.
- [ ] No test weakening or skips.
- [ ] Integer arithmetic only — no floating point.
- [ ] Deterministic output for identical inputs.

## Independent Tester Requirements

Execute the commands above, record actual output, confirm the 12 new tests fail before implementation (RED) and pass after (GREEN), confirm zero regression, inspect the diff for scope, record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## Release Gate

TP-02a through TP-02l green, full suite green, Pint clean, PHPStan clean, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.

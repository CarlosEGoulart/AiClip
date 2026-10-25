# Plan: Issue #81A — CaptionProjection Pure Function (Slice 3A)

## Active Scope and Baseline
Issue [#81A](https://github.com/CarlosEGoulart/AiClip/issues/81A), branch `@carlosegoulart/81A/feat/caption-projection`, based on merged `master` (Slice 2 worker projection present). Pure PHP service only; no database, FFmpeg, storage, worker contract, or UI changes.

## Stage 1 — RED: Write and run behavioral tests (expect failure)

1. Create `tests/Unit/Services/CaptionProjectionTest.php` with Pest tests covering all TP-02 scenarios (TP-02a through TP-02l).
2. Run `php artisan test --filter=CaptionProjectionTest`; expect all 12 tests to fail because `app/Services/CaptionProjection.php` does not exist.
3. **Do not** add implementation, stubs, or weaken assertions. RED must be an authentic behavior assertion.
4. Existing test suites must still pass (regression baseline confirmed before implementation).

### Targeted RED command
```bash
php artisan test --filter=CaptionProjectionTest
```

### Stop condition
- `CaptionProjectionTest.php` exists and contains tests for all 12 TP-02 scenarios
- Running the test file fails because `CaptionProjection` class or `project()` method is not defined
- No implementation code has been added to mask the failure
- Existing test suites still pass

---

## Stage 2 — GREEN: Implement only the projection function

1. Add `app/Services/CaptionProjection.php` with `CaptionProjection::project()` static method per `spec.md` algorithm.
2. Pure PHP — no FFmpeg, no database, no subprocess, no side effects, no I/O.
3. Integer arithmetic only — no floating point.
4. Run `php artisan test --filter=CaptionProjectionTest`; expect all 12 tests pass.
5. Run full Laravel test suite for regression.

### Targeted GREEN commands
```bash
# New projection tests (expect pass)
php artisan test --filter=CaptionProjectionTest

# Full backend suite regression
php artisan test
```

### Stop condition
- `CaptionProjection::project()` implemented in `app/Services/CaptionProjection.php`
- All 12 TP-02 tests pass
- Full Laravel test suite passes without modification (regression gate)
- No other test suites required for this slice
- No out-of-scope files modified

---

## Stage 3 — REFACTOR (if needed)

1. Improve implementation quality without altering behavior.
2. Run `vendor/bin/pint --dirty --format agent` for code style.
3. Run `phpstan analyse --level=5 app/Services/CaptionProjection.php` for static analysis.
4. Re-run Stage 2 GREEN commands to confirm all tests remain green.

### Stop condition
- All Stage 2 tests still pass
- Pint formatting clean
- PHPStan level 5 clean
- Code follows existing Laravel service patterns
- No out-of-scope changes introduced

---

## Notes on scope boundaries

**This slice does NOT include:**

- SRT generation (Slice 3B)
- SRT storage (Slice 3C)
- Worker contract integration (Slice 3C)
- RenderMediaClip job wiring (Slice 3D)
- End-to-end integration (Slice 3E)
- Database schema changes
- API endpoints
- UI changes
- Multiple caption tracks/languages
- Caption styling/customization
- Python worker changes
- FFmpeg invocation
- Commit, push, PR creation, CI monitoring, merge, or issue closure

These lifecycle operations belong to the Orchestrator and later slices. Builder stops after Stage 3 with a working, tested function.

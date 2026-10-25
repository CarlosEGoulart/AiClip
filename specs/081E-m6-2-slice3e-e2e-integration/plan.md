# Plan: Issue #81E — E2E Integration — Caption Flow End-to-End Test (Slice 3E)

## Active Scope and Baseline
Issue [#81E](https://github.com/CarlosEGoulart/AiClip/issues/81E), branch `@carlosegoulart/81E/test/caption-flow-e2e`, based on merged `master` (Slices 3A–3D complete). Test-only slice; no production code changes. Requires E2E test infrastructure (MinIO, FFmpeg, Python worker, FFprobe) available in CI.

## Stage 1 — RED: Write E2E tests (expect failure)

1. Create E2E test file(s) covering TP-01 through TP-07 scenarios (7 scenarios).
2. Run E2E test suite; expect caption-related tests to fail because E2E tests do not yet exist or infrastructure not wired.
3. **Do not** add production implementation or weaken assertions. RED must be an authentic behavior assertion.
4. Existing unit/feature test suites must still pass (regression baseline).

### Targeted RED command
```bash
cd apps/api && php artisan test --filter=CaptionFlowE2E
# or dedicated E2E test runner per CI configuration
```

### Stop condition
- E2E test file(s) exist with all 7 TP scenarios
- Running E2E tests fails because tests not yet implemented/infrastructure not wired
- No production code added to mask the failure
- Existing test suites still pass

---

## Stage 2 — GREEN: Implement E2E tests and wire infrastructure

1. Implement E2E tests per `spec.md` scenarios using real infrastructure (MinIO, FFmpeg, Python worker, FFprobe), no mocks for storage/worker/FFmpeg.
2. Ensure CI workflow provisions E2E infrastructure (MinIO, worker, FFmpeg, FFprobe).
3. Run E2E test suite; expect all 7 scenarios pass.
4. Run full backend unit/feature test suite for regression.

### Targeted GREEN commands
```bash
# E2E caption flow tests (must pass)
<ci-e2e-command>

# Full backend suite regression (must pass unchanged)
cd apps/api && php artisan test
```

### Stop condition
- All 7 E2E scenarios (TP-01 through TP-07) pass
- Real infrastructure used (no mocks for storage, worker, FFmpeg)
- Full backend test suite passes without modification (regression gate)
- No production code changes outside test scope
- CI workflow provisions E2E infrastructure

---

## Stage 3 — REFACTOR (if needed)

1. Improve test quality/clarity without altering assertions.
2. Re-run Stage 2 GREEN commands to confirm all tests remain green.

### Stop condition
- All Stage 2 tests still pass
- Tests remain deterministic and non-flaky
- No out-of-scope changes introduced

---

## Notes on scope boundaries

**This slice does NOT include:**

- Worker rendering logic changes (Slice 1)
- Laravel job/business logic changes (Slices 3A–3D)
- Database schema changes
- API endpoints
- UI changes
- Unit tests for individual services (Slices 3A–3D)
- Image generation (future capability)
- Production infrastructure changes
- Commit, push, PR creation, CI monitoring, merge, or issue closure

These lifecycle operations belong to the Orchestrator. Builder stops after Stage 3 with working, tested E2E coverage.

# Test Plan: Issue #81E — E2E Integration — Caption Flow End-to-End Test (Slice 3E)

## Scope

Validate end-to-end caption flow pipeline defined by `spec.md` and `plan.md` using real infrastructure (MinIO, FFmpeg, Python worker, FFprobe) with no mocks for storage, worker, or FFmpeg. Covers 7 E2E scenarios. No production code changes; test-only slice.

**Test files:** E2E caption flow test file(s) (e.g., `tests/Feature/E2E/CaptionFlowE2ETest.php` or per CI convention)
**Infrastructure:** MinIO, FFmpeg, Python worker, FFprobe (provisioned in CI)

## RED — before implementation

1. Create E2E test file(s) covering TP-01 through TP-07 scenarios.
2. Run E2E test suite; expect failures because tests/infrastructure not yet wired.
3. Confirm failure is a behavior assertion, not an environment error.
4. **Do not** add production implementation or weaken assertions. RED must be authentic.
5. Existing unit/feature test suites must still pass.

## GREEN — after implementation

Implement E2E tests per `spec.md`. Then run:

```bash
# E2E caption flow tests (must pass)
<ci-e2e-command>

# Full backend suite regression (must pass unchanged)
cd apps/api && php artisan test
```

### E2E Scenarios (7 scenarios)

| ID | Scenario | Infrastructure | Expected |
|----|----------|----------------|----------|
| TP-01 | Happy path (captions) | MinIO + FFmpeg + worker + FFprobe | Captions in output, duration match ±50ms |
| TP-02 | No transcript | MinIO + FFmpeg + worker | No captions, renders OK |
| TP-03 | Pending transcript | MinIO + FFmpeg + worker | No captions, graceful |
| TP-04 | Empty projection | MinIO + FFmpeg + worker | No caption_file, renders OK |
| TP-05 | Malformed segments | MinIO + FFmpeg + worker | Graceful, no crash |
| TP-06 | Idempotency | MinIO + worker | Reuse, no 2nd worker call, same ID |
| TP-07 | Transcript change | MinIO + worker | New render, new DerivedAsset |

## REFACTOR

Re-run all GREEN commands after any test refactor. Assertions must remain unchanged in meaning.

## Completion gate for this slice

- All 7 E2E scenarios pass (TP-01 through TP-07).
- Real infrastructure used (no mocks for storage, worker, FFmpeg).
- Full backend test suite passes (no regression).
- Tests deterministic and non-flaky.
- CI workflow provisions E2E infrastructure.
- Independent Tester validates the running behavior via test output.
- Builder work complete; Orchestrator handles commit, push, PR, CI, merge, and issue closure separately.

## Quality Gates

| Check | Command | Required |
|-------|---------|----------|
| E2E caption tests | `<ci-e2e-command>` | pass (7 scenarios) |
| Full backend suite | `php artisan test` | pass |
| E2E infra provisioned | CI workflow | MinIO + FFmpeg + worker + FFprobe |

## Scope Verification Checklist

- [ ] Only E2E test files new/modified.
- [ ] No production code changes (Laravel job, worker, schema, API, UI).
- [ ] No test weakening or skips.
- [ ] Real infrastructure (no mocks for storage, worker, FFmpeg).
- [ ] All 7 scenarios covered.

## Independent Tester Requirements

Execute the commands above, record actual output, confirm E2E tests fail before implementation (RED) and pass after (GREEN), confirm zero regression, inspect the diff for scope, record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## Release Gate

All 7 E2E scenarios green, full suite green, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.

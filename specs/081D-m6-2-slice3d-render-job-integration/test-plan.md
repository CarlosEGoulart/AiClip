# Test Plan: Issue #81D — Render Job Integration — Caption Flow in RenderMediaClip (Slice 3D)

## Scope

Validate the caption flow wiring in `RenderMediaClip` job defined by `spec.md` and `plan.md`: transcript fetching, segment projection, SRT generation, SRT storage, and worker contract `caption_file` inclusion/omission. No worker rendering (Python), FFmpeg execution, database migrations, UI, API, or E2E infrastructure (MinIO/FFprobe) are part of this slice.

**Production file:** `app/Jobs/RenderMediaClip.php`
**Test file:** `tests/Feature/Jobs/RenderMediaClipTest.php` (caption-related cases)

## RED — before implementation

1. Create/add caption flow tests in `RenderMediaClipTest.php` covering TP-01, TP-02, TP-04, TP-05 scenarios.
2. Run `php artisan test --filter=RenderMediaClip`; expect caption tests to fail because caption flow is not wired.
3. Confirm failure is a behavior assertion, not an environment error.
4. **Do not** add implementation, stubs, or weaken assertions. RED must be authentic.
5. Existing test suites must still pass.

## GREEN — after implementation

Wire caption flow per `spec.md`. Then run:

```bash
cd apps/api && php artisan test --filter=RenderMediaClip
cd apps/api && php artisan test
```

### TP-01 — Transcript Fetching (4 scenarios)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-01a | Completed transcript | segments fetched |
| TP-01b | No transcript | no caption_file |
| TP-01c | Status != completed | no caption_file |
| TP-01d | Empty segments | no caption_file |

### TP-02 — Segment Projection (matches Slice 2 TP-01 through TP-08)
- Normal projection, before clip, after clip, partial overlap start/end, empty input, determinism, mixed segments, zero-duration clip, exactly at bounds, empty text preserved, integer arithmetic.

### TP-04 — SRT Storage (3 scenarios)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-04a | File stored on correct disk | asset->storage_disk |
| TP-04b | Key matches expected pattern | projects/{pid}/captions/.../{uuid}.srt |
| TP-04c | MIME type | application/x-subrip |

### TP-05 — Worker Contract (4 scenarios)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-05a | caption_file when overlapping segments | present |
| TP-05b | caption_file omitted when no transcript | absent |
| TP-05c | caption_file omitted when no overlapping segments | absent |
| TP-05d | caption_file is storage key | string |

## REFACTOR

Re-run all GREEN commands after any implementation refactor. Assertions must remain unchanged in meaning.

## Completion gate for this slice

- All caption flow tests pass (TP-01, TP-02, TP-04, TP-05).
- Full Laravel test suite passes (no regression).
- Pint formatting clean.
- PHPStan level 5 clean on changed files.
- Backward compatibility preserved (caption_file omitted when no caption data).
- No out-of-scope files modified (no worker rendering, no FFmpeg, no UI, no E2E).
- Independent Tester validates the running behavior via test output.
- Builder work complete; Orchestrator handles commit, push, PR, CI, merge, and issue closure separately.

## Quality Gates

| Check | Command | Required |
|-------|---------|----------|
| Focused tests | `php artisan test --filter=RenderMediaClip` | pass |
| Full backend suite | `php artisan test` | pass |
| Code style | `vendor/bin/pint --dirty --format agent` | clean |
| Static analysis | `phpstan analyse --level=5` on changed files | clean |

## Scope Verification Checklist

- [ ] Only `RenderMediaClip.php` and caption test cases are new/modified.
- [ ] No migrations, worker rendering, FFmpeg, UI, API, or E2E changes.
- [ ] No test weakening or skips.
- [ ] Integer arithmetic only in projection — no floating point.
- [ ] Deterministic output for identical inputs.
- [ ] Backward compatibility: caption_file omitted when no caption data.

## Independent Tester Requirements

Execute the commands above, record actual output, confirm caption tests fail before implementation (RED) and pass after (GREEN), confirm zero regression, inspect the diff for scope, record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## Release Gate

Caption tests green, full suite green, Pint clean, PHPStan clean, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.

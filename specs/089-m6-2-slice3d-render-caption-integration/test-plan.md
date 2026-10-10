# Test Plan: Issue #89 — Caption Flow Integration into RenderMediaClip (Slice 3D)

## Authority
Verifies `spec.md` acceptance criteria AC-01..AC-07 for issue [#89](https://github.com/CarlosEGoulart/AiClip/issues/89). Uses Laravel Pest with `RefreshDatabase`, `Storage::fake()`, and mocked `ProcessMediaAction`.

## Test Location
`apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` — new `it()` cases appended after existing tests.

## Test Cases

### TC-RMJ-CAP-01: Happy path — completed transcript with in-range segments (AC-01)
- **Setup**: MediaAsset + completed MediaTranscript with segments overlapping candidate 0 (0-10000ms)
- **Action**: Dispatch `RenderMediaClip` job with candidateIndex=0
- **Assertions**:
  - `RenderMediaAction` mock received `renderClip` call with `caption_file` in request
  - `caption_file` matches `projects/{project_id}/captions/{asset_id}/{candidate_index}/{version}/{uuid}.srt` pattern
  - `Storage::fake()->disk('media')->exists($captionFile)` is true
  - SRT content valid format (contains timestamps and text)
  - DerivedAsset created with RENDER_STATUS_COMPLETED

### TC-RMJ-CAP-02: No transcript record (AC-02)
- **Setup**: MediaAsset without any MediaTranscript
- **Action**: Dispatch job
- **Assertions**:
  - `RenderMediaAction` mock received `renderClip` call WITHOUT `caption_file` key
  - DerivedAsset created successfully

### TC-RMJ-CAP-03: Transcript status not completed (AC-03)
- **Setup**: MediaAsset with MediaTranscript status = pending/transcribing/failed (parametrize)
- **Action**: Dispatch job
- **Assertions**:
  - `caption_file` absent from render contract
  - DerivedAsset created successfully

### TC-RMJ-CAP-04: Empty projection — all segments outside clip range (AC-04)
- **Setup**: Completed transcript with segments [20000, 30000] but candidate is [0, 10000]
- **Action**: Dispatch job
- **Assertions**:
  - `caption_file` absent from render contract (CaptionProjection returns empty array)
  - DerivedAsset created successfully

### TC-RMJ-CAP-05: Malformed transcript segments (AC-05)
- **Setup**: Completed transcript with segments missing required keys (e.g., no `start_ms`)
- **Action**: Dispatch job
- **Assertions**:
  - Job throws `ProcessMediaException` with message `invalid_input` (or similar)
  - DerivedAsset status = RENDER_STATUS_FAILED

### TC-RMJ-CAP-06: Idempotency — re-dispatch creates new caption file (AC-06)
- **Setup**: Same as TC-RMJ-CAP-01
- **Action**: Dispatch job twice (fresh job instances)
- **Assertions**:
  - Both dispatches succeed
  - Two distinct `caption_file` keys generated (different UUIDs)
  - Both SRT files exist on disk

### TC-RMJ-CAP-07: Regression — existing tests pass (AC-07)
- Run full `RenderMediaClipTest.php` suite
- All pre-existing tests (TC-RMJ-01 through TC-RMJ-10+) must pass

## Quality Gates
| Check | Command | Required |
|-------|---------|----------|
| Caption tests | `cd apps/api && php artisan test --filter=RenderMediaClipTest` | pass (all) |
| Full backend | `cd apps/api && php artisan test` | pass (986+) |
| Code style | `cd apps/api && vendor/bin/pint --dirty --format agent` | clean |
| Governance | `python -m unittest discover -s tests/governance -p 'test_*.py'` | pass |

## Scope Verification
- [ ] Only `apps/api/app/Jobs/RenderMediaClip.php` and `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` modified.
- [ ] No migrations, routes, controllers, frontend, worker logic changes.

## Independent Tester Requirements
- Execute all test commands, record actual output.
- Verify each test case passes independently.
- Confirm zero regression in existing tests.
- Inspect diff for scope compliance.
- Record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## Release Gate
All 7 test cases pass, full suite green, Pint clean, governance green, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.
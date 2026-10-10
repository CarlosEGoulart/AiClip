# Plan: Issue #89 — Caption Flow Integration into RenderMediaClip (Slice 3D)

## Active Scope and Baseline
Issue [#89](https://github.com/CarlosEGoulart/AiClip/issues/89), branch `@carlosegoulart/89/feat/render-caption-integration`, based on merged `master` (Slices 3A/3B/3C present). Integration only — no new services, no schema changes.

## Step 1 — RED: Write Failing Tests
Extend `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` with new test cases:
- TC-RMJ-CAP-01: Happy path with caption_file in contract
- TC-RMJ-CAP-02: No transcript
- TC-RMJ-CAP-03: Transcript status not completed
- TC-RMJ-CAP-04: Empty projection
- TC-RMJ-CAP-05: Malformed segments → exception
- TC-RMJ-CAP-06: Idempotency (distinct caption files)
Run: `cd apps/api && php artisan test --filter=RenderMediaClipTest` — expect 6 new failures.

## Step 2 — GREEN: Minimum Implementation
Modify `apps/api/app/Jobs/RenderMediaClip.php`:
1. After loading recommendation and candidate, fetch transcript:
   ```php
   $transcript = MediaTranscript::where('media_asset_id', $asset->id)
       ->where('status', MediaTranscript::STATUS_COMPLETED)
       ->first();
   ```
2. Extract clip timing from selected candidate:
   ```php
   $clipStartMs = (int) $selectedCandidate['start_ms'];
   $clipEndMs = (int) $selectedCandidate['end_ms'];
   ```
3. Pass caption params to `renderClipRequest()`:
   ```php
   $renderContract = MediaProcessingContract::renderClipRequest(
       $asset->id,
       $durationMs,
       $recommendation->toArray(),
       $recommendation->id,
       $this->candidateIndex,
       $sourceMedia,
       $asset->project_id,
       $transcript?->segments,        // transcriptSegments
       $clipStartMs,                  // clipStartMs
       $clipEndMs,                    // clipEndMs
       $asset->storage_disk           // disk
   );
   ```
4. The existing `renderClipRequest()` logic (Slice 3C) handles projection, SRT generation, storage, and `caption_file` inclusion/omission.

## Step 3 — REFACTOR and Verify
- `cd apps/api && vendor/bin/pint --dirty --format agent`
- Full regression: `cd apps/api && php artisan test`
- Scope verification: Only `RenderMediaClip.php` and `RenderMediaClipTest.php` modified.

## Step 4 — Governance and Handoff
- SDD bundle complete: `spec.md`, `plan.md`, `test-plan.md`, `evidence.md` (created later by Tester).
- Local preflight: `python -m unittest discover -s tests/governance -p 'test_*.py'`.
- Independent Tester approval recorded in `evidence.md` as `Decision: APPROVE`.
- PR references `Closes #89`, CI green, merge via `scripts/merge_gate.py`.

## Remaining Gates
- Governance, unit, e2e CI green on PR.
- Tester approval; merge gate execution.
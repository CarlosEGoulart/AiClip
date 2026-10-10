# Plan: Issue #87 — SRT Storage Keys and Worker Contract Extension (Slice 3C)

## Active Scope and Baseline
Issue [#87](https://github.com/CarlosEGoulart/AiClip/issues/87), branch `@carlosegoulart/87/feat/storage-contract-caption`, based on merged `master` (Slices 3A/3B present). Storage key generation, contract extension, worker schema only.

## Step 1 — RED: Write Failing Tests
1. Extend `apps/api/tests/Unit/StorageKeyBuilderTest.php` with TP-04a..TP-04i (9 tests).
2. Extend `apps/api/tests/Unit/MediaProcessingContractTest.php` with TP-05a..TP-05f (6 tests).
3. Run `cd apps/api && php artisan test --filter=StorageKeyBuilderTest` and `cd apps/api && php artisan test --filter=MediaProcessingContractTest`; expect 15 failures from missing behavior.

## Step 2 — GREEN: Minimum Implementation
1. `apps/api/app/Services/StorageKeyBuilder.php`: add `captionFile()` mirroring `renderClip()` validation, path `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`.
2. `apps/api/app/Contracts/MediaProcessingContract.php`: add optional `$transcriptSegments`, `$clipStartMs`, `$clipEndMs`, `$disk`; project → generate SRT → build key → `Storage::disk($disk)->put()` → add `caption_file`; omit key when projection empty or no transcript; raise `ProcessMediaException` on invalid inputs; include `caption_file` in `toRenderClipMetadataArray()` when set.
3. `services/worker/contracts/media_processing_v1.json`: add optional `caption_file` (`["string","null"]`) to `render_clip_request.properties`.
4. Re-run filters; expect green.

## Step 3 — REFACTOR and Verify
`cd apps/api && vendor/bin/pint --dirty --format agent`; full regression `cd apps/api && php artisan test`; verify only 5 intended files changed.

## Step 4 — Governance and Handoff
SDD bundle complete (`spec.md`, `plan.md`, `test-plan.md`, `evidence.md`); local preflight `python -m unittest discover -s tests/governance -p 'test_*.py'`; Tester `Decision: APPROVE` in evidence.md; PR `Closes #87`; CI green; merge via `scripts/merge_gate.py`.

## Remaining Gates
CI green on the PR; merge gate execution.
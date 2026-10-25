# Plan: Issue #81C — Storage Contract — Caption File Storage Key & Worker Contract (Slice 3C)

## Active Scope and Baseline
Issue [#81C](https://github.com/CarlosEGoulart/AiClip/issues/81C), branch `@carlosegoulart/81C/feat/storage-contract-caption`, based on merged `master` (Slices 3A and 3B present). Storage/contract changes only; no database, FFmpeg, storage writes beyond key generation, worker rendering, or UI changes.

## Stage 1 — RED: Write and run behavioral tests (expect failure)

1. Create tests for `StorageKeyBuilder::captionFile()` and `MediaProcessingContract::renderClipRequest()` caption_file handling covering TP-04 and TP-05 scenarios.
2. Run the targeted tests; expect failures because the new method/contract fields do not exist or behave as specified.
3. **Do not** add implementation, stubs, or weaken assertions. RED must be an authentic behavior assertion.
4. Existing test suites must still pass (regression baseline confirmed before implementation).

### Targeted RED command
```bash
cd apps/api && php artisan test --filter=StorageKeyBuilderTest
cd apps/api && php artisan test --filter=MediaProcessingContractTest
```

### Stop condition
- Test files exist and contain tests for all TP-04 and TP-05 scenarios
- Running tests fails because `captionFile()` method or contract `caption_file` field is not defined/behaves incorrectly
- No implementation code has been added to mask the failure
- Existing test suites still pass

---

## Stage 2 — GREEN: Implement storage key builder and contract changes

1. Add `StorageKeyBuilder::captionFile()` method returning deterministic S3 key per `spec.md` AC-01.
2. Extend `MediaProcessingContract::renderClipRequest()` to accept and return optional `caption_file` per `spec.md` AC-02.
3. Update `media_processing_v1.json` to include optional `caption_file` field in `render_clip_request` per `spec.md` AC-03.
4. Run targeted tests; expect all TP-04 and TP-05 tests pass.
5. Run full Laravel test suite for regression.

### Targeted GREEN commands
```bash
cd apps/api && php artisan test --filter=StorageKeyBuilderTest
cd apps/api && php artisan test --filter=MediaProcessingContractTest
cd apps/api && php artisan test
```

### Stop condition
- `StorageKeyBuilder::captionFile()` implemented
- Contract returns `caption_file` when present, omits when absent
- Schema updated with optional `caption_file`
- All TP-04 and TP-05 tests pass
- Full Laravel test suite passes without modification (regression gate)
- No out-of-scope files modified

---

## Stage 3 — REFACTOR (if needed)

1. Improve implementation quality without altering behavior.
2. Run `vendor/bin/pint --dirty --format agent` for code style.
3. Run `phpstan analyse --level=5` on changed files for static analysis.
4. Re-run Stage 2 GREEN commands to confirm all tests remain green.

### Stop condition
- All Stage 2 tests still pass
- Pint formatting clean
- PHPStan level 5 clean
- Code follows existing Laravel patterns
- No out-of-scope changes introduced

---

## Notes on scope boundaries

**This slice does NOT include:**

- Segment projection (Slice 3A)
- SRT generation (Slice 3B)
- RenderMediaClip job wiring (Slice 3D)
- End-to-end integration (Slice 3E)
- Database schema changes
- API endpoints
- UI changes
- Worker rendering logic (Python)
- FFmpeg invocation
- Multiple caption tracks/languages
- Caption styling/customization
- Commit, push, PR creation, CI monitoring, merge, or issue closure

These lifecycle operations belong to the Orchestrator and later slices. Builder stops after Stage 3 with working, tested code.

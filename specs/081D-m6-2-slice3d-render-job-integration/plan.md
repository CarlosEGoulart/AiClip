# Plan: Issue #81D — Render Job Integration — Caption Flow in RenderMediaClip (Slice 3D)

## Active Scope and Baseline
Issue [#81D](https://github.com/CarlosEGoulart/AiClip/issues/81D), branch `@carlosegoulart/81D/feat/render-job-caption-integration`, based on merged `master` (Slices 3A, 3B, 3C present). Job integration only; no database, FFmpeg, worker rendering, UI, or API changes.

## Stage 1 — RED: Write and run behavioral tests (expect failure)

1. Create/update tests for `RenderMediaClip` caption flow covering TP-01, TP-02, TP-04, TP-05 scenarios.
2. Run `php artisan test --filter=RenderMediaClip`; expect caption-related tests to fail because job wiring does not yet fetch transcript/project/generate/store/include caption_file.
3. **Do not** add implementation, stubs, or weaken assertions. RED must be an authentic behavior assertion.
4. Existing test suites must still pass (regression baseline confirmed before implementation).

### Targeted RED command
```bash
cd apps/api && php artisan test --filter=RenderMediaClip
```

### Stop condition
- Caption flow tests exist for TP-01, TP-02, TP-04, TP-05 scenarios
- Running tests fails because caption flow is not wired in `RenderMediaClip`
- No implementation code has been added to mask the failure
- Existing test suites still pass

---

## Stage 2 — GREEN: Implement caption flow wiring in RenderMediaClip

1. Wire `RenderMediaClip` job to fetch completed `MediaTranscript` for the `MediaAsset`.
2. Project segments via `CaptionProjection::project()` (integer arithmetic, deterministic).
3. Generate SRT via `SrtGenerator::generate()`.
4. Store caption file via `StorageKeyBuilder::captionFile()` on source media disk.
5. Include `caption_file` in `MediaProcessingContract::renderClipRequest()` only when projection yields non-empty segments; omit otherwise.
6. Run `php artisan test --filter=RenderMediaClip`; expect all caption tests pass.
7. Run full Laravel test suite for regression.

### Targeted GREEN commands
```bash
cd apps/api && php artisan test --filter=RenderMediaClip
cd apps/api && php artisan test
```

### Stop condition
- Caption flow wired in `RenderMediaClip` per `spec.md` AC-01 through AC-06
- All caption flow tests pass
- Full Laravel test suite passes without modification (regression gate)
- Backward compatibility preserved (caption_file omitted when no caption data)
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
- Code follows existing Laravel job patterns
- No out-of-scope changes introduced

---

## Notes on scope boundaries

**This slice does NOT include:**

- Worker rendering logic (Python media worker)
- Segment projection function (Slice 3A)
- SRT generation function (Slice 3B)
- Storage key builder / contract schema (Slice 3C)
- Database schema changes
- New API endpoints
- UI changes
- Multiple caption tracks/languages
- Caption styling/customization
- End-to-end integration tests (Slice 3E)
- FFmpeg invocation
- Commit, push, PR creation, CI monitoring, merge, or issue closure

These lifecycle operations belong to the Orchestrator and later slices. Builder stops after Stage 3 with working, tested code.

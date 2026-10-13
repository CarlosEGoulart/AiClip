# M6.2 Slice 3E: End-to-End Caption Flow Integration Test — Corrected Recovery Plan

## Issue
**#101** — test(e2e): end-to-end caption flow integration test (M6.2 Slice 3E)

## Branch
`@carlosegoulart/101/test/e2e-caption-flow`

---

## 1. Current State Assessment

### 1.1 Governance Issues (3)
| ID | Issue | Impact |
|----|-------|--------|
| G1 | Evidence.md claims `RED_VERIFIED` but tests are skipped (not failing) — invalid RED | Violates TDD contract; RED requires failing tests, not skipped |
| G2 | Plan.md assumes production code unchanged, but worker `request_sha256` canonicalization with `caption_file` is unverified | Cannot validate idempotency (AC-06) or cache invalidation (AC-07) without confirmed worker behavior |
| G3 | CI workflow created but never executed; no evidence MinIO/FFmpeg/libass actually work in CI | Infrastructure gap masquerading as implementation progress |

### 1.2 TDD Violations (2)
| ID | Issue | Impact |
|----|-------|--------|
| T1 | Tests written but immediately skipped via `skip()` — never observed a genuine RED failure | No evidence behavior is missing; tests could pass for wrong reasons |
| T2 | No Pre-RED phase (Phase 0) to verify infrastructure before writing tests | Cannot distinguish test defects from infrastructure defects |

### 1.3 Technical Defects (4)
| ID | Defect | File/Location | Expected vs Actual |
|----|--------|---------------|---------------------|
| D1 | `CaptionProjection::project()` does not validate segment structure — malformed segments cause silent failures or exceptions downstream | `app/Services/CaptionProjection.php` | Should throw `InvalidArgumentException` with `invalid_input` on missing `start_ms`/`end_ms`/`text` |
| D2 | `SrtGenerator::generate()` accepts empty projected segments and returns empty string — caller treats as "no captions" but no distinction from valid empty projection | `app/Services/SrtGenerator.php` | Should return `null` or throw for empty input; caller must distinguish "no transcript" from "empty projection" |
| D3 | `RenderMediaClip` job does not canonicalize `caption_file` into `request_sha256` — idempotency key ignores caption changes | `app/Jobs/RenderMediaClip.php` | `request_sha256` must include `caption_file` path/hash per REC-02/REC-03 |
| D4 | Worker `render-clip` CLI does not validate `caption_file` existence before FFmpeg — fails with cryptic FFmpeg error instead of clean `invalid_input` | `services/worker/aiclip_worker/rendering/vertical_clip_renderer.py` | Should validate file exists, readable, valid SRT format before FFmpeg invocation |

### 1.4 Infrastructure Gaps (2)
| ID | Gap | Status |
|----|-----|--------|
| I1 | Test video fixture `tests/fixtures/caption_test_video.mp4` is a placeholder (0 bytes), not a probeable 30s 1920x1080 h264/aac video | Blocking |
| I2 | Python worker package not installed in test environment; `config('media.worker_command')` points to non-existent CLI | Blocking |

---

## 2. Governance Recovery Strategy (Steps G1–G3)

### G1: Reset TDD State to Honest RED
- **Action**: Remove all `skip()` annotations from `RenderMediaClipCaptionE2ETest.php`
- **Action**: Run tests and capture genuine failures (infrastructure + code defects)
- **Evidence**: Record actual failure messages in `evidence.md` under RED Phase
- **Constraint**: NO force push, NO history rewrite — add corrective commits on current branch

### G2: Validate Worker Contract Before Test Execution
- **Action**: Write a minimal contract test verifying `request_sha256` includes `caption_file` in canonicalization
- **Action**: Verify worker CLI (`python -m aiclip_worker.cli render-clip`) accepts `--caption-file` and validates it
- **Evidence**: Document worker contract schema version and canonicalization logic in `evidence.md`

### G3: Execute CI Pipeline Once Before Claiming Readiness
- **Action**: Push current branch, trigger GitHub Actions workflow
- **Action**: Wait for CI completion; document all failures
- **Evidence**: Link CI run URL in `evidence.md`; only claim `GREEN_VERIFIED` after CI passes

---

## 3. Infrastructure Prerequisites (6 Verifiable Checks)

| # | Check | Command | Pass Criteria |
|---|-------|---------|---------------|
| P1 | MinIO reachable | `mc alias set test http://localhost:9000 minioadmin minioadmin && mc ls test/testing` | Returns bucket listing |
| P2 | FFmpeg with `subtitles` filter | `ffmpeg -filters | grep -w subtitles` | Line containing `subtitles` present |
| P3 | FFprobe available | `ffprobe -version` | Version ≥ 6.0 |
| P4 | Test video probeable | `ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1 tests/fixtures/caption_test_video.mp4` | Returns `30.0` ± 1s |
| P5 | Python worker CLI resolves | `cd services/worker && python -m aiclip_worker.cli render-clip --help` | Shows usage with `--caption-file` option |
| P6 | Laravel test environment | `cd apps/api && php artisan --version` | Returns Laravel 11.x |

**All 6 must pass before any test execution.**

---

## 4. Corrected TDD Sequence

### Phase 0: Pre-RED (Infrastructure Verification)
- [ ] Run all 6 Infrastructure Prerequisites checks
- [ ] Document results in `evidence.md`
- [ ] Only proceed if ALL pass

### RED Phase: 7 Tests Must FAIL (Genuine Failures)
| Test | Expected Failure Mode |
|------|----------------------|
| E2E-CAP-01 | Infrastructure missing (MinIO connection, worker CLI not found, video not probeable) OR code defects (D1-D4) |
| E2E-CAP-02 | Same as above |
| E2E-CAP-03 | Same as above |
| E2E-CAP-04 | Same as above |
| E2E-CAP-05 | Malformed segments not caught → exception type/message wrong OR no exception |
| E2E-CAP-06 | Idempotency fails: `request_sha256` missing `caption_file` → new render created |
| E2E-CAP-07 | Transcript change not detected → same `DerivedAsset` reused |

**RED Evidence**: Each test must fail with a clear, actionable error message. Skipped tests = INVALID RED.

### GREEN Phase: Minimal Fixes to Pass Each Test
- Fix D1–D4 in production code (minimal changes)
- Install/configure worker package
- Generate real test video fixture
- Run tests iteratively until all 7 pass

### REFACTOR Phase
- [ ] Code style: `vendor/bin/pint --dirty --format agent` — clean
- [ ] Static analysis: `phpstan analyse --level=5` — clean
- [ ] Test isolation verified: run suite 3x, no flakes
- [ ] No production code changes beyond D1–D4 fixes

---

## 5. Four Technical Defect Fixes

### D1: CaptionProjection Segment Validation
**File**: `app/Services/CaptionProjection.php`
**Method**: `project(array $segments, int $clipStartMs, int $clipEndMs): array`
**Expected Behavior**: Validate each segment has required keys `start_ms`, `end_ms`, `text` with correct types (int, int, string). Throw `InvalidArgumentException` with message containing `invalid_input` on violation.
**Error Handling**: Fail fast — no partial projection.
**Concrete Assertion**:
```php
expect(fn() => CaptionProjection::project([
    ['end_ms' => 5000, 'text' => 'missing start'],
], 0, 10000))->toThrow(InvalidArgumentException::class, 'invalid_input');
```

### D2: SrtGenerator Empty Input Handling
**File**: `app/Services/SrtGenerator.php`
**Method**: `generate(array $segments): ?string`
**Expected Behavior**: Return `null` for empty input array. Return valid SRT string for non-empty. Never return empty string.
**Error Handling**: Distinguish "no captions needed" (null) from "captions generated" (string).
**Concrete Assertion**:
```php
expect(SrtGenerator::generate([]))->toBeNull();
expect(SrtGenerator::generate([['local_start_ms' => 0, 'local_end_ms' => 1000, 'text' => 'x']]))
    ->toContain('00:00:00,000 --> 00:00:01,000');
```

### D3: RenderMediaClip request_sha256 Canonicalization
**File**: `app/Jobs/RenderMediaClip.php`
**Method**: `computeRequestHash(...): string` (or inline in `handle()`)
**Expected Behavior**: `request_sha256` = SHA256 of canonical JSON including: `media_asset_id`, `candidate_index`, `render_profile_version`, `transcript_hash`, **`caption_file`** (storage key or hash).
**Error Handling**: If `caption_file` present but transcript hash unchanged, new hash generated → new render.
**Concrete Assertion**:
```php
$hash1 = $job->computeRequestHash($paramsWithCaption);
$hash2 = $job->computeRequestHash($paramsWithoutCaption);
expect($hash1)->not->toEqual($hash2);
```

### D4: Worker caption_file Pre-Validation
**File**: `services/worker/aiclip_worker/rendering/vertical_clip_renderer.py`
**Method**: `render_singular(..., caption_file: Optional[str] = None)`
**Expected Behavior**: Before FFmpeg: validate `caption_file` exists, is readable, has `.srt` extension, contains valid SRT timestamps. Raise `ValueError("invalid_input: caption_file ...")` on failure.
**Error Handling**: Clean exception → worker returns `{"status": "error", "error": "invalid_input", ...}` → Laravel maps to `ProcessMediaException`.
**Concrete Assertion**:
```python
with pytest.raises(ValueError, match="invalid_input.*caption_file"):
    renderer.render_singular(..., caption_file="/nonexistent.srt")
```

---

## 6. CI Workflow Fixes

**File**: `.github/workflows/test-e2e-caption.yml`

### Required Changes:
1. **Add `before_script` step** running all 6 Infrastructure Prerequisites (P1–P6) — fail fast if any fails
2. **Add `services.worker` setup**: Install Python worker package from `services/worker` via `pip install -e .[dev]`
3. **Add artifact upload** for FFprobe JSON output on test failure (debugging)
4. **Increase timeout** to 600s for job (FFmpeg can be slow in CI)
5. **Use `actions/cache`** for MinIO data and Python packages between runs

### Minimal Working CI Snippet:
```yaml
jobs:
  e2e-caption-test:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    services:
      minio:
        image: minio/minio:latest
        ports: ['9000:9000']
        env:
          MINIO_ROOT_USER: minioadmin
          MINIO_ROOT_PASSWORD: minioadmin
        options: >-
          --health-cmd "curl -f http://localhost:9000/minio/health/live"
          --health-interval 10s --health-timeout 5s --health-retries 10
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, redis
      - name: Install FFmpeg
        run: sudo apt-get update && sudo apt-get install -y ffmpeg
      - name: Verify Infrastructure (P1-P6)
        run: |
          # P1
          mc alias set local http://localhost:9000 minioadmin minioadmin
          mc mb local/testing --ignore-existing
          # P2
          ffmpeg -filters | grep -w subtitles
          # P3
          ffprobe -version
          # P4
          ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1 tests/fixtures/caption_test_video.mp4
          # P5
          cd services/worker && pip install -e .[dev] && python -m aiclip_worker.cli render-clip --help
          # P6
          cd ../apps/api && php artisan --version
      - name: Run E2E Tests
        working-directory: ./apps/api
        run: php artisan test --filter=RenderMediaClipCaptionE2ETest --compact
        env:
          MINIO_ENDPOINT: http://localhost:9000
          MINIO_ACCESS_KEY: minioadmin
          MINIO_SECRET_KEY: minioadmin
          MINIO_BUCKET: testing
      - name: Upload FFprobe Artifacts
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: ffprobe-debug
          path: /tmp/ffprobe_*.json
```

---

## 7. Exit Criteria

| Criterion | Verification Method | Required |
|-----------|---------------------|----------|
| All 7 E2E tests pass locally (3 consecutive runs) | `php artisan test --filter=RenderMediaClipCaptionE2ETest` ×3 | ✅ |
| All 7 E2E tests pass in CI | GitHub Actions green check | ✅ |
| No production code modified beyond D1–D4 | `git diff --name-only` vs `main` | ✅ |
| Code style clean | `vendor/bin/pint --dirty --format agent` exits 0 | ✅ |
| Static analysis clean | `phpstan analyse --level=5` exits 0 | ✅ |
| Test video fixture probeable, 30s ±1s | `ffprobe` duration check | ✅ |
| Worker CLI validates caption_file | Unit test in worker repo + integration via E2E-CAP-05 | ✅ |
| `request_sha256` includes `caption_file` | Contract test + E2E-CAP-06/07 pass | ✅ |
| Evidence.md updated with RED/GREEN/REFACTOR logs | Manual review | ✅ |

---

## 8. Rollback Plan

If E2E tests cannot be stabilized after D1–D4 fixes and infrastructure ready:

1. **Revert test file only**: `git checkout main -- apps/api/tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php`
2. **Keep production fixes (D1–D4)**: These are legitimate improvements
3. **Keep CI workflow**: Valid for future use
4. **Document blockers** in Issue #101 and Issue #94 (REC-04)
5. **Return to `NO_ACTIVE_ISSUE`** — do not merge incomplete work
6. **Re-plan** when infrastructure reliably available

---

## 9. Risk Mitigation

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| FFmpeg `subtitles` filter missing in CI | Low | High | Pre-flight check (P2) fails fast |
| MinIO flaky in CI | Medium | High | Health checks, retry logic, dedicated bucket |
| Test video fixture generation fails | Low | Medium | Pre-generate and commit known-good fixture (≤10MB) |
| Worker CLI changes break contract | Low | High | Contract test in worker repo; schema version pin |
| Flaky FFmpeg timing | Medium | Medium | 300s timeout, ±100ms duration tolerance, 3x re-run |
| Cross-test MinIO pollution | Low | Low | Unique project IDs per test (RefreshDatabase) |

---

## 10. Verification Checklist

### Pre-Work (Must Complete Before Any Implementation)
- [ ] All 6 Infrastructure Prerequisites (P1–P6) pass
- [ ] `evidence.md` updated with Pre-RED results
- [ ] `skip()` removed from all 7 test methods

### RED Phase Verification
- [ ] All 7 tests fail with actionable errors (not skipped)
- [ ] Failure modes match expected in Section 4 table
- [ ] `evidence.md` records each failure message

### GREEN Phase Verification
- [ ] D1–D4 fixes committed separately with clear messages
- [ ] Each test passes individually after its fix
- [ ] Full suite passes 3x consecutively

### REFACTOR Phase Verification
- [ ] Pint clean
- [ ] PHPStan clean
- [ ] No test interdependence (random order passes)

### CI Verification
- [ ] GitHub Actions workflow passes
- [ ] Artifacts uploaded on failure for debugging

### Final Gates
- [ ] Tester independent approval
- [ ] No scope creep (production changes only D1–D4)
- [ ] Branch naming convention followed
- [ ] Conventional Commits on all commits

---

## 11. Corrected Lifecycle State

```text
NO_ACTIVE_ISSUE
  → ISSUE_CREATED (#101)
  → BRANCH_CREATED (@carlosegoulart/101/test/e2e-caption-flow)
  → SPEC_READY (spec.md, plan.md, test-plan.md aligned)
  → RED_VERIFIED (7 tests genuinely failing — THIS IS THE CORRECTED STATE)
  → GREEN_VERIFIED (7 tests passing after D1–D4 + infrastructure)
  → REFACTOR_VERIFIED (style, analysis, isolation clean)
  → TESTER_APPROVED (independent validation)
  → PR_OPEN (Closes #101)
  → CI_GREEN (GitHub Actions passes)
  → MERGE_GATE_READY (all checks green)
  → MERGED (squash or merge commit)
  → ISSUE_CLOSED (auto via PR)
  → NO_ACTIVE_ISSUE (stop, await explicit authorization)
```

**Current Corrected State**: `SPEC_READY` → **next action: execute Pre-RED (Phase 0)**

---

**SPEC_READY**
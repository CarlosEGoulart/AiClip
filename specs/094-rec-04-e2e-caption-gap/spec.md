# REC-04: End-to-End Caption Flow Integration Test Gap — Risk Registration

## 1. Executive Summary

This specification documents the missing real end-to-end integration test path for the caption flow:

```
Laravel → Storage (caption SRT) → Worker contract → FFmpeg subtitles filter → Output video → FFprobe validation → Laravel persistence
```

**This is a documentation and risk-registration task only.** No implementation work is authorized. Slice 3E (the implementation of the E2E test) remains explicitly blocked until:
- Recovery milestones REC-01, REC-02 are verified complete
- Worker `request_sha256` is verified implemented and tested
- Human authorization is granted

---

## 2. Problem Statement

### 2.1 Missing Verification Path

The caption flow was implemented across four slices (3A–3D) but **no real E2E test exercises the complete pipeline**:

| Slice | Scope | Test Coverage |
|-------|-------|---------------|
| 3A (Issue #81) | `CaptionProjection`, `SrtGenerator` | Unit tests with pure-PHP fixtures |
| 3B (Issue #81B) | Dedicated `SrtGenerator` implementation | 6 unit tests (single/multiple segments, empty, multiline, timestamp format) |
| 3C (Issue #87) | `StorageKeyBuilder::captionFile()`, contract extension with `caption_file` | Unit tests for key generation; contract schema tests |
| 3D (Issue #89) | `RenderMediaClip` job integrates caption flow | Feature tests using **mock worker action** (`RecordingRenderActionForRender`) |

**Gap**: Every existing test uses **mocks or static fixtures**. None exercises:
- Real S3 storage (MinIO) write/read of the generated SRT file
- Real worker contract serialization with `caption_file`
- Real FFmpeg `subtitles=` filter execution
- Real FFprobe validation of the output with burned-in subtitles
- Real Laravel persistence of the `DerivedAsset` with caption metadata

### 2.2 Why This Matters

The caption flow involves **cross-component integration** that mocks cannot verify:
1. **Storage contract**: SRT file must be readable by the worker at the exact path in `caption_file`
2. **Worker contract**: `caption_file` must be correctly included in canonical JSON for `request_sha256`
3. **FFmpeg filter**: `subtitles=` filter must parse the SRT and render correctly
4. **Output validation**: FFprobe must confirm subtitle stream exists in output
5. **Persistence**: Laravel must receive and store the completed render result

---

## 3. Current State of Dependencies

### 3.1 REC-01 (Issue #91) — CLOSED ✅
- **Goal**: Restore render completion validation contract
- **Status**: Merged in PR #96
- **Relevance**: `RenderValidator` now enforces `request_sha256` presence and correctness in worker responses

### 3.2 REC-02 (Issue #92) — CLOSED ✅
- **Goal**: Define idempotency semantics for render operations
- **Status**: Merged in PR #97
- **Relevance**: Worker can safely re-attempt renders; Laravel persists idempotently

### 3.3 Worker `request_sha256` — IMPLEMENTED ✅
**Location**: `services/worker/aiclip_worker/rendering.py`
- `render_singular()` (lines 530–886): Computes `request_sha256` using canonical JSON (`sort_keys=True`, `separators=(',', ':')`) including optional `caption_file` (line 855–856)
- `render_clips()` (lines 969–1039): Also computes `request_sha256` (but `caption_file` NOT in `render_clips` contract — line 1003–1005 commented)
- Cross-language canonicalization tests: `test_rendering.py` — `TestCrossLanguageCanonicalization` (CL-01 through CL-04)

### 3.4 Cross-Language Hash Verification
| Test | Scope | Status |
|------|-------|--------|
| CL-01 | PHP ↔ Python identical hash for canonical fixture | ✅ Passing |
| CL-02 | Same as CL-01 (PHP simulated with sorted keys) | ✅ Passing |
| CL-03 | With `caption_file` present — identical hash | ✅ Passing |
| CL-04 | `sort_keys=True` ensures deterministic output | ✅ Passing |

**Limitation**: These tests use **static fixtures**, not live contract objects.

---

## 4. Existing Test Coverage — Limits

### 4.1 Laravel Feature Tests (`apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`)
- Use `RecordingRenderActionForRender` mock (line 852+)
- **Never invokes real worker process**
- **Never exercises FFmpeg**
- Asserts `caption_file` key exists in mock call (line 865) and SRT content in fake storage (line 869–870)

### 4.2 Worker Unit Tests (`services/worker/tests/test_rendering.py`)
- Mock `_probe_source_media` and `_run_ffmpeg` (lines 96–105)
- **Never runs real FFmpeg or FFprobe**
- Tests `request_sha256` computation with mocked internals (lines 93–287)

### 4.3 Worker Integration Tests (`services/worker/tests/test_render_clip_integration.py`)
- Uses real FFmpeg **but without caption_file**
- Contract fixture has no `caption_file` (line 41–69)
- Validates output video properties only

### 4.4 PHP Contract/Unit Tests
- `MediaProcessingContractTest.php`: Tests `renderClipRequest` with caption generation (line 290+) using **fake storage**
- `RenderValidatorTest.php`: Tests cross-language hash with caption_file (line 901+) using **static fixtures**

---

## 5. Risk Assessment

| Risk | Impact | Likelihood | Evidence |
|------|--------|------------|----------|
| SRT path mismatch between Laravel storage and worker contract | Render fails silently or with cryptic FFmpeg error | Medium | No test verifies path resolution |
| `caption_file` omitted from `request_sha256` canonicalization | Validation failure in Laravel (`validation_failed`) | Low (unit tests pass) | CL-03 passes with static fixture only |
| FFmpeg `subtitles=` filter fails on generated SRT | Corrupted output or render failure | Medium | No real FFmpeg test with subtitles |
| FFprobe cannot detect subtitle stream in output | Laravel accepts invalid output | Medium | No validation of subtitle stream |
| Idempotency broken when caption_file changes | Duplicate renders with different hashes | Low | REC-02 defines semantics but untested E2E |

**Overall Risk Rating**: **MEDIUM** — Core caption flow is untested in production-like conditions.

---

## 6. Scope

### 6.1 In Scope (This Spec Package)
- Document the missing E2E verification path
- Catalog existing coverage and gaps
- Register risk with blocking dependencies
- Define acceptance criteria for documentation completeness
- Create evidence file for Planner/Orchestrator traceability

### 6.2 Explicitly OUT OF SCOPE
- ❌ **Slice 3E specification** — No spec.md for E2E test implementation
- ❌ **Slice 3E implementation** — No plan.md for test code
- ❌ **Test code creation** — No Builder work authorized
- ❌ **CI pipeline changes** — No pipeline modification
- ❌ **Any code changes** — Documentation only

---

## 7. Acceptance Criteria

The spec package is complete when:

- [ ] `spec.md` documents the missing E2E path, existing coverage, risk, dependencies, and out-of-scope declaration
- [ ] `plan.md` identifies documentation artifacts, evidence file, and test scenarios (no code changes)
- [ ] `test-plan.md` defines verification scenarios for the documentation
- [ ] `evidence.md` records the current state for Orchestrator handoff
- [ ] No implementation work is specified or implied

---

## 8. Verification Scenarios

| Scenario | Description | Expected Outcome |
|----------|-------------|------------------|
| V-01 | Spec documents full missing path with component names | Path explicitly enumerated |
| V-02 | Spec catalogs all 4 existing test layers and their limits | Table with coverage/gap per layer |
| V-03 | Spec records REC-01, REC-02, request_sha256 status | All three marked complete with PR refs |
| V-04 | Spec declares Slice 3E explicitly out of scope | Clear "OUT OF SCOPE" section |
| V-05 | Plan identifies zero code changes | "No code changes required" stated |
| V-06 | Test plan defines doc-only verification scenarios | Scenarios V-01 through V-05 covered |

---

## 9. Security Considerations

- No secrets involved — documentation only
- Caption files contain user transcript text; SRT paths must not leak PII in logs (existing Slice 3D handles this)

---

## 10. UX Considerations

- None — this is an internal engineering risk registration

---

## 11. References

- Issue #94: REC-04 — End-to-End Caption Flow Integration Test Gap
- Issue #91 (REC-01) — PR #96 merged
- Issue #92 (REC-02) — PR #97 merged
- Issue #93 (REC-03) — PR #98 merged
- Slice 3A: Issue #81 / PR #83
- Slice 3B: Issue #81B / PR #85
- Slice 3C: Issue #87 / PR #88
- Slice 3D: Issue #89 / PR #90
- Worker `rendering.py` — `render_singular()` with `caption_file` support
- Contract schema: `services/worker/contracts/media_processing_v1.json` — `render_clip_request.caption_file`
- Cross-language tests: `services/worker/tests/test_rendering.py::TestCrossLanguageCanonicalization`
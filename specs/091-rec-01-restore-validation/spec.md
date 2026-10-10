# REC-01: Restore Render Completion Validation Contract

## 1. Defect Summary

During M6.2 Slice 3D (Issue #89 / PR #90, commit `b2b2fd7`), **both validation boundaries were disabled** in `RenderMediaClip.php`:

1. **Worker boundary (`RenderValidator::result()`)** — Never called despite `$requestSha256` being computed (lines 288-290).
2. **Model boundary (`RenderValidator::validateCompletion()`)** — Explicitly commented out with `// TEMPORARILY DISABLED:` (lines 291-297).

**Result**: No independent validation occurs before persisting `DerivedAsset` as `COMPLETED`. The worker's response is trusted without verification.

---

## 2. Reproducible Root Cause

| Location | Problem |
|----------|---------|
| `apps/api/app/Jobs/RenderMediaClip.php:288-297` | `$requestSha256` is computed but `RenderValidator::result()` is never invoked; `validateCompletion()` is commented out. |
| `services/worker/aiclip_worker/rendering.py:808-827` | `render_singular()` builds the `parameters` dict but **does not include `request_sha256`**. |
| `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php:224-249` | Test mock (`RecordingRenderActionForRender`) **includes `request_sha256`** — tests pass but real worker would fail validation. |

**Contract Gap**: The PHP side expects `request_sha256` in `parameters`; the Python worker never provides it. The test mock masks this by manually computing and including the hash.

---

## 3. Expected Render-Completion Validation Behavior

Two independent trust boundaries must both pass before a render is marked `COMPLETED`:

### 3.1 Worker Boundary — `RenderValidator::result()`

**Invoked immediately after the worker returns**, before any persistence.

**Input**:
- `$result` — Raw worker response (decoded JSON).
- `$request` — The exact request metadata array sent to the worker (from `toRenderClipMetadataArray()`).
- `$requestSha256` — `hash('sha256', json_encode($request, JSON_THROW_ON_ERROR))`.

**Validates**:
1. `status === 'success'`
2. `render.algorithm === 'ffmpeg_vertical_baseline'`
3. `render.algorithm_version === '1.0.0'`
4. `parameters` has exact keys: `configuration`, `source_media`, `ffmpeg_version`, `filter_graph`, `limits`, `request_sha256`
5. `parameters.request_sha256 === $requestSha256` (binds result to this invocation)
6. `parameters.configuration === $request['configuration']` (config match)
7. `parameters.source_media` structure and values match request
8. `parameters.limits` matches `MAX_RECOMMENDATIONS`, `MAX_INPUT_BYTES`, `MAX_DURATION_MS`
9. Exactly one clip in `render.clips`
10. Clip `candidate_index`, `start_ms`, `end_ms`, `duration_ms` match request
11. Clip `output` metadata is complete and matches configuration (width, height, codecs, bitrates, size > 0)

**On failure**: Throws `ProcessMediaException('Render validation failed')` → job marks `DerivedAsset` as `FAILED` with `render_error = 'validation_failed'`.

### 3.2 Model Boundary — `RenderValidator::validateCompletion()`

**Invoked at the model boundary** before the `DerivedAsset` row is finalized as `COMPLETED`. The completion payload is **self-describing**: all recorded columns carry the authority, so no projection or inference is rerun.

**Input**:
- `$completion` — Associative array containing:
  - `algorithm` (string)
  - `algorithm_version` (string)
  - `parameters` (array with exact keys: `configuration`, `source_media`, `ffmpeg_version`, `filter_graph`, `limits`, `request_sha256`)
  - `clips` (list of exactly 1 clip)
  - `execution_parameters` (array with `timeout_seconds`, `lock_wait_seconds`)

**Validates**:
1. `algorithm === 'ffmpeg_vertical_baseline'`
2. `algorithm_version === '1.0.0'`
3. `parameters` has exact keys and `configuration` matches current profile
4. `parameters.source_media` structure and values are valid
5. `parameters.ffmpeg_version` is non-empty string
6. `parameters.filter_graph` is non-empty string
7. `parameters.limits` matches constants
8. `parameters.request_sha256` is valid 64-char lowercase hex
9. Exactly one clip in `clips`
10. Clip output metadata is complete; duration tolerance ≤ 50ms
11. Clip `filter_graph` and `ffmpeg_version` are non-empty
12. `execution_parameters.lock_wait_seconds === timeout_seconds + LOCK_WAIT_OFFSET_SECONDS`

**On failure**: Throws `ProcessMediaException('Render validation failed')` → job marks `DerivedAsset` as `FAILED` with `render_error = 'validation_failed'`.

---

## 4. Authoritative Source of Original Request and Configuration

The **only** authoritative source for the original request is the metadata array built by `MediaProcessingContract::renderClipRequest()` and serialized via `toRenderClipMetadataArray()`. This array contains exactly:

```php
[
    'version' => '1.0.0',
    'action' => 'render_clip',
    'media' => ['duration_ms' => int],
    'candidate_index' => int,
    'candidate' => ['start_ms' => int, 'end_ms' => int],
    'configuration' => [...],           // RenderProfile::configuration()
    'source_media' => [...],            // From probe
    'output_storage' => [...],          // Disk, key, mime_type
    'caption_file' => string|null,      // Optional
]
```

**No other data** (recommendation, recommendation_id, media_asset_id, project_id) is included in the worker request. This is enforced by `RenderValidator::request()` forbidding those keys.

The **trusted configuration** is `RenderProfile::configuration()` — pinned server-side, never accepted from the worker.

---

## 5. Worker Response Contract

The worker must return a JSON object matching this exact structure:

```json
{
  "status": "success",
  "render": {
    "algorithm": "ffmpeg_vertical_baseline",
    "algorithm_version": "1.0.0",
    "parameters": {
      "configuration": { ... },           // Must match request configuration exactly
      "source_media": { ... },            // Must match request source_media exactly
      "ffmpeg_version": "string",
      "filter_graph": "string",
      "limits": {
        "max_recommendations": 1000,
        "max_input_bytes": 8388608,
        "max_duration_ms": 2147483647
      },
      "request_sha256": "64-char-lowercase-hex"
    },
    "clips": [
      {
        "candidate_index": int,
        "start_ms": int,
        "end_ms": int,
        "duration_ms": int,
        "output": {
          "disk": "string",
          "key": "string",
          "size_bytes": int,
          "duration_ms": int,
          "width": int,
          "height": int,
          "video_codec": "string",
          "audio_codec": "string",
          "video_bitrate_kbps": int,
          "audio_bitrate_kbps": int,
          "mime_type": "video/mp4"
        }
      }
    ]
  }
}
```

---

## 6. Integrity-Hash Contract and Canonicalization Rules

**Purpose**: Bind the worker result to the exact request bytes sent, preventing substitution or replay.

### 6.1 PHP Canonicalization (Authoritative)

```php
$requestMetadata = $renderContractObj->toRenderClipMetadataArray();
$requestSha256 = hash('sha256', json_encode($requestMetadata, JSON_THROW_ON_ERROR));
```

- `JSON_THROW_ON_ERROR` ensures encoding failures are exceptions, not silent corruption.
- Key order is deterministic (PHP preserves insertion order; the contract builder inserts keys in a fixed order).

### 6.2 Python Canonicalization (Must Match Exactly)

```python
import hashlib, json

request_sha256 = hashlib.sha256(
    json.dumps(contract, separators=(',', ':'), sort_keys=True).encode()
).hexdigest()
```

- `separators=(',', ':')` → no whitespace.
- `sort_keys=True` → lexicographic key order (matches PHP's insertion order for the contract's fixed key set).
- `.encode()` → UTF-8.
- `.hexdigest()` → lowercase hex.

**Both sides must produce identical digests for the same logical request.**

---

## 7. Required Behavior for Missing, Malformed, or Inconsistent Fields

| Scenario | Worker Boundary (`result()`) | Model Boundary (`validateCompletion()`) |
|----------|------------------------------|------------------------------------------|
| Missing `request_sha256` in parameters | Validation failed | Validation failed |
| `request_sha256` not 64-char lowercase hex | Validation failed | Validation failed |
| `request_sha256` ≠ computed request hash | Validation failed | Validation failed (not re-verified here; checked at worker boundary) |
| `algorithm` mismatch | Validation failed | Validation failed |
| `algorithm_version` mismatch | Validation failed | Validation failed |
| `parameters.configuration` ≠ request configuration | Validation failed | Validation failed |
| `parameters.source_media` keys/values mismatch | Validation failed | Validation failed |
| `parameters.limits` mismatch | Validation failed | Validation failed |
| `parameters.ffmpeg_version` empty/missing | Validation failed | Validation failed |
| `parameters.filter_graph` empty/missing | Validation failed | Validation failed |
| Extra keys in any validated object | Validation failed | Validation failed |
| Missing required keys in any validated object | Validation failed | Validation failed |
| Clip count ≠ 1 | Validation failed | Validation failed |
| Clip `candidate_index` mismatch | Validation failed | N/A (validated at worker boundary) |
| Clip `start_ms`/`end_ms`/`duration_ms` mismatch | Validation failed | N/A (validated at worker boundary) |
| Clip `output` missing keys or invalid values | Validation failed | Validation failed |
| Clip `output` resolution ≠ configuration | Validation failed | Validation failed |
| Clip `output` duration tolerance > 50ms | N/A (validated at model boundary) | Validation failed |
| `execution_parameters` missing or invalid | N/A | Validation failed |
| `lock_wait_seconds` ≠ `timeout_seconds + 10` | N/A | Validation failed |

**All validation failures are sanitized**: The exception message is always `Render validation failed` with no worker detail, no previous cause, and a fixed category.

---

## 8. Compatibility Requirements for Valid Existing Render Requests

- Existing completed `DerivedAsset` rows with `render_status = 'completed'` are **not re-validated**. They remain valid.
- The fix only affects **new render executions** after deployment.
- The `render_profile_version` column (`vertical_v1`) provides isolation: if the profile ever changes, a version conflict prevents accidental reuse.
- No database migration is required.

---

## 9. Failure and Retry Behavior

| Failure Type | Job Behavior | DerivedAsset State | Retry |
|--------------|--------------|-------------------|-------|
| Worker returns error status | `RenderFailedException` → `FAILED` | `render_error = 'render_failed'` | Yes (job retries up to 3x) |
| Worker returns success but `result()` validation fails | `ProcessMediaException` → `FAILED` | `render_error = 'validation_failed'` | Yes |
| Worker returns success, `result()` passes, but `validateCompletion()` fails | `ProcessMediaException` → `FAILED` | `render_error = 'validation_failed'` | Yes |
| Worker process crashes / timeout | `ProcessMediaException` → `FAILED` | `render_error = 'render_failed'` | Yes |
| `invalid_input` (pre-flight) | `InvalidInputException` → `FAILED` | `render_error = 'invalid_input'` | No (permanent) |
| `invalid_configuration` | `ProcessMediaException` → re-throw | N/A (not persisted) | No (config bug) |

**Critical**: A `DerivedAsset` is **never** marked `COMPLETED` unless **both** validations pass.

---

## 10. Security Implications of Trusting Worker Output

If validation is disabled or incomplete:

1. **Output Substitution**: A compromised worker could return a different video file (wrong content, malware, etc.) while claiming success.
2. **Replay Attack**: A previous valid result could be replayed for a different request.
3. **Configuration Drift**: Worker could render with different parameters (resolution, bitrate, codec) than authorized.
4. **Metadata Forgery**: Worker could claim a larger/smaller file, different duration, or fake codec.
5. **Storage Key Hijacking**: Worker could write to an arbitrary storage key.

**The `request_sha256` binding prevents (1) and (2). The configuration match prevents (3). The output validation prevents (4). The output_storage key is server-generated, preventing (5).**

---

## 11. Exact Acceptance Criteria

| ID | Criterion |
|----|-----------|
| AC-01 | `RenderMediaClip` calls `RenderValidator::result()` immediately after `$action->renderClips()` with the correct `$request`, `$result`, and `$requestSha256`. |
| AC-02 | `RenderMediaClip` calls `RenderValidator::validateCompletion()` with the completion payload before marking `DerivedAsset` as `COMPLETED`. |
| AC-03 | Python `render_singular()` includes `request_sha256` in the returned `parameters` dict, computed using canonicalization rules. |
| AC-04 | A valid worker response with correct `request_sha256` passes both validations and persists as `COMPLETED`. |
| AC-05 | Missing `request_sha256` in worker response → `DerivedAsset` marked `FAILED` with `render_error = 'validation_failed'`. |
| AC-06 | Mismatched `request_sha256` → `DerivedAsset` marked `FAILED` with `render_error = 'validation_failed'`. |
| AC-07 | Algorithm/version mismatch → `DerivedAsset` marked `FAILED` with `render_error = 'validation_failed'`. |
| AC-08 | Configuration mismatch in parameters → `DerivedAsset` marked `FAILED` with `render_error = 'validation_failed'`. |
| AC-09 | Malformed/incomplete worker response → `DerivedAsset` marked `FAILED` with `render_error = 'validation_failed'`. |
| AC-10 | No `DerivedAsset` is ever marked `COMPLETED` when either validation rejects the result. |
| AC-11 | Cross-language hashing agreement verified on canonical fixtures (PHP and Python produce identical digests). |
| AC-12 | All existing tests pass; new tests cover validation failure paths. |

---

## 12. Tests Required to Demonstrate Correctness

### 12.1 Unit Tests (Pest)
- `RenderValidator::result()` with valid input → passes
- `RenderValidator::result()` missing `request_sha256` → fails
- `RenderValidator::result()` mismatched `request_sha256` → fails
- `RenderValidator::result()` algorithm mismatch → fails
- `RenderValidator::result()` configuration mismatch → fails
- `RenderValidator::result()` extra keys → fails
- `RenderValidator::validateCompletion()` with valid completion payload → passes
- `RenderValidator::validateCompletion()` missing fields → fails
- `RenderValidator::validateCompletion()` algorithm mismatch → fails
- `RenderValidator::validateCompletion()` execution_parameters mismatch → fails
- Canonicalization fixture: PHP `hash('sha256', json_encode($request, JSON_THROW_ON_ERROR))` === Python `hashlib.sha256(json.dumps(request, separators=(',', ':'), sort_keys=True).encode()).hexdigest()`

### 12.2 Feature Tests (RenderMediaClip)
- Happy path: valid worker response → `COMPLETED`
- Worker response missing `request_sha256` → `FAILED` with `validation_failed`
- Worker response with wrong `request_sha256` → `FAILED` with `validation_failed`
- Worker response with wrong algorithm → `FAILED` with `validation_failed`
- Worker response with wrong configuration → `FAILED` with `validation_failed`
- Worker response with malformed clip output → `FAILED` with `validation_failed`
- Worker response missing required parameters keys → `FAILED` with `validation_failed`
- Worker failure (exception) → `FAILED` with `render_failed` (unchanged)
- Retry after `validation_failed` → new attempt succeeds → `COMPLETED`

### 12.3 Worker Tests (pytest)
- `render_singular()` returns `request_sha256` in parameters
- `request_sha256` matches PHP canonicalization on shared fixtures
- Worker error paths still return proper error envelopes

---

## 13. Explicit Out-of-Scope Items

- Changing the render contract version (`1.0.0`) or action (`render_clip`).
- Modifying `RenderProfile` configuration keys or values.
- Adding new render actions (e.g., batch render, image render).
- Modifying `DerivedAsset` schema or status transitions.
- Changing the canonicalization algorithm (SHA-256 is fixed).
- Transcript/caption handling (already implemented in Slice 3D).
- Idempotency logic (already correct).
- Lock/transaction logic (already correct).
- Storage key generation (already correct).
- FFmpeg filter graph generation (already correct).

---

## 14. Dependencies

- No new dependencies.
- No database migrations.
- No configuration changes.
- Worker and API must be deployed together (atomic contract change).

---

## 15. Human-Gated Operations

None. This is a pure code fix with tests.
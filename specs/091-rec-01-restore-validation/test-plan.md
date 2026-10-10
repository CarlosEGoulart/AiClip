# REC-01: Restore Render Completion Validation Contract — Test Plan

## Test Strategy

Validate both trust boundaries independently and in combination:
1. **Unit tests** for `RenderValidator::result()` and `validateCompletion()`
2. **Feature tests** for `RenderMediaClip` job integration
3. **Worker tests** for Python contract compliance
4. **Cross-language canonicalization test** for hash agreement

---

## 1. Unit Tests — `RenderValidator::result()`

**File**: `apps/api/tests/Unit/Services/RenderValidatorTest.php`

| Test ID | Scenario | Expected |
|---------|----------|----------|
| UV-R-01 | Valid result with correct `request_sha256`, algorithm, version, config, parameters, clip | Returns validated result |
| UV-R-02 | Missing `request_sha256` in `parameters` | Throws `ProcessMediaException('Render validation failed')` |
| UV-R-03 | `request_sha256` not 64-char lowercase hex | Throws |
| UV-R-04 | `request_sha256` ≠ computed request hash | Throws |
| UV-R-05 | `algorithm` ≠ `'ffmpeg_vertical_baseline'` | Throws |
| UV-R-06 | `algorithm_version` ≠ `'1.0.0'` | Throws |
| UV-R-07 | `parameters.configuration` ≠ request configuration | Throws |
| UV-R-08 | `parameters.source_media` keys/values mismatch | Throws |
| UV-R-09 | `parameters.limits` values mismatch | Throws |
| UV-R-10 | `parameters.ffmpeg_version` empty | Throws |
| UV-R-11 | `parameters.filter_graph` empty | Throws |
| UV-R-12 | Extra keys in `parameters` | Throws |
| UV-R-13 | Missing required keys in `parameters` | Throws |
| UV-R-14 | `clips` count ≠ 1 | Throws |
| UV-R-15 | Clip `candidate_index` mismatch | Throws |
| UV-R-16 | Clip `start_ms`/`end_ms`/`duration_ms` mismatch | Throws |
| UV-R-17 | Clip `output` missing required keys | Throws |
| UV-R-18 | Clip `output` resolution ≠ configuration | Throws |
| UV-R-19 | Clip `output` size_bytes ≤ 0 | Throws |

---

## 2. Unit Tests — `RenderValidator::validateCompletion()`

**File**: `apps/api/tests/Unit/Services/RenderValidatorTest.php`

| Test ID | Scenario | Expected |
|---------|----------|----------|
| UV-C-01 | Valid completion payload with matching configuration | Passes (no exception) |
| UV-C-02 | Missing `algorithm` | Throws |
| UV-C-03 | `algorithm` mismatch | Throws |
| UV-C-04 | `algorithm_version` mismatch | Throws |
| UV-C-05 | `parameters` missing required keys | Throws |
| UV-C-06 | `parameters.configuration` ≠ provided `$configuration` | Throws |
| UV-C-07 | `parameters.source_media` invalid | Throws |
| UV-C-08 | `parameters.ffmpeg_version` empty | Throws |
| UV-C-09 | `parameters.filter_graph` empty | Throws |
| UV-C-10 | `parameters.limits` mismatch | Throws |
| UV-C-11 | `parameters.request_sha256` not valid 64-char hex | Throws |
| UV-C-12 | `clips` count ≠ 1 | Throws |
| UV-C-13 | Clip `output` missing keys | Throws |
| UV-C-14 | Clip `output` duration tolerance > 50ms | Throws |
| UV-C-15 | Clip `filter_graph` / `ffmpeg_version` empty (via parameters) | Throws |
| UV-C-16 | `execution_parameters` missing | Throws |
| UV-C-17 | `execution_parameters.lock_wait_seconds` ≠ `timeout_seconds + 10` | Throws |
| UV-C-18 | Extra keys in any validated object | Throws |

---

## 3. Feature Tests — `RenderMediaClip` Job

**File**: `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`

| Test ID | Scenario | Expected |
|---------|----------|----------|
| FV-01 | Happy path: valid worker response with correct `request_sha256` | `DerivedAsset` `COMPLETED`, both validations pass |
| FV-02 | Worker response **missing** `request_sha256` in parameters | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-03 | Worker response with **wrong** `request_sha256` (tampered) | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-04 | Worker response with **wrong algorithm** | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-05 | Worker response with **wrong algorithm_version** | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-06 | Worker response with **wrong configuration** in parameters | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-07 | Worker response with **malformed clip output** (missing keys) | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-08 | Worker response with **missing required parameters keys** | `DerivedAsset` `FAILED`, `render_error = 'validation_failed'` |
| FV-09 | Worker **throws exception** (render failure) | `DerivedAsset` `FAILED`, `render_error = 'render_failed'` (unchanged) |
| FV-10 | Retry after `validation_failed`: first attempt fails validation, second succeeds | First `FAILED` with `validation_failed`, second `COMPLETED` |
| FV-11 | `validateCompletion` called with correct payload including `execution_parameters` | No exception, `COMPLETED` persisted |
| FV-12 | Existing completed render reused (idempotency) — no worker call, no validation | `COMPLETED` returned, zero worker calls |

---

## 4. Worker Tests — Python Contract

**File**: `services/worker/tests/test_rendering.py`

| Test ID | Scenario | Expected |
|---------|----------|----------|
| WT-01 | `render_singular()` returns `parameters` containing `request_sha256` | `request_sha256` present, 64-char lowercase hex |
| WT-02 | `render_clips()` returns `parameters` containing `request_sha256` | `request_sha256` present |
| WT-03 | `request_sha256` computed from exact request metadata sent to worker | Matches canonicalization |
| WT-04 | Worker error paths still return proper error envelopes | `status: error`, `code`, `error`, `stderr` |
| WT-05 | `render_singular()` with caption_file includes it in request metadata for hash | Hash includes `caption_file` when present |

---

## 5. Cross-Language Canonicalization Test

**File**: `apps/api/tests/Unit/Services/RenderValidatorTest.php` (or separate fixture test)

**Shared Fixture** (JSON):
```json
{
  "action": "render_clip",
  "candidate": { "end_ms": 10000, "start_ms": 0 },
  "candidate_index": 0,
  "configuration": {
    "audio_bitrate_kbps": 128,
    "audio_codec": "aac",
    "target_fps": 30,
    "target_height": 1920,
    "target_width": 1080,
    "video_bitrate_kbps": 5000,
    "video_codec": "libx264"
  },
  "media": { "duration_ms": 30000 },
  "output_storage": {
    "disk": "media",
    "key": "renders/1/1/0_20260101T000000Z.mp4",
    "mime_type": "video/mp4"
  },
  "source_media": {
    "audio_codec": "aac",
    "disk": "media",
    "height": 1080,
    "key": "projects/1/assets/1/source.mp4",
    "video_codec": "h264",
    "width": 1920
  },
  "version": "1.0.0"
}
```

| Test ID | Scenario | Expected |
|---------|----------|----------|
| CL-01 | PHP `hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR))` | `a1b2c3d4e5f6...` (exact 64-char hex) |
| CL-02 | Python `hashlib.sha256(json.dumps(fixture, separators=(',', ':'), sort_keys=True).encode()).hexdigest()` | **Identical** to PHP result |
| CL-03 | PHP with `caption_file` added → Python with `caption_file` added | **Identical** to each other |
| CL-04 | Key order variation in Python input (unsorted dict) | `sort_keys=True` ensures deterministic output |

**Implementation**: Hard-code the expected hash in the test as the golden value. Both implementations must produce it.

---

## 6. Test Execution Commands

### Laravel (Pest)
```bash
# Run all tests
cd /workspaces/AiClip/apps/api && php artisan test --compact

# Run specific test files
php artisan test --compact --filter=RenderValidatorTest
php artisan test --compact --filter=RenderMediaClipTest
```

### Python Worker (pytest)
```bash
cd /workspaces/AiClip/services/worker && python -m pytest tests/test_rendering.py -v
```

### Cross-Language Hash Verification (Manual)
```bash
# PHP
cd /workspaces/AiClip/apps/api && php -r '
$fixture = json_decode(file_get_contents("tests/fixtures/render_request_canonical.json"), true);
echo hash("sha256", json_encode($fixture, JSON_THROW_ON_ERROR)) . PHP_EOL;
'

# Python
cd /workspaces/AiClip/services/worker && python -c '
import json, hashlib
fixture = json.load(open("tests/fixtures/render_request_canonical.json"))
print(hashlib.sha256(json.dumps(fixture, separators=(",", ":"), sort_keys=True).encode()).hexdigest())
'
# Outputs must match exactly
```

---

## 7. Test Data Requirements

### Fixtures
- `apps/api/tests/fixtures/render_request_canonical.json` — Canonical request for hash verification
- `services/worker/tests/fixtures/render_request_canonical.json` — Same fixture for Python

### Mock Worker Responses
- Valid response with all fields including correct `request_sha256`
- Response missing `request_sha256`
- Response with incorrect `request_sha256`
- Response with wrong algorithm/version
- Response with wrong configuration
- Response with malformed clip output
- Response missing required parameters keys

---

## 8. Acceptance Criteria Mapping

| AC | Covered By |
|----|------------|
| AC-01 | FV-01, FV-11 |
| AC-02 | FV-01, FV-11 |
| AC-03 | WT-01, WT-02, WT-03 |
| AC-04 | FV-01 |
| AC-05 | FV-02, UV-R-02 |
| AC-06 | FV-03, UV-R-04 |
| AC-07 | FV-04, UV-R-05, UV-C-03 |
| AC-08 | FV-06, UV-R-07, UV-C-06 |
| AC-09 | FV-07, FV-08, UV-R-12 thru UV-R-19, UV-C-05 thru UV-C-18 |
| AC-10 | FV-02 thru FV-08 (all fail closed) |
| AC-11 | CL-01 thru CL-04 |
| AC-12 | All test IDs above |

---

## 9. Test Independence

- Unit tests (`RenderValidatorTest`) require **no database**, **no worker**, **no queue** — pure PHP logic.
- Feature tests (`RenderMediaClipTest`) use `RefreshDatabase` and mocked `ProcessMediaAction`.
- Worker tests use deterministic fixtures, no real FFmpeg (mocked or fake renderer).
- Cross-language test uses static JSON fixture — no runtime dependencies.

---

## 10. CI Integration

Add to `.github/workflows/ci.yml`:

```yaml
- name: Laravel Tests
  run: cd apps/api && php artisan test --compact

- name: Worker Tests
  run: cd services/worker && python -m pytest tests/ -v

- name: Cross-Language Hash Verification
  run: |
    cd apps/api && php -r '
      $f = json_decode(file_get_contents("tests/fixtures/render_request_canonical.json"), true);
      $php_hash = hash("sha256", json_encode($f, JSON_THROW_ON_ERROR));
      echo "PHP: $php_hash\n";
    '
    cd services/worker && python -c '
      import json, hashlib
      f = json.load(open("tests/fixtures/render_request_canonical.json"))
      py_hash = hashlib.sha256(json.dumps(f, separators=(",", ":"), sort_keys=True).encode()).hexdigest()
      print(f"Python: {py_hash}")
    '
    # Add script to compare and fail if mismatch
```

---

## 11. Test Maintenance Notes

- When `RenderProfile::configuration()` changes, update the canonical fixture and expected hash.
- When contract version changes, update `version` in fixture and all tests.
- When new required keys are added to parameters/clip/output, add corresponding validation tests.
- The canonical fixture must be kept in sync between PHP and Python test directories.
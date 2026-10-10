# REC-01: Restore Render Completion Validation Contract — Implementation Plan

## Overview

This plan restores the two validation boundaries that were disabled in commit `b2b2fd7`:
1. **Worker boundary**: `RenderValidator::result()` in `RenderMediaClip.php`
2. **Model boundary**: `RenderValidator::validateCompletion()` in `RenderMediaClip.php`

And fixes the worker contract gap: `render_singular()` must include `request_sha256` in its response.

---

## File Changes (Ordered)

### 1. Python Worker: `services/worker/aiclip_worker/rendering.py`

**Function**: `render_singular()` (lines 528-829)

**Change**: Add `request_sha256` to the returned `parameters` dict.

**Location**: After building `parameters` dict (around line 809-827), before `return (clip_info, parameters)`.

**Implementation**:
```python
# Compute request_sha256 using canonicalization matching PHP
# The request contract is the same as what was passed to the worker
# We need to reconstruct the exact metadata array that PHP sent

# Build the request metadata exactly as PHP's toRenderClipMetadataArray()
request_metadata = {
    "version": "1.0.0",
    "action": "render_clip",
    "media": {"duration_ms": duration_ms},
    "candidate_index": candidate_index,
    "candidate": {"start_ms": start_ms, "end_ms": end_ms},
    "configuration": configuration.to_dict(),
    "source_media": {
        "disk": source_media.disk,
        "key": source_media.key,
        "width": source_media.width,
        "height": source_media.height,
        "video_codec": source_media.video_codec,
        "audio_codec": source_media.audio_codec,
    },
    "output_storage": {
        "disk": output_disk,
        "key": output_key,
        "mime_type": "video/mp4",
    },
}

if caption_file is not None:
    request_metadata["caption_file"] = caption_file

# Canonicalize: sort_keys=True, separators=(',', ':')
request_sha256 = hashlib.sha256(
    json.dumps(request_metadata, separators=(',', ':'), sort_keys=True).encode()
).hexdigest()

# Add to parameters
parameters["request_sha256"] = request_sha256
```

**Note**: The `render_clips()` function (line 932-965) also needs the same fix for consistency, though it's not currently used by the singular action.

---

### 2. Laravel: `apps/api/app/Services/RenderValidator.php`

#### 2.1 `validateCompletion()` — Accept `$configuration` Parameter

**Current signature** (line 255):
```php
public static function validateCompletion(mixed $completion): void
```

**New signature**:
```php
public static function validateCompletion(mixed $completion, ?array $configuration = null): void
```

**Change**: Pass `$configuration` to `validateParameters()` for config match validation.

**Location**: Line 267 (call to `validateParameters`):
```php
// Before:
self::validateParameters($parameters);

// After:
self::validateParameters($parameters, $configuration);
```

**Rationale**: The completion boundary must verify that the recorded `parameters.configuration` matches the currently selected profile. The `$configuration` parameter allows passing `RenderProfile::configuration()` from the job.

---

#### 2.2 `validateParameters()` — Already Supports `$configuration`

**Current** (line 355):
```php
private static function validateParameters(array $parameters, ?array $configuration = null): void
```

**Already implements** config match at line 359-362:
```php
if ($configuration !== null) {
    $expectedConfig = $configuration;
    self::require($parameters['configuration'] === $expectedConfig, 'Configuration does not match request');
}
```

**No change needed** — just needs the parameter passed through.

---

### 3. Laravel: `apps/api/app/Jobs/RenderMediaClip.php`

#### 3.1 Call `RenderValidator::result()` (Worker Boundary)

**Location**: After `$result = $action->renderClips($renderContractObj);` (line 286), before marking completed.

**Replace lines 288-297** (currently commented out):
```php
// Validate result - use metadata array for requestSha256 to match worker
$requestMetadata = $renderContractObj->toRenderClipMetadataArray();
$requestSha256 = hash('sha256', json_encode($requestMetadata, JSON_THROW_ON_ERROR));

// Worker boundary validation
RenderValidator::result($result, $requestMetadata, $requestSha256);
```

**Exception handling**: `RenderValidator::result()` throws `ProcessMediaException('Render validation failed')` on failure. The existing catch block (line 317-345) will catch it and mark the render as `FAILED` with `render_error = 'render_failed'`. We need to distinguish validation failures.

**Add specific handling** in the catch block (around line 327-338):
```php
// Expected worker/validation failure: sanitized failed render only
$locked->render_status = DerivedAsset::RENDER_STATUS_FAILED;
$locked->render_completed_at = now();

// Distinguish validation failure from render failure
if ($e instanceof ProcessMediaException && $e->getMessage() === 'Render validation failed') {
    $locked->render_error = 'validation_failed';
} else {
    $locked->render_error = 'render_failed';
}
$locked->save();
```

---

#### 3.2 Call `RenderValidator::validateCompletion()` (Model Boundary)

**Location**: After building the completion payload, before `$locked->save()` that marks `COMPLETED` (around line 315).

**Build completion payload from the validated result**:
```php
// Model boundary validation - build completion payload from result
$completionPayload = [
    'algorithm' => $result['render']['algorithm'],
    'algorithm_version' => $result['render']['algorithm_version'],
    'parameters' => $result['render']['parameters'],
    'clips' => $result['render']['clips'],
    'execution_parameters' => $executionParameters,
];

// Pass current profile configuration for config match validation
RenderValidator::validateCompletion($completionPayload, $renderConfiguration);
```

**Exception handling**: Same catch block handles it — will mark `FAILED` with `render_error = 'validation_failed'`.

---

### 4. Laravel: Test Updates

#### 4.1 `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`

**Mock Update**: `RecordingRenderActionForRender` already includes `request_sha256` (line 249) — this is now correct and matches the fixed worker.

**New Tests to Add**:
- Test: Worker response missing `request_sha256` → `validation_failed`
- Test: Worker response with wrong `request_sha256` → `validation_failed`
- Test: Worker response with wrong algorithm → `validation_failed`
- Test: Worker response with wrong configuration → `validation_failed`
- Test: Worker response with malformed clip output → `validation_failed`
- Test: Retry after `validation_failed` → succeeds

---

#### 4.2 `apps/api/tests/Unit/Services/RenderValidatorTest.php` (New File)

Create new unit test file for `RenderValidator`:
- `result()` valid input passes
- `result()` missing `request_sha256` fails
- `result()` mismatched `request_sha256` fails
- `result()` algorithm mismatch fails
- `result()` configuration mismatch fails
- `result()` extra keys fails
- `validateCompletion()` valid payload passes
- `validateCompletion()` missing fields fails
- `validateCompletion()` algorithm mismatch fails
- `validateCompletion()` execution_parameters mismatch fails

---

#### 4.3 `services/worker/tests/test_rendering.py` (pytest)

**New Tests**:
- `render_singular()` returns `request_sha256` in parameters
- Cross-language canonicalization: PHP and Python produce identical SHA-256 for shared fixture

---

## Detailed Change List

| # | File | Change Type | Description |
|---|------|-------------|-------------|
| 1 | `services/worker/aiclip_worker/rendering.py` | Fix | Add `request_sha256` computation and inclusion in `parameters` in `render_singular()` |
| 2 | `services/worker/aiclip_worker/rendering.py` | Fix | Add `request_sha256` computation and inclusion in `parameters` in `render_clips()` for consistency |
| 3 | `apps/api/app/Services/RenderValidator.php` | Enhance | Add `$configuration` parameter to `validateCompletion()` and pass to `validateParameters()` |
| 4 | `apps/api/app/Jobs/RenderMediaClip.php` | Restore | Call `RenderValidator::result()` with `$requestMetadata` and `$requestSha256` |
| 5 | `apps/api/app/Jobs/RenderMediaClip.php` | Restore | Call `RenderValidator::validateCompletion()` with completion payload and `$renderConfiguration` |
| 6 | `apps/api/app/Jobs/RenderMediaClip.php` | Fix | Distinguish `validation_failed` vs `render_failed` in catch block |
| 7 | `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` | Test | Add validation failure test cases |
| 8 | `apps/api/tests/Unit/Services/RenderValidatorTest.php` | Test | New unit test file for `RenderValidator` |
| 9 | `services/worker/tests/test_rendering.py` | Test | Add worker contract tests including cross-language canonicalization |

---

## Execution Order

1. **Python worker fix** (1, 2) — Must deploy first so worker produces `request_sha256`
2. **Laravel validator enhancement** (3) — Prepare `validateCompletion()` for config parameter
3. **Laravel job fix** (4, 5, 6) — Enable both validations with proper error handling
4. **Tests** (7, 8, 9) — Verify all paths

---

## Rollback Plan

If issues arise:
1. Revert Laravel job changes (4, 5, 6) — validations disabled again
2. Revert Python worker changes (1, 2) — worker stops sending `request_sha256`
3. Tests (7, 8, 9) can remain as they test the fixed behavior

No database migration to roll back.

---

## Verification Checklist

- [ ] Python `render_singular()` returns `request_sha256` in parameters
- [ ] PHP `hash('sha256', json_encode($request, JSON_THROW_ON_ERROR))` === Python `hashlib.sha256(json.dumps(request, separators=(',', ':'), sort_keys=True).encode()).hexdigest()` on shared fixture
- [ ] `RenderMediaClip` calls `RenderValidator::result()` and `validateCompletion()`
- [ ] Valid worker response → `DerivedAsset` marked `COMPLETED`
- [ ] Missing `request_sha256` → `DerivedAsset` marked `FAILED` with `validation_failed`
- [ ] Mismatched `request_sha256` → `DerivedAsset` marked `FAILED` with `validation_failed`
- [ ] Algorithm mismatch → `DerivedAsset` marked `FAILED` with `validation_failed`
- [ ] Configuration mismatch → `DerivedAsset` marked `FAILED` with `validation_failed`
- [ ] All existing tests pass
- [ ] New tests pass
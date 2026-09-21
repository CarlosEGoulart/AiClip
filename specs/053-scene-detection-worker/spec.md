# Specification: Deterministic Scene Detection Worker Stage

## Issue Reference

- **Issue**: #53
- **Title**: feat(media): add deterministic scene detection worker stage
- **Branch**: `@carlosegoulart/53/feat/scene-detection-worker-replacement`
- **PR**: #55 (replacement PR)
- **Milestone**: M4 Video Understanding (second slice)

## Goal

Add the second slice of video understanding: deterministic scene detection. This stage analyzes a video file to identify temporal scene boundaries (cuts) and produces an ordered list of scenes with start/end timestamps. The detection must be deterministic in CI (no model downloads) while supporting a real runtime adapter (PySceneDetect) for production. Critically, the current ProcessMediaAsset pipeline has early returns that prevent scene detection from running on videos without audio. The pipeline must be restructured so scene detection runs independently of the audio/transcription path.

## Critical Architecture Constraint

The current ProcessMediaAsset flow has two early returns that block scene detection:

**Early Exit A** (line 99-108): No audio stream → `markCompleted()` → return
**Early Exit B** (line 166-173): Existing completed transcript → `markCompleted()` → return

Scene detection is a VIDEO concern. A valid video with NO AUDIO must still be eligible for scene detection. The pipeline MUST be restructured from:

Current:
```
probe → audio? → extract → transcribe → done
         no audio → done
```

To:
```
probe → scene detection (always, for video)
     → audio available? → extract → transcribe
```

Scene detection failure must NOT block transcription. Transcription failure must NOT block scene detection. No-audio video must still complete scene detection.

**Blocker Reconciliation (Blocker #8)**: `ProcessMediaAsset` MUST validate that `scene_detection` result contains required fields (`detector`, `detector_version`, `parameters`, `scenes`) — not silently coerce missing fields to empty arrays via `?? []`. Use `?? null` then require non-null arrays.

## Architecture Invariants

1. Laravel remains authoritative for PostgreSQL; Python worker MUST NOT write PostgreSQL
2. Binary media in S3-compatible storage; original uploads immutable
3. Worker must use subprocess-safe APIs, avoid shell interpolation, enforce timeout, capture exit status/stderr, parse JSON defensively
4. No secrets in logs or worker payloads
5. STRICT, FAIL-CLOSED execution
6. CI must use deterministic fakes/fixtures for worker tests
7. No external AI provider or model download is allowed in CI
8. Secrets remain outside committed files
9. Derived assets are stored as separate objects in S3; metadata in PostgreSQL
10. Scene detection engine abstraction isolates application domain from specific engine implementation
11. Scene detection lifecycle is dedicated (pending → detecting → completed/failed) to reduce coupling with MediaAsset processing states
12. Contract version remains 1.0.0 with action "detect_scenes"
13. Scene detection runs independently of audio extraction and transcription
14. No-audio video receives scene detection
15. **Scene detection requires authoritative media duration (`media.duration_ms >= 1`) for the `detect_scenes` action** (Blocker #5, #6)
16. **Scene indexes MUST be 0-based, sequential, with no gaps, duplicates, or reversed order** (Blocker #9)

## Detailed Scope

### Included

1. **Scene Detection Engine Abstraction**
   - `SceneDetector` abstract base class (protocol) defining `detect(video_path: str, options: dict) -> SceneResult`
   - `Scene` dataclass containing: `index: int`, `start_ms: int`, `end_ms: int`
   - `SceneResult` dataclass containing: `detector: str`, `detector_version: str`, `parameters: dict`, `scenes: list[Scene]`
   - CI/test implementation: `DeterministicSceneDetector` that returns fixture-based results derived from video file hash (no model downloads)
   - Runtime implementation: `PySceneDetectAdapter` using PySceneDetect library for real detection
   - Engine selection configurable via environment variable `SCENE_DETECTION_ENGINE` (default: `pyscenedetect`)
   - **`detect_scenes.py` child process MUST pass `None` (not `"deterministic"`) to factory; CI explicitly sets `SCENE_DETECTION_ENGINE=deterministic`** (Blocker #1)
   - **Stale install docs corrected: `pip install scenedetect[opencv-headless]` (not `pyscenedetect opencv-python-headless`)** (Blocker #1)
   - `detect()` accepts `options` dict; `options["duration_ms"]` provides authoritative media duration for scene bounding (REQUIRED for `detect_scenes`, Blockers #5, #6)
   - **PySceneDetectAdapter MUST NOT call `open_video()` before `scenedetect.detect()` — `detect()` handles opening internally** (Blocker #10)

2. **Scene Result Contract**
   ```json
   {
     "status": "success",
     "scene_detection": {
       "detector": "pyscenedetect",
       "detector_version": "0.6",
       "parameters": {"threshold": 27.0},
       "scenes": [
         {"index": 0, "start_ms": 0, "end_ms": 3120},
         {"index": 1, "start_ms": 3120, "end_ms": 8400}
       ]
     }
   }
   ```

3. **Scene Invariants**
   - Scene index is 0-based, deterministic, and ordered
   - **First scene index MUST be 0; indexes MUST be sequential (0,1,2...); gaps (0,2), duplicates, and reversed order are REJECTED** (Blocker #9)
   - `start_ms` is integer and >= 0
   - `end_ms` is integer and > `start_ms`
   - `end_ms` <= authoritative/probed media duration (with documented 1-frame rounding tolerance)
   - Scenes ordered by `start_ms`
   - Scenes do not overlap (`scenes[i].start_ms >= scenes[i-1].end_ms`)
   - Duplicate indexes rejected
   - Malformed detector output rejected
   - Empty-scene behavior: return empty `scenes` array, NOT error

4. **Persistence Model: Structured JSON (Option B)**
   - Single `media_scene_analyses` table with validated structured `scenes` JSON column
   - Rationale: simpler for current project size; downstream M5 clip analysis can query JSON; avoids join-heavy patterns for small scene lists
   - Columns: `id`, `media_asset_id` (FK), `status`, `detector`, `detector_version`, `parameters` (JSON), `scenes` (JSON), `error`, `timestamps`
   - Unique constraint on `(media_asset_id)` to prevent duplicate scene analyses
   - Scenes JSON validated at persistence boundary (not just at worker output)
   - **Blocker #13 Reconciliation**: Issue #53 originally mentioned a `MediaScene` model and `media_scenes` table (normalized rows). This spec adopts Option B (single-row JSON) for simplicity and query flexibility. The `MediaSceneAnalysis` model replaces the originally-proposed normalized design. No `MediaScene` model or `media_scenes` table will be created.

5. **Python Worker: `detect-scenes` CLI Subcommand**
   - Extend `services/worker/aiclip_worker/cli.py` with `detect-scenes` subcommand
   - Accepts extended media processing contract (with `action: "detect_scenes"`)
   - Returns structured scene detection result as JSON to stdout
   - Exit codes: 0 (success), 1 (processing error), 2 (invalid input)

6. **Python Worker: detect-scenes Action**
   - New file `services/worker/aiclip_worker/actions/detect_scenes.py`
   - Supervisor pattern: spawns child process with `start_new_session=True`, bounded timeout
   - Reads video from local filesystem path (pre-staged by Laravel or accessible via storage)
   - Invokes selected scene detection engine
   - Returns structured result with scene detection metadata
   - Timeout enforcement (configurable via `SCENE_DETECT_TIMEOUT_SECONDS` env var, default 120)
   - Subprocess kill pattern: SIGTERM → 1s wait → SIGKILL escalation (reuse from transcribe.py)
   - Defensive PGID check

7. **Contract Schema Extension**
   - Extend `services/worker/contracts/media_processing_v1.json` with `action: "detect_scenes"`
   - `action` field: string, enum `["probe", "extract_audio", "transcribe", "detect_scenes"]`
   - **`media.duration_ms` field: REQUIRED integer, >= 1 for `detect_scenes` action** (Blocker #6). Passed by Laravel from authoritative probe duration. Used by scene detectors to bound scene timestamps to actual media duration.
   - Laravel `MediaProcessingContract::validate()` MUST require `$this->durationMs > 0` for `detect_scenes` action
   - Unknown MAJOR versions must be rejected

8. **Laravel MediaSceneAnalysis Model**
   - New Eloquent model `MediaSceneAnalysis`
   - Columns: `id`, `media_asset_id` (FK), `status` (pending/detecting/completed/failed), `detector`, `detector_version`, `parameters` (JSON), `scenes` (JSON), `error`, `timestamps`
   - Relationship: `belongsTo(MediaAsset::class)`
   - MediaAsset `hasOne(MediaSceneAnalysis::class)`

9. **Laravel Migration: `media_scene_analyses` Table**
   - Creates `media_scene_analyses` table
   - Foreign key to `media_assets.id` with cascade delete
   - Unique constraint on `(media_asset_id)`
   - JSON columns for `parameters` and `scenes`

10. **MediaSceneAnalysis Lifecycle**
    - Dedicated lifecycle: `pending → detecting → completed/failed`
    - `failed → detecting` is the only retry transition
    - `completed` is terminal (idempotent reuse)
    - Invalid transitions are no-ops
    - **`markCompleted()` signature CHANGED: `markCompleted(string $detector, string $detectorVersion, array $parameters, array $scenes, int $durationMs)` — `$durationMs` is now REQUIRED (non-nullable, must be > 0)** (Blockers #5, #6)
    - **`validateScenes(array $scenes, int $durationMs)` — `$durationMs` is REQUIRED (non-nullable, must be > 0); rejects scenes where `end_ms > durationMs`** (Blocker #5)
    - Empty scenes array is valid (not an error), but duration validation still applies

11. **ProcessMediaAsset Pipeline Restructuring**
    - Remove Early Exit A (no-audio → markCompleted → return)
    - Remove Early Exit B (completed transcript → markCompleted → return)
    - New flow:
      ```
      probe
      ├── scene detection (always, for video assets with video_codec)
      │   └── independent lifecycle, failure does not block subsequent stages
      └── audio available?
          └── extract_audio → transcription
      ```
    - Scene detection and audio extraction/transcription run sequentially within the job but with independent lifecycles
    - Scene detection failure → MediaSceneAnalysis marked `failed`, but job continues to audio path
    - Transcription failure → MediaTranscript marked `failed`, but scene analysis is unaffected
    - No-audio video → skip audio extraction/transcription, but scene detection still runs
    - Job completes when ALL applicable stages have resolved (completed or failed)
    - `markCompleted()` is called only when both scene detection and audio path (if applicable) have resolved
    - **`ProcessMediaAsset` validates worker result: requires `scene_detection.detector`, `detector_version`, `parameters` (array), `scenes` (array) — missing fields throw, not coerce to empty** (Blocker #8)
    - **Passes `$asset->duration_ms` (from authoritative probe) to both worker contract AND `MediaSceneAnalysis::markCompleted()` for duration-bound enforcement** (Blockers #5, #6)

12. **ProcessMediaAction: detect_scenes() Method**
    - New method on `ProcessMediaAction` service
    - Invokes worker CLI: `python -m aiclip_worker.cli detect-scenes --contract-json <json>`
    - Timeout: configurable via `media.scene_detect_timeout_seconds` (default 120)
    - Returns parsed JSON result with `scene_detection` data
    - Throws `ProcessMediaException` on failure

13. **MediaAsset Relationship**
    - Add `sceneAnalysis(): HasOne` relationship to MediaAsset model

14. **Deterministic Unit Tests**
    - Python worker tests for scene detection logic (pytest)
    - Python worker tests for CLI detect-scenes command
    - Python worker tests for scene detection engine abstraction
    - Laravel feature tests for MediaSceneAnalysis model
    - Laravel feature tests for ProcessMediaAsset scene detection stage
    - All tests deterministic, no model downloads, no skips

### NOT Included

- Face tracking
- Clip ranking
- Captions
- Rendering
- AI inference beyond scene detection
- Social publishing
- Worker callback/polling endpoints (future slice)
- Status polling API (future slice)
- Real AI model downloads in CI
- Semantic scene analysis
- Shot type classification
- Object detection within scenes
- Audio-visual scene correlation

## Scene Detection Engine Abstraction

### Interface Definition

```python
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import List

@dataclass
class Scene:
    index: int
    start_ms: int
    end_ms: int

@dataclass
class SceneResult:
    detector: str
    detector_version: str
    parameters: dict
    scenes: List[Scene]

class SceneDetector(ABC):
    @abstractmethod
    def detect(self, video_path: str, options: dict) -> SceneResult:
        """Detect scenes in video file and return structured result."""
        pass
```

### Deterministic Implementation (CI/Tests)

- `DeterministicSceneDetector` returns fixed output based on video file path hash
- No external dependencies, no model downloads
- Output mapping: `hash(video_path) → pre-defined scenes`
- Used in CI and deterministic tests

### Runtime Implementation (Production)

- **File**: `services/worker/aiclip_worker/scene_detection_pyscenedetect.py` (MUST exist as a real module)
- `PySceneDetectAdapter` class implementing `SceneDetector` protocol
- Imports `scenedetect` (PyPI package name `scenedetect`, NOT `pyscenedetect`)
- Uses `scenedetect.detect()` or `scenedetect.ContentDetector` for content-aware scene detection
- Accepts optional `options` dict with `threshold` key (default 27.0)
- `get_name()` returns `"pyscenedetect"`, `get_version()` returns `scenedetect.__version__`
- Converts PySceneDetect output to `SceneResult` with `Scene` dataclass instances
- `opencv-python-headless` for video frame access (no GUI)
- CPU inference by default
- Module MUST be importable; factory function raises `ImportError` with install instructions if dependency is missing

## PySceneDetect Evaluation

### Selection Rationale

PySceneDetect is selected as the runtime scene detection engine based on:

1. **Deterministic Foundation**: ContentDetector produces consistent results for same video input
2. **No GPU Required**: CPU-only processing suitable for server-side
3. **Well-Maintained**: Active project with stable API
4. **CI Strategy**: CI runs deterministic fake for fast unit tests AND real PySceneDetect integration tests with FFmpeg-generated video for mandatory Backend CI coverage
5. **opencv-python-headless**: No GUI dependency needed
6. **Pinned Version**: Intentionally excludes unreviewed releases

### Dependency Name

- **PyPI package name**: `scenedetect` (NOT `pyscenedetect`)
- **pyproject.toml dependency**: `scenedetect[opencv-headless]>=0.6.7,<0.7`
- The `scenedetect` package includes OpenCV as an optional extra via `[opencv-headless]`
- **No separate `opencv-python-headless` dependency is needed when using the `[opencv-headless]` extra** (Blocker #1: stale docs previously said `pip install pyscenedetect opencv-python-headless`; corrected to `pip install scenedetect[opencv-headless]`)
- Python import: `import scenedetect` (not `import pyscenedetect`)

### Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `SCENE_DETECTION_ENGINE` | `pyscenedetect` | Engine selection (`pyscenedetect` for production; `deterministic` for CI/tests — MUST be explicitly set in CI) |
| `SCENE_DETECT_THRESHOLD` | `27.0` | ContentDetector threshold |
| `SCENE_DETECT_TIMEOUT_SECONDS` | `120` | Timeout for scene detection |

### CI Behavior

- **CI MUST set** `SCENE_DETECTION_ENGINE=deterministic` explicitly (not rely on code default)
- Production/unconfigured deployments use `pyscenedetect` (the code default)
- No model downloads
- Deterministic output from fixtures
- Fast execution
- **CI installs `[scene_detection]` extra: `pip install -e ".[scene_detection,dev]"`** (Blocker #4)

### PySceneDetectAdapter Test Strategy (Blocker #2)

**Unit Tests (~15 mock-based tests for `PySceneDetectAdapter`):**
1. Implements `SceneDetector` protocol
2. Lazy import of `scenedetect` (import only on `detect()` call)
3. `ContentDetector` threshold configuration via options
4. Detection API invoked with correct parameters
5. Timecodes correctly converted to milliseconds
6. 0-based sequential scene indexes produced
7. Empty scenes list handled correctly
8. Corrupt media → graceful failure with error
9. Missing `scenedetect` dependency → clear `ImportError` with install hint
10. Version reported via `get_version()` from `scenedetect.__version__`
11. Parameters reported in `SceneResult.parameters`
12. Explicit `SCENE_DETECTION_ENGINE=deterministic` still selects `DeterministicSceneDetector`
13. Unset `SCENE_DETECTION_ENGINE` selects `pyscenedetect` (production default)
14. Invalid engine name raises appropriate error
15. No double video open — `open_video()` NOT called before `detect()` (Blocker #10)

### Integration Test (Blocker #3)

**Real FFmpeg-generated video + real `PySceneDetectAdapter`:**
- Generate test video: `ffmpeg -f lavfi -i testsrc=duration=10:size=320x240:rate=30 -c:v libx264 -pix_fmt yuv420p test_video.mp4` (solid A→B→C color changes)
- Run real `PySceneDetectAdapter.detect()` on generated video
- Verify: real scenedetect invoked, real video decode, real `ContentDetector`, ordered boundaries, non-overlapping, integer ms timestamps, final scene end_ms within actual media duration
- Runs in CI as MANDATORY Backend CI coverage when `[scene_detection]` extra installed
- The `[scene_detection]` extra is installed in CI via `pip install -e ".[scene_detection,dev]"`

## Worker CLI Interface Specification

### Input Contract

The worker CLI accepts an extended `MediaProcessingContract` v1.0.0 as JSON.

**CLI Usage**:
```bash
# Detect Scenes (new)
python -m aiclip_worker.cli detect-scenes --contract-json '{"version": "1.0.0", "action": "detect_scenes", ...}'
```

**Extended Contract Schema** (additions to `media_processing_v1.json`):

```json
{
  "action": {
    "type": "string",
    "enum": ["probe", "extract_audio", "transcribe", "detect_scenes"],
    "default": "probe"
  },
  "media": {
    "type": "object",
    "properties": {
      "duration_ms": {
        "type": "integer",
        "minimum": 1,
        "description": "Authoritative media duration in milliseconds from probe result. REQUIRED for detect_scenes action; used by scene detectors to bound scene timestamps to actual media duration."
      }
    },
    "required": ["duration_ms"]
  }
}
```

**Input Validation**:
- Contract must validate against extended schema
- `action` must be `"detect_scenes"` for detect-scenes subcommand
- Source `storage.key` must point to existing video file
- `media.duration_ms` is REQUIRED for `detect_scenes` action (minimum 1); passed through to detector `options["duration_ms"]`
- Unknown MAJOR versions must be rejected

### Output Contract

**Success Output** (stdout):
```json
{
  "status": "success",
  "scene_detection": {
    "detector": "pyscenedetect",
    "detector_version": "0.6",
    "parameters": {"threshold": 27.0},
    "scenes": [
      {"index": 0, "start_ms": 0, "end_ms": 3120},
      {"index": 1, "start_ms": 3120, "end_ms": 8400}
    ]
  }
}
```

**Error Output** (stdout):
```json
{
  "status": "error",
  "error": "Scene detection engine failed with timeout",
  "stderr": "Processing exceeded timeout"
}
```

**Exit Codes**:
- `0`: Success
- `1`: Processing error (file not found, corrupt video, engine failure)
- `2`: Invalid input (bad contract, missing fields, wrong action)

### Security Requirements

- Worker MUST NOT write to PostgreSQL
- Worker MUST NOT access database credentials
- Worker MUST NOT log secrets or tokens
- Worker MUST use subprocess-safe APIs (no shell interpolation)
- Worker MUST enforce timeout on scene detection invocation
- Worker MUST capture stderr for error reporting
- Worker MUST NOT write to original media storage keys
- Worker MUST NOT download models in CI (deterministic engine used)

## MediaSceneAnalysis Model Specification

### Schema

```php
Schema::create('media_scene_analyses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
    $table->string('status', 16); // pending, detecting, completed, failed
    $table->string('detector', 32)->nullable();
    $table->string('detector_version', 16)->nullable();
    $table->json('parameters')->nullable();
    $table->json('scenes')->nullable();
    $table->text('error')->nullable();
    $table->timestamps();

    $table->unique('media_asset_id');
});
```

### Model

```php
class MediaSceneAnalysis extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_DETECTING = 'detecting';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DETECTING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'media_asset_id',
        'status',
        'detector',
        'detector_version',
        'parameters',
        'scenes',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'scenes' => 'array',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * Validate scenes array structure.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int}>  $scenes
     * @param  int  $durationMs  Authoritative media duration in ms (REQUIRED, must be > 0).
     *                           Every scene.end_ms must be <= durationMs
     *                           (1-frame rounding tolerance documented).
     * @throws \InvalidArgumentException if durationMs <= 0 or scenes violate bounds
     */
    public static function validateScenes(array $scenes, int $durationMs): void
    {
        if ($durationMs <= 0) {
            throw new \InvalidArgumentException("Duration must be > 0 for scene validation");
        }

        // ... existing ordering, overlap, type, duplicate-index validations ...
        // Index invariant (Blocker #9): first index==0, sequential (0,1,2...), no gaps, no duplicates, no reversed

        // DURATION BOUND (Blocker #5): every scene.end_ms must be <= durationMs.
        foreach ($scenes as $i => $scene) {
            if ($scene['end_ms'] > $durationMs) {
                throw new \InvalidArgumentException(
                    "Scene {$i} end_ms ({$scene['end_ms']}) exceeds media duration ({$durationMs})"
                );
            }
        }
    }
}
```

### MediaAsset Relationship

```php
// Add to MediaAsset model:
public function sceneAnalysis(): HasOne
{
    return $this->hasOne(MediaSceneAnalysis::class);
}
```

## MediaSceneAnalysis Lifecycle

### Status Transitions

```
pending → detecting → completed
                   → failed
failed → detecting (retry)
```

### Transition Methods

- `markDetecting()`: pending → detecting (also clears error)
- `markCompleted(string $detector, string $detectorVersion, array $parameters, array $scenes, int $durationMs)`: detecting → completed. **$durationMs is REQUIRED (must be > 0)**. Calls `validateScenes($scenes, $durationMs)` before persisting.
- `markFailed(string $error)`: detecting → failed

### Validation

- Only valid transitions allowed
- Invalid transitions are no-ops (log warning)
- Unique constraint prevents duplicate scene analyses per MediaAsset
- **Duration-bound enforcement**: `validateScenes(array $scenes, int $durationMs)` requires `$durationMs > 0`; every `scene.end_ms` must be ≤ `$durationMs`. The calling code in `ProcessMediaAsset` passes `$asset->duration_ms` from the authoritative probe result (Blocker #5).
- Empty scenes array is valid (not an error), but duration validation still applies — `durationMs` must be provided and > 0 even when scenes is empty.

## ProcessMediaAsset Pipeline Restructuring

### Current Behavior (after Issue #51)

```
stored → queued → processing → probed → completed
                                   → failed

audio path: (inside job)
  no audio → markCompleted → return
  audio → extract → transcribe → completed/failed
```

### New Behavior

```
stored → queued → processing → probed → completed
                                   → failed

scene path: (independent lifecycle)
  MediaSceneAnalysis: pending → detecting → completed/failed

audio path: (independent lifecycle)
  audio available?
    yes → extract → transcribe → completed/failed
    no → (skip)
```

### Flow

1. Job reloads MediaAsset
2. Validates idempotency key
3. Marks as queued
4. Builds contract (with `action: "probe"`)
5. Marks as processing
6. Invokes `ProcessMediaAction::probe()` → gets probe result
7. Stores probe result, marks as `probed`
8. **Scene Detection Stage** (always for video assets):
   a. Check if `video_codec` is present in probe result
   b. If video exists:
      - Check for existing MediaSceneAnalysis (idempotency)
      - If completed: skip
      - If not exists or failed: create/retry
      - Create MediaSceneAnalysis with status `pending`
      - Mark as `detecting`
      - Build `detect_scenes` contract with `media.duration_ms = $asset->duration_ms` (REQUIRED, from authoritative probe)
      - Invoke `ProcessMediaAction::detectScenes()`
      - On success: **validate result contains required fields (`detector`, `detector_version`, `parameters` array, `scenes` array) — missing fields throw, do not coerce to empty** (Blocker #8)
      - On success: update with result, mark `completed` passing `$asset->duration_ms`
      - On failure: mark `failed`
9. **Audio Path** (independent, only if audio available):
   a. Check if `audio_codec` is present in probe result
   b. If audio exists: proceed with extraction and transcription (existing logic)
   c. If no audio: skip audio path entirely
10. **Job Completion**:
    - Mark MediaAsset as `completed` when BOTH:
      - Scene detection has resolved (completed or failed), OR video_codec absent
      - Audio path has resolved (completed or failed, or skipped if no audio)

### Idempotency

- If MediaSceneAnalysis with status `completed` already exists for this MediaAsset, scene detection is skipped
- If MediaSceneAnalysis with status `failed` exists, scene detection is retried
- MediaSceneAnalysis uniqueness: `(media_asset_id)` unique constraint

### Independence Guarantees

- Scene detection failure does NOT prevent audio extraction/transcription
- Transcription failure does NOT affect scene analysis
- No-audio video still receives scene detection
- Both lifecycles complete independently; job waits for both before marking completed

### Worker Result Validation (Blocker #8)

`ProcessMediaAsset` MUST validate the worker's JSON result before persisting:

```php
$sceneDetection = $result['scene_detection'] ?? null;
if (!$sceneDetection) {
    throw new ProcessMediaException('Missing scene_detection in worker result');
}
$detector = $sceneDetection['detector'] ?? null;
$detectorVersion = $sceneDetection['detector_version'] ?? null;
$parameters = $sceneDetection['parameters'] ?? null;
$scenes = $sceneDetection['scenes'] ?? null;

if ($detector === null || $detectorVersion === null || !is_array($parameters) || !is_array($scenes)) {
    throw new ProcessMediaException('Malformed scene_detection result: missing required fields');
}
```

Do NOT use `?? []` or `?? ''` — missing required fields are errors.

## Acceptance Criteria

1. Python worker CLI supports `detect-scenes` subcommand
2. Worker CLI accepts extended contract with `action: "detect_scenes"` field
3. Worker CLI returns structured scene detection result on success
4. Worker CLI returns structured error on failure
5. Worker CLI enforces timeout on scene detection invocation
6. Worker CLI uses subprocess-safe APIs (no shell interpolation)
7. Worker CLI captures stderr on failure
8. Worker CLI exits with appropriate exit codes (0, 1, 2)
9. Scene detection engine abstraction exists with deterministic CI implementation
10. **PySceneDetect runtime adapter exists as a real file** (`scene_detection_pyscenedetect.py`) implementing `SceneDetector` protocol using `scenedetect` (PyPI package name)
11. **PyPI dependency is `scenedetect[opencv-headless]>=0.6.7,<0.7`** (not `pyscenedetect`)
12. **Production default for `SCENE_DETECTION_ENGINE` is `pyscenedetect`**; CI/tests MUST explicitly set `deterministic`; **child process passes `None` to factory, not `"deterministic"`** (Blocker #1)
13. **Contract requires `media.duration_ms` (integer >= 1) for `detect_scenes` action**; Laravel passes probe duration; worker passes it to detector options (Blockers #5, #6)
14. ProcessMediaAction has `detectScenes()` method
15. MediaSceneAnalysis model exists with correct schema
16. MediaSceneAnalysis migration creates `media_scene_analyses` table
17. MediaAsset has `sceneAnalysis()` relationship
18. ProcessMediaAsset pipeline restructured for independent scene detection
19. ProcessMediaAsset creates MediaSceneAnalysis on video assets
20. ProcessMediaAsset is idempotent (skips if MediaSceneAnalysis exists)
21. ProcessMediaAsset marks scene detection failed on error
22. **ProcessMediaAsset passes `$asset->duration_ms` to `validateScenes()` and to the contract** for duration-bound enforcement (Blockers #5, #6)
23. **`MediaSceneAnalysis::validateScenes(array $scenes, int $durationMs)` enforces `scene.end_ms <= media duration`**; `$durationMs` is REQUIRED (> 0), not optional (Blocker #5)
24. Scene detection failure does NOT block audio extraction/transcription
25. Transcription failure does NOT block scene detection
26. Video without audio still receives scene detection
27. MediaSceneAnalysis lifecycle follows pending -> detecting -> completed/failed
28. Scene format is JSON array of {index, start_ms, end_ms}
29. Scene invariants enforced (ordered, non-overlapping, valid timing)
30. Empty scenes return empty array (not error)
31. **Scene index invariant: first index==0, sequential (0,1,2...), no gaps, no duplicates, no reversed order** (Blocker #9)
32. Deterministic unit tests exist for Python worker scene detection logic
33. Deterministic unit tests exist for worker CLI detect-scenes command
34. Deterministic feature tests exist for MediaSceneAnalysis model
35. Deterministic feature tests exist for ProcessMediaAsset scene detection
36. **PySceneDetectAdapter unit tests: ~15 mock-based tests covering protocol, lazy import, threshold, API invocation, timecode→ms, 0-based indexes, empty scenes, corrupt media, missing dep, version, parameters, deterministic override, unset env default** (Blocker #2)
37. **Integration test for PySceneDetect adapter using FFmpeg-generated video (solid A→B→C): real scenedetect, real decode, real ContentDetector, ordered boundaries, non-overlapping, integer ms, final scene within duration** (Blocker #3)
38. **CI installs `[scene_detection]` extra: `pip install -e ".[scene_detection,dev]"`** (Blocker #4)
39. **Duration propagation tests: contract requires duration_ms>=1, detector receives it, scenes bounded** (Blocker #6)
40. **Laravel duration-bound enforcement tests: validateScenes rejects scenes exceeding media duration; duration<=0 rejected; missing duration rejected; overflow never persists COMPLETED; overflow marks FAILED; retry FAILED→DETECTING→COMPLETED** (Blocker #7)
41. **ProcessMediaAsset validates worker result: requires `detector`, `detector_version`, `parameters` (array), `scenes` (array) — missing fields throw, not coerce to empty** (Blocker #8)
42. **PySceneDetectAdapter does NOT call `open_video()` before `scenedetect.detect()`** (Blocker #10)
43. All tests deterministic, no model downloads, no skips
44. All existing tests pass without modification
45. Code follows project conventions (Pint for PHP, flake8 for Python)
46. No secrets in logs or worker payloads

## Out of Scope

- Face tracking
- Clip ranking
- Captions
- Rendering
- AI inference beyond scene detection
- Social publishing
- Worker callback/polling endpoints
- Status polling API
- Real AI model downloads in CI
- Semantic scene analysis
- Shot type classification
- Object detection within scenes
- Audio-visual scene correlation

## Dependencies

1. **Existing Infrastructure**:
   - MediaAsset model and migration (Issue #35)
   - DerivedAsset model and migration (Issue #49)
   - MediaTranscript model and migration (Issue #51)
   - MediaProcessingContract class (Issue #45)
   - ProcessMediaAsset job (Issue #45, #49, #51)
   - ProcessMediaAction service (Issue #47, #49, #51)
   - Worker contract JSON schema (Issue #45)
   - Worker CLI with probe, extract-audio, transcribe subcommands (Issue #47, #49, #51)

2. **External Dependencies**:
   - `scenedetect[opencv-headless]>=0.6.7,<0.7` (PyPI package name: `scenedetect`, NOT `pyscenedetect`)
   - Python 3.10+ runtime
   - Laravel queue system (database driver)
   - Storage disk for video files

3. **CI Dependencies**:
   - MinIO service container (existing)
   - Python environment in CI
   - No model downloads in CI

## Risks

1. **PySceneDetect version differences**: Different versions may produce slightly different scene boundaries. Mitigate by pinning version in production, using deterministic CI engine.

2. **Timeout tuning**: 120s default may be insufficient for very long videos. Make configurable via environment variable.

3. **OpenCV dependency size**: opencv-python-headless adds significant package size. Acceptable for production; CI installs it via `[scene_detection]` extra for real integration tests.

4. **Scene boundary precision**: Different detectors may disagree on exact cut frame. Document 1-frame rounding tolerance.

5. **Pipeline restructuring complexity**: Changing ProcessMediaAsset flow requires careful testing to avoid regressions in existing audio extraction and transcription paths.

## Success Criteria

- All acceptance criteria satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic scene detection engine
- Worker tests run in CI from the start
- Scene detection engine abstraction is clean and replaceable
- MediaSceneAnalysis lifecycle is independent of MediaAsset processing states
- Pipeline restructuring preserves existing audio extraction and transcription behavior

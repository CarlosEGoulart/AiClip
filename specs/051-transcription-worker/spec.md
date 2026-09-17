# Specification: Deterministic Transcription Worker Stage

## Issue Reference

- **Issue**: #51
- **Title**: feat(media): add deterministic transcription worker stage
- **Branch**: @carlosegoulart/51/feat/transcription-worker
- **Milestone**: M4 Video Understanding (first slice)

## Goal

Add the first slice of video understanding: deterministic transcription of normalized audio. This stage takes the mono 16 kHz PCM WAV derivative produced by the audio extraction worker (Issue #49) and generates structured transcript metadata with timestamps and segments. The transcription must be deterministic in CI (no model downloads) while supporting a real runtime engine (faster-whisper) for production.

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
10. Transcription engine abstraction isolates application domain from specific engine implementation
11. Transcription lifecycle is dedicated (pending → transcribing → completed/failed) to reduce coupling with MediaAsset processing states
12. Contract version remains 1.0.0 with action "transcribe"

## Detailed Scope

### Included

1. **Transcription Engine Abstraction**
   - Abstract `Transcriber` interface (protocol) defining `transcribe(audio_path: str, options: dict) -> TranscriptResult`
   - `TranscriptResult` dataclass containing: `language`, `full_text`, `segments` (list of `Segment` dataclass with `start_ms`, `end_ms`, `text`), `engine`, `model`
   - CI/test implementation: `DeterministicTranscriber` that returns fixed output from a deterministic mapping (e.g., audio file hash → pre-defined transcript)
   - Runtime implementation: `FasterWhisperTranscriber` that uses faster-whisper library for real transcription
   - Engine selection configurable via environment variable `TRANSCRIPTION_ENGINE` (default: `faster_whisper`)

2. **Faster-Whisper Runtime Adapter**
   - Select faster-whisper as the runtime transcription engine
   - Configuration: model name (`WHISPER_MODEL`, default `base`), device (`WHISPER_DEVICE`, default `cpu`), compute type (`WHISPER_COMPUTE_TYPE`, default `int8`), model cache directory (`WHISPER_MODEL_CACHE`, default `~/.cache/whisper`)
   - Model download occurs only at runtime, never in CI
   - CPU-capable, supports small model sizes suitable for server-side processing
   - Deterministic output not guaranteed across versions; CI uses deterministic fake

3. **Python Worker: `transcribe` CLI Subcommand**
   - Extend `services/worker/aiclip_worker/cli.py` with `transcribe` subcommand
   - Accepts extended media processing contract (with `action: "transcribe"` and `derived_asset_id` fields)
   - Returns structured transcription result as JSON to stdout
   - Exit codes: 0 (success), 1 (processing error), 2 (invalid input)

4. **Python Worker: Transcribe Action**
   - New file `services/worker/aiclip_worker/actions/transcribe.py`
   - Reads normalized audio from local filesystem path (pre-staged by Laravel or accessible via storage)
   - Invokes selected transcription engine
   - Returns structured result with transcript metadata
   - Timeout enforcement (configurable via `TRANSCRIBE_TIMEOUT_SECONDS` env var, default 300)

5. **Contract Schema Extension**
   - Extend `services/worker/contracts/media_processing_v1.json` with `action: "transcribe"` and `derived_asset_id` field
   - `action` field: string, enum `["probe", "extract_audio", "transcribe"]`, default `"probe"`
   - `derived_asset_id` field: integer, required for transcribe action, references DerivedAsset.id containing normalized audio
   - `output_storage` field not required for transcribe (output is metadata only, not a new file)

6. **Laravel MediaTranscript Model**
   - New Eloquent model `MediaTranscript` representing a transcript of a MediaAsset
   - Columns: `id`, `media_asset_id` (FK), `derived_asset_id` (FK), `status` (enum: pending/transcribing/completed/failed), `language`, `full_text`, `segments` (JSON), `engine`, `model`, `error`, `created_at`, `updated_at`
   - Relationship: `belongsTo(MediaAsset::class)`, `belongsTo(DerivedAsset::class)`
   - MediaAsset `hasOne(MediaTranscript::class)`

7. **Laravel Migration: `media_transcripts` Table**
   - Creates `media_transcripts` table with schema above
   - Foreign key to `media_assets.id` with cascade delete
   - Foreign key to `derived_assets.id` with restrict delete (transcript references audio)
   - Unique constraint on `(media_asset_id)` to prevent duplicate transcripts per asset

8. **ProcessMediaAsset Job: Transcription Stage**
   - After audio extraction completes (DerivedAsset of type `audio_normalized` exists), check if MediaTranscript already exists (idempotency)
   - If no transcript exists: create MediaTranscript with status `pending`
   - Invoke `ProcessMediaAction::transcribe()` action with contract containing `derived_asset_id`
   - On success: update MediaTranscript with status `completed`, store language, full_text, segments, engine, model
   - On failure: update MediaTranscript with status `failed`, store error message
   - Idempotent: skip transcription if MediaTranscript with status `completed` exists for this MediaAsset

9. **Transcription Lifecycle**
   - Dedicated lifecycle for MediaTranscript: `pending → transcribing → completed/failed`
   - MediaAsset processing state remains `completed` after audio extraction; transcription is a separate lifecycle
   - MediaAsset can have at most one MediaTranscript (unique constraint)

10. **Segment Format**
    - JSON array of objects: `{ "start_ms": int, "end_ms": int, "text": string }`
    - Ordered by `start_ms` ascending
    - Monotonically increasing: `start_ms >= 0`, `end_ms >= start_ms`, `segments[i].start_ms >= segments[i-1].end_ms` (no overlap)
    - `text` trimmed, non-empty

11. **Deterministic Unit Tests**
    - Python worker tests for transcribe logic (pytest)
    - Python worker tests for CLI transcribe command
    - Python worker tests for transcription engine abstraction
    - Laravel feature tests for MediaTranscript model
    - Laravel feature tests for ProcessMediaAsset transcription stage
    - All tests deterministic, no model downloads, no skips

12. **CI Integration**
    - Worker tests run in CI from the start
    - No model downloads in CI
    - Deterministic transcriber used in CI tests
    - Python dependencies installed in CI

### NOT Included

- Scene detection
- Face tracking
- Clip ranking
- Captions (beyond raw transcript segments)
- Rendering
- AI inference beyond transcription
- Social publishing
- Worker callback/polling endpoints (future slice)
- Status polling API (future slice)
- Real AI model downloads in CI
- Redis/RabbitMQ/Kafka/Celery (unless explicitly justified)
- Multiple language detection (single language per transcript)
- Speaker diarization
- Word-level timestamps (segment-level only)
- Transcript editing or correction
- Real-time streaming transcription
- Audio-only source handling beyond normalized WAV

## Transcription Engine Abstraction

### Interface Definition

```python
from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import List

@dataclass
class Segment:
    start_ms: int
    end_ms: int
    text: str

@dataclass
class TranscriptResult:
    language: str
    full_text: str
    segments: List[Segment]
    engine: str
    model: str

class Transcriber(ABC):
    @abstractmethod
    def transcribe(self, audio_path: str, options: dict) -> TranscriptResult:
        """Transcribe audio file and return structured result."""
        pass
```

### Deterministic Implementation (CI/Tests)

- `DeterministicTranscriber` returns fixed output based on audio file path hash
- No external dependencies, no model downloads
- Output mapping: `hash(audio_path) → pre-defined transcript`
- Used in CI and deterministic tests

### Runtime Implementation (Production)

- `FasterWhisperTranscriber` uses faster-whisper library
- Configuration via environment variables
- Model download at runtime, cached in `WHISPER_MODEL_CACHE`
- CPU inference by default, configurable device/compute type
- Error handling for model loading, inference failures, timeouts

## Faster-Whisper Evaluation

### Selection Rationale

faster-whisper is selected as the runtime transcription engine based on:

1. **CPU Capability**: Supports CPU inference without GPU requirement, suitable for server-side processing
2. **Model Size**: Offers small models (tiny, base, small) suitable for server memory constraints
3. **CI Constraints**: CI uses deterministic fake, not real engine; no model downloads needed
4. **Configurability**: Model name, device, compute type all configurable via environment variables
5. **Performance**: CTranslate2 backend provides faster inference than original Whisper
6. **Compatibility**: Compatible with OpenAI Whisper models, well-maintained

### Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `TRANSCRIPTION_ENGINE` | `faster_whisper` | Engine selection (`deterministic` for CI, `faster_whisper` for production) |
| `WHISPER_MODEL` | `base` | Model size (tiny, base, small, medium, large-v2) |
| `WHISPER_DEVICE` | `cpu` | Device (cpu, cuda) |
| `WHISPER_COMPUTE_TYPE` | `int8` | Compute type (int8, float16, float32) |
| `WHISPER_MODEL_CACHE` | `~/.cache/whisper` | Model cache directory |
| `TRANSCRIBE_TIMEOUT_SECONDS` | `300` | Timeout for transcription |

### CI Behavior

- `TRANSCRIPTION_ENGINE=deterministic` in CI
- No model downloads
- Deterministic output from fixtures
- Fast execution

## Worker CLI Interface Specification

### Input Contract

The worker CLI accepts an extended `MediaProcessingContract` v1.0.0 as JSON.

**CLI Usage**:
```bash
# Probe (existing)
python -m aiclip_worker.cli probe --contract-json '{"version": "1.0.0", ...}'

# Extract Audio (existing)
python -m aiclip_worker.cli extract-audio --contract-json '{"version": "1.0.0", "action": "extract_audio", ...}'

# Transcribe (new)
python -m aiclip_worker.cli transcribe --contract-json '{"version": "1.0.0", "action": "transcribe", ...}'
```

**Extended Contract Schema** (additions to `media_processing_v1.json`):

```json
{
  "action": {
    "type": "string",
    "enum": ["probe", "extract_audio", "transcribe"],
    "default": "probe"
  },
  "derived_asset_id": {
    "type": "integer",
    "minimum": 1,
    "description": "Required for transcribe action. References DerivedAsset containing normalized audio."
  }
}
```

**Input Validation**:
- Contract must validate against extended schema
- `action` must be `"transcribe"` for transcribe subcommand
- `derived_asset_id` must be present and valid for transcribe action
- Source `storage.key` must point to existing audio file (normalized WAV)
- Unknown MAJOR versions must be rejected

### Output Contract

**Success Output** (stdout):
```json
{
  "status": "success",
  "transcription": {
    "language": "en",
    "full_text": "Hello world, this is a test transcript.",
    "segments": [
      {
        "start_ms": 0,
        "end_ms": 1200,
        "text": "Hello world,"
      },
      {
        "start_ms": 1200,
        "end_ms": 3500,
        "text": "this is a test transcript."
      }
    ],
    "engine": "faster_whisper",
    "model": "base"
  }
}
```

**Error Output** (stdout):
```json
{
  "status": "error",
  "error": "Transcription engine failed with timeout",
  "stderr": "Model loading exceeded timeout"
}
```

**Exit Codes**:
- `0`: Success
- `1`: Processing error (file not found, corrupt audio, engine failure)
- `2`: Invalid input (bad contract, missing fields, wrong action)

### Security Requirements

- Worker MUST NOT write to PostgreSQL
- Worker MUST NOT access database credentials
- Worker MUST NOT log secrets or tokens
- Worker MUST use subprocess-safe APIs (no shell interpolation)
- Worker MUST enforce timeout on transcription invocation
- Worker MUST capture stderr for error reporting
- Worker MUST clean up temporary files on success and failure
- Worker MUST NOT write to original media storage keys
- Worker MUST NOT download models in CI (deterministic engine used)

## MediaTranscript Model Specification

### Schema

```php
Schema::create('media_transcripts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
    $table->foreignId('derived_asset_id')->constrained()->restrictOnDelete();
    $table->string('status', 16); // pending, transcribing, completed, failed
    $table->string('language', 8)->nullable();
    $table->text('full_text')->nullable();
    $table->json('segments')->nullable();
    $table->string('engine', 32)->nullable();
    $table->string('model', 32)->nullable();
    $table->text('error')->nullable();
    $table->timestamps();

    $table->unique('media_asset_id');
});
```

### Model

```php
class MediaTranscript extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_TRANSCRIBING = 'transcribing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_TRANSCRIBING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'media_asset_id',
        'derived_asset_id',
        'status',
        'language',
        'full_text',
        'segments',
        'engine',
        'model',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function derivedAsset(): BelongsTo
    {
        return $this->belongsTo(DerivedAsset::class);
    }
}
```

### MediaAsset Relationship

```php
// Add to MediaAsset model:
public function transcript(): HasOne
{
    return $this->hasOne(MediaTranscript::class);
}
```

## MediaTranscript Lifecycle

### Status Transitions

```
pending → transcribing → completed
                     → failed
```

### Transition Methods

- `markTranscribing()`: pending → transcribing
- `markCompleted(string $language, string $fullText, array $segments, string $engine, string $model)`: transcribing → completed
- `markFailed(string $error)`: transcribing → failed

### Validation

- Only valid transitions allowed
- Invalid transitions are no-ops (log warning)
- Unique constraint prevents duplicate transcripts per MediaAsset

## ProcessMediaAsset Job: Transcription Stage

### Current Behavior (after Issue #49)

```
stored → queued → processing → probed → completed
                                   → failed
```

### New Behavior (transcription as separate lifecycle)

```
stored → queued → processing → probed → completed
                                   → failed

MediaTranscript lifecycle (separate):
pending → transcribing → completed
                     → failed
```

### Flow

1. Job reloads MediaAsset
2. Validates idempotency key
3. Marks as queued
4. Builds contract (with `action: "probe"`)
5. Marks as processing
6. Invokes `ProcessMediaAction::probe()` → gets probe result
7. Stores probe result, marks as `probed`
8. Checks if audio stream exists in probe result (`audio_codec` is not null)
9. If audio exists:
   a. Checks for existing `audio_normalized` DerivedAsset (idempotency)
   b. If not exists: invokes `ProcessMediaAction::extractAudio()` → creates DerivedAsset
   c. If audio extraction succeeds or DerivedAsset exists:
      - Check for existing MediaTranscript (idempotency)
      - If no transcript exists:
        i. Create MediaTranscript with status `pending`
        ii. Build transcribe contract with `derived_asset_id`
        iii. Mark MediaTranscript as `transcribing`
        iv. Invoke `ProcessMediaAction::transcribe()`
        v. On success: update MediaTranscript with result, mark `completed`
        vi. On failure: mark MediaTranscript `failed`
10. If no audio: mark MediaAsset as `completed` (no audio to transcribe)

### Idempotency

- If MediaTranscript with status `completed` already exists for this MediaAsset, transcription is skipped
- If MediaTranscript with status `transcribing` exists, transcription is retried (job may have failed previously)
- MediaTranscript uniqueness: `(media_asset_id)` unique constraint

## ProcessMediaAction: transcribe() Specification

```php
public function transcribe(MediaProcessingContract $contract): array
```

- Invokes worker CLI: `python -m aiclip_worker.cli transcribe --contract-json <json>`
- Timeout: configurable via `media.transcribe_timeout_seconds` (default 300)
- Returns parsed JSON result with `transcription` data
- Throws `ProcessMediaException` on failure

## Acceptance Criteria

1. Python worker CLI supports `transcribe` subcommand
2. Worker CLI accepts extended contract with `action: "transcribe"` and `derived_asset_id` fields
3. Worker CLI returns structured transcription result on success
4. Worker CLI returns structured error on failure
5. Worker CLI enforces timeout on transcription invocation
6. Worker CLI uses subprocess-safe APIs (no shell interpolation)
7. Worker CLI captures stderr on failure
8. Worker CLI exits with appropriate exit codes (0, 1, 2)
9. Worker CLI cleans up temporary files on success and failure
10. Transcription engine abstraction exists with deterministic CI implementation
11. Faster-whisper runtime adapter implemented and configurable
12. ProcessMediaAction has `transcribe()` method
13. MediaTranscript model exists with correct schema
14. MediaTranscript migration creates `media_transcripts` table
15. MediaAsset has `transcript()` relationship
16. ProcessMediaAsset job chains probe → audio extraction → transcription
17. ProcessMediaAsset job creates MediaTranscript on successful transcription
18. ProcessMediaAsset job is idempotent (skips if MediaTranscript exists)
19. ProcessMediaAsset job marks failed on transcription error
20. MediaTranscript lifecycle follows pending → transcribing → completed/failed
21. Segments format is JSON array of {start_ms, end_ms, text}
22. Deterministic unit tests exist for Python worker transcribe logic
23. Deterministic unit tests exist for worker CLI transcribe command
24. Deterministic feature tests exist for MediaTranscript model
25. Deterministic feature tests exist for ProcessMediaAsset transcription
26. All tests deterministic, no model downloads, no skips
27. Worker tests run in CI from the start
28. All existing tests pass without modification
29. Code follows project conventions (Pint for PHP, flake8 for Python)
30. No secrets in logs or worker payloads

## Security Considerations

1. **No secrets in contract**: Contract contains only IDs and storage references
2. **No database access**: Worker does not connect to PostgreSQL
3. **No shell interpolation**: Worker uses `subprocess.run` with list arguments
4. **Timeout enforcement**: Transcription invocations have configurable timeout
5. **Error isolation**: Worker failures do not leak sensitive information
6. **Input validation**: Contract validated before processing
7. **No PII in logs**: Transcription results logged without user-identifiable information
8. **Temporary file cleanup**: No residual files left on worker filesystem
9. **Original media protection**: Worker never writes to source storage key
10. **Derived asset ownership**: Laravel controls all metadata and lifecycle
11. **Model download isolation**: Models downloaded only at runtime, never in CI
12. **Engine abstraction**: Application domain decoupled from specific engine implementation

## Out of Scope

- Scene detection
- Face tracking
- Clip ranking
- Captions (beyond raw transcript segments)
- Rendering
- AI inference beyond transcription
- Social publishing
- Worker callback/polling endpoints (future slice)
- Status polling API (future slice)
- Real AI model downloads in CI
- Multiple language detection
- Speaker diarization
- Word-level timestamps
- Transcript editing or correction
- Real-time streaming transcription
- Audio-only source handling beyond normalized WAV
- Transcript storage optimization
- Transcript search or indexing
- Transcript export formats

## Dependencies

1. **Existing Infrastructure**:
   - MediaAsset model and migration (Issue #35)
   - DerivedAsset model and migration (Issue #49)
   - MediaProcessingContract class (Issue #45)
   - ProcessMediaAsset job (Issue #45, #49)
   - ProcessMediaAction service (Issue #47, #49)
   - Worker contract JSON schema (Issue #45)
   - Worker CLI with probe and extract-audio subcommands (Issue #47, #49)

2. **External Dependencies**:
   - faster-whisper Python library (runtime only)
   - Python 3.10+ runtime
   - Laravel queue system (database driver)
   - Storage disk for audio files

3. **CI Dependencies**:
   - MinIO service container (existing)
   - Python environment in CI
   - No model downloads in CI

## Risks

1. **faster-whisper version differences**: Different versions may produce slightly different transcription output. Mitigate by pinning version in production, using deterministic CI engine.

2. **Timeout tuning**: 300s default may be insufficient for very long audio. Make configurable via environment variable.

3. **Model size constraints**: Large models may exceed server memory. Make model configurable, default to small model.

4. **CPU inference performance**: CPU inference may be slow for long audio. Consider GPU support as future optimization.

5. **Deterministic CI vs runtime divergence**: CI uses deterministic fake, runtime uses real engine. Acceptable for testing infrastructure; integration tests use real engine.

## Success Criteria

- All acceptance criteria satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic transcription engine
- Worker tests run in CI from the start
- Transcription engine abstraction is clean and replaceable
- MediaTranscript lifecycle is independent of MediaAsset processing states
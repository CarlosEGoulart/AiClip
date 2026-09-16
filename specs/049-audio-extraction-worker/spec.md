# Specification: Deterministic Audio Extraction Worker

## Issue Reference

- **Issue**: #49
- **Title**: feat(media): add deterministic audio extraction worker stage
- **Branch**: @carlosegoulart/49/feat/audio-extraction-worker
- **Milestone**: M3 Asynchronous Media Processing (in progress)

## Goal

Add the NEXT stage in the deterministic media processing pipeline: FFmpeg-based audio extraction from an already-probed video. Extract audio, normalize it for future speech-to-text transcription, and store as a private derivative object in S3-compatible storage.

This is the second processing stage after probing (Issue #47). The normalized audio derivative becomes the canonical input for future transcription (not yet implemented).

## Architecture Invariants

1. Laravel remains authoritative for PostgreSQL; Python worker MUST NOT write PostgreSQL
2. Binary media in S3-compatible storage; original uploads immutable
3. FFmpeg must be deterministically pinned in CI
4. Worker must use subprocess-safe APIs, avoid shell interpolation, enforce timeout, capture exit status/stderr, parse JSON defensively
5. No secrets in logs or worker payloads
6. STRICT, FAIL-CLOSED execution
7. CI must use deterministic fakes/fixtures for worker tests
8. No external AI provider or model download is allowed
9. Secrets remain outside committed files
10. Derived assets are stored as separate objects in S3; metadata in PostgreSQL

## Detailed Scope

### Included

1. **Python Worker: `extract-audio` CLI Subcommand**
   - Extend `services/worker/aiclip_worker/cli.py` with `extract-audio` subcommand
   - Accepts extended media processing contract (with `action` and `output_storage` fields)
   - Returns structured extraction result as JSON to stdout
   - Exit codes: 0 (success), 1 (processing error), 2 (invalid input)

2. **Python Worker: ExtractAudio Action**
   - New file `services/worker/aiclip_worker/actions/extract_audio.py`
   - Invokes FFmpeg with deterministic flags for audio extraction and normalization
   - Reads source media from local filesystem path (pre-staged by Laravel or accessible via storage)
   - Writes normalized audio to specified output path
   - Returns structured result with output file metadata

3. **Contract Schema Extension**
   - Extend `services/worker/contracts/media_processing_v1.json` with optional `action` and `output_storage` fields
   - `action` field: string, enum `["probe", "extract_audio"]`, defaults to `"probe"` for backward compatibility
   - `output_storage` field: object with `disk`, `key`, `mime_type` for output destination

4. **Laravel ProcessMediaAction: `extractAudio()` Method**
   - Add `extractAudio(MediaProcessingContract $contract): array` method to `ProcessMediaAction`
   - Invokes worker CLI with `extract-audio` subcommand
   - Passes contract with output storage info
   - Returns structured extraction result

5. **Laravel DerivedAsset Model**
   - New Eloquent model `DerivedAsset` representing a derivative of a MediaAsset
   - Columns: `id`, `media_asset_id` (FK), `type` (enum: `audio_normalized`), `storage_disk`, `storage_key`, `mime_type`, `size_bytes`, `duration_ms`, `created_at`
   - Relationship: `belongsTo(MediaAsset::class)`
   - MediaAsset `hasMany(DerivedAsset::class)`

6. **Laravel Migration: `derived_assets` Table**
   - Creates `derived_assets` table with schema above
   - Foreign key to `media_assets.id` with cascade delete

7. **ProcessMediaAsset Job: Audio Extraction Stage**
   - After probe succeeds, check if audio streams exist in probe result
   - If audio exists: invoke `extractAudio()` action
   - On success: create `DerivedAsset` record, mark MediaAsset as `completed`
   - On failure: mark MediaAsset as `failed`
   - Idempotent: skip extraction if DerivedAsset of type `audio_normalized` already exists for this MediaAsset

8. **Audio Normalization Parameters** (verified for transcription pipeline)
   - **Channels**: 1 (mono)
   - **Sample rate**: 16000 Hz (16 kHz)
   - **Codec**: PCM WAV (pcm_s16le)
   - **Bit depth**: 16-bit signed little-endian
   - **Rationale**: 16 kHz mono PCM WAV is the standard input for speech-to-text systems (Whisper, Deepgram, Google Speech-to-Text). Captures frequencies up to 8 kHz, sufficient for human speech. Uncompressed format preserves audio quality.

9. **Deterministic Unit Tests**
   - Python worker tests for extract_audio logic (pytest)
   - Python worker tests for CLI extract-audio command
   - Python worker error handling tests
   - Laravel feature tests for DerivedAsset model
   - Laravel feature tests for ProcessMediaAsset audio extraction stage

10. **CI Integration**
    - Worker tests run in CI from the start (no post-hoc fix like Issue #47)
    - FFmpeg deterministically pinned in CI
    - Python dependencies installed in CI

### NOT Included

- Whisper/transcription (future slice)
- Scene detection
- Face tracking
- Clip ranking
- Captions
- Rendering
- AI inference
- Social publishing
- Worker callback/polling endpoints (future slice)
- Status polling API (future slice)
- Real AI model downloads
- Redis/RabbitMQ/Kafka/Celery (unless explicitly justified)
- Audio-only source handling (this issue expects video with audio; audio-only sources are a future consideration)
- Multiple audio stream selection (takes first audio stream only)

## Worker CLI Interface Specification

### Input Contract

The worker CLI accepts an extended `MediaProcessingContract` v1.0.0 as JSON.

**CLI Usage**:
```bash
# Probe (existing)
python -m aiclip_worker.cli probe --contract-json '{"version": "1.0.0", ...}'

# Extract Audio (new)
python -m aiclip_worker.cli extract-audio --contract-json '{"version": "1.0.0", "action": "extract_audio", ...}'
```

**Extended Contract Schema** (additions to `media_processing_v1.json`):

```json
{
  "action": {
    "type": "string",
    "enum": ["probe", "extract_audio"],
    "default": "probe"
  },
  "output_storage": {
    "type": "object",
    "required": ["disk", "key", "mime_type"],
    "properties": {
      "disk": { "type": "string", "minLength": 1 },
      "key": { "type": "string", "minLength": 1 },
      "mime_type": { "type": "string", "minLength": 1 }
    }
  }
}
```

**Input Validation**:
- Contract must validate against extended schema
- `action` must be `"extract_audio"` for extract-audio subcommand
- `output_storage` must be present and valid for extract-audio action
- Source `storage.key` must be non-empty and file must exist
- Unknown MAJOR versions must be rejected

### Output Contract

**Success Output** (stdout):
```json
{
  "status": "success",
  "extraction": {
    "output_path": "/tmp/aiclip-work/extracted/audio_abc123.wav",
    "output_size_bytes": 3200000,
    "duration_ms": 120000,
    "sample_rate": 16000,
    "channels": 1,
    "codec": "pcm_s16le",
    "format": "wav"
  }
}
```

**Error Output** (stdout):
```json
{
  "status": "error",
  "error": "FFmpeg failed with exit code 1",
  "stderr": "No audio stream found in input"
}
```

**Exit Codes**:
- `0`: Success
- `1`: Processing error (file not found, corrupt media, FFmpeg failure, no audio stream)
- `2`: Invalid input (bad contract, missing fields, wrong action)

### Security Requirements

- Worker MUST NOT write to PostgreSQL
- Worker MUST NOT access database credentials
- Worker MUST NOT log secrets or tokens
- Worker MUST use subprocess-safe APIs (no shell interpolation)
- Worker MUST enforce timeout on FFmpeg invocation
- Worker MUST capture stderr for error reporting
- Worker MUST clean up temporary files on success and failure
- Worker MUST NOT write to original media storage keys

## Audio Normalization Specification

### Parameters

| Parameter | Value | Rationale |
|-----------|-------|-----------|
| Channels | 1 (mono) | Simplifies downstream STT processing |
| Sample rate | 16000 Hz | Standard for speech-to-text; captures up to 8 kHz |
| Codec | pcm_s16le | Uncompressed 16-bit PCM; lossless quality |
| Format | WAV | Standard container for PCM audio |
| Bit depth | 16-bit signed | Sufficient dynamic range for speech |

### FFmpeg Command

```bash
ffmpeg -y -i <input_path> \
  -vn \
  -acodec pcm_s16le \
  -ar 16000 \
  -ac 1 \
  <output_path>
```

**Flags**:
- `-y`: Overwrite output without asking
- `-i <input_path>`: Input file path
- `-vn`: No video (discard video stream)
- `-acodec pcm_s16le`: 16-bit signed little-endian PCM
- `-ar 16000`: Sample rate 16 kHz
- `-ac 1`: Mono output
- `<output_path>`: Output WAV file path

### Timeout

- Default: 120 seconds (configurable via `EXTRACT_AUDIO_TIMEOUT_SECONDS` env var)
- Rationale: Audio extraction is faster than video transcoding but may take longer than probing for large files

### Temporary File Management

- Worker writes to a temporary file in a job-isolated directory
- On success: file is moved/renamed to final output path
- On failure: temporary file is deleted
- Temporary directory is cleaned up after extraction

## Derivative Object Naming Convention

### Source Media Key

Format: `{project_id}/{user_id}/{uuid}/{original_name}`

Example: `42/7/a1b2c3d4-e5f6-7890-abcd-ef1234567890/video.mp4`

### Derived Audio Key

Format: `{project_id}/{user_id}/{uuid}/audio_normalized.wav`

Example: `42/7/a1b2c3d4-e5f6-7890-abcd-ef1234567890/audio_normalized.wav`

**Rules**:
- Derived audio lives in the same UUID directory as the source
- Filename is deterministic: `audio_normalized.wav`
- Same storage disk as source (configurable)
- MIME type: `audio/wav`

## Storage Ownership Rules

1. **Original media**: Immutable. Never modified or deleted by worker.
2. **Derived audio**: Created by worker, metadata managed by Laravel.
3. **Worker writes**: Only to designated output path (never to source path).
4. **Laravel controls**: Creation, deletion, lifecycle of derived assets.
5. **Cleanup**: Derived assets deleted when parent MediaAsset is deleted (cascade).

## ProcessMediaAsset Job: Audio Extraction Stage

### Current Behavior (after Issue #47)

```
stored → queued → processing → probed
```

### New Behavior

```
stored → queued → processing → probed → completed
                                   probed → failed (extraction error)
```

### Flow

1. Job reloads MediaAsset
2. Validates idempotency key
3. Marks as queued
4. Builds contract (with `action: "probe"`)
5. Marks as processing
6. Invokes `ProcessMediaAction::probe()` → gets probe result
7. Stores probe result, marks as `probed`
8. **NEW**: Checks if audio stream exists in probe result (`audio_codec` is not null)
9. **NEW**: If audio exists:
   a. Checks for existing `audio_normalized` DerivedAsset (idempotency)
   b. If exists: skip extraction, mark as `completed`
   c. If not exists: invokes `ProcessMediaAction::extractAudio()`
   d. On success: creates `DerivedAsset` record, marks as `completed`
   e. On failure: marks as `failed`
10. **NEW**: If no audio: marks as `completed` (no audio to extract — not an error)

### Idempotency

- If `DerivedAsset` of type `audio_normalized` already exists for this MediaAsset, extraction is skipped
- This handles job retries without re-processing
- DerivedAsset uniqueness: `(media_asset_id, type)` unique constraint

## DerivedAsset Model Specification

### Schema

```php
Schema::create('derived_assets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
    $table->string('type', 32); // 'audio_normalized'
    $table->string('storage_disk', 64);
    $table->string('storage_key', 512);
    $table->string('mime_type', 128);
    $table->unsignedBigInteger('size_bytes');
    $table->unsignedBigInteger('duration_ms')->nullable();
    $table->unsignedInteger('sample_rate')->nullable();
    $table->unsignedTinyInteger('channels')->nullable();
    $table->string('codec', 64)->nullable();
    $table->timestamps();

    $table->unique(['media_asset_id', 'type']);
});
```

### Model

```php
class DerivedAsset extends Model
{
    const TYPE_AUDIO_NORMALIZED = 'audio_normalized';

    protected $fillable = [
        'media_asset_id',
        'type',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'duration_ms',
        'sample_rate',
        'channels',
        'codec',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'sample_rate' => 'integer',
            'channels' => 'integer',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
```

### MediaAsset Relationship

```php
// Add to MediaAsset model:
public function derivedAssets(): HasMany
{
    return $this->hasMany(DerivedAsset::class);
}
```

## ProcessMediaAction: extractAudio() Specification

```php
public function extractAudio(MediaProcessingContract $contract): array
```

- Invokes worker CLI: `python -m aiclip_worker.cli extract-audio --contract-json <json>`
- Timeout: configurable via `media.extract_audio_timeout_seconds` (default 120)
- Returns parsed JSON result with `extraction` data
- Throws `ProcessMediaException` on failure

## MediaAsset State Transition Updates

### Current State Graph (after Issue #47)

```
stored → queued → processing → probed → completed
                                   → failed
                   → failed
                   → failed
```

### No State Changes Required

The existing state graph already supports the audio extraction flow:
- `probed` → `completed` (extraction succeeds or no audio)
- `probed` → `failed` (extraction fails)

The audio extraction happens WITHIN the `processing` → `probed` → `completed` transition. No new states needed.

## Acceptance Criteria

1. Python worker CLI supports `extract-audio` subcommand
2. Worker CLI accepts extended contract with `action` and `output_storage` fields
3. Worker CLI returns structured extraction result on success
4. Worker CLI returns structured error on failure
5. Worker CLI enforces timeout on FFmpeg invocation
6. Worker CLI uses subprocess-safe APIs (no shell interpolation)
7. Worker CLI captures stderr on failure
8. Worker CLI exits with appropriate exit codes (0, 1, 2)
9. Worker CLI cleans up temporary files on success and failure
10. FFmpeg command produces mono 16 kHz PCM WAV output
11. ProcessMediaAction has `extractAudio()` method
12. DerivedAsset model exists with correct schema
13. DerivedAsset migration creates `derived_assets` table
14. MediaAsset has `derivedAssets()` relationship
15. ProcessMediaAsset job chains probe → audio extraction
16. ProcessMediaAsset job creates DerivedAsset on successful extraction
17. ProcessMediaAsset job is idempotent (skips if DerivedAsset exists)
18. ProcessMediaAsset job marks failed on extraction error
19. ProcessMediaAsset job marks completed when no audio stream exists
20. Deterministic unit tests exist for Python worker extract_audio logic
21. Deterministic unit tests exist for worker CLI extract-audio command
22. Deterministic feature tests exist for DerivedAsset model
23. Deterministic feature tests exist for ProcessMediaAsset audio extraction
24. Worker tests run in CI from the start
25. All existing tests pass without modification
26. Code follows project conventions (Pint for PHP, flake8 for Python)
27. No secrets in logs or worker payloads
28. FFmpeg deterministically pinned in CI

## Security Considerations

1. **No secrets in contract**: Contract contains only IDs and storage references
2. **No database access**: Worker does not connect to PostgreSQL
3. **No shell interpolation**: Worker uses `subprocess.run` with list arguments
4. **Timeout enforcement**: FFmpeg invocations have configurable timeout
5. **Error isolation**: Worker failures do not leak sensitive information
6. **Input validation**: Contract validated before processing
7. **No PII in logs**: Extraction results logged without user-identifiable information
8. **Temporary file cleanup**: No residual files left on worker filesystem
9. **Original media protection**: Worker never writes to source storage key
10. **Derived asset ownership**: Laravel controls all metadata and lifecycle

## Out of Scope

- Whisper/transcription (future slice)
- Scene detection
- Face tracking
- Clip ranking
- Captions
- Rendering
- AI inference
- Social publishing
- Worker callback/polling endpoints (future slice)
- Status polling API (future slice)
- Real AI model downloads
- Audio-only source handling
- Multiple audio stream selection
- Audio quality metrics
- Loudness normalization (LUFS)
- Silence removal
- Audio format conversion beyond normalization

## Dependencies

1. **Existing Infrastructure**:
   - MediaAsset model and migration (Issue #35)
   - MediaProcessingContract class (Issue #45)
   - ProcessMediaAsset job (Issue #45)
   - ProcessMediaAction service (Issue #47)
   - Worker contract JSON schema (Issue #45)
   - Worker CLI with probe subcommand (Issue #47)

2. **External Dependencies**:
   - FFmpeg binary (deterministically pinned)
   - Python 3.10+ runtime
   - Laravel queue system (database driver)
   - Storage disk for media files

3. **CI Dependencies**:
   - MinIO service container (existing)
   - FFmpeg binary in CI environment
   - Python environment in CI

## Risks

1. **FFmpeg version differences**: Different FFmpeg versions may produce slightly different WAV output. Mitigate by using deterministic flags and pinning version in CI.

2. **Timeout tuning**: 120s default may be insufficient for very large files. Make configurable via environment variable.

3. **Storage access**: Worker needs read access to source and write access to output. For S3-compatible storage, worker needs AWS credentials (read-write). Ensure credentials are not logged or exposed.

4. **Large file handling**: Audio extraction from very large video files may consume significant disk space for temporary files. Mitigate with bounded cleanup.

5. **No audio stream**: Video without audio is not an error — mark as completed without creating DerivedAsset. This is a valid state.

## Success Criteria

- All acceptance criteria satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic FFmpeg pinning
- Worker tests run in CI from the start

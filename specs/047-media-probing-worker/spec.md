# Specification: Deterministic Media Probing Worker

## Issue Reference

- **Issue**: #47
- **Title**: feat(media): implement deterministic media probing worker
- **Branch**: @carlosegoulart/47/feat/media-probing-worker
- **Milestone**: M3 Asynchronous Media Processing (in progress)

## Goal

Implement the first real deterministic media worker capability: media probing / metadata extraction using a Python worker with FFprobe/FFmpeg. This replaces the placeholder processing logic in `ProcessMediaAsset` with actual subprocess invocation and deterministic result parsing. Additionally, stabilize the confirmed flaky frontend authentication focus test.

## Architecture Invariants

1. Laravel remains authoritative for PostgreSQL; Python worker must NOT write PostgreSQL
2. Binary media in S3-compatible storage; original uploads immutable
3. FFprobe/FFmpeg must be deterministically pinned in CI
4. Worker must use subprocess-safe APIs, avoid shell interpolation, enforce timeout, capture exit status/stderr, parse JSON defensively
5. No secrets in logs or worker payloads
6. STRICT, FAIL-CLOSED execution
7. CI must use deterministic fakes/fixtures for worker tests
8. No external AI provider or model download is allowed
9. Secrets remain outside committed files

## Detailed Scope

### Included

1. **Python Worker CLI Entry Point**
   - CLI module `services/worker/aiclip_worker/cli.py` with `probe` command
   - Accepts media processing contract as JSON via stdin or file argument
   - Returns structured probe result as JSON to stdout
   - Exit codes: 0 (success), 1 (processing error), 2 (invalid input)

2. **ProbesMedia Action**
   - `services/worker/aiclip_worker/actions/probe.py`
   - Invokes FFprobe with deterministic flags and JSON output
   - Parses FFprobe JSON output defensively
   - Extracts: duration, width, height, codec, bitrate, fps, audio channels, sample rate
   - Enforces configurable timeout (default 30s)
   - Captures stderr on failure

3. **Laravel ProcessMediaAsset Job Refactoring**
   - Replace placeholder completed-marking with real subprocess invocation
   - Add `ProcessMediaAction` service class to invoke worker CLI
   - Capture exit status, stdout, stderr
   - Map worker exit codes to appropriate state transitions
   - Store probe result in MediaAsset (new columns: `probe_result` JSON, `duration_ms` integer)

4. **MediaAsset State Transition Updates**
   - Add `probed` state after successful probing
   - Update transition graph: `processing → probed → completed`
   - Update `MediaAsset` model with new state constants and transitions

5. **Flaky Test Stabilization**
   - Fix `apps/web/src/__tests__/Auth.test.tsx` test "associates all 422 field errors and focuses first invalid field for register=false"
   - Use `waitFor()` synchronization with focus effect
   - NO `test.retry()`, NO `setTimeout()`, NO `sleep()`, NO skipping, NO removing `toHaveFocus()`

6. **Deterministic Unit Tests**
   - Python worker probe logic tests with fixture media files
   - Worker error handling tests (non-existent file, corrupt file, timeout)
   - Laravel job invocation tests with mocked worker responses

7. **Deterministic Feature Tests**
   - ProcessMediaAsset job with mocked worker responses
   - MediaAsset state transitions
   - Auth focus test stabilization verification

### NOT Included

- FFmpeg transcoding
- Whisper/transcription
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

## Worker CLI Interface Specification

### Input Contract

The worker CLI accepts the existing `MediaProcessingContract` v1.0.0 as JSON.

**CLI Usage**:
```bash
python -m aiclip_worker.cli probe --contract-json '{"version": "1.0.0", ...}'
# OR
python -m aiclip_worker.cli probe --contract-file /path/to/contract.json
```

**Input Validation**:
- Contract must validate against `services/worker/contracts/media_processing_v1.json`
- Unknown MAJOR versions must be rejected
- Storage key must be non-empty

### Output Contract

**Success Output** (stdout):
```json
{
  "status": "success",
  "probe": {
    "duration_ms": 120000,
    "width": 1920,
    "height": 1080,
    "video_codec": "h264",
    "audio_codec": "aac",
    "bitrate_kbps": 5000,
    "fps": 29.97,
    "audio_channels": 2,
    "audio_sample_rate": 48000,
    "format": "mp4",
    "size_bytes": 1024000
  }
}
```

**Error Output** (stdout):
```json
{
  "status": "error",
  "error": "FFprobe failed with exit code 1",
  "stderr": "Invalid data found when processing input"
}
```

**Exit Codes**:
- `0`: Success
- `1`: Processing error (file not found, corrupt media, FFprobe failure)
- `2`: Invalid input (bad contract, missing fields)

### Security Requirements

- Worker MUST NOT write to PostgreSQL
- Worker MUST NOT access database credentials
- Worker MUST NOT log secrets or tokens
- Worker MUST use subprocess-safe APIs (no shell interpolation)
- Worker MUST enforce timeout on FFprobe invocation
- Worker MUST capture stderr for error reporting

## ProcessMediaAsset Job Refactoring Specification

### Current Behavior

The job currently:
1. Reloads media asset
2. Validates idempotency key
3. Marks as queued
4. Builds contract
5. Logs contract (placeholder)
6. Marks as processing
7. Marks as completed immediately (placeholder)

### New Behavior

The job will:
1. Reload media asset
2. Validate idempotency key
3. Mark as queued
4. Build contract
5. Mark as processing
6. Invoke `ProcessMediaAction` with contract
7. If probe succeeds:
   - Store probe result in `probe_result` column
   - Store duration in `duration_ms` column
   - Mark as `probed`
8. If probe fails:
   - Mark as failed with error message
9. Log probe result (no secrets)

### ProcessMediaAction Service

```php
class ProcessMediaAction
{
    public function probe(MediaProcessingContract $contract): array;
}
```

- Invokes worker CLI via `proc_open` or `Symfony\Component\Process\Process`
- Passes contract as JSON via stdin or temp file
- Reads stdout for result
- Captures stderr for error reporting
- Enforces timeout (configurable, default 30s)
- Returns parsed JSON result

### MediaAsset Schema Update

New columns:
```php
Schema::table('media_assets', function (Blueprint $table) {
    $table->json('probe_result')->nullable()->after('processing_error');
    $table->unsignedBigInteger('duration_ms')->nullable()->after('probe_result');
});
```

## MediaAsset State Transition Specification

### Updated State Graph

```
stored → queued → processing → probed → completed
                  processing → failed
                  queued → failed
```

### New State Constant

```php
const PROCESSING_PROBED = 'probed';
```

### Updated Valid Transitions

```php
private const VALID_TRANSITIONS = [
    self::PROCESSING_STORED => [self::PROCESSING_QUEUED, self::PROCESSING_FAILED],
    self::PROCESSING_QUEUED => [self::PROCESSING_RUNNING, self::PROCESSING_FAILED],
    self::PROCESSING_RUNNING => [self::PROCESSING_PROBED, self::PROCESSING_FAILED],
    self::PROCESSING_PROBED => [self::PROCESSING_COMPLETED, self::PROCESSING_FAILED],
    self::PROCESSING_COMPLETED => [],
    self::PROCESSING_FAILED => [],
];
```

### New Method

```php
public function markProbed(array $probeResult, int $durationMs): void;
```

## Flaky Test Stabilization Specification

### Test File

`apps/web/src/__tests__/Auth.test.tsx`

### Test Case

"associates all 422 field errors and focuses first invalid field for register=false"

### Current Issue

The test asserts `toHaveFocus()` without synchronizing with the focus effect that runs in `useEffect`. The focus is applied asynchronously after validation errors appear.

### Required Fix

Wrap the focus assertion in `waitFor()`:

```typescript
await waitFor(() => {
    expect(screen.getByLabelText(labels[0])).toHaveFocus();
});
```

### Constraints

- NO `test.retry()`
- NO `setTimeout()`
- NO `sleep()`
- NO skipping
- NO removing `toHaveFocus()` assertion

## Acceptance Criteria

1. Python worker CLI entry point exists and can be invoked as `python -m aiclip_worker.cli probe`
2. Worker CLI accepts valid processing contract via stdin or file
3. Worker CLI returns structured probe result on success
4. Worker CLI returns structured error on failure
5. Worker CLI enforces timeout on FFprobe invocation
6. Worker CLI uses subprocess-safe APIs (no shell interpolation)
7. Worker CLI captures stderr on failure
8. Worker CLI exits with appropriate exit codes (0, 1, 2)
9. ProcessMediaAsset job invokes ProcessMediaAction with contract
10. ProcessMediaAsset job stores probe result in MediaAsset
11. ProcessMediaAsset job transitions state correctly (stored → queued → processing → probed)
12. ProcessMediaAsset job marks failed on worker error
13. MediaAsset model includes new `probed` state constant
14. MediaAsset model includes `markProbed()` method
15. MediaAsset migration adds `probe_result` and `duration_ms` columns
16. Deterministic unit tests exist for Python worker probe logic
17. Deterministic unit tests exist for worker error handling
18. Deterministic feature tests exist for ProcessMediaAsset job
19. Deterministic feature tests exist for MediaAsset state transitions
20. Auth flaky test is stabilized and passes 10 consecutive runs
21. Existing tests pass without modification
22. Code follows project conventions (Pint for PHP, flake8 for Python)
23. No secrets in logs or worker payloads
24. FFprobe/FFmpeg deterministically pinned in CI

## Security Considerations

1. **No secrets in contract**: Contract contains only IDs and storage references
2. **No database access**: Worker does not connect to PostgreSQL
3. **No shell interpolation**: Worker uses `subprocess.run` with list arguments
4. **Timeout enforcement**: FFprobe invocations have configurable timeout
5. **Error isolation**: Worker failures do not leak sensitive information
6. **Input validation**: Contract validated before processing
7. **No PII in logs**: Probe results logged without user-identifiable information

## Dependencies

1. **Existing Infrastructure**:
   - MediaAsset model and migration (Issue #35)
   - MediaProcessingContract class (Issue #45)
   - ProcessMediaAsset job (Issue #45)
   - Worker contract JSON schema (Issue #45)

2. **External Dependencies**:
   - FFprobe/FFmpeg binaries (deterministically pinned)
   - Python 3.10+ runtime
   - Laravel queue system (database driver)
   - Storage disk for media files

3. **CI Dependencies**:
   - MinIO service container (existing)
   - FFprobe/FFmpeg binaries in CI environment
   - Python environment in CI

## Risks

1. **FFprobe version differences**: Different FFprobe versions may output slightly different JSON. Mitigate by parsing defensively and using strict JSON schema.

2. **Timeout tuning**: 30s default may be insufficient for large files. Make configurable via environment variable.

3. **Storage access**: Worker needs read access to storage. For S3-compatible storage, worker needs AWS credentials (read-only). Ensure credentials are not logged or exposed.

4. **State transition complexity**: Adding `probed` state increases state machine complexity. Mitigate with comprehensive tests.

5. **Flaky test fix**: Using `waitFor()` may increase test execution time slightly. This is acceptable for reliability.

## Success Criteria

- All acceptance criteria satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic FFprobe/FFmpeg pinning
- Auth flaky test passes 10 consecutive runs
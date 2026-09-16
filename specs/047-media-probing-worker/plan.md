# Implementation Plan: Deterministic Media Probing Worker

## Phase 1: Python Worker CLI Entry Point + ProbesMedia Action

### Objective
Create the Python worker CLI entry point and the ProbesMedia action that invokes FFprobe.

### Files to Create

1. **`services/worker/aiclip_worker/__init__.py`**
   - Empty init file for package

2. **`services/worker/aiclip_worker/cli.py`**
   - CLI entry point using `argparse`
   - `probe` subcommand accepting `--contract-json` or `--contract-file`
   - Input validation against JSON schema
   - Output formatting (JSON to stdout)
   - Exit code handling

3. **`services/worker/aiclip_worker/actions/__init__.py`**
   - Empty init file

4. **`services/worker/aiclip_worker/actions/probe.py`**
   - `probe_media(contract: dict) -> dict` function
   - FFprobe invocation via `subprocess.run` with list arguments
   - Deterministic FFprobe flags: `-v quiet -print_format json -show_format -show_streams`
   - JSON output parsing with defensive error handling
   - Timeout enforcement (configurable via `PROBE_TIMEOUT_SECONDS` env var, default 30)
   - Stderr capture on failure
   - Return structured probe result or error

5. **`services/worker/aiclip_worker/contracts.py`**
   - Contract validation against JSON schema
   - Schema loading from `services/worker/contracts/media_processing_v1.json`

### Files to Modify

None.

### Verification Steps

1. **Unit tests for probe logic**:
   - Create test fixtures: small valid MP4, corrupt file, non-existent file
   - Test successful probe returns expected fields
   - Test error handling returns structured error
   - Test timeout enforcement

2. **CLI integration tests**:
   - Test CLI with valid contract JSON via stdin
   - Test CLI with valid contract JSON via file
   - Test CLI with invalid contract (missing fields)
   - Test CLI with unknown MAJOR version
   - Test exit codes (0, 1, 2)

3. **Manual verification**:
   - Run CLI with a real media file (if available)
   - Verify JSON output format matches spec
   - Verify timeout behavior

## Phase 2: Laravel ProcessMediaAsset Job Refactoring + ProcessMediaAction Subprocess Invocation

### Objective
Refactor the existing ProcessMediaAsset job to invoke the Python worker and handle probe results.

### Files to Create

1. **`apps/api/app/Services/ProcessMediaAction.php`**
   - `probe(MediaProcessingContract $contract): array` method
   - Uses `Symfony\Component\Process\Process` for subprocess invocation
   - Passes contract as JSON via stdin
   - Reads stdout for result
   - Captures stderr for error reporting
   - Enforces timeout (configurable via config, default 30s)
   - Throws `ProcessMediaException` on failure

2. **`apps/api/app/Exceptions/ProcessMediaException.php`**
   - Custom exception for worker failures
   - Contains exit code, stderr, and error message

3. **`apps/api/database/migrations/2026_09_16_110000_add_probe_columns_to_media_assets_table.php`**
   - Adds `probe_result` JSON nullable column
   - Adds `duration_ms` unsigned big integer nullable column

### Files to Modify

1. **`apps/api/app/Jobs/ProcessMediaAsset.php`**
   - Replace placeholder logic with real subprocess invocation
   - Add dependency injection for `ProcessMediaAction`
   - Update `handle()` method to:
     - Call `ProcessMediaAction::probe()` with contract
     - Store probe result and duration on success
     - Mark as `probed` on success
     - Mark as `failed` on error
   - Update `failed()` method to handle `ProcessMediaException`

2. **`apps/api/app/Models/MediaAsset.php`**
   - Add `PROCESSING_PROBED` constant
   - Update `VALID_PROCESSING_STATES` array
   - Update `VALID_TRANSITIONS` array
   - Add `markProbed(array $probeResult, int $durationMs): void` method
   - Add `probe_result` and `duration_ms` to fillable
   - Add casts for `probe_result` (array) and `duration_ms` (integer)

3. **`apps/api/config/media.php`** (if exists, else create)
   - Add `probe_timeout_seconds` configuration (default 30)
   - Add `worker_command` configuration (default: `python -m aiclip_worker.cli`)

### Verification Steps

1. **Unit tests for ProcessMediaAction**:
   - Mock `Process` to simulate successful probe
   - Mock `Process` to simulate FFprobe failure
   - Mock `Process` to simulate timeout
   - Verify contract JSON passed via stdin
   - Verify probe result parsed correctly

2. **Feature tests for ProcessMediaAsset job**:
   - Mock `ProcessMediaAction` to return success
   - Mock `ProcessMediaAction` to return failure
   - Verify state transitions: stored → queued → processing → probed
   - Verify probe result stored in database
   - Verify duration stored in database
   - Verify failed state on error

3. **Integration tests**:
   - Test with real Python worker (if environment supports)
   - Verify end-to-end flow from job dispatch to probe result

## Phase 3: Deterministic Unit Tests for Worker Probe Logic

### Objective
Create comprehensive unit tests for the Python worker probe logic using deterministic fixtures.

### Files to Create

1. **`services/worker/tests/__init__.py`**
   - Empty init file

2. **`services/worker/tests/conftest.py`**
   - Pytest fixtures for test media files
   - Fixture for valid contract JSON
   - Fixture for invalid contract JSON

3. **`services/worker/tests/fixtures/valid_sample.mp4`**
   - Small valid MP4 file (create via FFmpeg in test setup or commit a tiny fixture)
   - Duration: ~1 second
   - Resolution: 640x480
   - Codec: h264/aac

4. **`services/worker/tests/fixtures/corrupt_sample.mp4`**
   - Invalid media file (random bytes)

5. **`services/worker/tests/test_probe.py`**
   - Test successful probe returns expected fields
   - Test probe with zero-duration media
   - Test probe with audio-only media
   - Test probe with video-only media

6. **`services/worker/tests/test_cli.py`**
   - Test CLI with valid contract via stdin
   - Test CLI with valid contract via file
   - Test CLI with invalid contract (missing fields)
   - Test CLI with unknown MAJOR version
   - Test CLI exit codes (0, 1, 2)
   - Test CLI with non-existent storage key

7. **`services/worker/tests/test_error_handling.py`**
   - Test non-existent file error
   - Test corrupt file error
   - Test timeout error
   - Test FFprobe not found error
   - Test invalid JSON output from FFprobe

### Verification Steps

1. **Run pytest**:
   ```bash
   cd services/worker
   python -m pytest tests/ -v
   ```

2. **Verify all tests pass**:
   - No skipped tests
   - No flaky tests
   - All assertions are deterministic

3. **Verify test coverage**:
   - Aim for >90% coverage of probe logic
   - Aim for >80% coverage of CLI logic

## Phase 4: Deterministic Feature Tests for Job Invocation

### Objective
Create feature tests for the Laravel ProcessMediaAsset job with mocked worker responses.

### Files to Create

1. **`apps/api/tests/Feature/Jobs/ProcessMediaAssetProbeTest.php`**
   - Test successful probe stores result
   - Test successful probe stores duration
   - Test failed probe marks asset as failed
   - Test timeout marks asset as failed
   - Test state transitions: stored → queued → processing → probed
   - Test idempotency (same idempotency key)
   - Test job failure handler marks asset as failed

2. **`apps/api/tests/Feature/Models/MediaAssetProbedStateTest.php`**
   - Test new `PROCESSING_PROBED` constant
   - Test `markProbed()` method
   - Test valid transitions to/from probed state
   - Test invalid transitions (e.g., stored → probed)

3. **`apps/api/tests/Unit/Services/ProcessMediaActionTest.php`**
   - Mock `Process` to simulate successful probe
   - Mock `Process` to simulate failure
   - Mock `Process` to simulate timeout
   - Verify contract JSON passed correctly
   - Verify probe result parsed correctly

### Files to Modify

None.

### Verification Steps

1. **Run Pest tests**:
   ```bash
   cd apps/api
   php artisan test --filter=ProcessMediaAssetProbeTest
   php artisan test --filter=MediaAssetProbedStateTest
   php artisan test --filter=ProcessMediaActionTest
   ```

2. **Verify all tests pass**:
   - No database issues
   - No queue issues
   - No mocking issues

3. **Verify test isolation**:
   - Tests do not depend on each other
   - Tests use factories, not real data
   - Tests clean up after themselves

## Phase 5: Auth Flaky Test Stabilization

### Objective
Fix the flaky authentication focus test.

### Files to Modify

1. **`apps/web/src/__tests__/Auth.test.tsx`**
   - Locate test: "associates all 422 field errors and focuses first invalid field for register=false"
   - Wrap focus assertion in `waitFor()`:
     ```typescript
     await waitFor(() => {
         expect(screen.getByLabelText(labels[0])).toHaveFocus();
     });
     ```

### Verification Steps

1. **Run the specific test 10 times consecutively**:
   ```bash
   cd apps/web
   for i in {1..10}; do
     npx vitest run --reporter=verbose src/__tests__/Auth.test.tsx -t "associates all 422 field errors"
     echo "Run $i completed"
   done
   ```

2. **Verify all 10 runs pass**:
   - No failures
   - No timeouts
   - No focus-related errors

3. **Verify no other tests break**:
   ```bash
   npx vitest run src/__tests__/Auth.test.tsx
   ```

## Phase 6: CI Integration

### Objective
Ensure CI can run Python worker tests and Laravel tests with worker invocation.

### Files to Modify

1. **`.github/workflows/backend.yml`**
   - Add Python setup step
   - Add FFprobe/FFmpeg installation (deterministically pinned)
   - Add Python dependencies installation for worker
   - Add worker test step (pytest)
   - Ensure worker tests run before Laravel tests

2. **`.github/workflows/frontend.yml`**
   - No changes needed (Auth test stabilization is code change, not CI)

### Verification Steps

1. **Run CI locally** (if possible):
   - Verify Python worker tests pass in CI environment
   - Verify FFprobe/FFmpeg are available
   - Verify Laravel tests pass with worker invocation

2. **Push to branch and verify CI passes**:
   - All checks green
   - No flaky tests

## Phase 7: Evidence Collection and Documentation

### Objective
Document implementation evidence and update project state.

### Files to Create

1. **`specs/047-media-probing-worker/evidence.md`**
   - RED evidence: test failures before implementation
   - GREEN evidence: test passes after implementation
   - REFACTOR evidence: tests remain green after refactoring
   - Manual verification screenshots (if applicable)

### Files to Modify

1. **`docs/project-state.md`**
   - Update M3 progress
   - Add media probing capability
   - Note flaky test stabilization

### Verification Steps

1. **Verify evidence is complete**:
   - All test runs documented
   - All acceptance criteria verified
   - No outstanding issues

2. **Verify documentation is accurate**:
   - Project state reflects current capabilities
   - No outdated information

## Implementation Order

1. **Phase 1**: Python worker CLI entry point + ProbesMedia action
   - Write RED tests first
   - Implement worker logic
   - Verify GREEN

2. **Phase 2**: Laravel ProcessMediaAsset job refactoring + ProcessMediaAction
   - Write RED tests first
   - Implement job and service
   - Verify GREEN

3. **Phase 3**: Deterministic unit tests for worker probe logic
   - Write tests first
   - Ensure they pass with worker implementation
   - Verify coverage

4. **Phase 4**: Deterministic feature tests for job invocation
   - Write tests first
   - Ensure they pass with job implementation
   - Verify coverage

5. **Phase 5**: Auth flaky test stabilization
   - Apply fix
   - Verify 10 consecutive runs pass

6. **Phase 6**: CI integration
   - Update workflows
   - Verify CI passes

7. **Phase 7**: Evidence collection and documentation
   - Collect all evidence
   - Update documentation

## Dependencies

1. **Python Environment**:
   - Python 3.10+
   - pytest
   - jsonschema (for contract validation)

2. **Laravel Environment**:
   - PHP 8.2+
   - Laravel 13
   - Pest testing framework
   - Symfony Process component

3. **CI Environment**:
   - FFprobe/FFmpeg binaries
   - Python runtime
   - MinIO (existing)

4. **Existing Code**:
   - MediaAsset model (Issue #35)
   - MediaProcessingContract (Issue #45)
   - ProcessMediaAsset job (Issue #45)
   - Worker contract JSON schema (Issue #45)

## Risks and Mitigations

1. **FFprobe version differences**:
   - Risk: Different FFprobe versions may output different JSON
   - Mitigation: Parse defensively, use strict JSON schema, pin version in CI

2. **Timeout tuning**:
   - Risk: 30s default may be insufficient for large files
   - Mitigation: Make configurable via environment variable

3. **Storage access in worker**:
   - Risk: Worker needs read access to S3-compatible storage
   - Mitigation: Use environment variables for credentials, do not log them

4. **State transition complexity**:
   - Risk: Adding `probed` state increases complexity
   - Mitigation: Comprehensive tests for all transitions

5. **Flaky test fix reliability**:
   - Risk: `waitFor()` may not be sufficient
   - Mitigation: Test 10 consecutive times, adjust if needed

## Success Criteria

- All acceptance criteria from spec.md satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic FFprobe/FFmpeg pinning
- Auth flaky test passes 10 consecutive runs
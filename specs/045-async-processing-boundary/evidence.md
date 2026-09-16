# Evidence: Asynchronous Processing Job Boundary

## TDD Evidence

### RED

#### Test Files Created

| File | Tests | Purpose |
|------|-------|---------|
| `tests/Unit/MediaAssetProcessingTest.php` | 5 | Processing state constants, transitions, validation |
| `tests/Unit/MediaProcessingContractTest.php` | 15 | Contract creation, serialization, validation, security |
| `tests/Unit/ProcessMediaAssetJobTest.php` | 11 | Job construction, dispatch, idempotency, interface |
| `tests/Unit/WorkerBoundaryTest.php` | 8 | Schema validation, security boundaries |
| `tests/Feature/Media/MediaProcessingDispatchTest.php` | 6 | Upload dispatches job, authorization, status tracking |
| `services/worker/contracts/media_processing_v1.json` | - | Worker contract JSON schema |

#### Pure Unit Tests (No DB Required)

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Unit/MediaAssetProcessingTest.php tests/Unit/MediaProcessingContractTest.php tests/Unit/ProcessMediaAssetJobTest.php tests/Unit/WorkerBoundaryTest.php
```

**Result:** 39 tests, 0 passed, 38 errors, 1 failure

**Failures by category (valid RED - behavior missing):**

#### MediaAsset Processing State Constants (4 errors)
```
Undefined constant App\Models\MediaAsset::PROCESSING_STORED
Undefined constant App\Models\MediaAsset::VALID_PROCESSING_STATES
Call to undefined method App\Models\MediaAsset::isValidTransition()
Call to undefined method App\Models\MediaAsset::isValidTransition()
```

#### MediaAsset Fillable Fields (1 failure)
```
Failed asserting that an array contains 'processing_status'.
```

#### MediaProcessingContract Class (15 errors)
```
Class "App\Contracts\MediaProcessingContract" not found
```

#### ProcessMediaAsset Job Class (11 errors)
```
Class "App\Jobs\ProcessMediaAsset" not found
```

#### Worker Boundary Schema Tests (8 errors)
```
Class "App\Contracts\MediaProcessingContract" not found
```

#### Feature Tests — PostgreSQL-Backed Integration Verification

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Feature/Media/MediaProcessingDispatchTest.php
```

**Result:** 6 tests, 4 passed, 2 failed, 44 assertions

**Passing behaviors (NOT RED — these confirm existing correct behavior):**
- `dispatches ProcessMediaAsset job after successful upload` — PASS
- `upload response does not depend on job completion` — PASS
- `preserves existing upload response structure` — PASS
- `does not dispatch job for non-owner upload attempt` — PASS

**Failing behaviors (NOT behavioral RED — test-harness / queue-execution issue):**

#### Failure 1: `creates media asset with default processing_status stored`
```
Failed asserting that two strings are identical.
-'stored'
+'completed'
```
Line 173: `expect($mediaAsset->processing_status)->toBe('stored')`

#### Failure 2: `media asset has processing lifecycle columns after upload`
```
Failed asserting that two strings are identical.
-'stored'
+'completed'
```
Line 193: `expect($mediaAsset->processing_status)->toBe('stored')`

#### Root Cause Analysis

**Why does `processing_status = 'completed'` appear immediately after upload?**

The test environment uses `QUEUE_CONNECTION=sync` (phpunit.xml line 33). The `sync` driver executes jobs inline within the same process.

**Execution chain during upload request:**

1. `MediaAsset::create()` → DB row with `processing_status = 'stored'`
2. `ProcessMediaAsset::dispatch()` → job executes **immediately** (sync driver)
3. Job `handle()` runs:
   - `markQueued()` → DB updated to `queued`
   - `markProcessing()` → DB updated to `processing`
   - `markCompleted()` → DB updated to `completed`
4. Controller returns response using in-memory `$mediaAsset` (still `stored`)
5. Test reads DB → sees `completed`

**Spec alignment:**

The spec (AC 5) says: *"Upload endpoint returns immediately with status `'stored'`, then dispatches job."*

- Upload response returns `stored` ✓ (in-memory object)
- DB state after request is `completed` ✗ (sync job ran before test assertion)

**The tests need `Queue::fake()` to prevent the sync driver from executing the job inline**, preserving the `stored` state in the DB for assertion. This aligns with:
- The four passing tests (which already use `Queue::fake()`)
- The spec's intent (upload produces `stored`, job dispatch is async)
- The approved test-plan.md (which uses `Queue::fake()` in upload tests)

#### RED Validation Summary

| Category | Count | Failure Type | Valid RED? |
|----------|-------|--------------|------------|
| Undefined constants | 2 | Behavior missing | ✅ Yes |
| Undefined methods | 2 | Behavior missing | ✅ Yes |
| Missing fillable fields | 1 | Behavior missing | ✅ Yes |
| Class not found (Contract) | 15 | Behavior missing | ✅ Yes |
| Class not found (Job) | 11 | Behavior missing | ✅ Yes |
| Class not found (Worker tests) | 8 | Behavior missing | ✅ Yes |
| Feature: sync job inline | 2 | Test-harness issue (Queue::fake) | ❌ Not behavioral RED |
| **Total** | **41** | | **39 valid behavioral RED** |

### GREEN

#### Fix Applied

Added `Queue::fake();` as the first line inside the two failing test closures in `MediaProcessingDispatchTest.php`:

1. `it('creates media asset with default processing_status stored', ...)` — added `Queue::fake();`
2. `it('media asset has processing lifecycle columns after upload', ...)` — added `Queue::fake();`

This prevents the sync queue driver from executing the job inline during tests, preserving the `stored` state for assertion.

#### Feature Tests — Post-Fix

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Feature/Media/MediaProcessingDispatchTest.php
```

**Result:** 6 tests, 6 passed, 48 assertions, 0 failures

#### Unit Tests — Post-Fix

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Unit/MediaAssetProcessingTest.php tests/Unit/MediaProcessingContractTest.php tests/Unit/ProcessMediaAssetJobTest.php tests/Unit/WorkerBoundaryTest.php
```

**Result:** 39 tests, 39 passed, 113 assertions, 0 failures

#### Full Backend Regression Check

**Command:**
```bash
cd apps/api && vendor/bin/pest
```

**Result:** 175 tests, 175 passed, 1358 assertions, 0 failures — full suite green.

### REFACTOR

#### Pint Code Style

**Command:**
```bash
cd apps/api && vendor/bin/pint --dirty --test
```

**Result:** Passed (no dirty files with style issues)

Note: `vendor/bin/pint --test` (full repo) reports a pre-existing style issue in `tests/Feature/Project/ProjectCrudTest.php` (ordered_imports, fully_qualified_strict_types) — not in scope for this issue.

#### Post-Refactor Verification

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Feature/Media/MediaProcessingDispatchTest.php tests/Unit/MediaAssetProcessingTest.php tests/Unit/MediaProcessingContractTest.php tests/Unit/ProcessMediaAssetJobTest.php tests/Unit/WorkerBoundaryTest.php
```

**Result:** 45 tests, 45 passed, 161 assertions, 0 failures — GREEN maintained after refactor.

## Independent Tester Review

Reviewer: Tester

### Commands Executed

1. **Targeted test suite:**
   ```bash
   cd apps/api && vendor/bin/pest tests/Feature/Media/MediaProcessingDispatchTest.php tests/Unit/MediaAssetProcessingTest.php tests/Unit/MediaProcessingContractTest.php tests/Unit/ProcessMediaAssetJobTest.php tests/Unit/WorkerBoundaryTest.php
   ```
   **Result:** 45 tests, 45 passed, 161 assertions, 0 failures (2760 ms)

2. **Full backend regression suite:**
   ```bash
   cd apps/api && vendor/bin/pest
   ```
   **Result:** 175 tests, 175 passed, 1358 assertions, 0 failures (20843 ms)

3. **Pint code style check (dirty files only):**
   ```bash
   cd apps/api && vendor/bin/pint --dirty --test
   ```
   **Result:** Passed (no dirty files with style issues)

4. **Diff inspection vs master:**
   ```bash
   git diff master --stat
   git diff master -- apps/api/app/ apps/api/database/ apps/api/tests/ services/worker/ docs/
   ```
   **Result:** 19 files changed, 2456 insertions, 8 deletions. All changes are within expected scope.

### Test Results

| Test command | Tests | Passed | Failed | Assertions |
|---|---|---|---|---|
| Targeted suite | 45 | 45 | 0 | 161 |
| Full suite | 175 | 175 | 0 | 1358 |
| Pint dirty | — | — | — | — |

### Worker Boundary Verification

- `services/worker/contracts/media_processing_v1.json` exists and contains a valid JSON Schema (draft-07) with required fields: `version`, `media_asset_id`, `project_id`, `storage`, `idempotency_key`, `created_at`. Schema includes `additionalProperties: false` to prevent secret injection.
- `services/worker/examples/sample_contract.json` exists and contains a valid example contract matching the schema.

### Scope Verification

The following out-of-scope items were **NOT** found in the diff:

- FFmpeg/Whisper/scene detection implementation
- Redis/RabbitMQ/Kafka additions
- Direct Python PostgreSQL writes
- External AI providers
- Secrets in committed files (no hardcoded credentials, tokens, or keys in new code)
- Governance file modifications (AGENTS.md, .opencode/**, tests/governance/**, scripts/merge_gate.py) — zero changes
- Unrelated code changes — all changes are tightly scoped to async processing boundary

### TDD Classification Review

The evidence correctly classifies test failures during the RED phase:

- **39 valid behavioral RED tests**: All failures were due to missing production classes, constants, methods, or fields (MediaProcessingContract not found, ProcessMediaAsset not found, undefined constants, missing fillable fields). This is correct RED — the required behavior did not exist.
- **2 Feature test failures (`creates media asset with default processing_status stored` and `media asset has processing lifecycle columns after upload`) were NOT behavioral RED**: These failures occurred because the test environment uses `QUEUE_CONNECTION=sync`, causing the ProcessMediaAsset job to execute inline during the upload request, transitioning `processing_status` from `stored` to `completed` before the test assertion. Adding `Queue::fake()` prevents the sync driver from executing the job, preserving the `stored` state for assertion. This is a test-harness isolation fix, not a production behavior fix.

The classification of **39 behavioral RED + 2 test-harness fixes = 41 total** is accurate.

### Final Decision

Decision: APPROVE

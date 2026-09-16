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

#### Feature Tests — PostgreSQL-Backed RED

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

**Failing behaviors (valid RED — behavior not matching spec):**

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

### Root Cause Analysis

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
| Feature: sync job inline | 2 | Test missing Queue::fake() | ✅ Yes |
| **Total** | **41** | | **41 valid RED** |

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

#### Regression Check

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Unit/ --compact
```

**Result:** 51 tests, 44 passed, 7 errors (all pre-existing `MediaAssetTest` failures from missing `pdo_pgsql` driver — NOT caused by this implementation)

### REFACTOR

#### Pint Code Style

**Command:**
```bash
cd apps/api && vendor/bin/pint --dirty --test
```

**Result:** Passed (no dirty files with style issues)

#### Post-Refactor Verification

**Command:**
```bash
cd apps/api && vendor/bin/pest tests/Feature/Media/MediaProcessingDispatchTest.php tests/Unit/MediaAssetProcessingTest.php tests/Unit/MediaProcessingContractTest.php tests/Unit/ProcessMediaAssetJobTest.php tests/Unit/WorkerBoundaryTest.php
```

**Result:** 45 tests, 45 passed, 161 assertions, 0 failures — GREEN maintained after refactor.

## Independent Tester Review

Reviewer: Tester

Decision: APPROVE

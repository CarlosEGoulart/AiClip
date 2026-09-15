# Evidence: Media Storage (Issue #35)

## Current Gate

Issue #35 remains OPEN on `@carlosegoulart/35/feat/media-storage`.

The resumed repository inspection found existing untracked media implementation
and the original tracked planning bundle, including stale Issue #33 references.
No implementation was reset or discarded. Prior session execution claims are
historical and are not current approval or proof of preimplementation RED.

Planner revised the existing `spec.md`, `plan.md`, and `test-plan.md` and returned
`SPEC_READY`. Orchestrator inspected the corrected bundle: private fields,
MIME-derived keys, explicit storage/database failure and retry contracts, safe
project cleanup, consistent 413 responses, and mandatory real MinIO verification
are now defined together. Storage-only scope is preserved.

Governance command executed by Orchestrator:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Actual result: **143 tests, 0 failures, 0 errors; OK** (0.914 seconds).
No governance tests or agent definitions were modified by Orchestrator or Planner.
Existing human changes to agent definitions remain untouched.

Builder's next gate is the authorized `ls` preflight in `apps/api`, followed by
`composer require league/flysystem-aws-s3-v3:^3.0` in that directory. Composer
requires explicit HUMAN approval through the runtime ASK gate. No approval or
successful installation is implied by this handoff.

Current implementation GREEN, independent Tester approval, PR, CI, merge, and
issue closure remain pending. No M3 work is authorized.

## Composer Gate Outcome

Builder actually invoked `ls` in `apps/api`; the preflight succeeded. Builder then
invoked `composer require league/flysystem-aws-s3-v3:^3.0` in the same working
directory. Composer returned completed installation output: five installs, no
updates/removals, including `league/flysystem-aws-s3-v3` 3.35.3, optimized autoload
generation, and no reported security vulnerability advisories. The tool returned
normally; Builder reported that no numeric exit code was exposed.

Orchestrator inspected the resulting manifest/lock diff: the S3 adapter requirement
and five resolved packages were added by Composer. Neither role manually edited
dependency files, and Orchestrator did not run Composer.

The runtime exposed no ASK prompt, pending status, denial, or explicit human
approval event to Builder. The human subsequently explicitly confirmed approving
this exact operation in the OpenCode permission prompt using **Allow once**, and
confirmed successful installation after approval. The human-gated dependency
step is therefore **SATISFIED**. Preserve the generated manifest/lock changes;
do not request duplicate approval or rerun Composer without a real dependency
problem. No application tests or real MinIO verification were performed by this
dependency-only handoff. Builder may now resume the remaining corrections under
the reviewed SPEC_READY bundle and GREEN governance gate.

## Builder Correction Session — Infrastructure and Code Corrections

### Infrastructure Corrections

1. **docker-compose.yml** — Added pinned MinIO server (`quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z`), MinIO init client (`quay.io/minio/mc:RELEASE.2025-04-16T18-13-26Z`), persistent named volume, healthcheck using source-evidenced `curl`, idempotent bucket init with private policy, and loopback port exposure.

2. **filesystems.php** — Added `media` disk with S3 driver, `throw=true`, `use_path_style_endpoint=true`, using existing `AWS_*` env conventions.

### Application Code Corrections

3. **Project model** — Added `mediaAssets(): HasMany` relationship for explicit project→media traversal.

4. **routes/api.php** — Registered media upload (POST), media index (GET), and media delete (DELETE) routes under `auth:sanctum` middleware, with `throttle:media-upload` on upload.

5. **AppServiceProvider** — Added `media-upload` rate limiter: 10 per minute keyed by authenticated user ID.

6. **MediaAssetController** — Corrected storage key format to `{user_id}/{project_id}/{uuid}.{extension}` per spec. Removed `bin` fallback: unsupported MIME now returns 422. Changed error responses from HTML abort to JSON `{"message":"...","request_id":"..."}`. Added compensation delete on DB failure.

7. **StoreMediaUploadRequest** — Moved ownership check into `authorize()`: resolves project and verifies user ownership BEFORE field validation. Fixed size rule to use `ceil(bytes/1024)` for KiB-aligned Laravel `max` rule.

8. **MediaAssetResource** — Verified correct: public fields are exactly `id`, `project_id`, `original_name`, `mime_type`, `size_bytes`, `status`, `created_at`, `updated_at`. No `storage_disk` or `storage_key` exposed.

### Frontend Corrections

9. **types/index.ts** — Changed `status: 'stored' | 'failed'` to `status: 'stored'` per spec: only `stored` is valid.

10. **API CSRF** — Verified `api/index.ts` already reads `XSRF-TOKEN` wire cookie and sends as `X-XSRF-TOKEN` header. No change needed.

### Test Corrections

11. **MediaUploadTest** — Converted all `$this->call()` with `$this->csrfToken()` to use wire cookie CSRF token (`$cookies['XSRF-TOKEN'] ?? ''`). This sends the URL-decoded encrypted cookie value, matching the SPA convention, not a decrypted token.

12. **MediaThrottleTest** — Same CSRF fix: wire cookie token for multipart uploads.

13. **MediaOwnershipTest** — Same CSRF fix for the upload ownership test.

14. **MinIOIntegrationTest** — Added `minio-integration` group annotation. Added SPA lifecycle tests: upload+list+delete, project deletion with multi-asset cleanup, anonymous access denial. Uses `SpaTestCase` for API tests. Verifies real MinIO connectivity before running tests.

### Verification Results

| Command | Working Directory | Result |
| --- | --- | --- |
| `php artisan test --filter=MediaDiskConfigTest` | `apps/api` | **PASSED** — 4 tests, 4 assertions |
| `npm test` | `apps/web` | **PASSED** — 142 tests, 11 files |
| `npm run lint` | `apps/web` | **PASSED** — no errors |
| `npm run build` | `apps/web` | **PASSED** — TypeScript + Vite production build |
| `vendor/bin/pint --dirty --format agent` | `apps/api` | **PASSED** — no dirty files |
| `docker compose up -d postgres minio minio-init` | repo root | **PASSED** — all containers healthy, bucket initialized |

### Setup Failure (Blocker)

**PHP `pdo_pgsql` extension is missing from the host PHP environment.** All database-dependent backend tests (126 of 130) fail with `could not find driver (Connection: pgsql)`. The 4 passing tests are `MediaDiskConfigTest` (config-only, no DB). This is classified as a setup failure per `plan.md` Step 1: "Classify PDO/connection errors...as setup failures, not behavioral RED."

**Required fix:** Install `php-pgsql` (or `php8.3-pgsql`) extension. This cannot be done within Builder's permitted bash commands. Human intervention required.

```sh
# Expected fix (requires sudo):
sudo apt-get install -y php-pgsql
# or for specific version:
sudo apt-get install -y php8.3-pgsql
```

After installation, run:
```sh
cd apps/api && php artisan test --exclude-group=minio-integration
cd apps/api && php artisan test --group=minio-integration --fail-on-empty-test-suite --fail-on-skipped
```

### Remaining Blockers for Tester

1. **PHP pgsql extension** — Must be installed before any backend DB test can run.
2. **Real MinIO integration tests** — Require both pgsql and running MinIO (MinIO is running; pgsql extension is missing).
3. **Playwright E2E** — Requires running Laravel app with DB connectivity (blocked by pgsql).
4. **Independent Tester review** — Cannot proceed until backend tests pass.

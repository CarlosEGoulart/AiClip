# Test Plan: Media Storage — Issue #35

## Authority, Preconditions, and Evidence Rules

Active issue: [Issue #35](https://github.com/CarlosEGoulart/AiClip/issues/35). Existing branch: `@carlosegoulart/35/feat/media-storage`. Verify `spec.md` acceptance criteria AC1–AC9 and the ordered work in `plan.md`; preserve existing implementation and tests. This document defines tests, not executed results.

Before Builder corrections: Orchestrator confirms the planning/governance gate, then Builder performs the authorized `ls` preflight in `apps/api`, invokes `composer require league/flysystem-aws-s3-v3:^3.0`, and WAITs for the pending HUMAN ASK to be explicitly approved and successfully executed. No inferred approval, manual lockfile, Orchestrator installation, or bypass. Actual required-operation denial means stop/report the attempted command and raw error.

After that gate, bootstrap dependencies, required PHP runtime/extensions (including PDO PostgreSQL and Fileinfo), Laravel/Pest/Faker bindings, migrations, dedicated disposable DB data, and exact-pinned real MinIO/private bucket per `plan.md`. Use repository-root `docker compose up -d postgres minio minio-init`, never standalone PostgreSQL startup. Require actual matching image resolution/pull/start and health/init/persistence checks; release URLs alone are not runtime evidence. No real `.env` secret inspection or production data.

Initial database connectivity/PDO failures, unbound Laravel TestCase/facades, missing Faker, wrong CSRF helper, malformed fixtures, duplicate registration users, syntax errors, and no-test discovery are setup failures, not RED. Verify and fix these before meaningful behavior assertions. `X-XSRF-TOKEN` uses the wire cookie convention, not a decrypted token from `csrfToken(...)`. Backend storage assertions query `MediaAsset.storage_key`, not public ID; confirm already-correct assertions/unique fixtures before modifying them. Retain enforced CSRF in the SPA test base.

Historical working-tree evidence was lost/reverted with the old bundle; previous reports are unverified. Do not claim preimplementation RED existed. For remaining corrections, write/strengthen a behavioral test, actually execute the relevant assertion failure, then implement GREEN and rerun after refactoring. Record current regression passes as such. Tests-written, connection errors, zero executed tests, and skips are never proof of success. Optional mutation verification is assertion-sensitivity evidence, not historical RED.

## 1. Backend Model, Validation, and Security Matrix (AC1–AC3)

Use existing Pest/Laravel-bound tests under `apps/api/tests/Unit` and `tests/Feature/Media`. Tests using Eloquent, configuration, or facades require the application TestCase; database tests use isolated PostgreSQL data. SPA API tests use `SpaTestCase` and real session/cookie/CSRF semantics. Fake storage is explicitly limited to fast deterministic behavior/failure tests.

| Area | Required assertions |
| --- | --- |
| Schema/factory | Required fields, project foreign key/relationship, positive integer size, timestamps, stored-only behavior, generated unique keys; explicitly inserting a duplicate key fails the unique constraint (two differing UUIDs alone do not test it). |
| Disk | Media disk resolves configuration to S3 with private visibility, path-style local endpoint, `throw=true`, byte-limit default 104857600 and an exact non-KiB-aligned override. Credentials never appear in diagnostics. |
| Success | Valid small real-byte MP4, QuickTime, and WebM fixtures return 201 and one correct row/object; list envelope contains only that project's assets, newest first with deterministic ties; no-media list is empty. |
| MIME versus filename | Supported MP4 bytes named `.mov`, `.txt`, without suffix, or with misleading client MIME still use `.mp4` generated keys. Repeat mapping for QuickTime/WebM. Unsupported bytes renamed `.mp4`, empty/unreadable file, and missing `file` return 422 with `errors.file` and no mutation. No `bin` fallback or extension-only rejection. |
| Key/name safety | Key matches server owner/project/UUID/mapped-extension pattern. Duplicate original names produce separate objects. Test traversal using both slash styles, control/NUL characters, long/Unicode names, and HTML-like names. Sanitized display output is bounded/escaped and never controls storage identity; backend tests query the model key. |
| Payload tampering | Inject foreign `project_id`, `user_id`, `storage_disk`, `storage_key`, `mime_type`, `size_bytes`, and status; none can change authoritative values or ownership. |
| Authentication | Guest/expired-session GET and correctly CSRF-prepared guest mutations receive 401; no redirects or bearer flow. Missing/incorrect CSRF on owner upload/media delete/project delete receives 419 without write/delete. Missing CSRF precedence is tested separately from authentication. |
| Ownership | User B cannot upload/list/delete user A's assets or delete A's project; missing IDs and foreign IDs return indistinguishable 404. Repeat foreign upload with missing/unsupported/app-oversized files, valid auth/CSRF, and unexhausted limiter; 404 precedes field validation. No storage/DB mutation. |
| Public fields | Upload/list include exactly the public fields in `spec.md` with correct types; assert absence of disk/key, user IDs, bucket/provider URLs, credentials and debug traces. Browser types have no storage internals and status is only `stored`. |
| Rate limiting | Ten ownership-admitted attempts in a minute, eleventh 429 with expected limit/retry headers; invalid admitted attempts cannot evade counting. Same user/different projects share limit, another user is independent, time advancement resets. List/delete are not subject to `media-upload`. Use existing cache/time facilities, no Redis. |

### Size Error Paths: Stable 413, Not Generic 422

For every path assert exactly:

```json
{"message":"File exceeds maximum upload size.","errors":{"file":["File exceeds maximum upload size."]}}
```

Assert HTTP 413, JSON content type, no `data`, no object/metadata, and no secrets. Cover:

1. Application limit minus one/equal/plus one, including a non-KiB-aligned configurable byte limit. Use a lowered limit with real small supported bytes for end-to-end enforcement; mocks alone must not define MIME success.
2. Symfony/Laravel uploaded-file objects carrying actual PHP `UPLOAD_ERR_INI_SIZE` and `UPLOAD_ERR_FORM_SIZE`, which must not fall through as missing-file 422. Other invalid upload cases remain validation 422.
3. Actual Laravel `Illuminate\Http\Exceptions\PostTooLargeException` handling through the configured API exception renderer/middleware, including body-discarded requests. A direct controller-only test is insufficient; do not invent `UploadedFileTooLargeException`.
4. An authorized disposable HTTP runtime probe with intentionally small PHP upload/post limits: send supported multipart bytes through the real server to exercise PHP's INI-size/body rejection and stable JSON. Probe settings are isolated and restored; do not change production limits or weaken normal API tests. Configure the normal server with file limit accommodating 100 MiB and post limit headroom (128M at default); record actual effective settings without exposing environment secrets.
5. Global body-limit failures may occur before routing/authentication; compare different resource IDs and verify identical resource-independent 413 rather than claiming ownership has run. Routed requests that reach ownership still meet the 404 cases above.

## 2. Failure and Concurrency Matrix (AC4–AC5)

Use deterministic storage/DB fault injection at the explicit application/service boundary, separate from real integration tests. Every controlled 500 must match the safe `message`/opaque `request_id` contract in `spec.md`. Capture operational events and assert usable correlation/stage/server-generated recovery identity without raw exception text, secrets, cookies, signed URLs, or original filenames. Sentinel secrets in injected exceptions must not reach response/log output.

| Scenario | HTTP and persistence assertions |
| --- | --- |
| Upload write false; upload write throws | 500; no successful media row. Do not falsely return 201 or assume a remote partial write was impossible. Correlate uncertain object identity safely. |
| Write succeeds; DB insert fails; compensation succeeds | 500; no row; compensation called for the exact generated disk/key; object absent. |
| Write succeeds; DB fails; compensation false or throws | 500; no successful row; orphan candidate and failed cleanup outcome recoverable from safe operational context. Never label it fully rolled back or automatically cleaned. |
| Media delete false; media delete throws | 500 and unchanged row. No generic error-message matching or subsequent exists probe converts failure into success. |
| Media object already absent | Real S3 delete succeeds idempotently; then DB deletion succeeds and HTTP 204. Another HTTP deletion after metadata is gone gives 404. |
| Media object removed; DB delete/commit fails | 500; rollback retains row, object remains absent. A later retry returns 204 and removes retained metadata. |
| Normal media deletion | 204 empty body; object and row gone; same-project siblings and other users' data unchanged. |
| Empty project deletion | Existing project DELETE returns 204 with no object operations. |
| Multi-asset project deletion succeeds | Every exact object cleaned before metadata/project delete; 204 only after DB commit; all relevant rows/objects absent; unrelated projects untouched. |
| First project object fails, false and exception variants | 500; project and all media rows retained; no DB delete/cascade attempted. |
| Partial project cleanup then failure | Seed at least three objects in deterministic ID order: first removed, second fails, third unattempted. 500; all rows/project retained, first object remains absent and later objects remain. No claim of restored objects. Restore service, retry; absent first delete succeeds and remaining cleanup/DB commit yields 204. |
| All project objects removed; DB delete/commit fails | 500; project and all rows retained, objects absent; retry succeeds without object restoration. |

Verify the existing project DELETE invokes the explicit cleanup service. An observer-only test or direct database cascade test cannot satisfy the project cleanup acceptance criterion. Test cleanup call order and retained rows, not merely final HTTP codes or log calls.

Concurrency must include controlled, real PostgreSQL interleavings with independent connections/processes and barriers, not flaky sleeps or a fake lock:

- Upload holds project lock, project deletion waits; once upload commits, delete refreshes metadata and cleans that upload.
- Project deletion holds lock, upload waits; after deletion commits, upload recheck returns 404 without any storage write.
- Media deletion and project deletion serialize under the same project lock; refreshed state handles a previously deleted asset without orphaning objects or bypassing ownership.
- Partial-cleanup failure releases the lock with database state intact; subsequent authorized retry/upload follows a fresh project recheck. Capture exact outcomes; database rollback does not restore S3 objects.

These tests require a safe disposable database and bounded calls. If the actual authorized runtime cannot execute needed concurrency/probe tooling, report the command/error as a verification gate; do not replace the required check with a static locking assertion.

## 3. Mandatory Standalone Real MinIO Integration (AC6–AC7)

Location: `apps/api/tests/Feature/Media/MinIOIntegrationTest.php`, discovered under the existing Feature suite. Group every contained test `minio-integration`. Bind Laravel's TestCase/SPA TestCase correctly; configured PostgreSQL is real where persistence is asserted.

Required chain: **Laravel Storage -> Flysystem -> league/flysystem-aws-s3-v3 -> actual MinIO**. Assert the disk is S3 and the bucket/endpoint are configured, and establish actual connectivity. No `Storage::fake`, mock adapter/client, local disk fallback, skip-on-missing-bucket, or environment flag silently returning success. A missing endpoint/bucket/driver/database is failure, never a skipped pass.

Required independent cases:

1. Generate a unique isolated object key; assert write result success, `exists=true`, exact byte-for-byte read, delete result success, `exists=false`; repeat delete on the absent object and assert success/absence.
2. Real SPA authenticated upload of a tiny valid video to an owned project: 201, committed metadata and exact public fields. Query its backend model for internal key/disk; assert real object existence and bytes. List using the API, delete using the API, assert 204, row absence and object absence.
3. Real API project deletion with multiple uploaded assets: collect keys from backend models, delete the project via its endpoint, and assert project/media rows plus every object absent. Include an already-absent object so actual S3 idempotency is exercised.
4. Anonymous read/list requests cannot access the private bucket/object. Test with known backend-only keys, without adding key exposure to the production API or browser helpers.

Use tiny deterministic supported-video fixtures checked into test assets with provenance as appropriate, not FFmpeg/FFprobe or generated fake MIME labels masquerading as real video. Unique key prefixes and isolated DB data prevent interference. Teardown attempts cleanup even on failure; it must not hide the original error or erase unrelated buckets/data. Fast fault-injection tests remain separate from this group.

Required infrastructure verification also records exact resolved server/client image identities matching `plan.md`, image pull/start results, functioning source-evidenced `curl` server healthcheck, client `mc` and any chosen shell availability, database readiness, zero-exit private bucket init, repeated init without data loss, and data persistence across a permitted restart/recreation. Do not destroy named volumes. Init/console/CLI success alone is not Laravel integration evidence.

## 4. Frontend Behavior and Regression (AC8)

Use existing Vitest + React Testing Library tests in `apps/web/src/features/media`, plus related project/auth integration tests only where this issue changes behavior. Verify current corrections first: pending-delete assertion queries `Confirm delete ...` (not `/Deleting/`), unused `fireEvent` is absent in `MediaList.test.tsx`, unused `clearErrors` destructuring is absent in `ProjectMediaSection.tsx`. Do not delete legitimate hook/test usages or change passing behavior to match stale reports.

- API client uses unchanged paths, `credentials: include`, CSRF-cookie handshake/header, and multipart FormData without hardcoded Content-Type. No bearer/localStorage credentials. Parse 201/200/204, 401/404/413/419/422/429/500 and network errors safely; preserve bounded existing CSRF recovery, not automatic uncertain-upload/500 retry.
- Owned project selection loads only that project's media; no-project and no-media states are understandable. File selection enables upload; pending state disables duplicates, announces status, and preserves stable layout. Supported bytes with a misleading suffix are not rejected solely by extension.
- Upload success adds the actual returned asset and clears the file; 413/422/throttle/network/server/CSRF failures show safe actionable errors, preserve retry, and do not invent a stored entry. No fake progress/processing/status transitions.
- List shows sanitized names, meaningful size/type/stored information, loading/empty/error states and persistence after reload/reselection. Async results from old project/session/unmount cannot populate the current project.
- Delete confirmation/cancel works; pending state disables repeat delete; success removes only the selected item, error retains it and permits retry. Existing project deletion waits for real 204, retains project on 500, and communicates possible partial object cleanup without promising rollback.
- Labels, alert/live status, keyboard/focus restoration, and stored-only types are covered. Actual `npm run lint` and `npm run build` catch remaining unused symbols/TS6133; tests alone are insufficient.

## 5. Playwright, Visual, Network, and Security Review (AC8–AC9)

Run the defined `npm run test:e2e` from `apps/web` against real PostgreSQL/MinIO. Existing Playwright config starts Laravel at 127.0.0.1:8000 and Vite at 127.0.0.1:5173 with `reuseExistingServer: false`; Laravel readiness hits the database-backed `/api/v1/health`. Prepare bucket/runtime before startup. Diagnose actual timeouts using server output, dependencies/PDO, database/migrations, environment, ports and health responses. No timeout-only workaround without measured healthy slow startup; no framework migration.

At each configured viewport **390x844**, **768x1024**, **1440x900**:

1. Register/login with unique isolated fixtures, create/select an owned project, inspect initial empty state, select/upload a real supported video, observe pending then stored listing, reload/reselect and verify persistence.
2. Cancel deletion then confirm; observe disabled pending action, actual successful API response, removal and empty state. Upload multiple assets and use existing project deletion, checking confirmed removal. Backend integration proves object cleanup independently; browser tests do not gain internal-key access.
3. Exercise unsupported/empty and size rejection, authenticated foreign ownership attempts, expired/invalid CSRF, rate limit, and controlled storage failure/retry. Any route interception used to visualize otherwise difficult failure/loading states is explicitly labeled simulated and complements, never replaces, backend fault tests and real-storage success E2E.
4. Exercise keyboard file/project selection, tab order, focus visibility/return, cancellation/confirmation, labels, alerts/live status, narrow overflow, spacing/alignment, long sanitized names, buttons and disabled states. Verify that UI state never implies processing or restored deleted objects.
5. Capture and independently inspect screenshots for empty, populated, pending, confirmation, and error states. Review actual browser console, uncaught exceptions, network requests, API JSON, failed resources and redirects. Unexpected console errors or application 4xx/5xx fail review; intentionally expected errors are identified per scenario. Check traversal names are escaped and secrets/storage keys never enter app responses, browser state, screenshots or logs.

Tester must operate the running app and inspect artifacts, not approve solely from an automated result. Existing health/auth/project E2E scenarios remain part of the regression gate.

## 6. Required Commands, Discovery, and CI (AC9)

All shell commands here belong to the appropriately authorized execution role, not Planner. Use the stated repository-relative working directories. A needed operation permission error is reported from an actual invocation with raw error; do not silently choose a denied-command workaround. Orchestrator/Tester handles governance when Builder's runtime does not allow it.

| Working directory | Command / required result |
| --- | --- |
| Repository root | `docker compose up -d postgres minio minio-init` — actual combined startup with the `plan.md` pins; separately verify health and successful private bucket init before integration/browser tests. |
| `apps/api` | `php artisan test --exclude-group=minio-integration` — optional fast feedback only, explicitly not the real integration/full regression gate. |
| `apps/api` | `php artisan test tests/Feature/Media/MinIOIntegrationTest.php --group=minio-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky` — mandatory Builder/Tester/CI real integration invocation after setup; nonzero on missing infra, skipped/incomplete/risky tests or empty selection. |
| `apps/api` | `php artisan test` — mandatory actual full backend run with infrastructure ready; require nonzero executed tests, all required tests executed, zero required skips/failures, and visible integration discovery. |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — Builder formatting per API addendum; Tester checks formatting without repairing production code. |
| `apps/web` | `npm test` — defined non-watch Vitest script, actual suite pass. |
| `apps/web` | `npm run lint` — actual lint pass. |
| `apps/web` | `npm run build` — TypeScript and Vite production build pass. |
| `apps/web` | `npm run test:e2e` — defined Playwright command, all configured viewport projects and required real-storage workflow executed. |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — authorized Orchestrator/Tester (Builder only if runtime allows); actual pass, no governance modifications. |

Verify installed runner support for fail-closed options during setup. An unknown option is a setup failure, not RED or permission to drop fail-closed behavior. The standalone integration output must explicitly show the file/group and all required cases actually executed, with positive test/assertion counts and zero skips; a command returning zero without those conditions is rejected. Do not configure a global exclusion that silently disables the mandatory group.

Validate `.github/workflows/e2e.yml` against GitHub Actions syntax/schema. Explicit Compose startup/readiness/init steps are preferred; Compose `command`, `depends_on`, `entrypoint` must not appear under Actions `services`. Avoid duplicate PostgreSQL services/port collisions. Require deterministic migrations/database health, pinned MinIO health and private bucket completion before the exact mandatory integration invocation and Playwright. Ensure PHP limits and S3/DB environment reach the browser-managed Laravel process. CI must fail for either integration or E2E failure, missing infrastructure, empty selection, or skipped required tests; no `continue-on-error` or fake fallback.

Record per run: command, working directory, exit result, tests/assertions executed and skipped, sanitized failure output, exact runtime/image identity where relevant, and which observations are simulated versus real. Builder and independent Tester both execute all authorized applicable checks; inability to run one remains a visible gate. No Planner edits to `evidence.md`, agents, governance tests, or lifecycle documents are authorized.

## Release Gate

Acceptance requires AC1–AC9 satisfied, meaningful fresh correction RED/GREEN/refactor where work remains, backend/frontend/integration/E2E/governance passes, valid required CI, and independent visual/accessibility/API/security/failure approval. Human Composer approval, infrastructure resolution, setup repair, tests and browser review remain pending until actually verified. After verified merge Orchestrator reconciles project-state/roadmap and closes this issue; no next issue/milestone starts without separate authorization.

# Implementation Plan: Media Storage — Issue #35

## Active Scope and Working-Tree Baseline

Issue: [Issue #35](https://github.com/CarlosEGoulart/AiClip/issues/35). Continue existing branch `@carlosegoulart/35/feat/media-storage`; do not reset, restart, discard untracked implementation, or create another issue/branch. `spec.md` is the behavior contract; `test-plan.md` defines required verification. All steps are storage-only and preserve Laravel/React/Sanctum/PostgreSQL architecture.

Planning inspected the active issue, architecture, PRD, roadmap, project state, ADR-0002, the current bundle, API routes/bootstrap/config/models/controllers, SPA test support, existing media tests, frontend media code/tests, package scripts, Playwright config, Compose, and E2E workflow. No real `.env` secrets were read. Roadmap/project-state entries are historical and need Orchestrator reconciliation, not Planner/Builder lifecycle edits.

Observed current gaps, not an exhaustive implementation verdict:

- Composer has no S3 adapter requirement; Compose has only PostgreSQL; the filesystem has no private `media` disk and API routes have no media registration. Existing untracked media files are useful work, not proof of a wired feature.
- Upload FormRequest authorizes everything before controller ownership and relies on KiB validation. Upload controller uses a project-only key, a `bin` fallback, an unchecked write result, unsanitized display name, and raw exception logging. Correct these incrementally against the revised contract.
- Individual deletion has some row-preservation corrections but still needs exact false/exception/absent-object and database-failure coverage. Project deletion still directly deletes the model; implement the explicit cleanup boundary and shared project lock rather than a swallowing observer.
- `MinIOIntegrationTest.php` exists under `tests/Feature/Media` but skips when its bucket is missing. It needs explicit discovery/grouping, asserted write/delete results, fail-closed prerequisites, and API/database lifecycle coverage.
- Public frontend fields already omit disk/key, but the status union still includes `failed`. `MediaListItem.test.tsx` already queries the pending confirmation button by `Confirm delete ...`; `MediaList.test.tsx` has no unused `fireEvent`; `ProjectMediaSection.tsx` no longer destructures unused `clearErrors`. Verify these, do not undo/reapply them blindly. Hook tests legitimately use `fireEvent`/`clearErrors`.
- Some ownership fixtures already use distinct emails and upload assertions already query model keys. Inspect each remaining helper/fixture: current media upload calls still send decrypted `csrfToken(...)` as `X-XSRF-TOKEN`, unlike the existing SPA wire-cookie convention. Do not assume all prior fixes survived.

Historical bundle/evidence was reported reverted to `e83ef98`; previous RED/GREEN claims are unverified. Preserve existing correct behavior and evidence honesty. This plan does not assert that tests were run before the existing implementation.

## Step 0 — First Operational Prerequisite: Human Composer Gate

After Orchestrator confirms valid planning and the governance gate, Builder's first operational action is the now-authorized mandatory `ls` preflight in working directory `apps/api`. Then invoke, in the same directory:

```text
composer require league/flysystem-aws-s3-v3:^3.0
```

The existing Composer ASK remains pending HUMAN. Invoke the operation and WAIT for explicit human approval. Do not start dependent implementation/environment/test work on inferred approval or a prior-session report. After approval, require an actual successful Composer result. Composer, owned by Builder, must generate both dependency and lock changes normally; Orchestrator must not run this instead, and no agent may hand-edit the lockfile, bypass platform requirements, or switch installation mechanisms to evade the gate.

If the actual needed operation is denied, stop and return the exact attempted command and raw permission error to Orchestrator. A static permission rule is not an invocation or proof of a blocker. Repository paths remain relative. A typo/path or external metadata-fetch error is not an agent permission denial; correct the request rather than claim authorization or weaken a requirement. Planner runs no shell commands.

## Step 1 — Bootstrap Real Dependencies and Repair Test Setup

1. After approved Composer success, verify the installed Laravel/Pest runtime, required PHP extensions (especially PDO PostgreSQL and Fileinfo), Composer autoloading and dev dependencies including Faker. Preserve the existing framework; satisfy its actual locked platform requirements rather than migrating it.
2. Prepare issue-required runtime settings, safe `.env.example` guidance, Compose server/init services, and PHP upload-limit documentation/configuration. `MEDIA_MAX_UPLOAD_SIZE` is bytes, default 104857600; PHP file limit accommodates it and post limit has multipart headroom (128M at the default). Never inspect real `.env` secrets. Use isolated disposable test data, not production accounts or buckets. Defer application `config/media.php` and private filesystem disk corrections to Step 2's assertion-based TDD; infrastructure preparation is not a claim of application GREEN.
3. Use the exact MinIO pins and health/init requirements below. From repository root invoke only the authorized combined startup:

   `docker compose up -d postgres minio minio-init`

   No standalone PostgreSQL startup, alternate runtime, destructive volume reset, or guessed permission workaround. Require actual image resolution/pull/start evidence for both pins. If cached, record resolved image identity and matching tag; do not infer a successful pull from documentation. Any additional necessary pull/inspection command follows runtime permissions and human approval where required.
4. Verify database health/connectivity, actual MinIO healthcheck success, and `minio-init` exit code zero with private bucket ready before tests. A detached Compose success message alone is insufficient. Prepare migrations/test runtime on a dedicated disposable database. Retain existing data volumes; do not reset them to repair failures.
5. Run a backend baseline with `php artisan test` in `apps/api` once runtime/infrastructure prerequisites are ready. Missing issue behavior/configuration can still fail at this point; do not require or claim a GREEN baseline. Classify PDO/connection errors, missing facades/container bindings, missing Faker, syntax/fixture failures, and empty discovery as setup failures, not behavioral RED. Repair minimal application-test setup before evaluating missing behavior. A skipped/unconfigured existing integration test is not successful integration; its mandatory acceptance run follows the disk/discovery corrections.
6. Use Laravel-bound `Tests\TestCase` for facade/model/config tests and the existing enforced-CSRF `SpaTestCase` for SPA feature tests. Adapt multipart requests to send real wire cookies and the URL-decoded cookie value in `X-XSRF-TOKEN`, not a decrypted token under that header. Preserve CSRF enforcement; do not remove middleware to make fixtures pass. Avoid duplicate registration/factory users for the same email. Assert storage using backend `MediaAsset.storage_key`, not public asset ID. Confirm existing corrections before changing them.

## Official MinIO Pin and Command Basis

Selected images, unchanged between local Compose and CI:

| Service | Pin |
| --- | --- |
| `minio` | `quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z` |
| `minio-init` | `quay.io/minio/mc:RELEASE.2025-04-16T18-13-26Z` |

Official sources fetched during this planning pass:

- [Server release](https://github.com/minio/minio/releases/tag/RELEASE.2025-04-22T22-12-26Z) and [client release](https://github.com/minio/mc/releases/tag/RELEASE.2025-04-16T18-13-26Z): published, signed release tags exist.
- [Server release Dockerfile](https://raw.githubusercontent.com/minio/minio/RELEASE.2025-04-22T22-12-26Z/Dockerfile.release): final image copies `minio`, `mc`, and static `curl` into `/usr/bin`, declares `/data`, and uses the server entrypoint. Therefore an exec-form healthcheck invoking `/usr/bin/curl -f --silent --show-error http://127.0.0.1:9000/minio/health/ready` has a source basis; verify it in the pulled image.
- [Healthcheck documentation at the server tag](https://raw.githubusercontent.com/minio/minio/RELEASE.2025-04-22T22-12-26Z/docs/metrics/healthcheck/README.md): documents `/minio/health/ready`. This is not proof of bucket creation or S3 credentials.
- [Server quickstart at the tag](https://raw.githubusercontent.com/minio/minio/RELEASE.2025-04-22T22-12-26Z/README.md): documents the official Quay repository, `server /data --console-address ":9001"`, persistent volume mapping, and standalone development/evaluation limits.
- [Client release Dockerfile](https://raw.githubusercontent.com/minio/mc/RELEASE.2025-04-16T18-13-26Z/Dockerfile.release): adds `/usr/bin/mc`, makes it executable, and sets `ENTRYPOINT ["mc"]`. Do not assume client-image `curl` or an implicit shell command will work under this entrypoint.
- Client-tag command sources: [`mb --ignore-existing`](https://raw.githubusercontent.com/minio/mc/RELEASE.2025-04-16T18-13-26Z/cmd/mb-main.go) and [`anonymous set private`](https://raw.githubusercontent.com/minio/mc/RELEASE.2025-04-16T18-13-26Z/cmd/anonymous-main.go). These establish idempotent creation and the explicit private policy operation.

Registry metadata requests did not validate image availability: Quay tag API requests returned HTTP 400; Docker Hub tag API requests returned HTTP 404 for these release names. Those are external HTTP lookup results, not Docker pull results or local operation permission errors. Official release/source evidence does **not** prove a published container can currently resolve/pull/start on this host. That verification remains mandatory for Builder and CI. Do not replace pins with floating tags or silently choose another release on failure; report actual resolution/pull errors to Orchestrator for targeted clarification. Official repositories are archived; these pins are for local/CI verification, not a claim of ongoing upstream support or production hardening.

Compose must mount a persistent named volume at the server data directory, expose host ports only on loopback, and use the server command documented above. Use finite healthcheck timeout/retries. `minio-init` waits for server health, configures a private alias from injected non-production credentials, executes `mc mb --ignore-existing` and `mc anonymous set private` for the configured bucket, and exits nonzero on any failure. Do not mask errors with unconditional success. If using a Compose shell entrypoint for multiple client commands, verify that shell in the resolved client image; only `mc` availability is established here. Re-running initialization must not erase data or make the bucket public. Avoid credential echoing/debug output. Runtime verification must include repeat init/private policy and persistence, not just a console screenshot.

## Step 2 — Fresh Correction TDD: Metadata, Validation, and API

For each remaining behavioral defect, first add/strengthen an assertion-based test, execute it in the bootstrapped environment, and verify the precise missing behavior is RED. Then minimally correct implementation, rerun to GREEN, and refactor with another run. Tests already passing on existing implementation are regression evidence, not newly invented historical RED; mutation checks can demonstrate assertion sensitivity but cannot rewrite history.

- Complete/reuse existing migration, model, factory, and Project relationship. Cover actual duplicate-key constraint violations, integer size, ownership relationships, and the `stored`-only contract.
- Add private disk and routes under existing Sanctum SPA handling; use existing AppServiceProvider limiter pattern, keyed per user at 10/minute. Ownership must precede ordinary FormRequest field validation, with recheck under the mutation lock. Preserve 404/401/419/429 semantics and regress existing auth/project/health behavior.
- Test then implement exact-byte size validation and server MIME mapping, sanitized metadata names, and UUID `{user_id}/{project_id}` keys. No extension-only rejection for valid bytes and no `bin` fallback. Cover app oversize, PHP INI/FORM size error codes, and actual Laravel `PostTooLargeException` rendering to the identical JSON 413 body. Ensure global early-body rejection remains resource-independent.
- Reuse explicit API Resources with the public allowlist. Keep internal fields out of browser types and serialized responses. Do not add production key access for E2E.

## Step 3 — Fresh Correction TDD: Storage Consistency and Project Deletion

Implement a small application/service boundary callable from the existing controllers, with injected/testable storage and database failure points as appropriate. Share project-row locking/recheck across upload, media delete, and existing project delete. No background infrastructure or generic framework rewrite.

- Upload: assert write false/throw handling before successful metadata; store first, insert/commit metadata second. On metadata failure, attempt object deletion; assert false/throw compensation leaves an honest orphan candidate and controlled 500. Emit only safe correlated operational context.
- Media delete: false or thrown object deletion preserves metadata and returns 500. Successful absent-object S3 delete is idempotent; generic exceptions are never classified by message text as success. Database failure after object deletion retains the row for retry without claiming the object exists. Success requires both stages, returning 204.
- Project delete: owned project is locked/rechecked; refresh asset list; clean objects in ID order; stop on first false/exception before any database deletion. Preserve project and all rows on failure. After all cleanup succeeds, remove metadata/project in the transaction and return 204 only after commit. Test failure after partial cleanup and after all objects but before DB commit; retry must finish despite absent objects. Remove any swallowing observer introduced by earlier work instead of duplicating cleanup in both places.
- Verify conflicting upload/media/project deletion with controlled PostgreSQL interleavings using the same project lock. Waiters recheck existence and never write to a committed-deleted project. Avoid implicit transaction auto-retry around external writes that could duplicate objects; keep UUID/compensation behavior explicit. Bound storage calls so locks are not held indefinitely.

## Step 4 — Real Integration Must Be Discovered and Run

Keep the existing standalone `apps/api/tests/Feature/Media/MinIOIntegrationTest.php`, give all its tests the separate `minio-integration` group, and ensure the existing Feature discovery actually includes it. No `Storage::fake`, mocked S3 client, local fallback, environment-based skip, or conditional return in this group. Missing infrastructure/configuration is a failed prerequisite, not a pass.

Exercise the real Laravel filesystem through Flysystem/S3 adapter to MinIO: assert successful write, exists, byte-for-byte read, successful delete, absence, and repeated absent-object deletion. Also exercise the real cookie/CSRF upload/list/media-delete/project-delete API with PostgreSQL and actual tiny supported video bytes. Query backend models for keys before deleting rows, then assert object absence. Isolate test keys/data and clean up in test teardown without hiding failures.

The mandatory non-skipping command and execution-count requirements are in `test-plan.md`. Fast fake-backed tests may explicitly exclude this group for developer feedback, but cannot replace the standalone integration gate, full backend execution, or real-storage browser E2E.

## Step 5 — Complete Existing Frontend, Not a Rewrite

Use existing `features/media` client/types/hooks/components and authenticated project selection. Preserve the current accessible-name/import corrections described above; run tests/lint/build before touching alleged prior defects. Change only remaining failures or missing specified behavior.

- Restrict status types/display to `stored`; show pending HTTP state separately. Ensure list fetch/reload/reselection, file input, disabled/pending upload, clear safe errors, and success reset work. Upload FormData must let the browser set its multipart boundary while sending cookies and CSRF header.
- Preserve explicit delete/cancel confirmation, prevent duplicate submissions, keep row/project on server failure, and communicate partial project cleanup honestly. Do not automatically retry an uncertain upload/500 as if it were idempotent. Existing bounded CSRF refresh/retry may remain.
- Guard stale async results across project/session changes and unmount. Keep labels, keyboard/focus behavior, announcements, and responsive design consistent with existing UI; apply applicable design guidance without introducing unrelated dependencies or a new framework.
- Run actual `npm test`, `npm run lint`, and `npm run build` in `apps/web`; TS6133/accessible-name failures must be reproduced or verified already absent, not assumed. Do not weaken assertions or remove legitimately used hooks/imports merely because prior reports mentioned them.

## Step 6 — CI and Playwright Startup

Update `.github/workflows/e2e.yml` with explicit Compose steps, replacing conflicting PostgreSQL Actions service startup as needed so only one database owns port 5432. Keep `command`, `depends_on`, and `entrypoint` in Compose, not Actions `services`. Validate real Actions schema, not only generic YAML parsing. Add no `continue-on-error`, skip-on-unavailable logic, or success-masking commands.

Order: checkout/runtime/dependency installation from Composer lock and npm lock; safe ephemeral environment and PHP limits; combined Compose startup with matching pins; bounded DB/MinIO readiness and successful private bucket init; migrations; mandatory real MinIO integration; managed browser E2E. Propagate consistent S3/DB settings to both PHP test process and Playwright's Laravel child process. Host-run Laravel uses the mapped host endpoint, while init uses Compose DNS. Require both real-integration and browser success in CI; retain safe failure diagnostics/artifacts without secrets.

The defined frontend script is `npm run test:e2e`, not a guessed command. `apps/web/playwright.config.ts` manages Laravel on 127.0.0.1:8000 (readiness at `/api/v1/health`, which checks the database) and Vite on 127.0.0.1:5173, with `reuseExistingServer: false` and all three required viewport projects.

Investigate any actual startup timeout from child-process logs, Composer/node/browser dependencies, PHP extensions, migrations/database connectivity, environment/CSRF host settings, occupied ports, and health responses. Fix the root cause. Do not merely increase timeout unless measurements show a healthy but legitimately slow startup, with those measurements recorded. No framework migration or unrelated startup redesign.

## Step 7 — Verification and Independent Handoff

Builder executes targeted RED/GREEN/refactor and all authorized mandatory commands in `test-plan.md`, reporting actual command, working directory, exit status, counts, and failure classification. Governance execution belongs to authorized Orchestrator/Tester; Builder runs it only if runtime permission allows. Never edit governance tests/agents to obtain a pass.

Independent Tester repeats all applicable backend, real integration, frontend test/lint/build, configured Playwright, and governance checks. Tester must operate the running app, inspect screenshots at 390x844, 768x1024, 1440x900, and check console/network/API, focus/keyboard/labels/live status, ownership, CSRF, traversal, secret exposure, and storage/database failures. Approval cannot rest on written tests or a fake-only suite. Defects return through Orchestrator to Builder on this same issue.

Orchestrator alone owns evidence/lifecycle coordination, commits, CI/PR/merge/closure and the authorized post-merge documentation reconciliation. Suggested concise documentation changes after verified merge: add private S3/MinIO storage and owned upload/list/delete/project cleanup as completed capabilities; record the 100 MiB configurable limit, no resumability/processing, and partial-delete/orphan manual-recovery limits; reconcile stale completed foundation entries and this storage slice without starting another milestone. Keep the next architectural goal subject to separate human authorization.

## Remaining Gates (Not Claimed Complete)

- HUMAN Composer ASK, actual approved dependency resolution, and any subsequently encountered operation permissions.
- Actual Docker image resolution/pull/start, chosen-image command verification, PostgreSQL/PDO/test setup, private bucket readiness, and persistence/re-init checks.
- Fresh meaningful correction RED/GREEN/refactor; actual backend/frontend/real integration/E2E/governance runs and valid required CI.
- Independent browser/screenshot/security/failure review and Tester approval. No runtime results or historical evidence are supplied by this planning revision.

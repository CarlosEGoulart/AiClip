# Specification: Project-Scoped Media Storage — Issue #35

## Authority and Description

Active issue: [Issue #35](https://github.com/CarlosEGoulart/AiClip/issues/35). Existing branch: `@carlosegoulart/35/feat/media-storage`.

An authenticated project owner can upload one video, list stored metadata, delete an asset, and delete a project with its media. Laravel remains authoritative; PostgreSQL stores metadata and private S3-compatible storage stores binaries. MinIO supplies local and CI storage. This clarification preserves existing implementation work; it does not authorize a restart, reset, another issue, or another milestone.

Read this specification with `plan.md` and `test-plan.md` in this directory. The active issue and explicit human storage-only corrections define this increment. `docs/architecture.md`, `docs/prd.md`, `docs/roadmap.md`, `docs/project-state.md`, and ADR-0002 provide context, not authorization for their broader future workflows or upload-capacity targets. Historical project-state/roadmap statements are not current implementation evidence; Orchestrator reconciles them after merge.

## Technical Tasks

- [ ] Complete the Builder-owned, human-gated Composer prerequisite before implementation corrections.
- [ ] Configure the private Laravel media disk, pinned MinIO server/init services, persistence, and deterministic readiness.
- [ ] Complete metadata relationships, server validation, ownership, resource serialization, routes, and per-user upload throttling.
- [ ] Implement testable upload/media-delete/project-delete application boundaries with compensation, recoverable failure, and shared project locking.
- [ ] Complete the existing project media UI and its session/CSRF API client without redesigning unrelated features.
- [ ] Verify regression, failure, security, real MinIO integration, browser behavior, and valid GitHub Actions CI.

## Scope and Out of Scope

Storage only: synchronous bounded upload, metadata listing, individual deletion, and cleanup through the existing project deletion endpoint. No Redis, queues, FFmpeg, FFprobe, Python worker, transcription, scene detection, clips, AI, rendering, images, social OAuth/publishing, or processing placeholders. No new milestone is authorized.

Also excluded: streaming/download endpoints, presigned URLs or multipart/resumable upload, bulk/drag-and-drop upload, percentage progress, thumbnails, duration/codec/resolution extraction, CDN, media versioning, project editing, email verification, billing, framework migration, and unrelated refactors. Pending HTTP operations are UI state, not persisted processing status.

## Authentication, Ownership, and Request Ordering

- Use existing `auth:sanctum` SPA cookie/session authentication. State-changing requests retain CSRF validation using the CSRF-cookie handshake and `X-XSRF-TOKEN`. No bearer-token flow or credentials in localStorage.
- Ownership is server-controlled `session user -> project -> media asset`. Resolve an owned project before upload field validation; resolve media through its owning project for deletion. Nonexistent and non-owned resources both return 404, including missing/unsupported/oversized file submissions that reach routing, authentication, and ownership checks. A controller-only ownership check after FormRequest validation is insufficient.
- Missing/expired authentication returns the existing JSON 401 contract when the request passes preceding middleware. Missing/mismatched CSRF on mutations returns 419 without mutation. A global request-body limit may produce the same resource-independent 413 before routing/authentication; it must not reveal existence. Tests isolate these middleware cases rather than claiming every malformed guest request returns 401.
- Do not mass-assign client data. The upload accepts only `file`; client-supplied `user_id`, `project_id`, disk, key, MIME, size, or status cannot influence ownership or persistence. Extra fields may be ignored, never trusted. IDs in routes are selectors, not authorization.
- Apply `media-upload` at 10 upload attempts per minute per authenticated user, shared across that user's projects, using the existing cache infrastructure. Count every ownership-admitted attempt, including invalid uploads. The eleventh attempt returns JSON 429 with rate-limit/retry headers; another user has an independent limit. Listing/deletion do not inherit this upload limiter.

## Metadata and Storage Identity

Retain `media_assets` with bigint primary key `id`, indexed `project_id` foreign key to projects with database cascade, `original_name` varchar(255), `storage_disk` varchar(64), unique `storage_key` varchar(512), `mime_type` varchar(128), positive bigint `size_bytes`, `status` varchar(32) defaulting to `stored`, and timestamps. Required values are non-null. `MediaAsset` belongs to `Project`; `Project` has many media assets. User ownership remains transitive, not duplicated from request input.

`stored` is the only status in persistence, API, and browser types. It records a successfully completed upload, not a live object-health check. Failed uploads create no successful metadata row. Retained rows after a failed deletion can reference objects already removed; no new status, restored-object claim, or processing/failure placeholder hides that limitation.

The server generates `{user_id}/{project_id}/{uuid}.{extension}` using the authenticated owner, locked project, and a fresh UUID. A uniqueness constraint guards collisions. Clients never choose any segment. The safe extension comes only from detected, server-validated MIME:

| Detected MIME | Storage extension |
| --- | --- |
| `video/mp4` | `mp4` |
| `video/quicktime` | `mov` |
| `video/webm` | `webm` |

Reject unsupported/unreadable/empty content; no `bin` fallback. Server MIME detection uses the uploaded bytes (for example PHP Fileinfo), not the client Content-Type or suffix. Supported bytes with a misleading, different, or missing filename extension remain acceptable: filenames are metadata, and the generated key uses the detected mapping. Unsupported bytes renamed `.mp4` are rejected. MIME allowlisting is not a promise of full video decoding or malware scanning.

Sanitize the original name for display only: take the final component after either slash style, remove control/NUL characters, trim, and limit to 255 characters without breaking Unicode. Use a neutral display fallback if empty. Render as escaped text; never use it in paths, commands, HTML, or log messages. Traversal names and duplicate names cannot select or overwrite objects.

## Public API

Preserve these endpoints and envelopes:

| Endpoint | Success |
| --- | --- |
| `POST /api/v1/projects/{project}/media/upload` | Multipart `file`; 201 with `{"data": <asset>}` only after storage and metadata commit succeed |
| `GET /api/v1/projects/{project}/media` | 200 with `{"data": [<asset>, ...]}`, newest `created_at` first (ID descending for ties); empty is `{"data": []}` |
| `DELETE /api/v1/media/{media}` | 204, empty body, only after object cleanup and metadata deletion succeed |
| Existing `DELETE /api/v1/projects/{project}` | 204, empty body, only after all required object cleanup and database deletion succeed |

Public asset fields are exactly `id`, `project_id`, `original_name`, `mime_type`, `size_bytes`, `status`, `created_at`, `updated_at`. IDs and size are JSON integers; names/MIME/status and ISO-8601 timestamps are strings; status is `stored`. `project_id` is server output, not an upload payload requirement. Do not expose `storage_disk`, `storage_key`, user IDs, bucket/provider locations, credentials, or object URLs in API responses or browser types. Backend tests obtain internal keys by querying the model; no debug endpoint or production backdoor is permitted.

## Upload Size and Errors

`MEDIA_MAX_UPLOAD_SIZE` is a positive integer byte limit, default `104857600` (100 MiB), exposed internally as `config('media.max_upload_size')`. Enforce exact bytes, including non-KiB-aligned overrides: size equal to the limit is allowed; limit plus one is rejected. Do not silently turn size failures into 422 by relying only on Laravel's KiB `max` rule.

Every handled upload-size failure returns HTTP 413 with `Content-Type: application/json` and this stable body:

```json
{"message":"File exceeds maximum upload size.","errors":{"file":["File exceeds maximum upload size."]}}
```

Map all three paths: application byte-limit rejection, PHP `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE`, and Laravel `Illuminate\Http\Exceptions\PostTooLargeException` (including requests whose body PHP discarded). Recognize PHP size errors before ordinary missing/file validation. There is no invented `UploadedFileTooLargeException` dependency. Missing, empty, unreadable, unsupported, or otherwise invalid files produce Laravel-style JSON 422 with `message` and `errors.file` string arrays, except the named size errors which remain 413. Rejections perform no storage write or successful metadata insert.

PHP `upload_max_filesize` must accommodate the configured file limit; `post_max_size` must be larger to allow multipart overhead (at the default, a documented 128M post limit is suitable). Prepare both CLI/server and CI runtime settings, not merely a Laravel environment variable. Document and verify any upstream body limit so it does not substitute an HTML error or an undersized ceiling for this API contract. This increment does not deliver the PRD's eventual large-file target.

Controlled storage/database failures below return JSON 500 with `{"message":"Unable to complete media storage operation.","request_id":"<opaque correlation UUID>"}`. Never return hardcoded success, provider exception text, stack traces, or credentials. Emit structured, access-controlled operational events with the correlation ID, operation/stage, server IDs, generated disk/key when recovery requires them, and cleanup outcome. Do not log raw exceptions, request bodies, cookies, authorization headers, secrets, signed URLs, or original filenames. The public correlation ID must resolve to safe internal recovery context, not expose that context itself.

## Application Boundaries, Failure, and Concurrency

Use explicit, independently testable application/service operations invoked by controllers. Project cleanup is not a swallowing model observer, and database FK cascade is not an object cleanup mechanism.

All upload, individual media deletion, and project deletion mutations acquire the same project's PostgreSQL row lock inside a transaction before external object mutation. Re-fetch/recheck project existence and ownership under that lock; media deletion also rechecks that the asset still belongs to that project. Hold the lock through storage work and database commit/rollback, with bounded storage timeouts. Always acquire the project lock first. Do not trust route-bound snapshots or use a new lock service/infrastructure. A waiting upload cannot write after project deletion commits; a preceding upload must be included in the deletion's refreshed asset set. Database transactions cover metadata only, never distributed ACID with S3.

| Operation and failure | Required result |
| --- | --- |
| Upload write returns false or throws | Controlled 500; no successful metadata. Treat an uncertain remote write as potentially leaving an object and record safe recovery context, never declare upload success. |
| Write succeeds, metadata insert/transaction fails | Roll back metadata; attempt compensation delete for the generated object; controlled 500 even if compensation succeeds. |
| Compensation returns false or throws | Still 500/no successful metadata; record the orphan candidate and unsuccessful cleanup for human recovery. Do not claim automatic cleanup or atomic rollback. |
| Individual object delete returns false or throws | Controlled 500; preserve the row and its recovery identity. Do not reinterpret generic provider errors or a later probe as success. |
| Individual object cleanup and database deletion both succeed | 204; no row or object remains for that asset; unrelated assets are unchanged. |
| Object cleanup succeeds, database deletion/commit fails | Controlled 500; rollback retains the retryable row. Object removal cannot be undone; retry handles the absent object. |
| Any project object cleanup returns false or throws | Controlled 500; retain project and all metadata rows. No database cascade/deletion may begin before every required object cleanup succeeds. |
| All project object cleanup succeeds, database step fails | Controlled 500; transaction rollback retains the project and metadata for retry, without pretending objects were restored. |
| All project object cleanup and database commit succeed | 204; project/media rows and their objects are gone. A project without media also deletes successfully. |

An already-absent object is a normal idempotent S3 delete success; this behavior must be verified against real MinIO. A false return or thrown error is not this successful case and must fail closed. A repeated HTTP delete after its metadata is gone returns 404 under the normal resource contract.

Project cleanup iterates its refreshed metadata in deterministic ID order and stops on the first cleanup failure, leaving all database rows intact. Earlier object deletions cannot be rolled back. After storage/database recovery, the owner retries the same deletion; absent objects succeed and remaining objects are removed. For upload compensation failures, a human operator uses the correlated internal generated key, confirms no committed metadata references it, and removes only that orphan through authorized storage tooling. This is a documented manual recovery path, not a new queue, sweeper, lifecycle policy, or permission grant. Process termination/ambiguous remote outcomes can also leave orphan candidates; no exactly-once or distributed rollback guarantee is made.

## Storage Infrastructure and Dependencies

- Use `MEDIA_DISK=media`, a private S3 disk with `throw=true`, and the existing `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` conventions. Handle false results even with throwing enabled. Examples contain only clearly non-production placeholders; do not inspect real `.env` secrets.
- Add `league/flysystem-aws-s3-v3:^3.0` through normal Composer resolution. After valid planning/governance, Builder's first operational prerequisite is the now-authorized `ls` preflight in `apps/api`, then `composer require league/flysystem-aws-s3-v3:^3.0` there. The Composer ASK is pending HUMAN: invoke it, WAIT for explicit approval, and continue only after approved successful execution. No inferred approval, manual lockfile editing, Orchestrator installation, or permission bypass.
- Pin server `quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z` and init client `quay.io/minio/mc:RELEASE.2025-04-16T18-13-26Z`. Official release/source evidence and unverified registry status are recorded in `plan.md`. These are local/CI pins, not a production security/support certification.
- Keep PostgreSQL and add `minio` plus one-shot `minio-init` in `docker-compose.yml`, persistent named MinIO data volume, private idempotent bucket initialization, loopback host exposure, and bounded readiness. Server healthcheck uses the chosen image's source-evidenced `curl`, not guessed tools. Builder must actually resolve/pull/start the exact pins and verify the command in the running image.
- Authorized startup is the combined repository-root `docker compose up -d postgres minio minio-init`, not a standalone PostgreSQL startup. Respect actual runtime permissions; a required-operation denial must be reported with the attempted command and raw error, not inferred from a static permission pattern. Stop rather than bypass a denial.
- `.github/workflows/e2e.yml` must use valid GitHub Actions syntax, preferably explicit Compose steps. Compose-only `command`, `depends_on`, and `entrypoint` do not belong under Actions `services`. Database health, MinIO readiness, and successful private bucket init precede real integration and browser tests. CI must require both, without skip/empty-selection success.

## UX and Accessibility

Extend the existing owned-project selection flow, not a new framework or dashboard. Selecting a project shows its name, a labeled single-file input, allowed formats/size guidance, upload action, and its media list. Show loading, empty, stored, uploading, error, and deletion-pending states truthfully; no fake processing or percentage progress. Supported filename/MIME mismatch must not be blocked by an extension-only client validator.

On successful upload, show the returned asset and reset the selection. On failure, display actionable safe feedback and allow retry without inserting an optimistic success. Listing persists after reload/reselection. Project changes/unmounts/session changes cannot apply stale results to a different project. Deletion requires clear confirmation and cancellation; disable duplicate actions while pending, remove an item/project only after 204, and retain recoverable UI state on failure. Warn that failed project cleanup may already have removed some objects and that retry is required; do not claim full rollback.

Preserve keyboard operation, associated labels, visible focus, sensible focus return after cancellation/deletion, polite live status and alert errors, and responsive layout at 390x844, 768x1024, 1440x900. Expected accessible names take precedence over transient visual button text in tests. Browser console, network, and API payloads must not leak secrets/internal storage fields.

## Acceptance Criteria

- [ ] AC1 — Private metadata schema, transitive ownership, UUID user/project keys, sanitized display names, and public-field allowlist match this specification; only `stored` is persisted/exposed.
- [ ] AC2 — Existing upload/list/delete paths and SPA cookie/CSRF behavior work; cross-user and nonexistent resource access is hidden, including invalid routed submissions; per-user 10/minute limit works.
- [ ] AC3 — Server byte/MIME validation enforces the 100 MiB configurable default, accepts supported bytes regardless of filename suffix, rejects invalid/empty bytes, and returns stable 413 for every named size path versus 422 for other validation.
- [ ] AC4 — Upload false/exception and metadata-failure compensation paths return controlled failures without successful metadata or secret leakage; orphan recovery is honest and correlated.
- [ ] AC5 — Media and explicit project deletion preserve retryable database state on failure, clean objects before database deletion, handle absent-object retries, and serialize conflicting mutations through the shared project lock.
- [ ] AC6 — Approved Composer output installs the S3 adapter; exact pinned images actually start with verified healthcheck, persistence, and idempotent private bucket initialization.
- [ ] AC7 — A discovered standalone `MinIOIntegrationTest` exercises Laravel -> Flysystem -> S3 adapter -> real MinIO write/read/exists/delete/absent and real API/database lifecycles; mandatory invocation fails on unavailable infrastructure, skips, or no tests. Fakes alone are insufficient.
- [ ] AC8 — Existing media UI supports owned project/file selection, pending/errors/list/reload/confirmed deletion/empty state without regressions; keyboard, focus, labels, announcements, and all three viewports pass independent review.
- [ ] AC9 — Actual backend/frontend/lint/build/Playwright/governance checks and required real-integration/E2E CI pass. Independent Tester reviews running application, screenshots, console/network, failure and security behavior. No setup failure, skipped test, or tests-written claim substitutes for RED/GREEN or approval.

## Verification Status

This is a corrected planning contract, not implementation approval. The working-tree bundle was reported reverted to `e83ef98`; historical evidence is lost/reverted or otherwise unverified. Existing code/tests and prior reports do not establish historical preimplementation RED. Builder must bootstrap the environment, then obtain fresh assertion-based RED for remaining correction work, implement GREEN, and rerun after refactoring. Required commands, discovery rules, and independent review are defined in `test-plan.md`. No Planner test execution or Composer/image approval is claimed.

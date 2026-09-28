# Current Architecture

Laravel 13 API, React 19 + Vite 8 frontend, and PostgreSQL 16, verified by a
health endpoint (`GET /api/v1/health`) with a real database check. Authentication
via Laravel Sanctum SPA session/cookie mode with CSRF protection. Media storage
via S3-compatible backend (MinIO in development, AWS S3 in production).
Laravel's `ProcessMediaAsset` queue job invokes `ProcessMediaAction`, which
runs the Python media CLI as a subprocess. Laravel validates worker results
and owns PostgreSQL persistence; Python does not consume the Laravel queue.

# Completed Capabilities

M0 governance foundation (Issues #1–#15). M1 foundation: health API/page, Pest
backend suite, Vitest frontend suite with mutation-proven assertions,
Playwright-managed E2E at three viewports, and backend/frontend/E2E/governance
CI. Sanctum SPA auth (Issue #28): register, login, logout, session persistence,
CSRF protection, database sessions, password hashing, rate limiting. Auth UI
styled per Taste Skill and Vercel Web Design Guidelines. Health check route at
/health independent of AuthProvider. Authenticated project management
(Issue #30): session-owned projects with name and optional description;
list/create/show/delete API, non-owner 404 responses, rate-limited creation,
and frontend list/create/delete with confirmation. Final verification is in
`specs/030-project-management/evidence.md`. M2 media storage foundation
(Issue #35): project-scoped video upload and S3-compatible storage with
user_id/project_id/uuid key structure, MediaAsset model, upload/list/delete
API with rate limiting, frontend media upload and list with delete confirmation,
MinIO integration tests, and full E2E coverage with mocked storage. Final
verification is in `specs/035-media-storage/evidence.md`.
M3: queued processing, FFprobe probing and FFmpeg audio extraction.
M4: transcription, scene detection, contract hardening and deterministic clip
candidate analysis (Issue #58 / PR #59 merged and closed). Candidate metadata
uses `scene_timing_baseline` v1.0.0 with timing-based scores/ranks, not AI
recommendation. Scene analysis is independent of transcription failure;
no-audio and extraction-failure workflows retain scene-only analysis.

# Important Decisions

One active implementation issue at a time. TDD RED before implementation.
Independent Tester review. English-only content. Laravel is the authoritative
backend; heavy media/ML work stays out of HTTP request processes. Health check
route at /health (no auth provider). Frontend uses React 19 StrictMode-safe
auth hooks with generation-based race-condition protection. Storage uses
`filter_var(..., FILTER_VALIDATE_BOOLEAN)` for boolean casting in PHPUnit.
CSRF exceptions for media upload routes.

# Known Limitations

E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports
5173/8000. Email verification not implemented. No project update/edit endpoint
yet (out of scope for #30). Real runtime transcription model requires the
faster-whisper optional dependency and model download. Real runtime scene
detection requires the scenedetect[opencv-headless] optional dependency.
Mandatory CI contains deterministic worker/unit coverage, mocked PySceneDetect
adapter coverage, AND real FFmpeg-generated video + real PySceneDetect
integration coverage. Rendering and social features do not exist yet. Semantic
clip ranking exists only as the unmerged Issue #64 candidate on the recovery
branch; mainline still carries deterministic `scene_timing_baseline` metadata
only, and there is no recommendation UI or public recommendation API. The agent
runtime does not allow local execution of `tests/governance/pr_enforcement.py`
(it runs in Actions) or deletion of generated `__pycache__` files; generated
caches must never be staged.

# Current Milestone

M0–M4 foundations and M4 corrective closeout completed. Issue #60 remains
closed as completed following the merge of PR #61 (PostgreSQL concurrency,
failure boundaries, empty-result completion and documentation), with evidence
separate from the merged #58 artifacts. The single active implementation issue
is #64 (M5 first slice, semantic clip recommendation). The implementation
candidate at local head `10d882e` (rewritten 12-commit chain, tree-verified,
governance-tested locally) carries the prior independent Tester approval, and
the status-documentation delta was separately reviewed and approved; evidence
holds exactly one normative verdict type, `Decision: APPROVE`. Under the
human-approved recovery of 2026-09-28, the original branch
`@carlosegoulart/64/feat/semantic-clip-recommendation` and PR #65 (remote head
`857965e`) are superseded without merge and preserved: PR #65 will be closed
unmerged, its branch and published refs are not deleted or rewritten, and no
force push is used. The recovery branch
`@carlosegoulart/64/feat/semantic-clip-recommendation-recovery` carries the
same content plus the four approved status documents, and its replacement PR
is the one active PR. CI results exist only for the superseded head `857965e`
(backend, frontend, E2E and governance passing; pr-enforcement failing there
on the old invalid commit message and pre-normalization evidence decisions);
the recovery candidate is unmerged and its five final CI checks are pending.
Nothing is merged or shipped and Issue #64 remains open.

# Next Architectural Goal

Finish Issue #64 through the approved recovery: publish
`@carlosegoulart/64/feat/semantic-clip-recommendation-recovery` with an
ordinary `git push -u` (no force, no force-with-lease, no rewriting or
deletion of published refs), keep exactly one active PR by superseding PR #65
without merge, then wait for the five fresh checks on the exact new head
(Backend CI/tests, Frontend CI/test, E2E CI/e2e, governance/governance,
governance/pr-enforcement) and stop at `CI_GREEN_WAITING_HUMAN_MERGE` (spec
acceptance #6). Only after explicit human authorization may
`scripts/merge_gate.py` run, the replacement PR merge, and Issue #64 close;
then return to NO_ACTIVE_ISSUE. A standalone queue-consuming Python service
remains a future architectural evolution, not the current CLI execution
topology. M6 and any further M5 slices require separate explicit
authorization after #64 closes.

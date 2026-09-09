# Implementation Plan — Issue #5

## Objective

Resolve 16 specific inconsistencies in MVP documentation before application scaffolding begins.

## Step 1: Read Current Files

Read all files that will be modified:
- `docs/architecture.md`
- `docs/prd.md`
- `docs/roadmap.md`
- `docs/project-state.md`
- `README.md`

## Step 2: Update `docs/architecture.md`

### T1: Simplify architecture terminology
- Line 5: Replace "microservices-based platform" with "modular application with a dedicated media worker boundary"
- Line 6: Remove "independent scaling" language that implies microservices

### T2: Pin concrete technology versions
- Line 17-19: Replace "React 18+" with "React 19"
- Line 42-43: Replace "Laravel 10+ with PHP 8.2+" with "Laravel 11 with PHP 8.3"
- Line 68: Replace "Python 3.11+" with "Python 3.12"
- Add Node.js 22 LTS if mentioned

### T3: Standardize API strategy
- Lines 166-168: Remove "GraphQL for complex queries (optional)" and "WebSocket for real-time status updates (optional)"
- Replace with: "REST JSON API with /api/v1 versioning"
- Update endpoint examples to reflect /api/v1 prefix

### T4: Revise authentication architecture
- Lines 172-175: Replace token-based auth with Sanctum SPA authentication
- Document: HTTP-only session cookies, CSRF protection, server-side session management
- Add explicit distinction between AiClip user authentication and social OAuth authorization

### T5: Replace AI image provider
- Line 309: Replace "OpenAIImageProvider (primary)" with "FluxImageProvider (primary), SdxlImageProvider (alternative)"
- Ensure FakeImageProvider is listed for CI

### T6: Document development AI vs product AI
- Add new subsection or note distinguishing:
  - Development AI: OpenCode models for agent reasoning (Planner, Builder, Tester)
  - Product AI: Image generation, transcription, clip ranking models used by application

### T7: Clarify video AI strategy
- Add note in Media Processing Flow: "Long-video clipping does NOT require generative video models"
- Primary pipeline: FFprobe → transcription → scene detection → ranking → reframing → FFmpeg render

### T14: Clarify queue boundary
- Add note: Laravel dispatches jobs to queue (Redis/SQS). Python worker consumes using compatible client (rq for Redis, boto3 for SQS), not Laravel-native serialized jobs.

### T15: Clarify worker database access
- Line 87: Expand "Updates metadata in PostgreSQL via API" to clarify:
  - Laravel owns application persistence
  - Worker results cross explicit application boundary (HTTP API or job result payload)
  - Worker never directly writes to PostgreSQL

## Step 3: Update `docs/prd.md`

### T8: Correct MVP clip editor scope
- Line 192: Change "Advanced video editing features (trimming, merging, effects beyond reframing)" to:
  - "Advanced video editing features (multi-track timeline, complex transitions, manual compositing, advanced effects, keyframe animation, professional color grading, full nonlinear editor)"
- Add note that basic trimming (start/end time adjustment) IS in MVP scope

### T9: Correct social media manager scope
- Line 25: Change "Track publishing status and performance metrics" to "Track publishing status across platforms"

### T10: Define one-click publishing semantics
- Add clarification: "One-click publishing means: after accounts are already connected and publishing metadata has been reviewed, the user can trigger publication to selected destinations with one explicit confirmation action."
- Document: publication batch created, each destination has independent status, failure on one platform does not rollback others, operations are idempotent.

### T11: Reclassify performance requirements
- Lines 169-171: Move hard targets to new "Performance Benchmarks" subsection
- Add note: "The following are target benchmarks subject to measurement, not guaranteed requirements. Actual performance depends on infrastructure and content characteristics."
- Keep: page load time, API response time as architectural requirements

### T12: Remove third-party security review
- Line 220: Replace "Third-party security review completed" with "Internal security review completed"

### T13: Replace issue number references
- Line 208: Change "Issue #2 (Application Scaffolding): Pending" to "M1 — Application Foundation: Pending"
- Review line 209-211 for similar issues

## Step 4: Update `docs/roadmap.md`

### T16: Update current milestone
- Line 7: Change "M0 Engineering Governance (issue #1)" to reflect current state
- Add: "M1 preparation. Issue #3 (documentation formalization) and Issue #5 (documentation corrections) completed."

## Step 5: Update `docs/project-state.md`

- Update Completed Capabilities to mention Issue #5
- Update Important Decisions with key corrections
- Update Current Milestone if needed

## Step 6: Update `README.md`

- Update technology stack to match pinned versions
- Update current development cycle section

## Step 7: Verify Consistency

Cross-check all documents:
- PRD features match architecture services
- Architecture matches roadmap milestones
- No contradictions between documents
- All documents in English

## Expected Final State

After all steps:
- `docs/architecture.md` — Corrected terminology, versions, API, auth, providers
- `docs/prd.md` — Corrected scope, performance, security, references
- `docs/roadmap.md` — Updated current milestone
- `docs/project-state.md` — Updated state
- `README.md` — Updated versions and links
- No application code changes

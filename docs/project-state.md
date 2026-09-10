# Current Architecture

MVP product definition and system architecture formalized. Application foundation established with Laravel API, React frontend, and PostgreSQL.

# Completed Capabilities

Issue #1 governance foundation merged into master: four OpenCode roles, six local skills, planning documents, governance tests, and lightweight CI.

Issue #3 documentation formalization: complete MVP product definition, system architecture, and architectural decision record.

Issue #5 architecture inconsistencies resolved: pinned technology versions, standardized REST API with /api/v1, documented Sanctum SPA authentication, replaced OpenAI image provider with Flux/SDXL, clarified video AI pipeline, documented development AI vs product AI distinction, clarified queue boundary and worker database access, corrected scope definitions, reclassified performance benchmarks, and replaced issue references with milestone references.

Issue #7 governance enforcement: automated validation for commit messages (Conventional Commits), branch naming, PR-to-issue linkage, Tester approval evidence, and TDD evidence structure. CI extended with PR enforcement job.

Issue #9 PR enforcement completion: orchestration script integrating all five validators, cross-validation of branch issue against PR body, evidence file resolution, Tester APPROVE gate, TDD evidence validation. Integration tests with deterministic fixtures.

Issue #11 merge gate finalization: APPROVE-only merge gate (REJECT now fails), deterministic evidence resolution (multiple matches fail), TDD N/A requires meaningful reason. Integration tests use real temporary evidence without error filtering.

Issue #13 SDD bundle enforcement: generic `validate_sdd_bundle()` validates every specs/ directory contains spec.md, plan.md, test-plan.md, evidence.md. Dependency injection for test isolation. Historical Issues #9 and #11 repaired with retrospective markers. Integration tests use temporary directories without mutating real specs/.

Issue #15 SDD bundle wiring: `validate_sdd_bundle()` now executed by `run_checks()` in actual PR governance. Generic validation catches any incomplete `specs/<issue>-*` directory without hardcoded issue list. Integration tests exercise `run_checks()` orchestration with isolated temporary directories.

Issue #17 M1 Application Foundation: Laravel 11 API in apps/api/, React 19 + TypeScript + Vite frontend in apps/web/, PostgreSQL 16 via Docker Compose, GET /api/v1/health endpoint with real database connectivity check, backend Pest tests, frontend Jest tests, CI workflows for backend, frontend, and E2E.

# Important Decisions

Strict issue linearity with one active implementation issue. TDD RED before implementation content. Independent Tester review. English-only repository content.

Laravel remains authoritative application backend. Python media worker handles ML/media workloads outside PHP. Provider abstractions isolate external service dependencies. Object storage for media binaries. Deterministic fakes for CI testing.

Architecture is modular application with dedicated media worker boundary (not microservices). Sanctum SPA authentication with HTTP-only session cookies. REST JSON API with /api/v1 versioning. Open-weight image generation providers (Flux, SDXL). Long-video clipping does NOT require generative video models. Worker communicates results through explicit application boundary, never directly writes to PostgreSQL.

# Known Limitations

Vitest has compatibility issues with Node.js v24 in this environment; Jest is used for frontend testing instead. Playwright E2E tests are configured but require full stack startup for execution.

# Current Milestone

M1 Application Foundation.

# Next Architectural Goal

Authentication and user management: implement Sanctum SPA authentication, user registration, and login flows.

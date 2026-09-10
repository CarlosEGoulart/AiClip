# Title

fix(m1): stabilize application foundation testing and documentation

## Description

Stabilize the existing Laravel/PostgreSQL health endpoint and React health page without extending M1 functionality. Replace tests of a copied component with tests of production behavior, restore Vitest, make full-stack browser testing self-starting and trustworthy, and document reproducible setup.

This specifies existing GitHub issue **#19**, not a new issue. The authorized handoff reports #17 closed, #18 merged, four master workflows green, no PR, and branch `@carlosegoulart/19/fix/m1-foundation-stabilization` already created by the outer Orchestrator. GitHub and branch state were not independently fetched by Planner. Requirements below derive from that handoff and local source inspection.

## Technical Tasks

- [ ] Demonstrate that the original frontend suite passes despite a meaningful production `HealthCheck` mutation; restore the source before proceeding.
- [ ] Restore Vitest on Node 24/Vite 8, import the production component directly, and mock only fetch/environment boundaries. Cover pending, success, rejection, non-2xx, and API URL selection. Remove obsolete Jest dependencies/configuration, including ts-jest, and use `vite/client` typing instead of `@ts-ignore`.
- [ ] Add deterministic database query exception coverage and observe the actual health query during a real PostgreSQL success request. Preserve the existing safe route unless a demonstrated defect requires a minimal correction.
- [ ] Reproduce standalone `node test-server.js` ESM `__dirname` failure before removing/replacing that lifecycle. Prefer Playwright-managed Laravel and Vite servers with strict unused ports, readiness checks, no reuse, and cleanup.
- [ ] Add `npm run test:e2e`; use it locally and in CI without prebuilt dist or externally started application servers once dependencies, environment, and PostgreSQL are ready.
- [ ] Strengthen browser coverage and screenshot review at all three required viewports, including deterministic pending and explicit error states, with listeners installed before navigation.
- [ ] Default frontend requests to relative `/api/v1/health` through the same-origin Vite `/api` proxy. Retain an optional public production-origin override and document deployment routing/CORS responsibility.
- [ ] Align environment examples, scripts, lockfile, affected application CI, root/app READMEs, architecture test-runner description, and concise six-heading project state. Preserve governance CI and gates.

## Acceptance Criteria

- [ ] **AC1 — Production coverage:** No component-module mock or copied implementation remains. Frontend tests assert loading, successful status/database/timestamp rendering, rejected-fetch error, non-2xx error, and exit from loading. A controlled unresolved promise makes pending assertions deterministic. Tests verify default relative URL and configured origin override, resetting globals/environment between tests.
- [ ] **AC2 — Mutation sensitivity:** Evidence first shows original tests missing a production mutation, then repaired tests failing on that same mutation. Additional targeted mutations of loading/success/error handling and HTTP status checking fail behavior assertions. All temporary mutations are restored and final tests pass.
- [ ] **AC3 — Runner and typing:** Clean installation, non-watch `npm test`, build/typecheck, and lint work on Node 24/Vite 8 with Vitest/React Testing Library. Jest runner, Jest-only types/configuration, ts-jest, and stale references are removed; `@testing-library/jest-dom` may remain with Vitest integration. No `@ts-ignore` is needed for Vite environment access. Runner substitution requires Planner adjudication of an exact reproducible non-resource defect; resource/process limits or setup errors do not justify it.
- [ ] **AC4 — Backend contract:** Forced query exception yields HTTP 503 with exact JSON `{"status":"error","database":"disconnected"}` and no exception/SQL/credential details. Independent real PostgreSQL success coverage asserts HTTP 200, expected fields/ISO timestamp, PostgreSQL connection, and observation of `SELECT 1` during the request. Failure mocks are isolated. Removing the query or leaking exception details is detected by regression tests.
- [ ] **AC5 — E2E lifecycle:** Original standalone startup failure is recorded before replacement. From a prepared dependency/database environment with no dist and no prestarted apps, `npm run test:e2e` starts the actual Laravel/Vite stack, waits for readiness, passes, and releases owned processes/ports. Port collisions fail rather than silently reuse or move ports. CI uses the same lifecycle instead of background app startup and fixed sleeps.
- [ ] **AC6 — Browser quality:** At 390x844, 768x1024, and 1440x900 the real health result is visible, content is not horizontally overflowing or clipped, and the page is usable. Deterministic loading and controlled rejection/non-2xx states are exercised. Pre-navigation listeners capture console errors, page errors, failed requests, and API responses. Happy paths have no unexpected failures; negative scenarios permit only the exact deliberately induced endpoint/status/failure. No broad favicon/HMR or other exclusions are allowed.
- [ ] **AC7 — Configuration/docs:** `.env.example` defaults do not bypass the Vite proxy. Documentation describes public override semantics, production same-origin routing or explicit deployment CORS, prerequisites, working directories, database preparation, dev/test/build/E2E commands, ports, and cleanup. Root and affected app READMEs describe actual implemented foundation separately from planned features. Architecture identifies Vitest; project state keeps exactly the six mandated headings and removes the unsupported Node incompatibility claim without claiming premature completion.
- [ ] **AC8 — Independent gate:** Relevant unit, backend integration, E2E, build/lint, and governance tests pass. Independent Tester runs the actual stack, reviews screenshots at each viewport and console/network/API states, reproduces production mutation failures, validates mocked DB failure plus real query success, and executes README commands. Any unmet criterion is REJECT on #19. Only outer Orchestrator performs lifecycle operations after Tester approval and all required CI, then verifies closure and stops.

## Out of Scope

Authentication, registration, projects, media, Redis, queues, AI, social integrations, dashboard, UI redesign, additional application scaffolding, deployment implementation, unrelated refactors, and durable agent/permission configuration changes. Issue-19-only temporary runtime authorization does not expand Planner's three-file write scope.

## Security Considerations

Keep database exceptions private; use synthetic sensitive markers in failure tests, never real secrets. Vite-prefixed settings are public build-time configuration, not secret storage. A separate production API origin requires deployment-owned explicit CORS; do not solve this with permissive wildcard security changes. Temporary mutations and test credentials must not leak into delivered implementation or artifacts.

## UX Considerations

Preserve the existing foundation page. Validate readable loading/success/error content, semantic status/alert feedback, keyboard/focus behavior of existing controls, contrast, and responsive layout. Make only minimal fixes necessary for these criteria, not a redesign.

## Test Scenarios

See `test-plan.md` for regression/mutation ordering, clean startup, database contracts, and viewport/state review.

## Dependencies

Existing M1 foundation; Node 24, npm lockfile installation, PHP/Composer compatible with the installed Laravel version and PostgreSQL extension, PostgreSQL 16, and installed Playwright Chromium/system dependencies. Builder verifies installed versions and applicable API-local guidance before implementation. Environment limitations must be reported distinctly from product failures. Execution roles own `evidence.md`; Planner creates only this specification, `plan.md`, and `test-plan.md`.

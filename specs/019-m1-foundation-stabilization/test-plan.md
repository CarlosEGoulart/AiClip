# Test Plan — Issue #19

## Preconditions and Recording

Execution roles verify issue/branch and obtain authorized test/runtime access; this document grants no permission bypass. Prepare installed dependencies, Node 24/Vite 8, PHP/Composer and required extensions, isolated PostgreSQL 16 configuration, Laravel environment/key and documented database preparation, and Playwright Chromium. Record exact commands, working directories, installed versions, exit statuses, assertions, and evidence paths. Do not record credentials. Infrastructure/setup errors are blockers, not RED.

## 1. Baseline and Regression Proof (AC1–AC5)

1. In `apps/web`, run the original frontend suite and record its result. Temporarily alter production `HealthCheck` success output so the existing success expectation should fail; run the original suite and demonstrate it still passes because it mocks the component. Restore the source. This is evidence of a coverage blind spot, not a test failure.
2. Before removing its lifecycle, execute `node test-server.js` standalone and capture the actual ESM `__dirname` failure. Do not let a prestarted server mask it or substitute a missing-module/setup error for this reproduction.
3. With repaired tests importing production, repeat the identical rendering mutation: the success assertion must now fail. Individually mutate pending rendering, rejected-fetch handling, and the non-2xx guard; each relevant test must fail for its intended behavior, not syntax or runtime configuration. Restore after every mutation and rerun GREEN. Keep a concise mutation-to-test/result table.
4. For the already-safe backend route, first run new coverage unchanged and honestly report initial passes. Mutate exception response to include synthetic error details: exact 503 JSON assertion must fail. Remove `DB::select('SELECT 1')`: real query-observation coverage must fail even if the response still says connected. Restore and rerun after each.

## 2. Frontend Unit/Component Matrix (AC1–AC3, AC7)

Use Vitest and React Testing Library against the imported production component, with no component mock or copied implementation. Stub fetch only at the network boundary and reset environment/globals/DOM between cases.

| Scenario | Required assertions |
| --- | --- |
| Deferred fetch remains pending | Status/loading text visible; no success or alert; pending promise is controlled, not a timing race. |
| Deferred fetch resolves 200 | Loading disappears; actual response status, database and timestamp render; no alert. |
| Fetch rejects | Loading disappears; meaningful error alert is visible; success absent. |
| Non-2xx response (503) | Error alert reflects HTTP failure, not successful rendering of error JSON; loading ends. |
| No public origin configured | Fetch requests exactly `/api/v1/health`. |
| Public origin configured | Fetch requests the documented origin plus `/api/v1/health`; configuration is isolated between tests. |

From `apps/web`, verify clean `npm ci`, non-watch `npm test`, `npm run build` (including TypeScript), and `npm run lint`; verify the watch script invokes Vitest without needing to claim an unattended watch exit. Confirm Vitest does not collect E2E files. Inspect manifests, lockfile, configs and types for removed Jest-only/ts-jest remnants; retain jest-dom only as compatible matchers. No Vite environment suppression remains. Resource limitations may justify a documented bounded worker setting, not an unsupported Node incompatibility claim or runner replacement.

## 3. Backend Integration (AC4)

From `apps/api`, run the targeted health feature tests and the complete relevant backend suite using its installed test runner (for example `php artisan test --compact tests/Feature/HealthTest.php`, then `php artisan test --compact`).

- Force the query boundary to throw deterministically with a synthetic SQL/credential-like marker. Assert status 503 and exact body `{"status":"error","database":"disconnected"}`; no timestamp, message, stack, SQL, credentials, or extra fields. No real outage is needed for this isolated test.
- Separately use actual PostgreSQL, with no DB success mock or SQLite substitution. Observe queries only across the health request and assert the PostgreSQL connection executes `SELECT 1`. Assert 200, `status: ok`, `database: connected`, and a valid ISO timestamp.
- Run failure then success and the full suite to expose leaked mocks/listeners. Reproduce query-removal and disclosure mutation failures from section 1. Preserve safe existing route behavior rather than manufacturing missing implementation.

## 4. Clean E2E Lifecycle (AC5, AC7)

After dependencies/environment/PostgreSQL are ready, start with no prebuilt `apps/web/dist` and no app process on the configured test ports. From `apps/web`, `npm run test:e2e` must manage Laravel plus Vite, await readiness, reach real PostgreSQL health through Vite's relative `/api` proxy, and exit successfully without manually started apps.

- Verify explicit hosts/ports/proxy targets agree, both ports are strict, and server reuse is disabled locally and in CI.
- Repeat the invocation to verify cleanup and absence of stale process masking. Verify ports/processes are released on success and an intentional test failure.
- With an authorized controlled process occupying either configured test port, startup must fail clearly rather than reuse it or advance ports. Release only the controlled test process; never terminate unrelated services. Rerun normally.
- Inspect CI for the same command and inherited backend DB settings, no separately backgrounded app servers/fixed sleeps, and useful report/screenshot/trace uploads. Preserve independent build and governance checks.

## 5. Browser and Visual Matrix (AC6)

Run Chromium at **390x844**, **768x1024**, and **1440x900**. Before each navigation install console, pageerror, requestfailed, and response listeners, plus any controlled route needed by that scenario. Avoid broad exclusions and arbitrary sleeps.

At every viewport inspect actual screenshots and the running page for:

- Real-stack success: visible health heading, status/database/timestamp, correct 200 API JSON, no unexpected console errors, uncaught exceptions, failed resources/requests, redirects, or application 4xx/5xx.
- Deterministic pending: hold the specific health request until loading is asserted and captured, then release it and verify transition. Do not use an unbounded artificial delay.
- Controlled network rejection and HTTP 503: understandable error state with loading cleared, no misleading success. Only exact diagnostics for the deliberately failed health request may be expected; document endpoint/status/failure and reject all other errors. Test doubles here do not replace real-stack success or backend failure tests.
- No horizontal document overflow, clipped health content, unreadable wrapping, or unusable page; inspect bounding/layout behavior rather than asserting only that a heading exists. Check spacing, text contrast, semantic status/alert, and keyboard/focus operation of existing interactive elements. Mark nonexistent dialogs/destructive/empty-state controls N/A with a reason, not the browser gate itself.

Retain screenshot paths labeled by viewport/state and diagnostic/API summaries. Independent Tester must visually open and evaluate these artifacts, not approve solely on automatic visibility/geometry assertions.

## 6. Documentation, Governance, and Decision (AC7–AC8)

- Independently follow root/API/web README commands with documented working directories and prerequisites: database preparation, API/frontend dev startup and cleanup, frontend unit/build/lint, backend tests, and clean-start E2E. Confirm examples default to same-origin proxy; review optional public production origin and deployment CORS/routing responsibility without introducing deployment scope.
- Check accurate installed stack descriptions, implemented-versus-planned boundaries, Vitest architecture entry, and compact project state with exactly `Current Architecture`, `Completed Capabilities`, `Important Decisions`, `Known Limitations`, `Current Milestone`, and `Next Architectural Goal` headings. Remove unsupported Node claims and stale current-issue assertions; do not claim closure before it occurs.
- Run governance regression tests from root: `python -m unittest discover -s tests/governance -p 'test_*.py' -v`. Verify the completed issue bundle includes execution-owned evidence and that existing workflow gates were not weakened.
- Tester independently runs actual-stack tests, mutation failures/restored GREEN, DB failure plus real query success, and README validation. If necessary Builder applies/removes temporary production mutations while Tester independently executes and judges; Tester does not repair implementation.
- Record APPROVE only if every criterion passes with evidence and no mutation or unrelated change remains. Otherwise REJECT with criterion-specific findings through Orchestrator to Builder on **#19**. Outer Orchestrator alone verifies all required CI, handles the approved PR/merge/closure, and stops at `NO_ACTIVE_ISSUE`; no next issue is authorized here.

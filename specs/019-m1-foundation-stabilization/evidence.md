# Evidence — Issue #19: M1 Foundation Stabilization

Owner: Builder. Tester review pending. No lifecycle operations performed.

Environment (directly verified by Builder unless noted): Node v24.20.0,
npm 12.0.2, PHP 8.3.6 with pdo_pgsql, PostgreSQL 16.15 at 127.0.0.1:5432
(database/role `aiclip`), Vitest 5.0.0, Vite 8.2.2, Playwright Chromium
(headless shell v1243, installed via `npx playwright install chromium`).
Working directories: `apps/web` for frontend/E2E, `apps/api` for backend,
repository root for governance checks.

Runtime roles (provided by outer Orchestrator, not independently verified by
Builder): Planner model gpt6-astra; Builder session muse-spark-1.3-contributor-free.
The session found one uncommitted production change (Vite suppression removed);
Builder restored the committed baseline first, recorded blind-spot and startup
evidence, then re-implemented the fix inside this issue.

## TDD Evidence

### RED

Baseline blind-spot demonstration (original suite unmodified, then production mutated):

1. `npm test` in `apps/web` (original Jest suite): 3 passed. The suite replaced
   the production component with a copy via `jest.mock` and the Jest config used
   an unknown `setupFilesAfterSetup` option.
2. Temporary production mutation `<h1>Health Status</h1>` to
   `<h1>MUTATED Health Status</h1>` in `apps/web/src/components/HealthCheck.tsx`:
   original suite still 3 passed. This proves the blind spot (test never imports
   production). Mutation restored immediately after.
3. Standalone startup reproduction: `node test-server.js` in `apps/web` failed with
   `ReferenceError: __dirname is not defined in ES module scope` (package
   `type: module`). Recorded before the lifecycle replacement; the static server
   file has since been deleted and nothing references it.
4. Backend coverage addition is honestly labeled initially passing: the existing
   route already returned safe 503 and queried the database. Sensitivity was proven
   by mutation (see table), not by a missing implementation.

Frontend mutation-to-test table (repaired Vitest suite importing production;
each mutation applied singly, then restored):

| # | Temporary production mutation | Failing assertion (RED) | Result |
|---|---|---|---|
| M1 | `<h1>` to `MUTATED Health Status` (identical to blind-spot mutation) | success test: `getByRole('heading', { name: 'Health Status' })` | 1 failed / 5 passed |
| M2 | loading text to `MUTATED loading text` | pending test: loading text assertion | 1 failed / 5 passed |
| M3 | `catch` swallows the error (`setLoading(false)` only) | rejection test and non-2xx test: `getByRole('alert')` absent | 2 failed / 4 passed |
| M4 | removed `if (!res.ok) throw` guard | non-2xx test: no alert; error JSON misleadingly rendered as success | 1 failed / 5 passed |

Backend mutation-to-test table (`apps/api/routes/api.php`, each restored after):

| # | Temporary production mutation | Failing assertion (RED) | Result |
|---|---|---|---|
| M-B1 | 503 payload leaks `$e->getMessage()` | exact-503 test: `assertExactJson` shows extra `message` with synthetic marker | 5 passed / 1 failed |
| M-B2 | removed `DB::select('SELECT 1')` | query-observation test: `SELECT 1` not observed despite 200 `connected`; exact-503 test also fails (nothing throws) | 4 passed / 2 failed |

Setup-only issues (blockers, not RED): Vitest/Vite/Oxlint timeouts were caused by
stale `node_modules` artifacts and resolved by a clean `npm ci`; missing Chromium
browsers resolved by `npx playwright install chromium`. No supported-platform
Vitest blocker exists; no runner exception needed.

### GREEN

- `npm test -- --maxWorkers=1` in `apps/web` (Vitest 5, jsdom, production import,
  fetch stubbed only at the network boundary, env reset between tests): 1 file /
  6 passed in ~2-4s (deferred pending, 200 success with heading/status/database/
  timestamp, rejection error, non-2xx error, default relative URL
  `/api/v1/health`, origin override prefix). Temporary diagnostic smoke test
  deleted; obsolete `jest.config.js` and `test-server.js` deleted.
- `php artisan test --compact tests/Feature/HealthTest.php` in `apps/api`:
  6 passed, 19 assertions in ~0.4s (success fields/ISO timestamp, exact safe 503
  JSON `{"status":"error","database":"disconnected"}` under forced query exception
  with synthetic markers, real PostgreSQL `SELECT 1` observation with pgsql
  driver check).
- `php artisan test --compact` in `apps/api`: 8 passed, 21 assertions in ~0.5s
  (failure test runs before success coverage; no leaked mocks/listeners).
- `npm run test:e2e` in `apps/web` (~17s) from a prepared environment with no
  prestarted apps and no prebuilt `dist/` dependency: Playwright manages Laravel
  (`php artisan serve --host=127.0.0.1 --port=8000 --tries=0`, readiness
  `http://127.0.0.1:8000/api/v1/health`) and Vite
  (`--host 127.0.0.1 --port 5173 --strictPort`, readiness
  `http://127.0.0.1:5173`), both `reuseExistingServer: false`. 18 passed
  (6 scenarios x mobile 390x844, tablet 768x1024, desktop 1440x900): real-stack
  success (exact endpoint, HTTP 200, field/ISO-timestamp body, zero unexpected
  diagnostics), deterministic pending (held with empty diagnostics, then released
  and re-verified), controlled rejection (alert, loading cleared, every failed
  request exactly `http://127.0.0.1:5173/api/v1/health` with failure
  `net::ERR_FAILED`, every console error exactly that message with exactly that
  source URL), HTTP 503 (alert `HTTP 503`, exact body
  `{"status":"error","database":"disconnected"}`, exact status/endpoint, exact
  console source/message), plus an injection-regression scenario proving
  wrong-origin console errors, wrong failure codes, late wrong-status responses,
  and extra-field bodies are rejected. Settlement tracks in-flight requests by
  `Request` identity (not method+URL keys, which collapsed StrictMode
  duplicates): a gated-duplicate regression (first 503 fulfilled and finished,
  identical second request held behind an unreleased gate) failed 3/3 on the old
  helper (`pending.size` 0 while one request outstanding) and passes 3/3 after
  the identity fix, with the released second 500 observed. Diagnostics track
  every request from before navigation plus pending response-body parses; settlement (zero pending
  requests, drained parses, event-aware, no sleeps) precedes geometry and
  screenshots, and final diagnostic assertions run after screenshots. Readable
  content is verified by viewport bounding boxes, not only document overflow.
  12 labeled screenshots in
  `apps/web/test-results/screenshots/<viewport>-<state>.png`. Ports 5173/8000
  released after the run (verified via `ss -ltnp`).
- Port-collision check: two concurrent `npm run test:e2e` invocations; the loser
  failed fast at startup (`Error: Process from config.webServer was not able to
  start. Exit code: 1`, zero tests executed, no reuse or port move) while the
  winner completed 12 passed. Laravel strictness verified in framework source
  (`ServeCommand::canTryAnotherPort()` retries another port only when `--port`
  is unset; the managed command passes `--port=8000` explicitly and now also
  `--tries=0`); Vite uses `--strictPort`. Repeat invocation passes, confirming
  cleanup.
- `npm run build` in `apps/web`: `tsc -b` plus Vite production build succeed in
  <1s (no `@ts-ignore`; `vite/client` typing covers `import.meta.env`).
- `npm run lint` in `apps/web`: Oxlint clean.
- `vendor/bin/pint --dirty --format agent` in `apps/api` (required by
  `apps/api/AGENTS.md`): fixed only `tests/Feature/HealthTest.php` via the
  `fully_qualified_strict_types` fixer (`\Exception` to `Exception`; the file
  has no namespace declaration, so semantics are unchanged). Targeted rerun
  `php artisan test --compact tests/Feature/HealthTest.php`: 6 passed,
  19 assertions.
- `python -m unittest discover -s tests/governance -p 'test_*.py' -v` from root:
  83 tests OK in ~0.15s (latest verified run; includes six-heading project-state
  and SDD bundle gates).

### REFACTOR

- Re-applied the earlier production change properly inside this issue
  (`import.meta.env.VITE_API_BASE_URL` without suppression, typed by the existing
  `vite/client` entry); verified by clean `npm run build`.
- E2E diagnostics hardened after Tester REJECT (no app behavior change): exact
  endpoint equality via `new URL('/api/v1/health', page.url()).href`, exact
  failure code `net::ERR_FAILED`, exact console source URL plus exact message,
  exact 503 body/status/endpoint; request tracking from before navigation with
  pending body-parse draining for deterministic settlement (no sleeps);
  screenshots/geometry before final diagnostic assertions; injection-regression
  scenario rejecting wrong-origin, wrong-failure, late wrong-status, and
  extra-field diagnostics. Root/app READMEs document Ctrl+C shutdown of both
  manual dev terminals before managed E2E and that E2E owns/releases servers.
  No broad favicon/HMR exclusions. Tester REJECT retained below pending fresh
  independent review.
- `apps/web/.env.example` defaults to empty (same-origin proxy) with documented
  origin-override semantics; `apps/api/.env.example` no longer sets a conflicting
  `VITE_API_BASE_URL`; `vite.config.ts` uses explicit loopback host, strict
  port, and loopback proxy target; `playwright.config.ts` owns both servers with
  readiness, no reuse, and strict ports; `e2e.yml` runs `npm run test:e2e` with
  inherited DB settings and uploads screenshots (always) plus the HTML report
  (on failure); `frontend.yml` runs tests bounded, lint, and build;
  `apps/web/.gitignore` ignores `playwright-report/` and `test-results/`
  (screenshots stay on disk for Tester review).
- Root README replaced with a concise runnable foundation guide (~100 lines);
  `docs/project-state.md` compacted (~30 lines, exact six headings).
- Reran after refactor (final verification below): frontend 6 passed; backend 8
  passed; E2E 18 passed; build/lint clean; governance 83 passed.
- Verified no temporary mutation remains: production files contain no
  `MUTATED` markers; synthetic failure markers exist only inside the backend test.

## Tester Review

Decision: APPROVE

Final independent review on 2026-09-10 by native Tester, not the Builder context,
for `@carlosegoulart/19/fix/m1-foundation-stabilization`. Approval covers the actual
reviewed working-tree diff and all AC1-AC8, not future CI/merge/closure. Earlier
iterations rejected inexact diagnostics, missing independent runtime proof, and
then a reproduced duplicate-request settlement race. All are now resolved.
Builder-owned pending/rejection wording above is historical and superseded by
this final review; there is no outstanding Tester blocker.

### Narrow post-Pint approval refresh

Approval refreshed on 2026-09-10 for the current formatting-only change.
`git diff --no-index` against the retained approved API copy at
`/tmp/opencode/aiclip19-review/resumed/apps/api/tests/Feature/HealthTest.php`
shows exactly one replacement: `new \Exception` to `new Exception` at line 51.
The file declares no namespace or competing Exception import, so behavior is
unchanged. The final approved E2E copy also compares identically to the repository.
Inspected Builder's Pint record (`vendor/bin/pint --dirty --format agent`,
`fully_qualified_strict_types`) and verified installed Pint v1.32.0 with
`composer show laravel/pint`. Pint execution remains Builder-owned; Tester did
not rerun a write-capable formatter or claim an independent Pint execution.
Independent `php artisan test --compact tests/Feature/HealthTest.php` in
`apps/api`: 6 passed, 19 assertions, 330ms, exit 0. `git diff --check` passed.
No new concern or blocker; prior unaffected approval evidence stands. No broader
tests, implementation edits, or lifecycle operations were performed for this refresh.

### Final correction independently verified

The helper now tracks `Set<Request>` identity, preserving concurrent identical
requests. Inspected the actual helper, new genuinely gated repository regression,
and updated Builder evidence. A source comparison against the previously tested
disposable snapshot found only `apps/web/e2e/health.spec.ts` changed across API/web
source/config/docs; unaffected direct mutation and README results below stand.

Executed `python /tmp/opencode/aiclip19-review/final_review.py` (exit 0), using a
fresh physical web copy, no dist, shared installed dependencies and the previously
prepared example-only disposable API with real PostgreSQL. Independent Chromium
probe passed at all three viewports, reporting during the unreleased second gate:

```json
{"actualOutstanding":1,"trackedPending":1,"finished":false}
```

After release, settlement awaited the second HTTP 500 and its JSON parse; both
503/500 responses were captured, `assertExact503` rejected the actual late 500,
and outstanding requests/pending parses were zero. Exact induced console source
URLs/messages were asserted; no unexpected page/request/resource errors occurred.
This is real asynchronous interaction, not injected diagnostic arrays.

Reinstating collapsed method+URL tracking only in the disposable helper caused
3/3 intended failures (`held duplicate must remain tracked`, expected 1, got 0).
Restoring identity tracking returned 3/3 GREEN. The copied file was restored
byte-for-byte to the repository file. Ports 8000/5173 and fallback candidates
8001/5174 were free after success, induced failure, and restored GREEN.

Final artifacts: `/tmp/opencode/aiclip19-review/final/` contains
`snapshot-comparison.json`, `independent-gated-probe.log`,
`collapsed-helper-regression.log`, `restored-gated-probe.log`, `ports.log`, and
`no-dist-e2e.log`. The runner preserves the independent probe source. Earlier
race reproduction remains in `/tmp/opencode/aiclip19-review/resumed/async-duplicate.log`
as historical RED, not a current blocker.

### Independently verified mutations and restored GREEN

Executed `python /tmp/opencode/aiclip19-review/resume.py` (exit 0). It copied app
sources to `/tmp/opencode/aiclip19-review/resumed/apps/`, excluded secrets,
generated caches/storage and dist, used only example environments, and symlinked
installed dependencies. Laravel `APP_BASE_PATH` selected the disposable API rather
than the real path of the shared Composer loader. Frontend source/tests were
physical copies; failure DOM output contains the mutated heading/loading text,
proving Vite did not silently test the unmutated repository component.

| Mutation in disposable production source | Actual failure | Restoration |
| --- | --- | --- |
| Loading text changed | Pending text assertion; 1 failed / 5 passed | 6 passed |
| Heading changed to identical baseline `MUTATED Health Status` | Success heading assertion; 1 failed / 5 passed | 6 passed |
| Rejection handler swallows error | Missing alert; 2 failed / 4 passed | 6 passed |
| Non-2xx guard removed | Missing alert and misleading error JSON rendering; 1 failed / 5 passed | 6 passed |
| DB exception message disclosed | Exact-503 JSON rejects extra synthetic message; 1 failed / 5 passed | Full backend 8 passed |
| `SELECT 1` removed | Query-observation assertion and safe-failure status fail; 2 failed / 4 passed | Full backend 8 passed |

Commands were `npm test -- --maxWorkers=1` in copied web and
`php artisan test --compact tests/Feature/HealthTest.php` in copied API, with full
`php artisan test --compact` after each backend restoration. Logs are
`/tmp/opencode/aiclip19-review/resumed/{mutation,restored}-*.log`; inspected actual
assertions, not just exit codes. All temporary production mutations restored;
script verified original repository component/route remained unchanged. The
original pre-repair blind-spot chronology remains Builder-owned historical
evidence; this resumption directly proves repaired-suite mutation sensitivity.

### Lifecycle and README findings resolved

- Actual repository `composer install --no-interaction --prefer-dist --no-progress`
  verified lock/platform compatibility, installed dependencies and autoload setup.
  Copied `.env.example` files, ran key generation and migrations successfully in
  disposable API, using the available real local PostgreSQL (not SQLite/fakes).
  Freshness applies to app configuration/copy, not an empty database; migrations
  reported the prepared database current. No real environment secrets were copied.
- With no dist and no app listeners, copied `npm run test:e2e` passed 15/15;
  confirmed dist still absent and ports released. Restored repeat passed 15/15.
- Separately occupied 8000 and 5173 with controlled HTTP listeners. Each run
  exited 1 with its exact URL `is already used`, no tests/reuse/fallback. At exit
  only the controlled listener remained; ports 8001/5174 were also absent.
  Released only the owned fixture and verified all four ports free.
- An intentional copied heading assertion failure exited 1; both owned servers
  released ports, then restored E2E passed. Logs: `e2e-clean-no-dist.log`,
  `collision-8000.log`, `collision-5173.log`, `e2e-intentional-failure.log`,
  `e2e-restored.log`, and `ports.log` in the scratch results directory.
- Executed README manual API serve and `npm run dev` against the example setup.
  The relative proxy returned actual 200/ok/connected/timestamp JSON, retained as
  `manual-proxy.json`. Sent terminal-equivalent SIGINT to both owned process
  groups, waited for exits, and verified ports free (`after-manual-sigint`).
  Root/API/web READMEs now explicitly explain Ctrl+C shutdown before managed E2E.
  Proxy default/public-origin semantics remain consistent. Docker download is
  not a defect: API documentation permits the prepared local PostgreSQL used.

### Final suites, browser review, and scope

- Fresh actual-repository `npm ci`: 112 packages, zero audit vulnerabilities;
  `npm test`: 6 passed with no runner warnings; build/typecheck and lint passed.
  Vitest remains consistent across scripts/CI/docs, with no Jest runner/config.
- Actual `php artisan test --compact`: 8 passed / 21 assertions, including exact
  safe 503 and real pgsql `SELECT 1` observation. Dependency versions inspected
  earlier remain Laravel 13.31.0, Pest 4.7.8, PHPUnit 12.5.33.
- Final actual-repository `npm run test:e2e`: 18 passed (16.0s), including the
  new gated regression at all three viewports. Fresh no-dist copy: 18 passed
  (15.5s), with no dist created and no prestarted app listeners. Build/typecheck
  and lint were rerun after the final correction and passed without warnings.
- Governance command from root, `python -m unittest discover -s tests/governance
  -p 'test_*.py' -v`: 83 passed. `git diff --check` passed. Native role discovery
  was exercised in the initial review; read-only git/socket probes were repeated.
- Explicitly opened all 12 fresh final-copy PNGs under
  `/tmp/opencode/aiclip19-review/final/apps/web/test-results/screenshots/`,
  `{mobile,tablet,desktop}-{success,pending,error-rejection,error-503}.png`.
  At 390x844, 768x1024 and 1440x900, all content is readable, unclipped and free
  of horizontal overflow. Heading/status/database/timestamp hierarchy is clear;
  loading is stable; errors are visible without success content. Sparse layout
  and top spacing are acceptable for this preserved minimal slice. Semantic
  status/alert roles are present. Keyboard/focus, dialogs, destructive, disabled
  and empty-data controls are N/A because no interactive controls exist.
  Real browser scenarios exercised success, gated loading and induced failures;
  exact URL/message/status/failure/body correlation now passes. No unexpected
  baseline console/page/network/API errors reported. The final independent gated
  probe additionally verifies delayed diagnostics are collected and rejected.
- No unrelated feature work, authentication/media/social implementation, tracked
  governance workflow/test changes, or durable agent/permission changes found.
  Reviewed content is English. Existing untracked governance bytecode must remain
  unstaged. Only this Tester Review was edited in the repository; all probes and
  reversible mutations were confined to the authorized disposable QA tree.
  No delegation, production repair, permission modification, or lifecycle
  mutation performed. No outstanding acceptance criterion or scope finding.
  Orchestrator alone owns subsequent lifecycle operations and required CI checks.

Out of scope (untouched): authentication, registration, projects, media, Redis,
queues, AI, social integrations, dashboard, UI redesign, deployment, durable
agent/permission configuration, governance CI/gates. Generated/ignored files
(`dist/`, `playwright-report/`, `test-results/`, `__pycache__`) are left on disk
and excluded from staging by outer Orchestrator.

Interactive-element note: the foundation page exposes no buttons, links, dialogs,
or destructive controls, so keyboard/focus assertions are N/A with this reason;
semantic `status`/`alert` roles are asserted in unit and browser tests.

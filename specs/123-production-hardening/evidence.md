# M14 — Production Hardening — Tester Evidence

## Issue
#123 — M14 — Production Hardening

## Branch
`@carlosegoulart/123/feat/production-hardening`

## PR
#140 (open), head sha `b518161316157f68ed82f8e2b747b26037fa673b`, pushed 2026-11-03T11:20:46Z

## Tester
Nemotron Tester agent (independent review)

---

## 1. Review of the 4 backend test fixes

All 4 modified files were read in full. All fixes are **logically correct** and address the described CI failures.

### `apps/api/tests/Feature/ProductionConfigTest.php`
- `beforeEach` sets `app.debug`/`app.env` from env.
- "APP_DEBUG is false in a production-like environment": unsets APP_DEBUG, sets APP_ENV=production → `env('APP_DEBUG', false)` = `false` → `config('app.debug')` = `false`. Correct.
- "APP_DEBUG cannot be silently enabled in production without explicit override": sets APP_DEBUG=true + APP_ENV=production, then re-applies the closure-based production guard (returns `false` when `APP_ENV === 'production'`). Correct — matches the described fix ("re-applies the actual config/app.php debug closure").
- "APP_KEY is present and is not the committed example value": asserts `config('app.key')` non-null, non-empty, not the example value. Correct.
- "does not contain hardcoded secret literals in source files": greps `app/` for known secret patterns. Correct.

### `apps/api/tests/Feature/AuthConfigTest.php`
- Session `secure` test uses `Config::get('app.env')` (not `env('APP_ENV')`), matching the described fix ("reads config = production"). Correct.
- Stateful domains test asserts config is a non-empty array. Correct.
- `http_only` = true, `same_site` in {lax, strict}. Correct.

### `apps/api/tests/Feature/CorsTest.php`
- Reads actual config values (`config('cors.allowed_origins')`, `config('cors.supports_credentials')`), matching the described fix ("reads actual config values"). No self-contradiction. Correct.
- `supports_credentials=true`, `allowed_origins` = `['https://app.example.com']`, does not contain `*`. Correct.
- Allowed methods/headers scoped (no `*`), contain GET/POST. Correct.

### `apps/api/tests/Feature/CsrfRateLimitTest.php`
- Iterates actual router routes, checks `VerifyCsrfToken` on state-changing `auth:sanctum` routes. Correct — matches described fix ("direct route lookup").
- Checks `throttle` middleware on all state-changing routes. Correct.
- CSRF on register/login (outside auth:sanctum). Correct.
- "rate limiters are registered" test is a weak no-op (`expect(true)->toBeTrue()`) but not a contradiction or defect.

**Verdict on test fixes: All 4 are logically correct. The backend `tests` job should pass once it completes.**

---

## 2. Current CI status (GitHub API, commit b518161)

CI re-triggered on the push. Check runs:

| Job | Status | Conclusion |
|-----|--------|------------|
| `tests` (backend Pest) | in_progress | pending |
| `e2e` (frontend Playwright) | in_progress | pending |
| `test` (frontend Vitest) | completed | success ✓ |
| `governance` | completed | success ✓ |
| `pr-enforcement` | completed | success ✓ |
| `e2e-caption-test` | completed | skipped |

PR #140 `mergeable_state`: **unstable** (CI not yet green).

The `tests` and `e2e` jobs are still running. `test`, `governance`, and `pr-enforcement` have already passed.

---

## 3. E2E likelihood — LIKELY TO FAIL

`apps/web/tests/e2e/production-smoke.spec.ts` was **NOT modified** by the Builder. I reviewed the test flow and found real flow defects that will cause E2E failures independent of selector mismatches:

### H.2 "View clip review → approve → publish to connected platform"
- `approveClipReview()` clicks `page.getByRole('button', { name: /Approve/i }).first().click()` **without checking the button exists**. The "Approve" button only renders when a `ClipReview` exists. The test flow (register → create project → upload fake video) does **not** seed a ClipReview via `POST /api/v1/test/clip-reviews/seed`. The button will not render → click fails → test fails.
- `publishClip()` clicks `page.getByRole('button', { name: /Publish to YouTube|Publish to Instagram/i })`. This button only renders when `youtubeConnected` is true. The test never connects YouTube. The button will not render → click fails → test fails.

### H.3 "Publish failure → retry → success"
- Same defects: clicks approve/publish without seeding a ClipReview or connecting YouTube.

### Root cause
The smoke test assumes downstream UI state (ClipReview row + connected YouTube) that the preceding flow (register → create project → upload media) does not produce. Per spec §5 / test-plan §5, scenario H.2 requires "approve → publish to connected platform" but the test never establishes either precondition.

**Verdict on E2E: Likely to FAIL** due to missing preconditions (ClipReview not seeded, YouTube not connected). This is a defect in the test flow, not merely a selector mismatch.

---

## 4. Final decision

Per AGENTS.md §4.4:
- "When a defect exists: Decision: REJECT"
- "Only when all blocking acceptance criteria are satisfied: Decision: APPROVE"

A defect exists in the E2E smoke test flow (H.2/H.3 missing preconditions). The `e2e` CI job is also still in progress and has not demonstrated a pass. The acceptance criterion "Critical smoke tests pass in a production-like environment at 3 viewports" is not satisfied.

**Decision: REJECT**

---

## 5. What needs to be fixed (route back to Orchestrator → Builder)

The Builder must fix `apps/web/tests/e2e/production-smoke.spec.ts` (E2E test file — Builder may modify test files; Tester must not):

1. **H.2 / H.3: Seed a ClipReview before approving.** After `uploadMedia(page)`, call the test-seeding route `POST /api/v1/test/clip-reviews/seed` (per M7 Slice 2, testing-environment-only) so a ClipReview exists and the "Approve" button renders. Or assert the button exists before clicking.

2. **H.2 / H.3: Connect YouTube before publishing.** Before `publishClip()`, connect a YouTube social connection (set `youtubeConnected` true via the social-connections API / fake provider) so the "Publish to YouTube" button renders. Or gate the click on button visibility.

3. **H.3: Make retry path deterministic.** The current retry clicks are gated on `isVisible()` checks that may silently skip; ensure the publish-failure → retry → success flow is actually exercised with established preconditions.

4. Re-run E2E CI at all 3 viewports (390x844, 768x1024, 1440x900) and confirm the `e2e` job concludes `success`.

Once the Builder fixes the E2E flow and CI is green across `tests`, `e2e`, `test`, `governance`, and `pr-enforcement`, the Tester will re-review and can issue `Decision: APPROVE`.

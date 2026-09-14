# Evidence: Authenticated Project Management

## Final Status and Provenance

[Issue #30](https://github.com/CarlosEGoulart/AiClip/issues/30) is closed and
complete; [PR #31](https://github.com/CarlosEGoulart/AiClip/pull/31) is merged.

- Final PR head: `8bd43cee1bb08cacfa28baa2bdd3ec176fc2c0a1`.
- Merge commit: `364733af595016f0a0d40f7dac5f106601ee3063`.
- The original SDD bundle exists: `spec.md`, `plan.md`, `test-plan.md`, and
  `evidence.md` in this directory.

This is the final historical account, reconciled under Issue #32 using the
existing implementation, Orchestrator-verified official final CI results, and
the supplied final Tester conversation review. Removal of the unused request
stub is separate #32 maintenance, not an implementation change made by PR #31.

## Final Implementation Contract

### API and Security

Laravel Sanctum SPA session/cookie authentication and CSRF protection apply.
All project endpoints are inside `auth:sanctum`:

| Method | Endpoint | Operation |
| --- | --- | --- |
| GET | `/api/v1/projects` | List the authenticated user's projects |
| POST | `/api/v1/projects` | Create an owned project |
| GET | `/api/v1/projects/{project}` | Show an owned project |
| DELETE | `/api/v1/projects/{project}` | Delete an owned project |

There is no update/edit endpoint. Creation requires a string `name` of at
most 255 characters and accepts an optional nullable string `description` of
at most 1000 characters. Creation uses `throttle:project-create`.

List and create use `$request->user()->projects()`. Creation passes only
validated name and description to that relationship, deriving ownership from
the authenticated session rather than trusting client-supplied `user_id`.
Show and delete use an explicit ownership comparison in
`authorizeOwnership()`, returning 404 for non-owners. They are not described
as relationship-scoped queries.

`ProjectResource` exposes only `id`, `name`, `description`, `created_at`, and
`updated_at`; internal `user_id` is not exposed.

Sources: `apps/api/routes/api.php`,
`apps/api/app/Http/Controllers/Api/V1/ProjectController.php`,
`apps/api/app/Http/Requests/StoreProjectRequest.php`,
`apps/api/app/Http/Resources/ProjectResource.php`, and this issue's `spec.md`.

### Frontend and Viewport Coverage

The authenticated React shell integrates project list/create/delete. The
create form accepts an optional description, and cards display it when
present. Deletion requires inline confirmation. Existing components show
loading, empty, error/validation and pending states, disabling controls while
their operations are pending.

Source review shows a full-width, max-width-constrained flex-column project
section and list, flex cards, and truncated project names. Playwright is
configured for Chromium mobile **390×844**, tablet **768×1024**, and desktop
**1440×900**. The final CI log records project scenario passes at each viewport;
this is automated execution coverage, not independent visual validation.

Labels, selected ARIA attributes, status/alert roles and native controls are
source/code-review observations only, not full accessibility certification.

Sources: `apps/web/src/features/projects/components/{CreateProjectForm,ProjectCard,ProjectList}.tsx`,
`apps/web/src/features/auth/components/AuthenticatedShell.tsx`,
`apps/web/src/index.css`, and `apps/web/playwright.config.ts`.

## Final Tester Decision

**APPROVE — 2026-09-14**, from the supplied final conversation review
`ses_f5f8bd716ffe63j9hSzMie1TcH`. The decision was based on source/code review
and GitHub checks, including review of labels, ARIA and responsive code.
There was **no actual independent browser inspection**. No public review URL
or additional runtime review actions are established by that source.

This historical Tester decision is separate from the CI results below and
does not approve maintenance Issue #32.

## Final CI Results

**Backend CI PASS**, **Frontend CI PASS**, **E2E CI PASS**, and
**Governance PASS**: four workflows with five successful checks associated
with the final PR head above, as verified by Orchestrator from official job
logs and `gh pr view 31` check results.

The workflows checked out synthetic PR merge
`6e988c50d16b6f14e72bd47f2f6812a54dff43f0`, incorporating that final head.
These are not claimed as executions on the later merge commit
`364733af595016f0a0d40f7dac5f106601ee3063`.

| Workflow / check | Final result | Official source |
| --- | --- | --- |
| Backend CI / tests | PASS — 89 passed, 905 assertions across the suite; includes 14 passing project cases | [Run 34859619119 / job 104028067409](https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619119/job/104028067409) |
| Frontend CI / test | PASS — 100 tests across 6 files; project component/hook file 22 plus API file 6 = 28 project tests; lint 0 warnings / 0 errors; build passed | [Run 34859619142 / job 104028067767](https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619142/job/104028067767) |
| E2E CI / e2e | PASS — 69 executions passed (1.0m) across the suite; 6 project scenarios × 3 viewports = 18 passing project executions | [Run 34859619246 / job 104028067347](https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619246/job/104028067347) |
| governance / governance | PASS — 143 tests, OK | [Run 34859619288 / job 104028069051](https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619288/job/104028069051) |
| governance / pr-enforcement | SUCCESS, verified in final PR checks | [Run 34859619288 / job 104028069525](https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619288/job/104028069525) |

Numerical results are from Orchestrator-supplied verified official logs, not
counts inferred from test declarations. During reconciliation Builder also
inspected the supplied saved E2E and governance log excerpts. These results
are historical CI evidence, not tests newly executed by Builder under #32.

## Historical TDD and Verification Limits

Original assertion-based RED is **unverified** in the available evidence.
No historical failing command, assertion or exit status is reconstructed from
present-day source. Final passing CI establishes execution results, not the
original RED/GREEN/refactor chronology. #32 manual artifact RED/GREEN is
recorded separately and cannot substitute for #30 historical TDD evidence.

Independent project browser interaction, visual inspection, keyboard testing,
accessibility interaction and console/network inspection remain **unverified**
by the supplied final review. Source observations, E2E success and any
screenshot artifact existence do not establish those independent checks.
The maintenance reconciliation does not reopen or re-audit the feature to
fill these historical gaps.

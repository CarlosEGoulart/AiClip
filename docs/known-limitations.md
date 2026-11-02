# Known Limitations

## Production Readiness

- Real runtime transcription requires the `faster-whisper` optional dependency and model download. Not included in CI (deterministic fakes used instead).
- Real runtime scene detection requires `scenedetect[opencv-headless]` optional dependency. Not included in CI.
- Email verification not implemented. Users are considered authenticated after login without email confirmation.
- No project update/edit endpoint yet (out of scope for Issue #30).

## Observability

- Logs are sanitized via `SanitizeLogProcessor` to mask tokens, secrets, keys, and passwords. Exception messages are redacted for sensitive fields.
- Health/readiness endpoints do not expose internal system details (no stack traces, no dependency versions).
- No distributed tracing or metrics collection implemented yet.

## Queue Reliability

- All media jobs have `$timeout` and `uniqueId()` for idempotent dispatch.
- Failed jobs are visible via `php artisan queue:failed`.
- Failed jobs older than 7 days are auto-cleaned.
- No dead-letter queue or alerting on repeated failures yet.

## Security

- `APP_DEBUG` is forced false in production via closure-based guard in `config/app.php`.
- `LOG_LEVEL` defaults to `error` in production.
- CORS origins are env-driven; never `*` when credentials are involved.
- CSRF protection applied to all state-changing routes.
- Rate limiters applied to auth and destructive routes.
- Frontend applications never receive social provider refresh tokens.

## Deployment

- No blue/green or canary deployment strategy implemented yet.
- No automated rollback triggers implemented yet.
- Backup restore tests run weekly in staging; results retained for 90 days.

## E2E Smoke Test

- Production-like smoke test (`apps/web/tests/e2e/production-smoke.spec.ts`) covers critical user flows at 3 viewports (390x844, 768x1024, 1440x900).
- No real platform credentials used; deterministic fakes only.
- No network calls to external platforms.

## Agent/Governance

- No changes to agent permissions or governance made in this issue.
- All CI checks must pass before merge (backend, frontend, E2E, governance, pr-enforcement).
- Tester approval required before merge.

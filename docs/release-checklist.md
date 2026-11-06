# Release Checklist

## Pre-Release

### Code Quality
- [ ] All code follows Conventional Commits format
- [ ] No TODO, FIXME, or placeholder production code
- [ ] No disabled assertions or skipped required tests
- [ ] No hardcoded fake success values
- [ ] All test doubles confined to test/provider boundaries

### Testing
- [ ] Backend CI passes (Pest/PHPUnit suite)
- [ ] Frontend CI passes (Vitest suite)
- [ ] E2E CI passes (Playwright at 390x844, 768x1024, 1440x900)
- [ ] Governance CI passes
- [ ] PR-enforcement CI passes
- [ ] Tester approved via `specs/123-production-hardening/evidence.md`

### Security
- [ ] `.env.example` contains no real secrets
- [ ] `APP_DEBUG=false` in production config
- [ ] `LOG_LEVEL=error` in production config
- [ ] Secrets externalized via environment variables only
- [ ] CORS origins env-driven, never `*` with credentials
- [ ] CSRF protection on all state-changing routes
- [ ] Rate limiters on auth and destructive routes
- [ ] Log sanitization processor active (`SanitizeLogProcessor`)
- [ ] No tokens/secrets returned to clients
- [ ] Frontend never receives refresh tokens

### Reliability
- [ ] All queue jobs have `$timeout` and `uniqueId()`
- [ ] Failed jobs visible via `php artisan queue:failed`
- [ ] Health endpoint (`GET /api/v1/health`) returns 200
- [ ] Readiness endpoint (`GET /api/v1/readiness`) returns all checks passing
- [ ] Migration files have reversible `down()` methods
- [ ] Queue worker failure handling tested

### Documentation
- [ ] `docs/production-hardening.md` updated
- [ ] `docs/runbooks/deployment.md` updated
- [ ] `docs/runbooks/rollback.md` updated
- [ ] `docs/runbooks/incident-response.md` updated
- [ ] `docs/runbooks/backup-restore.md` updated
- [ ] `docs/known-limitations.md` updated
- [ ] `docs/release-checklist.md` updated
- [ ] `docs/project-state.md` updated

### Release Execution
- [ ] PR created referencing issue #123 (`Closes #123`)
- [ ] PR description includes TDD Evidence (RED/GREEN/REFACTOR)
- [ ] PR description includes Tests, API Review, Visual Review, Risks, CI, Scope Verification
- [ ] Merge gate check passes: `python scripts/merge_gate.py <PR_NUMBER> --check`
- [ ] Merge executed: `python scripts/merge_gate.py <PR_NUMBER>`
- [ ] PR confirmed merged on GitHub
- [ ] Issue #123 confirmed closed
- [ ] `docs/project-state.md` updated to reflect completion
- [ ] Return to NO_ACTIVE_ISSUE; await explicit authorization for next issue

## Release Blockers / Accepted Risks

Document any findings that could not be fixed before release:

| Finding | Severity | Decision | Owner | Rationale |
|---------|----------|----------|-------|-----------|
| (none yet) | | | | |

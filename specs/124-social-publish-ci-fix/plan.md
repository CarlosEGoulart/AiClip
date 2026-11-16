# Implementation Plan — Social Publish CI Fix (Issue #124)

## Branch and Commits

Branch: `@carlosegoulart/124/fix/social-publish-ci-fix` (already created, based on master)

Planned commits (Conventional Commits, Refs #124):

1. `fix(social-publish): encrypt tokens in SocialConnectionFactory::connected()`
   - Sets encrypted access_token/refresh_token in connected() state
   Refs #124

2. `test(social-publish): verify encrypted tokens yield published status`
   - Confirms the 13 failing tests now pass
   Refs #124

## Phases

### Phase 1 — RED (TDD)

- Run the 13 failing tests in `Tests\Feature\SocialPublish\` to confirm they
  fail with `failed` status instead of `published`.
- Capture the failure evidence (which tests fail, error message).

### Phase 2 — GREEN (implementation)

- Modify `SocialConnectionFactory::connected()` to set `access_token` and
  `refresh_token` as encrypted values using `encrypt(...)`, consistent with
  `definition()`.
- Run the failing tests to confirm they now pass (status `published`).
- Run the full backend suite to confirm no regressions.

### Phase 3 — REFACTOR

- Review the factory change for consistency with `definition()` and
  `expired()`/`revoked()` states.
- Ensure no plain-text token values remain.
- Run tests again to confirm still green.

## Files to Modify

- `apps/api/database/factories/SocialConnectionFactory.php`
  - `connected()`: replace plain-text `access_token`/`refresh_token` with
    `encrypt(...)`-wrapped values.

## Verification

- `php artisan test --compact` — backend suite green.
- 13 previously-failing SocialPublish tests pass.
- Governance checks pass.

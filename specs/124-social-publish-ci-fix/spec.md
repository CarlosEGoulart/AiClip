# Social Publish CI Fix — Publish jobs reach 'failed' instead of 'published'

## Overview

Social publish jobs (`PublishToYouTube`, `PublishToInstagram`, `PublishToTikTok`)
are ending with `SocialPublishStatus::Failed` instead of `SocialPublishStatus::Published`
in the CI environment. Thirteen tests under `Tests\Feature\SocialPublish\` fail.

The publish flow depends on `SocialConnectionManager::getValidAccessToken()` to
return a valid access token. When the token is null, every publish job marks the
record as failed with `No valid access token available`.

## Root Cause

`SocialConnectionFactory::connected()` sets `access_token` and `refresh_token`
to plain-text values (`fake_access_token`, `fake_refresh_token`). The
`SocialConnection` model casts both fields as `encrypted`, so reading them in
the testing environment attempts `decrypt()` on a non-encrypted string. This
causes `getValidAccessToken()` to fail to return a usable token, so all three
publish jobs transition to `Failed`.

The default `definition()` already encrypts tokens via `encrypt(...)`, but
`connected()` overrides them with plain text, breaking the encrypted cast.

## Acceptance Criteria

- [ ] `SocialConnectionFactory::connected()` sets encrypted `access_token` and
      `refresh_token` values (matching `definition()` encryption).
- [ ] All 13 failing tests in `Tests\Feature\SocialPublish\` pass.
- [ ] YouTube publish job reaches `published` status for a connected connection.
- [ ] Instagram publish job reaches `published` status for a connected connection.
- [ ] TikTok publish job reaches `published` status for a connected connection.
- [ ] Expired token is refreshed and publish proceeds to `published`.
- [ ] Null token results in `failed` status with sanitized error message
      (no token values leaked).
- [ ] No plain-text token values appear in test responses, logs, or error
      messages.
- [ ] Backend test suite passes (`php artisan test --compact`).
- [ ] Governance checks pass.
- [ ] PR governance checks pass (PR body sections, evidence.md).

## Files to Modify

- `apps/api/database/factories/SocialConnectionFactory.php`
  - Fix `connected()` to set encrypted `access_token`/`refresh_token`.

## Out of Scope

- Modifying `SocialConnectionManager::getValidAccessToken()` logic.
- Modifying publish jobs (`PublishToYouTube`, `PublishToInstagram`,
  `PublishToTikTok`).
- Adding new platforms or publishers.
- Frontend or E2E changes.
- Production token encryption key rotation.

## Security Considerations

- Never log or return `access_token` or `refresh_token` values.
- Error messages must not contain token values or fragments.
- Test fixtures must use encrypted fake tokens, never plain-text secrets.
- `SocialConnection` model hides token fields via `$hidden`.

## Test Scenarios

- YouTube: connected connection → publish reaches `published`.
- Instagram: connected connection → publish reaches `published`.
- TikTok: connected connection → publish reaches `published`.
- Expired token: connection with expired access token + valid refresh token →
  token refreshed → publish reaches `published`.
- Null token: connection without usable token → publish reaches `failed` with
  `No valid access token available`, no token leaked in error message.

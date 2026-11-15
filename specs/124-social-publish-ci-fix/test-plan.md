# Test Plan — Social Publish CI Fix (Issue #142)

## Test Scenarios

### YouTube

1. **YouTube publish reaches published**
   - Given: a connected YouTube social connection with encrypted tokens.
   - When: a publish job runs for an approved clip with completed render.
   - Then: `SocialPublish` status is `published`, `external_video_id` and
     `video_url` are set.

2. **YouTube publish status reflects published after job**
   - Given: connected YouTube connection, pending publish row.
   - When: `PublishToYouTube::handle()` runs inline.
   - Then: refresh shows `SocialPublishStatus::Published`.

### Instagram

3. **Instagram publish reaches published**
   - Given: a connected Instagram social connection with encrypted tokens.
   - When: a publish job runs for an approved clip with completed render.
   - Then: `SocialPublish` status is `published`.

4. **Instagram publish status reflects published after job**
   - Given: connected Instagram connection, pending publish row.
   - When: `PublishToInstagram::handle()` runs inline.
   - Then: refresh shows `SocialPublishStatus::Published`.

### TikTok

5. **TikTok publish reaches published**
   - Given: a connected TikTok social connection with encrypted tokens.
   - When: a publish job runs for an approved clip with completed render.
   - Then: `SocialPublish` status is `published`.

### Expired Token Refresh

6. **Expired token is refreshed and publish proceeds**
   - Given: a connected connection with expired `access_token_expires_at` and a
     valid `refresh_token`.
   - When: the publish job runs.
   - Then: `getValidAccessToken()` refreshes the token via the fake provider,
     and status reaches `published`.

### Null Token Failure

7. **Null token results in failed with sanitized error**
   - Given: a connection without a usable token (manager mocked to return null).
   - When: the publish job runs.
   - Then: status is `failed`, `error_message` is `No valid access token
     available`, and the message contains no `access_token` or `refresh_token`
     values.

## Test Acceptance Criteria

- [ ] All 13 previously-failing `Tests\Feature\SocialPublish\` tests pass.
- [ ] YouTube, Instagram, and TikTok publish paths reach `published`.
- [ ] Expired-token refresh scenario reaches `published`.
- [ ] Null-token scenario reaches `failed` with sanitized error message.
- [ ] No token values leak into responses, logs, or error messages.
- [ ] Full backend suite (`php artisan test --compact`) passes with no
  regressions.
- [ ] Governance checks pass.

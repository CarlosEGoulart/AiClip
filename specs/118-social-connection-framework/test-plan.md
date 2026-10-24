# M9 — Social Connection Framework Test Plan

## Overview

This test plan defines all test scenarios required to verify the Social Connection Framework implementation. Tests are organized by layer (unit, feature, integration, E2E) and mapped to acceptance criteria.

---

## Test Coverage Matrix

| Acceptance Criterion | Unit Tests | Feature Tests | Integration Tests | E2E Tests |
|---------------------|------------|---------------|-------------------|-----------|
| Platform-neutral contract documented | ✓ | | | |
| OAuth state validated, no replay | ✓ | ✓ | ✓ | |
| Tokens encrypted at rest, never in browser/logs | ✓ | ✓ | | ✓ |
| User ownership enforced | | ✓ | ✓ | ✓ |
| Connect, callback failure, disconnect, expired, unauthorized tested | | ✓ | ✓ | ✓ |
| UI states keyboard accessible, visible focus | | | | ✓ |
| Backend/frontend tests, lint, build, governance, E2E pass | ✓ | ✓ | ✓ | ✓ |

---

## 1. Unit Tests (Backend)

### 1.1 SocialConnection Model Tests
**File**: `tests/Unit/Models/SocialConnectionTest.php`

| Test Case | Description |
|-----------|-------------|
| `it_has_correct_fillable_attributes` | Verify fillable includes all fields except tokens/state |
| `it_hides_sensitive_attributes` | Verify `access_token`, `refresh_token`, `pending_oauth_state` are hidden |
| `it_casts_tokens_as_encrypted` | Verify encrypted cast on token fields |
| `it_casts_datetime_fields` | Verify datetime casts on expires_at fields |
| `it_has_connected_scope` | Scope filters to status=connected |
| `it_has_expired_scope` | Scope filters to status=expired |
| `it_has_revoked_scope` | Scope filters to status=revoked |
| `it_has_active_scope` | Scope filters to connected+expired |
| `it_belongs_to_user` | Relationship returns User instance |
| `it_enforces_unique_user_platform_provider` | Unique constraint on [user_id, platform, provider_user_id] |
| `it_returns_true_for_is_token_expired_when_past` | Accessor returns true when expires_at in past |
| `it_returns_false_for_is_token_expired_when_future` | Accessor returns false when expires_at in future |
| `it_returns_true_for_needs_refresh_when_close_to_expiry` | Accessor returns true when < 10 min remaining |
| `it_returns_false_for_needs_refresh_when_not_connected` | Accessor returns false for non-connected status |

### 1.2 SocialConnectionStatus Enum Tests
**File**: `tests/Unit/Enums/SocialConnectionStatusTest.php`

| Test Case | Description |
|-----------|-------------|
| `it_defines_connected_status` | Enum case exists with value 'connected' |
| `it_defines_expired_status` | Enum case exists with value 'expired' |
| `it_defines_revoked_status` | Enum case exists with value 'revoked' |
| `it_defines_error_status` | Enum case exists with value 'error' |

### 1.3 SocialConnectionProvider Contract Tests
**File**: `tests/Unit/Contracts/SocialConnectionProviderTest.php`

| Test Case | Description |
|-----------|-------------|
| `it_defines_required_interface_methods` | All 7 methods declared |
| `token_response_has_required_properties` | TokenResponse has accessToken, refreshToken, expiresIn, scopes |
| `provider_user_profile_has_required_properties` | ProviderUserProfile has providerUserId, displayName, avatarUrl, channelUrl |

### 1.4 Fake Provider Tests
**File**: `tests/Unit/Services/FakeSocialProvidersTest.php`

| Test Case | Description |
|-----------|-------------|
| `fake_youtube_provider_implements_interface` | Implements all methods |
| `fake_instagram_provider_implements_interface` | Implements all methods |
| `fake_tiktok_provider_implements_interface` | Implements all methods |
| `fake_provider_get_authorization_url_includes_state` | URL contains state parameter |
| `fake_provider_exchange_code_returns_token_response` | Returns valid TokenResponse |
| `fake_provider_refresh_access_token_returns_token_response` | Returns valid TokenResponse |
| `fake_provider_validate_token_returns_boolean` | Returns true/false |
| `fake_provider_revoke_token_does_not_throw` | No exception thrown |
| `fake_provider_get_user_profile_returns_profile` | Returns valid ProviderUserProfile |

### 1.5 SocialConnectionManager Tests
**File**: `tests/Unit/Services/SocialConnectionManagerTest.php`

| Test Case | Description |
|-----------|-------------|
| `initiate_connection_creates_pending_row` | Creates connection with pending state, returns auth URL |
| `initiate_connection_generates_unique_state` | State is 40-char random string |
| `initiate_connection_sets_state_ttl` | State expires in 10 minutes |
| `handle_callback_validates_state` | Fails if state missing/expired/mismatched |
| `handle_callback_consumes_state` | State nulled after successful validation |
| `handle_callback_exchanges_code_for_tokens` | Calls provider exchangeCodeForTokens |
| `handle_callback_fetches_user_profile` | Calls provider getUserProfile |
| `handle_callback_persists_connection_data` | Updates connection with profile, tokens, status |
| `handle_callback_logs_audit_without_tokens` | Log contains user_id, platform, provider_user_id, no tokens |
| `handle_callback_prevents_replay` | Second call with same state fails |
| `refresh_connection_returns_false_when_no_refresh_token` | Early return false |
| `refresh_connection_calls_provider_refresh` | Calls provider refreshAccessToken |
| `refresh_connection_updates_tokens_on_success` | Updates access_token, refresh_token, expires_at, status |
| `refresh_connection_logs_audit_on_success` | Log contains connection_id, no tokens |
| `refresh_connection_marks_revoked_on_failure` | Status=revoked, tokens cleared, error logged |
| `disconnect_calls_provider_revoke` | Calls provider revokeToken with access_token |
| `disconnect_clears_local_tokens` | Tokens nulled, status=revoked |
| `disconnect_logs_audit` | Log contains connection_id |
| `get_valid_access_token_returns_null_when_not_connected` | Returns null for expired/revoked/error |
| `get_valid_access_token_refreshes_when_needed` | Calls refreshConnection if needs_refresh |
| `get_valid_access_token_returns_token_when_valid` | Returns decrypted access_token |
| `validate_connection_returns_false_when_not_connected` | Early return false |
| `validate_connection_returns_false_when_token_invalid` | Provider validateToken returns false |
| `validate_connection_marks_expired_on_validation_failure` | Status=expired, error set |
| `validate_connection_returns_true_when_valid` | Returns true for valid connection |

---

## 2. Feature Tests (Backend API)

### 2.1 SocialConnectionResource Tests
**File**: `tests/Feature/Social/SocialConnectionResourceTest.php`

| Test Case | Description |
|-----------|-------------|
| `resource_includes_all_public_fields` | id, platform, platform_label, display_name, avatar_url, channel_url, status, last_error, timestamps |
| `resource_excludes_tokens_and_state` | No access_token, refresh_token, pending_oauth_state in output |
| `resource_maps_platform_labels` | youtube→YouTube, instagram→Instagram, tiktok→TikTok |
| `collection_wraps_in_pagination_envelope` | Standard Laravel pagination structure |

### 2.2 Request Validation Tests
**File**: `tests/Feature/Social/SocialConnectionRequestTest.php`

| Test Case | Description |
|-----------|-------------|
| `connect_request_validates_platform` | Rejects invalid platform (422) |
| `connect_request_accepts_valid_platforms` | Accepts youtube, instagram, tiktok |
| `disconnect_request_authorizes_ownership` | 404 for non-owned connection |

### 2.3 SocialConnectionController Tests
**File**: `tests/Feature/Social/SocialConnectionControllerTest.php`

#### 2.3.1 Authentication & Authorization
| Test Case | Description |
|-----------|-------------|
| `index_returns_401_for_guest` | Unauthenticated → 401 |
| `connect_returns_401_for_guest` | Unauthenticated → 401 |
| `callback_returns_401_for_guest` | Not applicable (public OAuth redirect) |
| `disconnect_returns_401_for_guest` | Unauthenticated → 401 |
| `status_returns_401_for_guest` | Unauthenticated → 401 |
| `index_returns_only_own_connections` | Other user's connections not in response |
| `connect_returns_404_for_other_user_connection` | Not applicable (creates new) |
| `disconnect_returns_404_for_other_user_connection` | 404 (hides existence) |
| `status_returns_404_for_other_user_connection` | 404 (hides existence) |

#### 2.3.2 GET /api/v1/social/connections (List)
| Test Case | Description |
|-----------|-------------|
| `index_returns_empty_array_when_no_connections` | 200, data: [] |
| `index_returns_user_connections` | 200, data with connection objects |
| `index_paginates_results` | Pagination envelope when > 15 |
| `index_respects_rate_limit` | 60/min → 429 on excess |

#### 2.3.3 POST /api/v1/social/{platform}/connect (Initiate)
| Test Case | Description |
|-----------|-------------|
| `connect_returns_422_for_invalid_platform` | Platform not in [youtube, instagram, tiktok] |
| `connect_returns_409_when_connection_exists` | Active connection for same platform/user |
| `connect_returns_authorization_url_and_state` | 200 with authorization_url, state, connection_id |
| `connect_creates_pending_connection_row` | Row exists with pending_oauth_state |
| `connect_respects_rate_limit` | 20/min → 429 on excess |
| `connect_requires_csrf_token` | 419 without valid X-XSRF-TOKEN |

#### 2.3.4 GET /api/v1/social/{platform}/callback (OAuth Callback)
| Test Case | Description |
|-----------|-------------|
| `callback_returns_302_redirect` | Always redirects (never JSON) |
| `callback_validates_state_parameter` | Missing state → error redirect |
| `callback_rejects_expired_state` | State older than 10 min → error redirect |
| `callback_rejects_mismatched_state` | State doesn't match pending → error redirect |
| `callback_rejects_missing_code` | No code param → error redirect |
| `callback_exchanges_code_on_valid_state` | Calls provider exchangeCodeForTokens |
| `callback_redirects_to_frontend_on_success` | Redirect to /connections?connected={platform} |
| `callback_redirects_to_frontend_on_failure` | Redirect to /connections?error=... |
| `callback_consumes_state_prevents_replay` | State nulled after first use |
| `callback_logs_audit_on_success` | Log contains user_id, platform, provider_user_id |
| `callback_logs_error_on_failure` | Log contains reason, no tokens |

#### 2.3.5 DELETE /api/v1/social/connections/{connection} (Disconnect)
| Test Case | Description |
|-----------|-------------|
| `disconnect_returns_204_on_success` | No content |
| `disconnect_returns_404_for_nonexistent` | Connection not found |
| `disconnect_returns_404_for_other_user` | Hides existence |
| `disconnect_calls_manager_disconnect` | Manager disconnect invoked |
| `disconnect_respects_rate_limit` | 30/min → 429 on excess |
| `disconnect_requires_csrf_token` | 419 without valid X-XSRF-TOKEN |

#### 2.3.6 GET /api/v1/social/connections/{connection}/status (Optional)
| Test Case | Description |
|-----------|-------------|
| `status_returns_connection_status` | 200 with status, last_error, expires_at |
| `status_returns_404_for_other_user` | Hides existence |

---

## 3. Integration Tests (Backend)

### 3.1 Full Connection Flow Tests
**File**: `tests/Feature/Social/SocialConnectionIntegrationTest.php`

| Test Case | Description |
|-----------|-------------|
| `full_connect_callback_list_disconnect_flow` | Connect → callback → list shows connected → disconnect → list empty |
| `multiple_platforms_can_be_connected` | Connect YouTube and Instagram, both appear in list |
| `duplicate_platform_connection_prevented` | Second connect for same platform returns 409 |
| `expired_token_can_be_refreshed` | Set expires_at past, call refresh, status=connected |
| `refresh_failure_marks_revoked` | Provider throws, status=revoked, tokens cleared |
| `revoked_connection_shows_in_list` | Status=revoked, display_name preserved |
| `concurrent_connect_attempts_handled` | Two simultaneous connects → one succeeds, one 409 |
| `callback_with_invalid_provider_response_handled` | Provider throws → error redirect, connection cleaned up |

### 3.2 Security Integration Tests
| Test Case | Description |
|-----------|-------------|
| `tokens_never_in_api_response` | List/show responses lack token fields |
| `tokens_never_in_logs` | Log output lacks access_token, refresh_token, code, state |
| `state_single_use_enforced` | Reused state rejected |
| `state_ttl_enforced` | Expired state rejected |
| `ownership_enforced_on_all_endpoints` | 404 for cross-user access |

---

## 4. Frontend Unit Tests (Vitest + React Testing Library)

### 4.1 API Client Tests
**File**: `apps/web/src/features/social/api/index.test.ts`

| Test Case | Description |
|-----------|-------------|
| `getConnections_returns_connections_array` | Maps response to typed array |
| `getConnections_handles_network_error` | Throws classified network error |
| `getConnections_handles_unauthorized` | Throws classified unauthorized error |
| `getConnections_handles_throttle` | Throws classified throttle error |
| `connectPlatform_returns_authorization_data` | Returns { authorizationUrl, state, connectionId } |
| `connectPlatform_includes_csrf_token` | Adds X-XSRF-TOKEN header |
| `connectPlatform_handles_validation_error` | Throws validation error with fields |
| `disconnectConnection_returns_void_on_success` | 204 → undefined |
| `disconnectConnection_handles_not_found` | Throws classified error |
| `disconnectConnection_includes_csrf_token` | Adds X-XSRF-TOKEN header |

### 4.2 Hook Tests
**File**: `apps/web/src/features/social/hooks.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `useSocialConnections_returns_loading_initially` | loading=true, connections=[] |
| `useSocialConnections_returns_connections_on_success` | loading=false, connections populated |
| `useSocialConnections_returns_error_on_failure` | loading=false, error set |
| `useConnectPlatform_returns_pending_during_mutation` | isPending=true during call |
| `useConnectPlatform_returns_data_on_success` | Returns authorization data |
| `useConnectPlatform_returns_error_on_failure` | error set with classified message |
| `useDisconnectConnection_returns_pending_during_mutation` | isPending=true during call |
| `useDisconnectConnection_invalidates_queries_on_success` | Query cache invalidated |

### 4.3 SocialConnectionCard Component Tests
**File**: `apps/web/src/features/social/components/SocialConnectionCard.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_connected_state_with_green_badge` | Status badge: "Connected" + green |
| `renders_expired_state_with_yellow_badge` | Status badge: "Expired" + yellow, shows Reconnect |
| `renders_revoked_state_with_gray_badge` | Status badge: "Revoked" + gray, shows Connect Again |
| `renders_error_state_with_red_badge` | Status badge: "Error" + red, shows error message + Retry |
| `disconnect_button_shows_confirmation_dialog` | Dialog opens on click |
| `disconnect_button_calls_onDisconnect_on_confirm` | Callback invoked after confirm |
| `reconnect_button_calls_onReconnect` | Callback invoked |
| `retry_button_calls_onRetry` | Callback invoked |
| `keyboard_navigation_works` | Tab to buttons, Enter/Space activates |
| `focus_indicators_visible` | 3px outline on focus |
| `aria_labels_on_icon_buttons` | Disconnect/Reconnect/Retry have aria-label |
| `status_badge_uses_text_not_color_only` | Badge text present |

### 4.4 SocialConnectionList Component Tests
**File**: `apps/web/src/features/social/components/SocialConnectionList.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_loading_skeletons` | 3 skeleton cards when loading |
| `renders_empty_state` | "No connections yet" + CTA when empty |
| `renders_list_of_cards` | Maps connections to SocialConnectionCard |
| `passes_correct_callbacks_to_cards` | onDisconnect, onReconnect, onRetry propagated |

### 4.5 ConnectPlatformModal Component Tests
**File**: `apps/web/src/features/social/components/ConnectPlatformModal.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_platform_grid` | YouTube, Instagram, TikTok cards |
| `shows_loading_during_initiation` | Spinner + "Connecting..." |
| `shows_error_on_initiation_failure` | Error message + dismiss |
| `clicking_platform_calls_onConnect` | Callback with platform name |
| `keyboard_navigation_between_platforms` | Arrow keys navigate grid |
| `escape_closes_modal` | Escape key calls onClose |
| `focus_trapped_in_modal` | Tab cycles within modal |

### 4.6 SocialConnections Page Component Tests
**File**: `apps/web/src/features/social/components/SocialConnections.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `loads_connections_on_mount` | Calls useSocialConnections |
| `opens_modal_on_connect_click` | Modal visible after "Connect Account" |
| `handles_connect_success_redirect` | URL ?connected= → toast + refetch |
| `handles_connect_error_redirect` | URL ?error= → toast |
| `handles_disconnect_success` | Removes from list, shows toast |
| `handles_reconnect_success` | Updates card to connected state |

---

## 5. E2E Tests (Playwright)

### 5.1 Test Configuration
**File**: `apps/web/tests/e2e/social-connections.spec.ts`

**Viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Test User**: Pre-authenticated via test setup

### 5.2 Test Scenarios

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| `Social Connections - Empty State` | Visit page, verify empty state illustration + CTA | All 3 |
| `Social Connections - Loading State` | Intercept API delay, verify 3 skeleton cards | All 3 |
| `Social Connections - Connect Flow` | Click Connect → select platform → mock OAuth redirect → verify connection appears | All 3 |
| `Social Connections - Disconnect Flow` | With existing connection → click disconnect → confirm → verify removed | All 3 |
| `Social Connections - Expired State` | With expired connection → verify yellow badge + Reconnect button | All 3 |
| `Social Connections - Reconnect Flow` | Click Reconnect on expired → mock OAuth → verify status=connected | All 3 |
| `Social Connections - Revoked State` | With revoked connection → verify gray badge + Connect Again | All 3 |
| `Social Connections - Error State` | With error connection → verify red badge + error message + Retry | All 3 |
| `Social Connections - Keyboard Navigation` | Tab through all interactive elements, verify focus order | All 3 |
| `Social Connections - Focus Indicators` | Verify 3px outline on all focused elements | All 3 |
| `Social Connections - ARIA Labels` | Verify icon buttons have aria-label | All 3 |
| `Social Connections - Live Region Announcements` | Verify status changes announced | All 3 |
| `Social Connections - Console Cleanliness` | No unexpected console errors | All 3 |
| `Social Connections - Network Cleanliness` | No 4xx/5xx except expected (mock OAuth) | All 3 |
| `Social Connections - Responsive Layout` | Single column < 1024px, side-by-side ≥ 1024px | All 3 |
| `Social Connections - Touch Targets` | All interactive elements ≥ 44px | All 3 |

### 5.3 OAuth Redirect Mocking Strategy

Since real OAuth providers aren't available in CI:

1. **Mock the callback endpoint** to simulate provider redirect
2. **Test the frontend redirect handling** by directly navigating to `/connections?connected=youtube` or `/connections?error=invalid_state`
3. **Verify UI state transitions** based on URL parameters
4. **Backend callback tested separately** in feature/integration tests with fake providers

---

## 6. Governance & Quality Gates

### 6.1 Backend Lint (Pint)
```bash
vendor/bin/pint --dirty --format agent
```
- Must pass with 0 errors

### 6.2 Frontend Lint (ESLint)
```bash
npm run lint
```
- Must pass with 0 errors

### 6.3 TypeScript Build
```bash
npm run build
```
- Must compile without errors

### 6.4 Governance Tests
```bash
php artisan test --filter=Governance
```
- All 173+ governance tests must pass

### 6.5 Full Test Suite
```bash
# Backend
php artisan test --compact

# Frontend Unit
npm run test

# Frontend E2E
npm run test:e2e
```
- All tests must pass

---

## 7. Manual Verification Checklist

Perform after automated tests pass:

| Check | Verification Method |
|-------|---------------------|
| Connect YouTube (fake) → callback → appears in list | Browser: click Connect → YouTube → mock callback → verify list |
| Connect Instagram (fake) → callback → appears in list | Same as above for Instagram |
| Connect TikTok (fake) → callback → appears in list | Same as above for TikTok |
| Disconnect → removed from list | Click disconnect → confirm → verify removed |
| Expired connection shows "Reconnect" → works | Manually set expires_at past → refresh page → click Reconnect |
| Revoked connection shows "Connect again" → works | Manually set status=revoked → click Connect Again |
| Error state shows retry → works | Manually set status=error → click Retry |
| Keyboard navigation works throughout | Tab through entire page, all actions reachable |
| Focus indicators visible | Tab to each element, verify 3px outline |
| No tokens in browser dev tools / network | DevTools Network tab, inspect all responses |
| No tokens in Laravel logs | `tail -f storage/logs/laravel.log` during operations |
| Rate limiting enforced | Exceed limits, verify 429 responses |
| CSRF protection works | Submit without token, verify 419 |

---

## 8. Test Data Requirements

### 8.1 Fake Provider Behaviors
- `FakeYouTubeProvider`: Simulates Google OAuth2 flow
- `FakeInstagramProvider`: Simulates Instagram Basic Display / Graph API
- `FakeTikTokProvider`: Simulates TikTok Login Kit

All fakes must:
- Be deterministic (no randomness)
- Not make network calls
- Implement full `SocialConnectionProvider` interface
- Allow configuring success/failure scenarios for tests

### 8.2 Database Seeding
- `SocialConnectionFactory` for creating test connections in various states
- Factories for User, Project (existing)

---

## 9. Evidence Collection

Upon completion, update `specs/118-social-connection-framework/evidence.md` with:

1. **Test Results Summary**
   - Backend test count/pass/fail
   - Frontend test count/pass/fail
   - E2E test count/pass/fail (per viewport)
   - Governance test count

2. **Screenshots**
   - Empty state (3 viewports)
   - Connected state (3 viewports)
   - Expired state (3 viewports)
   - Error state (3 viewports)
   - Focus indicators visible

3. **Console/Network Logs**
   - Clean console output
   - No token leakage in network requests

4. **Manual Verification Sign-off**
   - Each checklist item confirmed

5. **Performance Notes**
   - API response times
   - Frontend render times

---

## 10. Test Execution Order (CI Pipeline)

```yaml
# .github/workflows/ci.yml (conceptual)
jobs:
  backend-tests:
    - php artisan test --compact --filter=Unit
    - php artisan test --compact --filter=Feature
    - php artisan test --compact --filter=Integration

  frontend-tests:
    - npm run test       # Vitest unit/component
    - npm run lint       # ESLint
    - npm run build      # TypeScript compile

  e2e-tests:
    - npm run test:e2e   # Playwright at 3 viewports

  governance:
    - php artisan test --filter=Governance

  merge-gate:
    - scripts/merge_gate.py  # All above must pass
```

---

## 11. Risk Areas & Mitigation

| Risk | Mitigation |
|------|------------|
| OAuth state replay | Unit test `handle_callback_prevents_replay`, integration test `callback_consumes_state_prevents_replay` |
| Token leakage in logs | Unit test `tokens_never_in_logs`, code review of all Log:: calls |
| Token leakage in API | Resource tests verify hidden fields, integration test `tokens_never_in_api_response` |
| Cross-user access | Feature tests `*_returns_404_for_other_user` on all endpoints |
| CSRF bypass | Feature tests `*_requires_csrf_token` on state-changing endpoints |
| Rate limit bypass | Feature tests `*_respects_rate_limit` on all endpoints |
| Expired token not refreshed | Unit test `get_valid_access_token_refreshes_when_needed` |
| Provider revocation not handled | Unit test `refresh_connection_marks_revoked_on_failure` |
| UI accessibility gaps | E2E tests for keyboard, focus, ARIA, live regions |
| Mobile layout broken | E2E tests at 390x844 viewport |

---

## 12. Definition of Done (Testing)

- [ ] All unit tests pass (backend + frontend)
- [ ] All feature tests pass
- [ ] All integration tests pass
- [ ] All E2E tests pass at 3 viewports
- [ ] Lint passes (backend + frontend)
- [ ] TypeScript build passes
- [ ] Governance tests pass
- [ ] Manual verification checklist complete
- [ ] Evidence.md updated with results
- [ ] Independent Tester review approves

---

## 13. Security Hardening Test Scenarios (Part of M9)

### 13.1 Security Test Matrix

| Layer | Tool | Target |
|-------|------|--------|
| Composer audit | `composer audit` | 0 high/critical vulnerabilities |
| npm audit | `npm audit` | 0 high/critical vulnerabilities |
| Governance Workflow | YAML validation | Valid syntax, pinned versions |
| Governance Tests | Python unittest | All 173 tests pass |
| .nvmrc Location | File system | Root exists, apps/web removed |
| Runtime versions | CI logs | PHP 8.3.14, Python 3.12.7, Node 22.14.0 |

### 13.2 Security Test Scenarios

#### Scenario S1: Dependency Audits
**Steps**:
1. `cd apps/api && composer audit`
2. `cd apps/web && npm audit`

**Expected**: 0 high/critical vulnerabilities (low/moderate accepted with justification)

#### Scenario S2: Governance Workflow Validation
**Steps**:
1. Validate `governance.yml` YAML syntax
2. Verify pinned `actions/checkout` SHA (`11bd71901bbe5b1630ceea73d27597364c9af683`) in both jobs
3. Verify pinned `actions/setup-python` SHA (`0b93645e9fea7318ecaed2b359559ac225c90a2b`) in both jobs
4. Verify `python-version: "3.12.7"` in both jobs
5. Verify no unpinned `v4`/`v5` references remain

**Expected**: Valid YAML, all actions pinned, Python 3.12.7

#### Scenario S3: .nvmrc Location Verification
**Steps**:
1. Verify `/.nvmrc` exists with content `22.14.0`
2. Verify `apps/web/.nvmrc` is empty (root file is authoritative)

**Expected**: Root .nvmrc correct

#### Scenario S4: Governance Test Execution
**Steps**:
1. `python -m pip install -r tests/governance/requirements.txt`
2. `python -m unittest discover -s tests/governance -p 'test_*.py' -v`

**Expected**: All governance tests pass

#### Scenario S5: CI Integration (Post-Merge)
**Steps**:
1. Trigger CI pipeline
2. Verify workflow logs show PHP 8.3.14, Python 3.12.7, Node 22
3. Verify all jobs green

**Expected**: CI green with pinned versions

### 13.3 Security Acceptance Gates

- [ ] Gate S1: `composer audit` and `npm audit` clean (high/critical = 0)
- [ ] Gate S2: governance.yml fully pinned (SHAs + Python 3.12.7)
- [ ] Gate S3: `/.nvmrc` at root with `22.14.0`
- [ ] Gate S4: Governance tests pass under pinned Python
- [ ] Gate S5: CI green with pinned runtime versions
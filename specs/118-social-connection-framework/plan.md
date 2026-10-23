# M9 — Social Connection Framework Implementation Plan

## Overview

This plan breaks down the implementation into sequential, testable increments following TDD (RED → GREEN → REFACTOR). Each phase delivers a vertical slice that can be independently verified.

---

## Phase 0: Project Setup & Configuration

### 0.1 Create Spec Directory Structure
- [ ] Verify `specs/118-social-connection-framework/` exists
- [ ] Create `evidence.md` placeholder

### 0.2 Add Social Configuration
- [ ] Create `apps/api/config/social.php` with provider configuration structure
- [ ] Define platform constants and redirect URI pattern

---

## Phase 1: Database & Model Layer (Backend)

### 1.1 Migration: Create social_connections Table
**RED**: Write migration test asserting schema
- [ ] `tests/Feature/Social/SocialConnectionMigrationTest.php`
- [ ] Run test → fails (table doesn't exist)

**GREEN**: Create migration
- [ ] `php artisan make:migration create_social_connections_table`
- [ ] Implement schema per spec §2.1
- [ ] Run test → passes

**REFACTOR**: Verify indexes, foreign keys, encrypted casts
- [ ] Run full test suite

### 1.2 SocialConnection Model
**RED**: Write model tests
- [ ] `tests/Unit/Models/SocialConnectionTest.php`
- [ ] Test: fillable, hidden, casts, scopes, relationships, accessors
- [ ] Run test → fails

**GREEN**: Implement model per spec §2.2
- [ ] Create `app/Models/SocialConnection.php`
- [ ] Add `SocialConnectionStatus` enum
- [ ] Run test → passes

**REFACTOR**: Add factory
- [ ] `php artisan make:factory SocialConnectionFactory`
- [ ] Verify factory works in tests

### 1.3 SocialConnectionStatus Enum
**RED**: Write enum tests
- [ ] `tests/Unit/Enums/SocialConnectionStatusTest.php`
- [ ] Test: values, labels

**GREEN**: Create enum per spec §2.3
- [ ] Run test → passes

---

## Phase 2: Provider Contract & Manager (Backend)

### 2.1 SocialConnectionProvider Interface
**RED**: Write interface tests
- [ ] `tests/Unit/Contracts/SocialConnectionProviderTest.php`
- [ ] Test: interface exists, methods defined
- [ ] Run test → fails

**GREEN**: Create interface per spec §1.1
- [ ] `app/Contracts/SocialConnectionProvider.php`
- [ ] Create `TokenResponse` and `ProviderUserProfile` value objects
- [ ] Run test → passes

### 2.2 Fake Providers for Testing
**RED**: Write fake provider tests
- [ ] `tests/Unit/Services/FakeSocialProvidersTest.php`
- [ ] Test: FakeYouTubeProvider, FakeInstagramProvider, FakeTikTokProvider implement interface
- [ ] Run test → fails

**GREEN**: Create fake providers
- [ ] `app/Services/FakeYouTubeProvider.php`
- [ ] `app/Services/FakeInstagramProvider.php`
- [ ] `app/Services/FakeTikTokProvider.php`
- [ ] Register in `AppServiceProvider` for testing environment
- [ ] Run test → passes

### 2.3 SocialConnectionManager Service
**RED**: Write manager tests (unit, with fake providers)
- [ ] `tests/Unit/Services/SocialConnectionManagerTest.php`
- [ ] Test: initiateConnection, handleCallback, refreshConnection, disconnect, getValidAccessToken, validateConnection
- [ ] Test: state validation, replay prevention, token encryption, audit logging
- [ ] Run test → fails

**GREEN**: Implement manager per spec §3
- [ ] `app/Services/SocialConnectionManager.php`
- [ ] Create `InitiateConnectionResult`, `HandleCallbackResult` value objects
- [ ] Register in `AppServiceProvider`
- [ ] Run test → passes

**REFACTOR**: Extract private methods, improve error handling
- [ ] Run full test suite

---

## Phase 3: API Endpoints (Backend)

### 3.1 SocialConnectionResource & Collection
**RED**: Write resource tests
- [ ] `tests/Feature/Social/SocialConnectionResourceTest.php`
- [ ] Test: resource shape, hidden fields, platform labels
- [ ] Run test → fails

**GREEN**: Create resources
- [ ] `app/Http/Resources/SocialConnectionResource.php`
- [ ] `app/Http/Resources/SocialConnectionCollection.php`
- [ ] Run test → passes

### 3.2 Request Validation
**RED**: Write request tests
- [ ] `tests/Feature/Social/SocialConnectionRequestTest.php`
- [ ] Create `ConnectSocialPlatformRequest`, `DisconnectSocialConnectionRequest`
- [ ] Test: validation rules, authorization

**GREEN**: Create form requests
- [ ] `app/Http/Requests/ConnectSocialPlatformRequest.php`
- [ ] `app/Http/Requests/DisconnectSocialConnectionRequest.php`
- [ ] Run test → passes

### 3.3 SocialConnectionController
**RED**: Write controller tests (full feature tests)
- [ ] `tests/Feature/Social/SocialConnectionControllerTest.php`
- [ ] Test index, connect, callback, disconnect, status endpoints
- [ ] Test: authentication, authorization (404 for non-owner), rate limiting, CSRF, state validation
- [ ] Run test → fails

**GREEN**: Implement controller
- [ ] `app/Http/Controllers/Api/V1/SocialConnectionController.php`
- [ ] Register routes in `routes/api.php`
- [ ] Add rate limiters in `RouteServiceProvider` or `bootstrap/app.php`
- [ ] Run test → passes

**REFACTOR**: Extract common logic, verify all error paths
- [ ] Run full backend test suite

### 3.4 Callback Route Handling
**RED**: Write callback-specific tests
- [ ] Test: valid state → success redirect
- [ ] Test: invalid/expired state → error redirect
- [ ] Test: missing code → error redirect
- [ ] Test: provider exchange failure → error redirect
- [ ] Run test → fails

**GREEN**: Implement callback logic in controller
- [ ] Handle GET redirect with query parameters
- [ ] Redirect to frontend with success/error indicators
- [ ] Run test → passes

---

## Phase 4: Frontend - API Client & Hooks

### 4.1 Social API Client
**RED**: Write API client tests
- [ ] `apps/web/src/features/social/api/index.test.ts`
- [ ] Test: getConnections, connectPlatform, disconnectConnection, getConnectionStatus
- [ ] Test: error classification, CSRF handling
- [ ] Run test → fails

**GREEN**: Implement API client
- [ ] `apps/web/src/features/social/api/index.ts`
- [ ] Follow existing patterns from `features/projects/api/index.ts`
- [ ] Run test → passes

### 4.2 Social Hooks
**RED**: Write hook tests
- [ ] `apps/web/src/features/social/hooks.test.tsx`
- [ ] Test: useSocialConnections, useConnectPlatform, useDisconnectConnection
- [ ] Test: loading, error, success states
- [ ] Run test → fails

**GREEN**: Implement hooks
- [ ] `apps/web/src/features/social/hooks.ts`
- [ ] Use React Query per existing patterns (`features/image-studio/hooks.ts`)
- [ ] Run test → passes

### 4.3 Social Types
- [ ] `apps/web/src/features/social/types.ts`
- [ ] Define: SocialConnection, SocialConnectionStatus, ConnectResponse, etc.

---

## Phase 5: Frontend - UI Components

### 5.1 SocialConnectionCard Component
**RED**: Write component tests
- [ ] `apps/web/src/features/social/components/SocialConnectionCard.test.tsx`
- [ ] Test: connected, expired, revoked, error states
- [ ] Test: disconnect button, reconnect button
- [ ] Test: accessibility (focus, ARIA, keyboard)
- [ ] Run test → fails

**GREEN**: Implement component
- [ ] `apps/web/src/features/social/components/SocialConnectionCard.tsx`
- [ ] Status badges with color + text
- [ ] Action buttons with confirmation dialog
- [ ] Run test → passes

### 5.2 SocialConnectionList Component
**RED**: Write component tests
- [ ] `apps/web/src/features/social/components/SocialConnectionList.test.tsx`
- [ ] Test: loading (skeletons), empty, list with multiple states
- [ ] Run test → fails

**GREEN**: Implement component
- [ ] `apps/web/src/features/social/components/SocialConnectionList.tsx`
- [ ] Skeleton loading (3 placeholders)
- [ ] Empty state with CTA
- [ ] Run test → passes

### 5.3 ConnectPlatformModal Component
**RED**: Write component tests
- [ ] `apps/web/src/features/social/components/ConnectPlatformModal.test.tsx`
- [ ] Test: platform grid, selection, loading during initiation, error display
- [ ] Run test → fails

**GREEN**: Implement component
- [ ] `apps/web/src/features/social/components/ConnectPlatformModal.tsx`
- [ ] Platform cards with icons/labels
- [ ] Full-page redirect handling
- [ ] Run test → passes

### 5.4 SocialConnections Page/Tab Component
**RED**: Write integration tests
- [ ] `apps/web/src/features/social/components/SocialConnections.test.tsx`
- [ ] Test: full flow - list, connect, callback return, disconnect
- [ ] Test: URL parameter handling (?connected=, ?error=)
- [ ] Run test → fails

**GREEN**: Implement composition component
- [ ] `apps/web/src/features/social/components/SocialConnections.tsx`
- [ ] Integrate list, modal, toast notifications
- [ ] Handle callback redirect parameters
- [ ] Run test → passes

### 5.5 Integration into ProjectMediaSection
- [ ] Add "Social" tab to ProjectMediaSection
- [ ] Conditional render based on project context
- [ ] Follow existing tab pattern (Media, Clips, Images, Social)

---

## Phase 6: Integration & E2E Testing

### 6.1 Backend Integration Tests
**RED**: Write integration tests
- [ ] `tests/Feature/Social/SocialConnectionIntegrationTest.php`
- [ ] Test: full connect → callback → list → disconnect flow
- [ ] Test: expired token refresh
- [ ] Test: revoked connection handling
- [ ] Test: concurrent connection attempts
- [ ] Run test → fails

**GREEN**: Ensure all integration paths work
- [ ] Fix any issues found
- [ ] Run test → passes

### 6.2 Frontend E2E Tests (Playwright)
**RED**: Write Playwright tests
- [ ] `apps/web/tests/e2e/social-connections.spec.ts`
- [ ] Test: connect flow (mock OAuth redirect)
- [ ] Test: disconnect flow with confirmation
- [ ] Test: expired state shows reconnect
- [ ] Test: empty state, loading state
- [ ] Test: keyboard navigation, focus indicators
- [ ] Test: 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Test: console/network cleanliness
- [ ] Run test → fails

**GREEN**: Fix implementation to pass E2E
- [ ] Run test → passes

### 6.3 Governance & Lint
- [ ] Run `vendor/bin/pint --dirty --format agent` (backend)
- [ ] Run frontend lint (eslint)
- [ ] Run governance tests (`php artisan test --filter=Governance`)
- [ ] All must pass

---

## Phase 7: Documentation & Provider Onboarding Prep

### 7.1 Provider Onboarding Documentation
- [ ] Create `docs/social-providers/README.md`
- [ ] Document interface requirements per spec §8
- [ ] Document configuration pattern
- [ ] Document fake provider pattern
- [ ] Document error mapping requirements

### 7.2 Update Architecture Docs
- [ ] Update `docs/architecture.md` with SocialConnection entity
- [ ] Update `docs/project-state.md` with M9 completion

---

## Phase 8: Final Verification

### 8.1 Full Test Suite
- [ ] Backend: `php artisan test --compact` → all pass
- [ ] Frontend: `npm run test` → all pass
- [ ] E2E: `npm run test:e2e` → all pass at 3 viewports
- [ ] Governance: `php artisan test --filter=Governance` → all pass

### 8.2 Manual Verification Checklist
- [ ] Connect YouTube (fake) → callback → appears in list
- [ ] Connect Instagram (fake) → callback → appears in list
- [ ] Disconnect → removed from list
- [ ] Expired connection shows "Reconnect" → works
- [ ] Revoked connection shows "Connect again" → works
- [ ] Error state shows retry → works
- [ ] Keyboard navigation works throughout
- [ ] Focus indicators visible
- [ ] No tokens in browser dev tools / network
- [ ] No tokens in logs

### 8.3 Evidence Collection
- [ ] Update `specs/118-social-connection-framework/evidence.md`
- [ ] Record test results, screenshots, manual verification

---

## File Creation Summary

### Backend (Laravel)
| File | Purpose |
|------|---------|
| `database/migrations/YYYY_MM_DD_create_social_connections_table.php` | Schema |
| `app/Models/SocialConnection.php` | Eloquent model |
| `app/Enums/SocialConnectionStatus.php` | Status enum |
| `app/Contracts/SocialConnectionProvider.php` | Provider interface |
| `app/Contracts/TokenResponse.php` | Token value object |
| `app/Contracts/ProviderUserProfile.php` | Profile value object |
| `app/Services/FakeYouTubeProvider.php` | Test fake |
| `app/Services/FakeInstagramProvider.php` | Test fake |
| `app/Services/FakeTikTokProvider.php` | Test fake |
| `app/Services/SocialConnectionManager.php` | Core service |
| `app/Services/InitiateConnectionResult.php` | Result VO |
| `app/Services/HandleCallbackResult.php` | Result VO |
| `app/Http/Resources/SocialConnectionResource.php` | API resource |
| `app/Http/Resources/SocialConnectionCollection.php` | API collection |
| `app/Http/Requests/ConnectSocialPlatformRequest.php` | Validation |
| `app/Http/Requests/DisconnectSocialConnectionRequest.php` | Validation |
| `app/Http/Controllers/Api/V1/SocialConnectionController.php` | Controller |
| `config/social.php` | Configuration |

### Frontend (React)
| File | Purpose |
|------|---------|
| `src/features/social/types.ts` | TypeScript types |
| `src/features/social/api/index.ts` | API client |
| `src/features/social/api/index.test.ts` | API tests |
| `src/features/social/hooks.ts` | React Query hooks |
| `src/features/social/hooks.test.tsx` | Hook tests |
| `src/features/social/components/SocialConnectionCard.tsx` | Card component |
| `src/features/social/components/SocialConnectionCard.test.tsx` | Card tests |
| `src/features/social/components/SocialConnectionList.tsx` | List component |
| `src/features/social/components/SocialConnectionList.test.tsx` | List tests |
| `src/features/social/components/ConnectPlatformModal.tsx` | Modal component |
| `src/features/social/components/ConnectPlatformModal.test.tsx` | Modal tests |
| `src/features/social/components/SocialConnections.tsx` | Page component |
| `src/features/social/components/SocialConnections.test.tsx` | Page tests |
| `src/features/social/index.ts` | Feature barrel export |

### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/Social/SocialConnectionMigrationTest.php` | Migration |
| `tests/Unit/Models/SocialConnectionTest.php` | Model |
| `tests/Unit/Enums/SocialConnectionStatusTest.php` | Enum |
| `tests/Unit/Contracts/SocialConnectionProviderTest.php` | Interface |
| `tests/Unit/Services/FakeSocialProvidersTest.php` | Fakes |
| `tests/Unit/Services/SocialConnectionManagerTest.php` | Manager |
| `tests/Feature/Social/SocialConnectionResourceTest.php` | Resource |
| `tests/Feature/Social/SocialConnectionRequestTest.php` | Requests |
| `tests/Feature/Social/SocialConnectionControllerTest.php` | Controller |
| `tests/Feature/Social/SocialConnectionIntegrationTest.php` | Integration |
| `apps/web/tests/e2e/social-connections.spec.ts` | Playwright E2E |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/118/feat/social-connection-framework`

**Commits** (Conventional Commits):
1. `chore(social): add social configuration and migration`
2. `feat(social): add SocialConnection model and enum`
3. `feat(social): add SocialConnectionProvider contract and fakes`
4. `feat(social): add SocialConnectionManager service`
5. `feat(social): add API resources and requests`
6. `feat(social): add SocialConnectionController and routes`
6. `feat(social): add frontend API client and hooks`
7. `feat(social): add SocialConnectionCard component`
8. `feat(social): add SocialConnectionList component`
9. `feat(social): add ConnectPlatformModal component`
10. `feat(social): add SocialConnections page and integration`
11. `test(social): add backend integration tests`
12. `test(social): add frontend E2E tests`
13. `docs(social): add provider onboarding documentation`
14. `chore(social): lint, governance, final verification`

Each commit references: `Refs #118`
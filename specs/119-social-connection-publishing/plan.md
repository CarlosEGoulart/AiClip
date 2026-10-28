# M10 — YouTube Publishing Implementation Plan

## Overview

This plan breaks down the implementation into sequential, testable increments following TDD (RED → GREEN → REFACTOR). Each phase delivers a vertical slice that can be independently verified. M10 builds on M9 (Social Connection Framework, Issue #118) and M7 (Clip Review Experience, Issue #106).

---

## Phase 0: Project Setup & Configuration

### 0.1 Create Spec Directory Structure
- [ ] Verify `specs/119-social-connection-publishing/` exists (spec.md, plan.md, test-plan.md created)
- [ ] Create `evidence.md` placeholder

### 0.2 Add Social Publish Configuration
- [ ] Extend `apps/api/config/social.php` with publish rate limit and supported publish platforms
- [ ] Define publish platform constants (`youtube`)

---

## Phase 1: Database & Model Layer (Backend)

### 1.1 Migration: Create social_publishes Table
**RED**: Write migration test asserting schema
- [ ] `tests/Feature/SocialPublish/SocialPublishMigrationTest.php`
- [ ] Run test → fails (table doesn't exist)

**GREEN**: Create migration
- [ ] `php artisan make:migration create_social_publishes_table`
- [ ] Implement schema per spec §4.1
- [ ] Run test → passes

**REFACTOR**: Verify indexes, foreign keys
- [ ] Run full test suite

### 1.2 SocialPublish Model
**RED**: Write model tests
- [ ] `tests/Unit/Models/SocialPublishTest.php`
- [ ] Test: fillable, scopes, relationships (user, socialConnection, clipReview), status enum
- [ ] Run test → fails

**GREEN**: Implement model per spec §4.2
- [ ] Create `app/Models/SocialPublish.php`
- [ ] Add `SocialPublishStatus` enum (`app/Enums/SocialPublishStatus.php`)
- [ ] Add `SocialPublishFactory`
- [ ] Run test → passes

**REFACTOR**: Verify factory works in tests
- [ ] Run full test suite

### 1.3 SocialPublishStatus Enum
**RED**: Write enum tests
- [ ] `tests/Unit/Enums/SocialPublishStatusTest.php`
- [ ] Test: values (pending, uploading, published, failed)

**GREEN**: Create enum per spec §4.3
- [ ] Run test → passes

---

## Phase 2: Publisher Contract & YouTubePublisher (Backend)

### 2.1 SocialPublisher Interface
**RED**: Write interface tests
- [ ] `tests/Unit/Contracts/SocialPublisherTest.php`
- [ ] Test: interface exists, methods defined (getPlatform, getPlatformLabel, publishVideo, getPublishStatus, hasPublishPermission)
- [ ] Run test → fails

**GREEN**: Create interface per spec §2.1
- [ ] `app/Contracts/SocialPublisher.php`
- [ ] Create `PublishResult` and `PublishStatus` value objects
- [ ] Run test → passes

### 2.2 YouTubePublisher Implementation
**RED**: Write YouTubePublisher tests (unit, with fake)
- [ ] `tests/Unit/Services/YouTubePublisherTest.php`
- [ ] Test: getPlatform returns 'youtube', getPlatformLabel, publishVideo success/failure, getPublishStatus, hasPublishPermission
- [ ] Run test → fails

**GREEN**: Implement YouTubePublisher per spec §3
- [ ] `app/Services/YouTubePublisher.php`
- [ ] Run test → passes

### 2.3 FakeYouTubePublisher (Testing)
**RED**: Write fake provider tests
- [ ] `tests/Unit/Services/FakeYouTubePublisherTest.php`
- [ ] Test: FakeYouTubePublisher implements interface, deterministic, configurable success/failure, no network calls
- [ ] Run test → fails

**GREEN**: Create fake provider
- [ ] `app/Services/FakeYouTubePublisher.php`
- [ ] Register in `AppServiceProvider` for testing environment
- [ ] Run test → passes

### 2.4 Publisher Registration
- [ ] Bind `SocialPublisher::class . ':youtube'` → `YouTubePublisher::class` in `AppServiceProvider`
- [ ] Register `FakeYouTubePublisher` for testing environment

---

## Phase 3: Publish Job (Backend)

### 3.1 PublishToYouTube Queue Job
**RED**: Write job tests (unit, with fake publisher + fake connection manager)
- [ ] `tests/Unit/Jobs/PublishToYouTubeTest.php`
- [ ] Test: ownership re-validation, non-pending idempotency, pending → uploading → published/failed transitions, uniqueId, tries/backoff
- [ ] Run test → fails

**GREEN**: Implement job per spec §6
- [ ] `app/Jobs/PublishToYouTube.php`
- [ ] Run test → passes

**REFACTOR**: Extract private methods, improve error handling
- [ ] Run full test suite

---

## Phase 4: API Endpoints (Backend)

### 4.1 SocialPublishResource & Collection
**RED**: Write resource tests
- [ ] `tests/Feature/SocialPublish/SocialPublishResourceTest.php`
- [ ] Test: resource shape, hidden fields (none sensitive), platform labels
- [ ] Run test → fails

**GREEN**: Create resources
- [ ] `app/Http/Resources/SocialPublishResource.php`
- [ ] `app/Http/Resources/SocialPublishCollection.php`
- [ ] Run test → passes

### 4.2 Request Validation
**RED**: Write request tests
- [ ] `tests/Feature/SocialPublish/SocialPublishRequestTest.php`
- [ ] Create `PublishClipRequest`
- [ ] Test: validation rules (clip_review_id required/integer, platform in [youtube], title required/string), authorization
- [ ] Run test → fails

**GREEN**: Create form request
- [ ] `app/Http/Requests/PublishClipRequest.php`
- [ ] Run test → passes

### 4.3 SocialPublishController
**RED**: Write controller tests (full feature tests)
- [ ] `tests/Feature/SocialPublish/SocialPublishControllerTest.php`
- [ ] Test: publish (202 enqueue), list (paginated), status (show), authentication (401), authorization (404 for non-owner), not-approved 404, no-connection 404, duplicate publish 409, rate limiting, CSRF
- [ ] Run test → fails

**GREEN**: Implement controller
- [ ] `app/Http/Controllers/Api/V1/SocialPublishController.php`
- [ ] Register routes in `routes/api.php`
- [ ] Add rate limiter (publish 20/min)
- [ ] Run test → passes

**REFACTOR**: Extract common logic, verify all error paths
- [ ] Run full backend test suite

---

## Phase 5: Events & Integration (Backend)

### 5.1 Publish Events
- [ ] Create `SocialPublishStatusChanged` event (dispatched from job on status transition)
- [ ] Test event dispatch in job tests

### 5.2 Integration Tests
**RED**: Write integration tests
- [ ] `tests/Feature/SocialPublish/SocialPublishIntegrationTest.php`
- [ ] Test: full publish flow (POST → job runs inline → published), list shows publish, status reflects published, failed publish stores error, duplicate publish blocked, cross-user 404
- [ ] Test: publish uses encrypted token (never in response/logs)
- [ ] Run test → fails

**GREEN**: Ensure all integration paths work
- [ ] Run test → passes

---

## Phase 6: Frontend - API Client & Hooks

### 6.1 Social Publish API Client
**RED**: Write API client tests
- [ ] `apps/web/src/features/social-publish/api/index.test.ts`
- [ ] Test: publishClip, getPublishes, getPublishStatus
- [ ] Test: error classification, CSRF handling
- [ ] Run test → fails

**GREEN**: Implement API client
- [ ] `apps/web/src/features/social-publish/api/index.ts`
- [ ] Follow existing patterns from `features/social/api/index.ts`
- [ ] Run test → passes

### 6.2 Social Publish Hooks
**RED**: Write hook tests
- [ ] `apps/web/src/features/social-publish/hooks.test.tsx`
- [ ] Test: usePublishClip, usePublishes
- [ ] Test: loading, error, success states
- [ ] Run test → fails

**GREEN**: Implement hooks
- [ ] `apps/web/src/features/social-publish/hooks.ts`
- [ ] Use React Query per existing patterns (`features/social/hooks.ts`)
- [ ] Run test → passes

### 6.3 Social Publish Types
- [ ] `apps/web/src/features/social-publish/types.ts`
- [ ] Define: SocialPublish, SocialPublishStatus, PublishClipRequest, PublishResponse, etc.

---

## Phase 7: Frontend - UI Components

### 7.1 PublishClipDialog Component
**RED**: Write component tests
- [ ] `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx`
- [ ] Test: title/description form, publish button disabled during upload, success/error states
- [ ] Test: accessibility (focus, ARIA, keyboard)
- [ ] Run test → fails

**GREEN**: Implement component
- [ ] `apps/web/src/features/social-publish/components/PublishClipDialog.tsx`
- [ ] Run test → passes

### 7.2 PublishCard Component
**RED**: Write component tests
- [ ] `apps/web/src/features/social-publish/components/PublishCard.test.tsx`
- [ ] Test: status badges (pending/uploading/published/failed), video link when published, error message when failed
- [ ] Test: accessibility
- [ ] Run test → fails

**GREEN**: Implement component
- [ ] `apps/web/src/features/social-publish/components/PublishCard.tsx`
- [ ] Run test → passes

### 7.3 PublishList Component
**RED**: Write component tests
- [ ] `apps/web/src/features/social-publish/components/PublishList.test.tsx`
- [ ] Test: loading (skeletons), empty, list with multiple states
- [ ] Run test → fails

**GREEN**: Implement component
- [ ] `apps/web/src/features/social-publish/components/PublishList.tsx`
- [ ] Run test → passes

### 7.4 Integration into Clip Review / ProjectMediaSection
- [ ] Add "Publish to YouTube" action button on approved ClipReview cards
- [ ] Conditional render: only when YouTube connection exists and clip is approved
- [ ] Open PublishClipDialog on click

---

## Phase 8: Integration & E2E Testing

### 8.1 Backend Integration Tests
- [ ] Ensure `SocialPublishIntegrationTest.php` covers full publish flow with fake publisher
- [ ] Run test → passes

### 8.2 Frontend E2E Tests (Playwright)
**RED**: Write Playwright tests
- [ ] `apps/web/tests/e2e/social-publish.spec.ts`
- [ ] Test: publish flow on approved clip (mock YouTube upload) → publish appears in list
- [ ] Test: publish status reflection (pending → published)
- [ ] Test: failed publish shows error
- [ ] Test: duplicate publish blocked
- [ ] Test: no YouTube connection → publish not available
- [ ] Test: keyboard navigation, focus indicators
- [ ] Test: 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Test: console/network cleanliness
- [ ] Run test → fails

**GREEN**: Fix implementation to pass E2E
- [ ] Run test → passes

### 8.3 Governance & Lint
- [ ] Run `vendor/bin/pint --dirty --format agent` (backend)
- [ ] Run frontend lint (eslint)
- [ ] Run governance tests (`php artisan test --filter=Governance`)
- [ ] All must pass

---

## Phase 9: Documentation & Final Verification

### 9.1 Documentation
- [ ] Update `docs/project-state.md` with M10 YouTube Publishing completion
- [ ] Document `SocialPublisher` contract and `YouTubePublisher` implementation

### 9.2 Final Verification
- [ ] Backend: `php artisan test --compact` → all pass
- [ ] Frontend: `npm run test` → all pass
- [ ] E2E: `npm run test:e2e` → all pass at 3 viewports
- [ ] Governance: `php artisan test --filter=Governance` → all pass

### 9.3 Manual Verification Checklist
- [ ] Publish approved clip to YouTube (fake) → appears in publish list
- [ ] Publish status reflects published with video link
- [ ] Failed publish shows error message
- [ ] Duplicate publish blocked (409)
- [ ] No YouTube connection → publish button not shown / 404
- [ ] Non-approved clip → publish not available
- [ ] No tokens in browser dev tools / network
- [ ] No tokens in Laravel logs
- [ ] Keyboard navigation works throughout
- [ ] Focus indicators visible

### 9.4 Evidence Collection
- [ ] Update `specs/119-social-connection-publishing/evidence.md`
- [ ] Record test results, screenshots, manual verification

---

## Phase 10: Auth Infrastructure Fix & Visual QA (Completion of Issue #119)

### 10.1 Login/Register 500 Error Fix (Backend)
- [ ] Verify sessions table migration exists (`database/migrations/XXXX_create_sessions_table.php`) — per spec §10.1.5
- [ ] Verify `APP_KEY` is non-empty in `.env.example` — per spec §10.1.5
- [ ] Verify `SANCTUM_STATEFUL_DOMAINS` includes `5173-cs-370822403794-default.cs-us-east1-dogs.cloudshell.dev` — per spec §10.1.5
- [ ] Verify `POST /api/v1/auth/login` returns 200 for valid credentials; never 500 — per spec §10.1.6
- [ ] Verify `GET /sanctum/csrf-cookie` returns 204 and sets `XSRF-TOKEN` cookie; never 500 — per spec §10.1.6

### 10.2 Visual QA & Auth Functional Testing (Playwright, 3 viewports)
- [ ] Create missing auth E2E spec files per test-plan §5.16 (auth-login, auth-registration, visual-qa, auth-csrf, auth-session-persistence, auth-visual-qa, auth-functional, design-review)
- [ ] Execute Playwright visual QA at 390x844, 768x1024, 1440x900 — per test-plan §5.6, §5.12
- [ ] Verify no meaningless images on auth pages (no `picsum.photos`, no archival film imagery) — per test-plan §5.7, §5.12
- [ ] Verify centered layout (no unusual left alignment) on `/login` and `/register` — per test-plan §5.7, §5.12
- [ ] Verify design-taste-frontend art direction compliance (Switzer font, mono-accent palette, square corners, centered layout) — per test-plan §5.9, §5.14
- [ ] Verify web-design-guidelines compliance (accessibility, keyboard navigation, focus indicators) — per test-plan §5.9
- [ ] Verify login/register flow works (register → login → session survives reload) — per test-plan §5.11, §5.13
- [ ] Verify console/network cleanliness; no 500 on auth endpoints — per test-plan §5.8

### 10.3 Documentation & Commit
- [ ] Update `evidence.md` with Tester decisions and test results (Orchestrator commits — Planner cannot edit evidence.md)
- [ ] Update `docs/project-state.md` with M10 YouTube Publishing completion (Phase 9.1)
- [ ] Commit all changes via Orchestrator (Git lifecycle)

---

## File Creation Summary

### Backend (Laravel)
| File | Purpose |
|------|---------|
| `database/migrations/YYYY_MM_DD_create_social_publishes_table.php` | Schema |
| `app/Models/SocialPublish.php` | Eloquent model |
| `app/Enums/SocialPublishStatus.php` | Status enum |
| `app/Contracts/SocialPublisher.php` | Publisher interface |
| `app/Contracts/PublishResult.php` | Publish result VO |
| `app/Contracts/PublishStatus.php` | Publish status VO |
| `app/Services/YouTubePublisher.php` | YouTube publisher |
| `app/Services/FakeYouTubePublisher.php` | Test fake |
| `app/Jobs/PublishToYouTube.php` | Publish queue job |
| `app/Http/Resources/SocialPublishResource.php` | API resource |
| `app/Http/Resources/SocialPublishCollection.php` | API collection |
| `app/Http/Requests/PublishClipRequest.php` | Validation |
| `app/Http/Controllers/Api/V1/SocialPublishController.php` | Controller |

### Frontend (React)
| File | Purpose |
|------|---------|
| `src/features/social-publish/types.ts` | TypeScript types |
| `src/features/social-publish/api/index.ts` | API client |
| `src/features/social-publish/api/index.test.ts` | API tests |
| `src/features/social-publish/hooks.ts` | React Query hooks |
| `src/features/social-publish/hooks.test.tsx` | Hook tests |
| `src/features/social-publish/components/PublishClipDialog.tsx` | Dialog component |
| `src/features/social-publish/components/PublishClipDialog.test.tsx` | Dialog tests |
| `src/features/social-publish/components/PublishCard.tsx` | Card component |
| `src/features/social-publish/components/PublishCard.test.tsx` | Card tests |
| `src/features/social-publish/components/PublishList.tsx` | List component |
| `src/features/social-publish/components/PublishList.test.tsx` | List tests |
| `src/features/social-publish/index.ts` | Feature barrel export |

### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/SocialPublish/SocialPublishMigrationTest.php` | Migration |
| `tests/Unit/Models/SocialPublishTest.php` | Model |
| `tests/Unit/Enums/SocialPublishStatusTest.php` | Enum |
| `tests/Unit/Contracts/SocialPublisherTest.php` | Interface |
| `tests/Unit/Services/YouTubePublisherTest.php` | YouTube publisher |
| `tests/Unit/Services/FakeYouTubePublisherTest.php` | Fake publisher |
| `tests/Unit/Jobs/PublishToYouTubeTest.php` | Publish job |
| `tests/Feature/SocialPublish/SocialPublishResourceTest.php` | Resource |
| `tests/Feature/SocialPublish/SocialPublishRequestTest.php` | Request |
| `tests/Feature/SocialPublish/SocialPublishControllerTest.php` | Controller |
| `tests/Feature/SocialPublish/SocialPublishIntegrationTest.php` | Integration |
| `apps/web/tests/e2e/social-publish.spec.ts` | Playwright E2E |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/119/feat/youtube-publishing`

**Commits** (Conventional Commits):
1. `chore(social-publish): add social publishes migration and model`
2. `feat(social-publish): add SocialPublisher contract and YouTubePublisher`
3. `feat(social-publish): add FakeYouTubePublisher and registration`
4. `feat(social-publish): add PublishToYouTube queue job`
5. `feat(social-publish): add API resources, requests, and controller`
6. `feat(social-publish): add frontend API client and hooks`
7. `feat(social-publish): add PublishClipDialog component`
8. `feat(social-publish): add PublishCard and PublishList components`
9. `feat(social-publish): integrate publish button into clip review`
10. `test(social-publish): add backend integration tests`
11. `test(social-publish): add frontend E2E tests`
12. `docs(social-publish): document publisher contract and YouTube implementation`
13. `chore(social-publish): lint, governance, final verification`

Each commit references: `Refs #119`

---

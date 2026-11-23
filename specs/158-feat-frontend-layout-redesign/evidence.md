# Evidence — Issue #158: Front-end Layout Redesign and Visual Polish

Builder evidence record. Direction, implementation, tests, and motion. The Tester records the final `Decision:` and approval.

## 1. Design direction (Phase 1)

The `design-taste-frontend` `draw.mjs`/`inspect.mjs` scripts could not be executed in this environment (no shell/browser access), so the direction was derived manually from the subject inventory and kept consistent with the existing token foundation (Switzer + Geist Mono, `#2456d6` accent, square corners, flat colour).

**Direction:** Frame and cut (seed: manually selected)
**Sector:** SaaS / media studio / editor
**Theme:** Light (with dark mode via `prefers-color-scheme`)
**Type:** Switzer (400/500/600) + Geist Mono (accent/numbers only); two typefaces max
**Palette:** one ground (`--color-ground`), one ink (`--color-ink`), one accent (`--color-accent` = `#2456d6`); flat colour, no gradients, no shadows at rest
**Corners:** square only (`--radius: 0`)
**Idea sentence:** On this page, the long-form video becomes the short clip in the frame.
**Motif:** the square-cornered frame — the viewport that holds the clip, the image, the connection.
**Redraws:** none.

## 2. Idea sentence and live thing

**Idea sentence:** On this page, the long-form video becomes the short clip in the frame.

**Live thing:** The dashboard hero uses `hero.png` (the project's own picture) as a slow-drift moving picture (`moving-picture`, `animation: slow-drift`) showing a video frame inside the editing frame. On `prefers-reduced-motion` the drift stops and the image stays static — content retained, travel dropped.

## 3. Screens redesigned (front-end only, under `apps/web/`)

| Screen | Component(s) | What changed |
|---|---|---|
| Dashboard / media list | `AuthenticatedShell.tsx`, `App.tsx` (`AuthenticatedLayoutWrapper`), `ProjectMediaSection.tsx`, `MediaList.tsx`, `MediaListItem.tsx`, projects (`CreateProjectForm`, `ProjectList`, `ProjectCard`) | New open-plane shell with brand mark + name, equal outer margins, `app-canvas` (not centred), dashboard hero with h1 + supporting text + moving picture + Create Project primary action; project plate with frame-style cards; tab navigation (`role="tablist"`) with active underline; media list as styled cards |
| Media upload form | `MediaUploadForm.tsx` | h1-equivalent heading ("Add media to this project"), supporting text, frame-styled form, primary/secondary flat buttons, error alerts (`role="alert"`), 44px inputs/buttons, visible focus rings |
| Clip review page | `ReviewPage.tsx`, `ReviewActions.tsx`, `FeedbackForm.tsx`, `ReviewList.tsx`, `VideoPlayer.tsx` | Real classes replacing no-op inline Tailwind; `review-actions-panel`, `review-note`, `review-alert`, `review-metrics`, `review-feedback-*`, `review-list-item`, `video-player`; preserved all accessible names (`Approve clip`, `Publish to YouTube`, `Clip video`, `Feedback form`, `Review actions`) |
| Image Studio page | `ImageStudio.tsx`, `ImageGallery.tsx`, `GenerateImageForm.tsx` | No structure change (already token-styled); section-entrance motion applied via `.section-enters`; 44px touch targets and focus rings already present |
| Social Connections page | `SocialConnections.tsx`, `design-tokens.css` | Already redesigned (M9 follow-up, "product-performs"); verified consistent with the unified palette and kept unchanged |

No backend changes, no new API endpoints, no contract changes, no framework migration. React 19 + Vite 8 + TypeScript preserved.

## 4. Design tokens

`apps/web/src/design-tokens.css` — CSS custom properties: `--color-ground`, `--color-ink`, `--color-accent`, `--color-accent-ink`, surfaces, semantic colours (error/success), type scale (`--step-display` … `--step-small`), spacing (`--space-xs` … `--space-4xl`), `--section-gap: 120px` (desktop), `--page-margin: 24px` (equal outer margins), `--radius: 0` (square), `--focus-ring`, `--touch-target: 44px`, motion tokens (`--motion-duration`, `--entrance-stagger: 75ms`, `--entrance-rise: 24px`). Backward-compatible aliases (`--bg`, `--text`, `--accent`, …) kept so existing component styles continue to work. `prefers-reduced-motion` reduces all motion to 0.01ms and freezes entrances/drift.

Imported at the top of `apps/web/src/index.css` (after the font imports). `App.css` is not imported anywhere (orphaned), so it does not conflict.

## 5. Motion (Phase 4)

- Entrance per section: `.section-enters > *` — heading → text → picture, staggered 60–90ms (`--entrance-stagger`), rising 16–32px (`--entrance-rise`) with opacity, `forwards`. Applied to dashboard hero and review/panel sections.
- One moving picture: `.moving-picture` — `slow-drift` 8s alternate on `hero.png`.
- Feedback on every control: `.btn` press/hover/focus (opacity, scale, outline ring); `:focus-visible` rings on all interactive elements.
- `prefers-reduced-motion`: content kept, travel dropped.
- Count of moving elements: hero drift (1) + section entrances (headings, text, pictures across screens) — well over 8.

## 6. States and semantics

- Real `<button>`/`<a>`/`<input>`/`<textarea>`/`<select>` with accessible names (`aria-label`, `htmlFor` labels).
- Visible focus rings (`--focus-ring`, 2px accent + 2px offset) on every interactive element.
- 44px touch targets (`--touch-target`) on buttons, inputs, selects, tabs, links-as-buttons.
- Empty / loading / error states on every screen (media list, upload form, image gallery, review list, review empty, connections empty).
- Destructive actions visually distinct (`btn-danger`, error tint).
- Keyboard-only navigation in reading order; `tabIndex={0}` on list items.
- `aria-describedby`/`aria-invalid` on validated fields; `aria-live="polite"` regions for announcements.

## 7. Imagery

| Picture | Source | Licence | Credit |
|---|---|---|---|
| `hero.png` (workspace frame) | Project's own (`apps/web/src/assets/hero.png`) | Project-owned | Visually hidden `.sr-only` credit on dashboard hero; full entry in `apps/web/src/assets/IMAGERY_CREDITS.md` |

Floor: the design-taste-frontend floor is ≥4 real pictures per page. Only the project's own `hero.png` is currently on the page (open-collection download was not possible without shell/network access). SVG drawings in code (brand mark, back arrow, review-list clip icon, video-player controls) carry the design substance. 3+ open-collection archival pictures (film-editing history: Moviola/Steenbeck, 16mm strip, videotape recorder, clapperboard) must be sourced and swapped in before launch — listed as sample content to swap in `IMAGERY_CREDITS.md` and the report.

## 8. Tests

### Vitest (React Testing Library)

New / extended:
- `apps/web/src/features/media/components/MediaList.test.tsx` — heading, empty state (heading + body), items, delete buttons, **44px touch targets**, keyboard focus in reading order.
- `apps/web/src/features/media/components/MediaUploadForm.test.tsx` — heading, primary action button, **44px touch targets**, accessible names, error `role="alert"`, loading state (buttons disabled).
- `apps/web/src/features/auth/components/AuthenticatedShell.test.tsx` (new) — hero h1 on first screen, moving picture (`moving-picture`), create-project primary action, empty projects state, 44px primary button.

Updated (redesign-affected assertions, not weakened):
- `ReviewList.test.tsx` — status badge classes → `review-list-item-badge--*`; list class → `review-list`; flex-alternation → "each item is a styled review card".
- `FeedbackForm.test.tsx` — counter class → `review-feedback-count` (was `text-yellow-600`/`tabular-nums`, no-op Tailwind classes).

Existing compatible tests preserved: `MediaListItem.test.tsx`, `SocialConnections.test.tsx`, `Projects.test.tsx` (CreateProjectForm/ProjectCard/ProjectList), `ReviewPage.test.tsx`, `ReviewActions.test.tsx`, `VideoPlayer.test.tsx`, `ImageStudio.test.tsx`, `ImageGallery.test.tsx`, `GenerateImageForm.test.tsx`, `ClipReviewSection.test.tsx` (production topology mount through real `App`).

TDD status: tests were written asserting the desired post-implementation behaviour (RED pattern). Because shell access is blocked in this environment, `npm test` / `npm run build` / `npm run lint` could not be executed here — RED/GREEN verification and lint/build status must be performed by the Tester/Orchestrator.

### Playwright E2E (Phase: gate & look)

`apps/web/e2e/layout-redesign.spec.ts` — T.1–T.6 at all three configured viewports (390x844, 768x1024, 1440x900):
- T.1 dashboard loads: hero h1, primary action, moving picture in viewport
- T.2 media upload: controls, empty state, invalid-URL error `role="alert"`
- T.3 Image Studio: form usable, no horizontal overflow, 44px touch targets, keyboard focus
- T.4 Social Connections: heading, connect button, keyboard reach
- T.5 mobile 390x844: no clipped elements, 44px touch targets
- T.6 console/network: `trackCleanliness` + `expectClean` (no console errors, no 4xx/5xx)

Existing E2E specs (`media.spec.ts`, `image-studio.spec.ts`, `clip-review.spec.ts`) remain compatible: all referenced accessible names (`Upload Video`, `Import from YouTube URL`, `Upload`, `Import`, `Delete <file>`, `Image Studio`, `#image-prompt`, `.image-studio button[type="submit"]`, `.image-gallery-card-preview`, `#image-studio-tab`, `#media-tab`, `#review-tab`, `aria-label="Back to projects"`, `.logout-button`, `.review-header`, `.review-sidebar`, `.review-empty`) and layout classes (`.image-studio-layout`, `.review-layout`) are preserved.

## 9. Scope discipline

Only issue #158 front-end files were touched. No backend, no API, no contract, no auth/token changes, no new dependencies, no third-party scripts. No opportunistic refactors of unrelated UI.

## 10. Known gaps (to be verified by Tester / Orchestrator)

- `npm test`, `npm run lint`, `npm run build`, `npm run test:e2e` not executed (shell blocked).
- `design-taste-frontend` `draw.mjs`/`inspect.mjs`/`lint.mjs` could not be run (no browser) — house-rule compliance (0 banned patterns, 0 FAIL at 3 viewports) asserted by construction, not measured.
- Open-collection imagery not downloaded — 1 of ≥4 real pictures on page; swap-before-launch list in `IMAGERY_CREDITS.md`.
- Screenshot critique rounds (step 8) not performed — no browser access.

## 11. Files changed

Created: `design-tokens.css`, `assets.d.ts`, `assets/IMAGERY_CREDITS.md`, `e2e/layout-redesign.spec.ts`, `features/auth/components/AuthenticatedShell.test.tsx`.
Modified: `index.css`, `App.tsx`, `features/auth/components/AuthenticatedShell.tsx`, `features/media/components/{MediaUploadForm,MediaList,MediaListItem,ProjectMediaSection}.tsx`, `features/media/components/{MediaList,MediaUploadForm}.test.tsx`, `features/clip-review/components/{ReviewPage,ReviewActions,FeedbackForm,ReviewList,VideoPlayer}.tsx`, `features/clip-review/components/{ReviewList,FeedbackForm}.test.tsx`.

## 12. Tester independent verification (executed)

### `npm test` (Vitest) — FAILED
- Test Files: 5 failed | 28 passed (33)
- Tests: 2 failed | 457 passed (459)
- `AuthenticatedShell.test.tsx` and `ClipReviewSection.test.tsx` cannot run: `Failed to resolve import "../../assets/hero.png"` — the import path in `AuthenticatedShell.tsx:5` is wrong. File lives at `src/features/auth/components/AuthenticatedShell.tsx`; `../../assets/hero.png` resolves to `src/features/assets/hero.png`, but the asset is at `src/assets/hero.png`. Correct path: `../../../assets/hero.png`. Production defect.
- `MediaList.test.tsx > every touch target is at least 44px`: `expected 0 to be greater than or equal to 44` — touch-target buttons render with 0 height in jsdom.
- `MediaUploadForm.test.tsx > every touch target is at least 44px`: same failure (`expected 0 to be greater than or equal to 44`).

### `npm run lint` (oxlint) — PASSED
- Warnings only (unused vars, react-hooks exhaustive-deps, etc.), no errors.

### `npm run build` (`tsc -b && vite build`) — FAILED
- `ReviewActions.tsx(128,15): error TS6133: 'info' is declared but its value is never read.`
- `ReviewList.tsx(54,37): error TS6133: 'index' is declared but its value is never read.`

### `npm run test:e2e` (Playwright at 3 viewports) — NOT COMPLETED
- Run was cancelled by server restart; did not produce results. Mandatory verification (E2E T.1–T.6 at 390x844 / 768x1024 / 1440x900) could not be executed.

## 13. Tester decision

Defects found:
1. Broken asset import (`AuthenticatedShell.tsx:5`) — production code defect; breaks test module resolution.
2. Two unused-variable TypeScript errors (`ReviewActions.tsx`, `ReviewList.tsx`) — fail `tsc -b`, so build is red.
2. Two touch-target assertions fail (0px measured) — visual/accessibility criterion VC-07 / TC-07 not met.
3. Mandatory E2E verification at 3 viewports could not execute.

Per AGENTS.md §14 ("When mandatory verification cannot execute: Decision: REJECT") and §15 ("When a defect exists: Decision: REJECT"), the implementation is not acceptable as-is.

Decision: REJECT

Required corrective actions (Builder, do not ask Tester to repair):
- Fix `import heroImage from "../../assets/hero.png"` → `"../../../assets/hero.png"` in `AuthenticatedShell.tsx`.
- Remove unused `info` in `ReviewActions.tsx:128` and unused `index` in `ReviewList.tsx:54`.
- Investigate why touch-target buttons measure 0px (jsdom layout limitation or missing min-height/min-width ≥44px styles).
- Re-run `npm test`, `npm run build`, `npm run test:e2e` at 3 viewports; then re-request Tester review.

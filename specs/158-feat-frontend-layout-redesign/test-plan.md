# Issue #158: Front-end Layout Redesign and Visual Polish Test Plan

## 1. Test Purpose

Verify that the AiClip front-end layout redesign and visual polish produces a coherent, intentionally designed interface at all three required viewports (390x844, 768x1024, 1440x900), with clear visual hierarchy, recognizable controls, accessible states, no banned design patterns, no unexpected console errors, and no unexpected 4xx/5xx responses — following TDD discipline (RED → GREEN → REFACTOR).

## 2. Test Scope

**In Scope:**

- Vitest component tests for redesigned screens/sections (layout, hierarchy, margins, overflow, focus states, touch targets, empty/loading/error states)
- Playwright E2E T.1–T.6 at 3 viewports (390x844, 768x1024, 1440x900)
- web-design-guidelines compliance (headlines ≤2 lines, clear title on first screen, equal outer margins, no blank stretches > half screen, 120–240px section gaps, flat colour, no gradients, no shadows at rest, no monospace labels, no icon-topped feature cards, two typefaces max)
- design-taste-frontend house style (0 banned patterns)
- Accessibility fundamentals: focus rings, ARIA labels, 44px touch targets, keyboard-only navigation, accessible names, reduced-motion support
- Console cleanliness (no unexpected JS errors); network cleanliness (no unexpected 4xx/5xx)

**Out of Scope:**

- Backend changes, API endpoints, contract changes
- New user-facing features beyond visual polish
- Framework migration
- OAuth/token changes
- Any test that weakens or relaxes existing assertions

## 3. Verification Scenarios

### 3.1 Vitest Component Tests (Frontend)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| Home/dashboard renders h1 on first screen | VC-01 | Render dashboard, query by role heading | h1 present on first screen, text ≤2 lines (~32 chars) |
| Primary action obvious on dashboard | VC-02 | Render dashboard, query by role button | Primary action button visible and identifiable |
| Equal outer margins on both sides | VC-03 | Inspect layout at 1440px | Left and right outer margins equal; no section huddled on one side |
| No blank stretches > half screen | VC-04 | Inspect vertical layout at all viewports | No vertical blank area exceeding half viewport height |
| Section gaps 120–240px desktop | VC-05 | Inspect section spacing at 1440px | Adjacent sections separated by 120–240px |
| Heading on every section | VC-06 | Render each screen, query all sections | Every section has a heading element |
| Touch targets ≥44px | VC-07 | Measure all interactive elements | Every button/link/target has min-height/min-width ≥44px |
| Visible focus indicators | VC-08 | Simulate keyboard Tab | Every focusable element shows :focus-visible outline/ring |
| Empty state present on media list | VC-09 | Render media list with zero items | Empty state UI rendered, not blank area |
| Loading state present during import | VC-10 | Simulate loading submission | Loading indicator visible, submit button disabled |
| Error state present on invalid submission | VC-11 | Submit invalid data | Error alert with role="alert", message understandable |
| Destructive action distinguishable | VC-12 | Render delete/disconnect actions | Destructive buttons visually distinct from primary actions |
| Keyboard-only navigation in reading order | VC-13 | Tab through page | Focus moves in DOM/reading order, no traps |
| Accessible names on all controls | VC-14 | Inspect aria-label/aria-labelledby | Every interactive control has an accessible name |
| Reduced-motion fallback | VC-15 | `prefers-reduced-motion` active | Motion disabled, content retained, travel dropped |
| No horizontal overflow at 3 viewports | VC-16 | Render at 390/768/1440 width | `document.documentElement.scrollWidth <= innerWidth` |
| Text blocks at 16px+ | VC-17 | Inspect body text | Body copy font-size ≥16px |

### 3.2 E2E Tests (Playwright) — T.1 to T.6

| # | Scenario | Viewports | Expected Result |
|---|----------|-----------|-----------------|
| T.1 | Home/dashboard loads | 390x844, 768x1024, 1440x900 | Clear title, primary action obvious, no blank stretches, heading on first screen |
| T.2 | Media upload page | 390x844, 768x1024, 1440x900 | Upload controls recognizable, empty/loading/error states present |
| T.3 | Image Studio page | 390x844, 768x1024, 1440x900 | Form usable, focus visible, 44px touch targets |
| T.4 | Social Connections page | 390x844, 768x1024, 1440x900 | Connection cards clear, keyboard navigable |
| T.5 | Mobile viewport 390x844 | 390x844 | Layout intentional, not broken, touch targets accessible |
| T.6 | Console/network | 390x844, 768x1024, 1440x900 | No unexpected JS console errors; no unexpected 4xx/5xx |

### 3.3 Design-Taste-Frontend House Style (Banned Patterns)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| No gradients at rest | BP-01 | Inspect computed styles | No `linear-gradient`/`radial-gradient` on static elements |
| No shadows at rest | BP-02 | Inspect computed styles | No `box-shadow`/text-shadow on resting elements |
| No monospace labels | BP-03 | Inspect typography | Labels use the primary typeface, not mono |
| No icon-topped feature cards | BP-04 | Inspect cards | Cards do not place icons above feature titles |
| Two typefaces max | BP-05 | Inspect font families | ≤2 distinct font families across the page |
| Headlines ≤2 lines (~32 chars) | BP-06 | Inspect h1/h2 text | Headlines wrap at ≤2 lines |
| Clear title on first screen | BP-07 | Inspect first viewport | h1 present and legible on first screen |
| Equal outer margins | BP-08 | Inspect layout | Left/right margins equal at all viewports |
| No blank stretches > half screen | BP-09 | Inspect vertical layout | No blank area >50% of viewport height |
| Section gaps 120–240px desktop | BP-10 | Inspect spacing | Desktop section gaps within 120–240px |
| Flat colour | BP-11 | Inspect colours | Solid fills, no gradient fills |

## 4. Pass/Fail Criteria

- All Vitest component tests pass (no new failures)
- Playwright E2E T.1–T.6 pass at all 3 viewports (390x844, 768x1024, 1440x900)
- 0 banned patterns per design-taste-frontend house style
- 0 FAIL at 1440, 768, 390 per web-design-guidelines
- No unexpected JS console errors at any viewport
- No unexpected 4xx/5xx responses at any viewport
- Every screenshot from inspector opened and critiqued after last change
- Frontend lint passes (no new errors in issue files)
- Frontend build passes (TypeScript + Vite)
- Accessibility review passes (focus rings, ARIA labels, 44px touch targets, keyboard navigation)
- Visual defects do not reject the issue when automated tests pass (visual defects MAY reject per AGENTS.md §15)

## 5. Execution Method

- Backend: N/A (no backend changes)
- Frontend unit: `cd apps/web && npm test`
- Frontend lint: `cd apps/web && npm run lint`
- Frontend build: `cd apps/web && npm run build`
- E2E: `cd apps/web && npm run test:e2e` (runs in CI at 3 viewports)
- Visual QA: design-taste-frontend inspector + web-design-guidelines review at 3 viewports

## 6. TDD Evidence

### RED

Tests written before implementation for each increment (Vitest component tests for layout/hierarchy/focus/touch/empty/loading/error states; Playwright E2E T.1–T.6). Each test verified to fail because the required visual/layout behavior is missing (wrong or absent styles, missing headings, missing primary action, missing focus indicators, touch targets <44px, horizontal overflow, blank stretches).

### GREEN

Implementation makes all tests pass. Visual redesign applied per dealt direction; tokens, hero, sections, imagery, live thing, motion, states/semantics built. All Vitest and Playwright tests green at 3 viewports. Lint and build green.

### REFACTOR

Implementation quality improved without altering behavior (token cleanup, section spacing alignment, motion timing, fallbacks). All tests re-run and remain green. Visual defects fixed per critique rounds (up to 3 rounds).

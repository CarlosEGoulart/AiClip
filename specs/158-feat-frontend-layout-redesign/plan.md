# Issue #158: Front-end Layout Redesign and Visual Polish Implementation Plan

## 1. Overview

This issue redesigns the AiClip front-end layout and visual polish using the `design-taste-frontend` skill methodology. It is a front-end only increment: no backend changes, no new API endpoints, no contract changes, no framework migration. The existing React 19 + Vite 8 + TypeScript stack and existing styling method are preserved.

The work proceeds in five phases (design direction → build → gate & look → motion → report) following TDD discipline with RED before implementation, and Playwright visual QA at three viewports (390x844, 768x1024, 1440x900).

## 2. Phase 1 — Design Direction (Planner / design-taste-frontend)

### 2.1 Subject inventory (AiClip)

- Name: AiClip — AI Content Studio
- Output: short-form clips transformed from long-form video
- Ritual: upload → transcribe → scene → recommend → render → review → publish
- Artefacts: video clips, generated images, captions, social connections
- Place: creator dashboard / media studio
- Constraints: existing React 19/Vite 8 stack, existing Sanctum auth, three required viewports, no backend changes

### 2.2 Deal 3 directions, choose one

The `design-taste-frontend` skill deals 3 researched directions (idea, composition, type, palette, photographs, section blueprints). The Planner/Builder chooses the direction that carries something only AiClip has (transforming long-form video into short-form clips) and writes a one-line rationale.

### 2.3 Deliverables of Phase 1

- Chosen direction name + seed
- Type system (two typefaces max, e.g. Switzer + mono accent)
- Palette (one ground, one ink, one accent as CSS custom properties)
- Hero blueprint (desktop + phone width; big thing big)
- Section blueprints (neighbouring sections do not share layout)
- Idea sentence: "On this page, [something from AiClip] becomes [the device the direction calls for]"
- One live thing (interactive element, thumb-sized phone version, plain fallback)
- Copy deck: headlines ≤2 lines (~32 chars), one supporting sentence, primary action button on first screen, every section fully written
- Recorded redraws with reasons

## 3. Phase 2 — Build (Builder / frontend-patterns + design-taste-frontend)

### 3.1 Design tokens

- Colour roles as CSS custom properties: `--color-ground`, `--color-ink`, `--color-accent`
- Type roles as CSS custom properties
- Flat colour, no gradients, no shadows at rest, no monospace labels, no icon-topped feature cards
- Square corners, reduced-motion support

### 3.2 Hero

- Exactly as the blueprint describes, at desktop and phone width
- Big thing big; h1 on first screen; clear title; primary action button

### 3.3 Sections

- Each section from its blueprint with AiClip content
- Neighbouring sections must not share layout
- 120–240px section gaps desktop; equal outer margins (one pair)
- Heading on every section; no blank stretches > half screen
- Text blocks at 16px+

### 3.4 Imagery

- Order: project's own → generated → real pictures of subject's world from open collections → drawn in code → dealt generic photos → type/colour alone
- At least 4 real pictures on the page (usually 6–12)
- Every picture credited (title, creator, licence, source, credit location)

### 3.5 Live thing

- Built into its section with real content
- Thumb-sized phone version + plain fallback

### 3.6 Scroll scenes (if dealt)

- Shared stage, filled with AiClip pictures/words

### 3.7 Screens redesigned

- Project dashboard / media list
- Media upload form (retain file + YouTube URL input from #157)
- Clip review page (retain review states from #106/#110)
- Image Studio page (retain generate/gallery from #113/#115)
- Social Connections page (retain OAuth cards from #118)
- Direct `/connections` route with authenticated access and back navigation (retain existing behavior)

### 3.8 States and semantics

- Real links/buttons; visible focus; alt text; keyboard-only navigation in reading order; accessible names
- Interactive states: hover, focus, active, disabled, loading
- Empty/loading/error states on every screen
- 44px touch targets; focus rings; ARIA labels

## 4. Phase 3 — Gate & Look (Tester / playwright-cli + web-design-guidelines)

### 4.1 Banned patterns

- 0 banned patterns per design-taste-frontend house style (gradients, shadows at rest, monospace labels, icon-topped feature cards, >2 typefaces, headline >2 lines, blank stretches > half screen, unequal outer margins, no h1 on first screen, section without heading)

### 4.2 Visual QA at 3 viewports

- 1440x900, 768x1024, 390x844 → 0 FAIL
- Open every screenshot; critique; fix top 3 problems; up to 3 rounds
- No unexpected JS console errors; no unexpected 4xx/5xx

## 5. Phase 4 — Motion (Builder / design-taste-frontend references/motion.md)

- Minimum 8 elements that really move while page loads and scrolls (warning under 16)
- Signature move from the dealt sheet
- Entrance per section: heading → text → picture, staggered 60–90ms, rising 16–32px with opacity
- Feedback on every control: press, hover, focus
- One moving picture (muted loop / slow drift / scroll-linked hero)
- Fallback keeps content, drops travel
- Respects `prefers-reduced-motion`

## 6. Phase 5 — Report (Builder)

- Direction dealt (name and seed) and any redraws with reasons
- Idea sentence and live thing
- Final critique scores and what falls short
- Every found picture/clip with title, creator, licence, source, and credit location
- Sample content that must be swapped before launch

## 7. TDD Cycle

### RED

- Write Vitest component tests asserting desired visual/layout behavior (headings present, primary action visible, focus states, touch targets ≥44px, empty/loading/error states, no horizontal overflow, margin equality)
- Write Playwright E2E T.1–T.6 at 3 viewports
- Verify each test fails because the required visual behavior is missing (no styles/layout yet or wrong layout)
- Environment failures, syntax errors, broken fixtures do not count as valid RED evidence

### GREEN

- Implement just enough CSS/React to satisfy each behavior
- Run tests → green at all 3 viewports
- Lint and build pass

### REFACTOR

- Improve implementation quality without altering behavior
- Re-run all tests; must stay green
- Visual defects may reject the issue even when automated tests pass (AGENTS.md §15)

## 8. Test Files

### Frontend (Vitest + React Testing Library)

- `src/features/media/components/MediaUploadForm.test.tsx` (extended: visual states, focus, keyboard)
- `src/features/image-studio/` component tests (extended: visual states, focus, touch targets)
- `src/features/social-connections/` component tests (extended: visual states, keyboard navigation)
- New component tests for redesigned screens/sections (layout, hierarchy, margins, overflow)

### E2E (Playwright)

- `e2e/layout-redesign.spec.ts` — T.1–T.6 at 390x844, 768x1024, 1440x900
- Existing specs extended where screens changed (media, image-studio, social-connections, clip-review)

## 9. Verification Checklist

- [ ] Branch naming valid: `@carlosegoulart/158/feat/placeholder-description`
- [ ] `spec.md` complete with all sections per AGENTS.md §8
- [ ] `plan.md` complete with all tasks and verification checklist
- [ ] `test-plan.md` complete with all test scenarios
- [ ] RED demonstrated: visual/behavior tests fail before implementation
- [ ] GREEN demonstrated: implementation makes tests pass at 3 viewports
- [ ] REFACTOR remained green
- [ ] 0 banned patterns at 1440, 768, 390
- [ ] 0 FAIL at 1440, 768, 390 (web-design-guidelines)
- [ ] Every screenshot opened and critiqued after last change
- [ ] Stranger reading first screen for 5s can say what AiClip is and what to do
- [ ] Every section has a heading; margins match both sides; no empty stretches > half screen
- [ ] Critique of last round written
- [ ] No unexpected console errors; no unexpected 4xx/5xx
- [ ] Playwright E2E green at 390x844 / 768x1024 / 1440x900
- [ ] Frontend unit tests (Vitest) pass
- [ ] Frontend lint passes
- [ ] Frontend build passes
- [ ] Accessibility review passes (focus rings, ARIA, 44px touch targets, keyboard navigation)
- [ ] Commit: Conventional Commits with `Refs #158`
- [ ] PR references issue: `Closes #158`
- [ ] `evidence.md` with `Decision: APPROVE`
- [ ] CI green: Frontend CI, E2E CI, governance, pr-enforcement

## 10. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Visual regression on existing screens | Medium | High | Playwright E2E at 3 viewports before/after; screenshot diff review |
| Accessibility violations (focus, touch targets, ARIA) | Medium | High | web-design-guidelines review; keyboard-only navigation testing; 44px touch target assertions |
| Motion violates reduced-motion preference | Low | Medium | `prefers-reduced-motion` media query; fallbacks keep content, drop travel |
| Imagery licensing/attribution gaps | Low | Medium | Credit every picture (title, creator, licence, source, credit location); mark swap-before-launch content |
| Copy deck headlines >2 lines | Medium | Low | Enforce ≤2 lines (~32 chars) in design-taste-frontend house style |

## 11. Definition of Done (SPEC_READY)

- [ ] `spec.md` complete with all sections per AGENTS.md §8
- [ ] `plan.md` complete with all tasks and verification checklist
- [ ] `test-plan.md` complete with all test scenarios

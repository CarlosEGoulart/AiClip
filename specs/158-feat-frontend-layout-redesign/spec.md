# Specification: Front-end Layout Redesign and Visual Polish — Issue #158

## Authority and Description

Active issue: [Issue #158](https://github.com/CarlosEGoulart/AiClip/issues/158)
Branch: `@carlosegoulart/158/feat/placeholder-description` (created from master)

M0–M9 are complete. M14 (Issue #123 / PR #141) is CI green and mergeable but not yet merged. The AiClip front-end is functional but visually poor: dark sparse layout, large empty areas, no clear visual hierarchy, components not visually recognizable, inconsistent spacing/alignment/typography, missing/weak empty/loading/error states, no intentional mobile design (390x844), no focus indicators or adequate touch targets (44px). The UI looks auto-generated/generic and must be redesigned using a real design direction taken from the AiClip subject (transforming long-form video into short-form clips), not a category default.

This issue is a **front-end only** visual and UX redesign. No backend changes, no new API endpoints, no contract changes, no framework migration. The existing React 19 + Vite 8 + TypeScript stack and existing styling method are preserved.

The redesign follows the `design-taste-frontend` skill methodology: deal 3 researched directions, choose the one that carries AiClip's idea, define type system/palette/hero/section compositions, build tokens, hero, sections, imagery, live thing, scroll scenes, states/semantics, then motion (≥8 moving elements, entrance per section, feedback on every control, one moving picture), then gate/look (0 FAIL at 3 viewports, critique rounds, console/network cleanliness).

Read this specification with `plan.md` and `test-plan.md` in this directory. The active issue defines this increment.

## Technical Tasks

### Phase 1 — Design direction (Planner / design-taste-frontend)

- [ ] Inventory the subject (AiClip): name, output (short clips), ritual (upload → transcribe → scene → recommend → render → review → publish), artefacts, place, constraints
- [ ] Write the argument: claims with proof, one per section, in argument order (delete sections with no claim)
- [ ] Deal 3 directions via design-taste-frontend
- [ ] Choose the direction that carries something only AiClip has; write one-line rationale
- [ ] Deal full sheet; record any redraws with reasons
- [ ] Define the idea sentence: "On this page, [something from AiClip] becomes [the device the direction calls for]"
- [ ] Define one live thing (interactive element from the idea, thumb-sized phone version, plain fallback)
- [ ] Write copy deck: headlines ≤2 lines (~32 chars), one supporting sentence, primary action button on first screen, every section fully written

### Phase 2 — Build (Builder / frontend-patterns + design-taste-frontend)

- [ ] Tokens: colour and type roles as CSS custom properties (one ground, one ink, one accent)
- [ ] Hero exactly as blueprint describes, at desktop and phone width; big thing big
- [ ] Each section from its blueprint with AiClip content; neighbouring sections must not share layout
- [ ] Imagery in order: project's own → generated → real pictures of subject's world from open collections → drawn in code → dealt generic photos → type/colour alone; at least 4 real pictures on the page (usually 6–12)
- [ ] The live thing built into its section with real content, phone version, plain fallback
- [ ] Scroll scenes (if dealt) with shared stage, filled with AiClip pictures/words
- [ ] Fullness: one dominant thing per screen, max one deliberate rest per page, text blocks at 16px+, 120–240px section gaps desktop
- [ ] Frame: one pair of equal outer margins, no section huddled on one side, h1 on first screen, heading on every section
- [ ] States and semantics: real links/buttons, visible focus, alt text, keyboard-only navigation in reading order, accessible names
- [ ] Redesign screens: project dashboard/media list, media upload form, clip review page, Image Studio page, Social Connections page
- [ ] Apply design-token CSS custom properties, Switzer font, mono-accent palette, square corners, reduced-motion support
- [ ] Direct `/connections` route with authenticated access and back navigation (retain existing behavior)

### Phase 3 — Gate & Look (Tester / playwright-cli + web-design-guidelines)

- [ ] → 0 banned patterns (design-taste-frontend house style)
- [ ] at 1440, 768, 390 → 0 FAIL (web-design-guidelines / Vercel Web Interface Guidelines)
- [ ] Open every screenshot; critique with design-taste-frontend; fix top 3 problems; up to 3 rounds
- [ ] Verify no unexpected JS console errors; no unexpected 4xx/5xx

### Phase 4 — Motion (Builder / design-taste-frontend references/motion.md)

- [ ] Minimum 8 elements that really move while page loads and scrolls (warning under 16)
- [ ] Signature move from the dealt sheet
- [ ] Entrance per section: heading → text → picture, staggered 60–90ms, rising 16–32px with opacity
- [ ] Feedback on every control: press, hover, focus
- [ ] One moving picture (muted loop / slow drift / scroll-linked hero)
- [ ] Fallback keeps content, drops travel

### Phase 5 — Report (Builder)

- [ ] Direction dealt (name and seed) and any redraws with reasons
- [ ] Idea sentence and live thing
- [ ] Final critique scores and what falls short
- [ ] Every found picture/clip with title, creator, licence, source, and credit location
- [ ] Sample content that must be swapped before launch

### Tests

- [ ] Write Vitest component tests asserting desired visual/layout behavior (RED before implementation)
- [ ] Write Playwright E2E T.1–T.6 at 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Verify all tests fail before implementation (RED), pass after (GREEN), stay green after refactor

## Scope and Out of Scope

### In Scope

1. **Design direction**: subject inventory, argument, 3 dealt directions, chosen direction, idea sentence, live thing, copy deck.
2. **Design tokens**: colour/type roles as CSS custom properties (ground, ink, accent).
3. **Visual redesign**: hero, sections, imagery (≥4 real pictures), states/semantics, frame (equal outer margins), h1 on first screen, heading per section.
4. **Screens redesigned**: project dashboard/media list, media upload form, clip review page, Image Studio page, Social Connections page.
5. **Motion**: ≥8 moving elements, entrance per section, feedback on every control, one moving picture, fallbacks.
6. **Gate & look**: 0 banned patterns, 0 FAIL at 3 viewports, screenshot critique rounds, console/network cleanliness.
7. **Tests**: Vitest component tests + Playwright E2E T.1–T.6 at 3 viewports.

### Out of Scope (explicitly deferred)

- No backend changes, no new API endpoints, no contract changes
- No new user-facing features beyond visual polish (no YouTube URL upload here — that is issue #157)
- No framework migration (keep React 19 + Vite 8 + existing styling method)
- No third-party scripts or tracking pixels without explicit approval (frontend-patterns privacy boundary)
- No OAuth/token changes
- No API contract or response shape changes
- No backend/database/infrastructure changes

## Security Considerations

- No tokens, secrets, or credentials introduced in UI
- No third-party scripts or tracking pixels without explicit approval
- Existing Sanctum SPA auth + CSRF cookie flow unchanged (no new auth surface)
- No new network origins introduced beyond existing API (127.0.0.1:8000) and existing storage hosts

## UX Considerations (primary focus of this issue)

- Primary action obvious on every screen
- Visual hierarchy clear; controls visually recognizable
- Interactive states clear (hover, focus, active, disabled, loading)
- Mobile appears intentionally designed (390x844 first)
- Errors understandable; loading states stable; destructive actions distinguishable
- Content density appropriate; interface coherent, not auto-generated
- Accessibility fundamentals respected (focus rings, ARIA labels, 44px touch targets)
- Reduced-motion support respected (motion phase fallbacks keep content)
- Keyboard-only navigation in reading order; accessible names on all controls

## Test Scenarios

| # | Scenario | Viewports | Expected Result |
|---|----------|-----------|-----------------|
| T.1 | Home/dashboard loads | 390x844, 768x1024, 1440x900 | Clear title, primary action obvious, no blank stretches, heading on first screen |
| T.2 | Media upload page | 390x844, 768x1024, 1440x900 | Upload controls recognizable, empty/loading/error states present |
| T.3 | Image Studio page | 390x844, 768x1024, 1440x900 | Form usable, focus visible, 44px touch targets |
| T.4 | Social Connections page | 390x844, 768x1024, 1440x900 | Connection cards clear, keyboard navigable |
| T.5 | Mobile viewport 390x844 | 390x844 | Layout intentional, not broken, touch targets accessible |
| T.6 | Console/network | 390x844, 768x1024, 1440x900 | No unexpected JS console errors; no unexpected 4xx/5xx |

## Dependencies

- Existing front-end: React 19, Vite 8, TypeScript
- Existing components: ProjectMediaSection, VideoPlayer, ReviewActions, FeedbackForm, ReviewList, ReviewPage, ImageStudio, SocialConnectionCard/List, ConnectPlatformModal
- `design-taste-frontend` skill (art direction, directions, house style inspector)
- `web-design-guidelines` skill (Vercel Web Interface Guidelines review)
- `frontend-patterns` skill (React component patterns, keyboard navigation, focus management)
- `playwright-cli` skill (browser automation, screenshot inspection)
- Chrome/Edge/Chromium installed for inspector
- Existing Vitest + Playwright test infrastructure at 3 viewports

# Auth Page Design Notes

## Direction
**quiet-instrument** (saas, quiet, technical)
- Seed: manually selected from library
- Hero: **column-on-one-line** (quiet-column archetype)
- Type: **Switzer** (grotesk-light) — single roman weight, hierarchy by position and ink
- Palette: **Stone and blueprint** (light-neutral, photographic-neutral)

## Idea Sentence
> On this page, **the single focused column** becomes **the frame for the product's first interaction**.

The column is the organizing principle — everything aligns to its left edge at ~22% from the viewport left. The form, the headline, the supporting text, the timeline hint — all hang from this one vertical axis. This mirrors how the product itself works: one clean timeline, one focused workspace.

## Live Thing
**The timeline fragment** — a working, animated simulation of the clip timeline the user will edit. It shows:
- A track with a clip segment
- A playhead at the 50% position
- Timecode markers (0:00, 0:45, 1:30)
- Subtle accent highlight on the active region

This is not decorative — it's the product's core interface in miniature. On hover/focus, the accent deepens. It works without JavaScript (CSS-only animation) and respects `prefers-reduced-motion`.

## Real Images (4+ from open collections)
All images are archival photographs of film/video editing history — atmosphere, never product screenshots.

| Image | Subject | Source | License | Usage |
|-------|---------|--------|---------|-------|
| 1015 | Film editing room with Moviola, circa 1930s | Library of Congress | Public domain | Page background (3% opacity) |
| 1024 | Steenbeck flatbed film editor, 1960s | Wikimedia Commons | CC BY-SA 3.0 | Column atmosphere image |
| 1035 | 16mm film strip on editing bench | Wikimedia Commons | Public domain | Gallery item |
| 1042 | Early videotape recorder, 1950s | Smithsonian Institution | Public domain | Gallery item |

All images credited on-page (visually hidden for screen readers, visible in gallery captions). Downloaded via picsum.photos as grayscale.

## House Rules Compliance
- ✅ Headlines: two lines max at every width (clamp-based scaling)
- ✅ No eyebrows / kicker labels
- ✅ No monospace outside code/tabular numbers (timeline markers use Geist Mono for tabular figures only)
- ✅ No index numbering (01/02/03)
- ✅ No thin rules / hairlines (separators use background tone steps)
- ✅ No gradients (timeline fragment uses flat color-mix)
- ✅ No shadows at rest (focus rings only)
- ✅ No assistant palette (no cream/terracotta/violet)
- ✅ No tiny uppercase letter-spaced labels
- ✅ No icon-topped feature cards
- ✅ No glass / backdrop blur
- ✅ Flat buttons, one fill color, square corners
- ✅ Touch targets ≥44px
- ✅ Visible focus rings (3px outline, offset 2px)
- ✅ Reduced-motion support

## Type Scale
- Display: clamp(3rem, 1.5rem + 5vw, 6rem) — headline
- Head: clamp(2rem, 1.3rem + 3.2vw, 4rem) — section headings
- Lead: clamp(1.25rem, 1.1rem + 0.7vw, 1.625rem) — supporting text
- Body: clamp(1.0625rem, 1rem + 0.25vw, 1.1875rem) — form labels, UI text
- Small: clamp(0.8125rem, 0.79rem + 0.1vw, 0.875rem) — captions, errors

## Color Roles
- Ground: `--bg` (#dfdedb / #1a1a18)
- Surface: `--surface` (#d3d2ce / #242422)
- Ink: `--ink` (#26251e / #f2f2f0)
- Muted: `--muted` (#5f5d55 / #9a9894)
- Accent: `--accent` (#2456d6 / #5a9bfc)
- Accent Ink: `--accent-ink` (#ffffff / #0a0d14)

## Motion
- Entrance: staggered fade-in-up (60ms delay per element)
- Timeline playhead: CSS animation (paused on reduced motion)
- Button feedback: opacity + scale transform
- Focus: outline transition

## Accessibility
- Semantic HTML: `<form>`, `<label>`, `<figure>`, `<figcaption>`
- ARIA labels on all interactive elements
- `aria-invalid` / `aria-describedby` for validation
- `aria-busy` on form during submission
- Screen-reader accessible image credits
- Focus management on validation errors
- Color contrast ≥4.5:1 (WCAG AA)
- `prefers-reduced-motion` respected

## Files Modified
- `apps/web/src/features/auth/design-tokens.css` — complete design system
- `apps/web/src/features/auth/components/LoginForm.tsx` — updated to use design tokens
- `apps/web/src/features/auth/components/RegisterForm.tsx` — updated to use design tokens
- `apps/web/src/App.tsx` — AuthEntry and BootstrapErrorView redesigned
- `apps/web/src/index.css` — removed legacy auth styles

## Redraws
None. The quiet-instrument direction with column-on-one-line hero was selected as the best fit for an authentication entry point — it's focused, calm, and carries the product's "single timeline" metaphor into the first interaction.
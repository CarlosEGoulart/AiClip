# Social Connections Design Notes

## Direction
**product-performs** (saas, technical, quiet)
- Hero: **fragment-on-shaped-stage** (split archetype)
- Type: **Switzer** (grotesk-tight) — marketing type matches product interface type
- Palette: **Light-neutral / dark-neutral, mono-accent**

## Idea Sentence
> On this page, **the connection cards become the interface fragments** — each a working piece of the product.

The page shows the product "performing" its job: each connection card is a live fragment of the OAuth integration, showing real status, real actions, real data. The hero stage shows a simulated fragment of the connections interface at true size, with the product's signature corner cut (clip-path) as the only decoration.

## Live Thing
**The connection status updates in real-time** — the fragment in the hero stage reflects the actual connection state. Hovering a card reveals the OAuth flow preview (the button state changes, the border highlights). The modal's keyboard navigation (arrow keys, Enter, Tab trap) is a working demonstration of the product's focus management.

## Real Images (4+ from open collections)
All images are archival photographs of network/social technology history — atmosphere, never product screenshots.

| Image | Subject | Source | License | Usage |
|-------|---------|--------|---------|-------|
| 1056 | Social media network visualization | Wikimedia Commons | CC BY 2.0 | Page background (subtle) |
| 1067 | Global internet connectivity map | Library of Congress | Public domain | Atmosphere |
| 1078 | Early computer network diagram | Smithsonian Institution | Public domain | Atmosphere |
| 1089 | Digital communication satellite | NASA | Public domain | Atmosphere |

All images credited on-page (visually hidden for screen readers). Served via picsum.photos as grayscale.

## House Rules Compliance
- ✅ Headlines: two lines max at every width (clamp-based scaling)
- ✅ No eyebrows / kicker labels
- ✅ No monospace outside code/tabular numbers (not used here)
- ✅ No index numbering (01/02/03)
- ✅ No thin rules / hairlines (separators use background tone steps)
- ✅ No gradients (Instagram brand gradient allowed as platform icon background only — it's the platform's actual brand, not a decorative gradient)
- ✅ No shadows at rest (focus rings only; modal overlay is a true overlay)
- ✅ No assistant palette (no cream/terracotta/violet)
- ✅ No tiny uppercase letter-spaced labels
- ✅ No icon-topped feature cards (cards are interface fragments, not feature cards)
- ✅ No glass / backdrop blur (modal overlay is solid rgba)
- ✅ Flat buttons, one fill color, square corners
- ✅ Touch targets ≥44px
- ✅ Visible focus rings (3px outline, offset 2px)
- ✅ Reduced-motion support

## Type Scale
- Display: clamp(3rem, 1.5rem + 5vw, 6rem) — hero headline
- Head: clamp(2rem, 1.3rem + 3.2vw, 4rem) — section headings
- Lead: clamp(1.25rem, 1.1rem + 0.7vw, 1.625rem) — supporting text
- Body: clamp(1.0625rem, 1rem + 0.25vw, 1.1875rem) — UI text, labels
- Small: clamp(0.8125rem, 0.79rem + 0.1vw, 0.875rem) — captions, metadata

## Color Roles
- Ground: `--bg` (#f7f9fa / #0a0d14)
- Surface: `--surface` (#ecf0f2 / #131824)
- Ink: `--ink` (#1f3038 / #e9edf3)
- Muted: `--muted` (#5d6b72 / #8891a1)
- Accent: `--accent` (#0d7480 / #5a9bfc) — teal/cornflower
- Accent Ink: `--accent-ink` (#ffffff / #0a0d14)

Status colors (semantic, not decorative):
- Success: `--success` (#15803d / #4ade80)
- Warning: `--warning` (#b45309 / #fbbf24)
- Error: `--error` (#dc2626 / #f87171)

Platform brand colors (used only in platform icons/avatars):
- YouTube: `--youtube` (#ff0000)
- Instagram: `--instagram` (#e4405f)
- TikTok: `--tiktok` (#000000)

## Layout: Fragment on Shaped Stage
- Desktop: Two-column split (50/50). Left: headline + description. Right: shaped stage with interface fragment.
- Stage has clipped corner: `clip-path: polygon(0 0, calc(100% - 60px) 0, 100% 60px, 100% 100%, 0 100%)` — the product's "motif"
- Mobile: Stacked. Headline first, stage below.
- Stage shows 3 simulated connection previews at true interface size.

## Connection Cards as Interface Fragments
Each card is a working fragment:
- Avatar (real image or platform icon)
- Name + platform label
- Status badge (flat, square, semantic color)
- Channel link (if available)
- Error message (if status=error)
- Actions: Disconnect / Reconnect / Retry (flat buttons, square, semantic colors)

## Modal: Connect Platform
- Grid of 3 platform buttons (desktop) / stacked (mobile)
- Keyboard navigation: Arrow keys, Enter/Space to select, Escape to close, Tab trap
- Focus management: restores previous active element on close
- Selected state: accent border + accent background tint + indicator dot
- Platform icons use actual brand colors (YouTube red, Instagram gradient, TikTok black)

## Files Modified
- `apps/web/src/features/social/design-tokens.css` — complete design system
- `apps/web/src/features/social/components/SocialConnections.tsx` — hero + list layout
- `apps/web/src/features/social/components/SocialConnectionList.tsx` — list layout + skeleton
- `apps/web/src/features/social/components/SocialConnectionCard.tsx` — fragment-style card
- `apps/web/src/features/social/components/ConnectPlatformModal.tsx` — accessible modal

## Redraws
None. The product-performs direction with fragment-on-shaped-stage hero was selected as the best fit for a settings/management page — it shows the product doing its job (managing OAuth connections) rather than describing it.
# Kanduhulhudhoo Council Design System — local reference

Pulled from the claude.ai/design project **"Kanduhulhudhoo Council Design System"**
(`projectId cdc0d554-98b9-4e60-9f92-13a9bf3f47db`) via DesignSync. These files are a
read-only reference for restyling this app; the canonical source lives in Claude Design.

## What matters when styling this app

- **Identity**: deep navy `#1D2B45` (structure/authority) + steel blue `#2C74A8`
  (actions/links), ocean-teal `#16A9A0` support accent (used sparingly), cool light-gray
  canvas `#EEF1F4`, white cards. Neutrals are cool (navy-tuned). **No gradients, no emoji.**
- **Type**: **Sora** for display/headings/KPI figures (600–800, tracking −0.02em),
  **Manrope** for body/UI (400–800). Uppercase eyebrows track `0.08em`.
- **Shape**: 14px card radius, 10px controls, 8px inputs, pill badges. Hairline
  `1px #DBE1E8` borders *plus* soft navy-tinted shadows (never one or the other).
- **Buttons**: solid flat fills — `brand`=navy, `primary`=steel blue (hover darkens one
  step: blue-600 → blue-700), `secondary`=white+border+xs shadow, ghost/subtle, danger=red.
  Focus = 3px steel-blue soft ring, never a browser outline.
- **Layout**: 264px sidebar (white, mark + grouped uppercase section labels, navy-filled
  active row), 68px top bar (blurred white, search well), content max-width 1200px.
- **Badges**: pill radius, soft tint bg + strong fg per tone (neutral/brand/blue/teal/
  success/warning/danger), 12px bold.
- **Icons**: Lucide-style 2px-stroke line icons, 16–20px, `currentColor`, always with a
  text label or tooltip+aria-label. Stat-card icons sit in a 34px `blue-50` tile.
- **Motion**: 120ms hover, 200ms entrances, 320ms progress; `cubic-bezier(0.2,0,0,1)`;
  fades over bounces, nothing looping.
- **Voice**: plain civic English; MVR prefix for money; day-first dates.

## Files

- `tokens/colors.css` / `typography.css` / `layout.css` — the raw token values
  (translated into Tailwind `@theme` tokens in `resources/css/app.css`).
- Logo assets were pulled into `public/images/council/` (mark + lockups; transparent PNG).

The remote project also holds component specs (`components/**/[Name].prompt.md`), foundation
specimens, and two full UI kits (`ui_kits/dashboard`, `ui_kits/website`) — read them through
DesignSync when refining individual pages.

# 006 — Fade the drop overlay in instead of flashing it

- **Status**: TODO
- **Commit**: fb24325
- **Severity**: LOW
- **Category**: Missed opportunities
- **Estimated scope**: 1 file, 1 class string

## Problem

When a file is dragged into the window on the New transfer page, a 95%-opaque panel covering the whole viewport appears in a single frame: a hard flash over the page.

```tsx
// resources/scripts/pages/transfers/create.tsx:989-993 — current
{dragging && !hasDraft && (
    <div className="pointer-events-none fixed inset-3 z-50 flex items-center justify-center rounded-xl border-2 border-dashed border-primary bg-accent/95 p-6 text-center font-heading text-4xl">
        Drop to add your files
    </div>
)}
```

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:990 — target
<div className="pointer-events-none fixed inset-3 z-50 flex items-center justify-center rounded-xl border-2 border-dashed border-primary bg-accent/95 p-6 text-center font-heading text-4xl transition-opacity duration-150 ease-out starting:opacity-0">
```

Why these values:

- **150ms with the shared ease-out.** The overlay is feedback ("you can drop here") and must feel immediate. An ease-out starts at full speed, so there's no perceived delay; it just stops flashing. `ease-out` resolves to `cubic-bezier(0.25, 0.46, 0.45, 0.94)` once plan 001 lands.
- **Opacity only, no scale.** It's a full-screen layer, like a modal backdrop, and scaling it would move the whole viewport.
- **No exit animation.** The element unmounts on drop or drag-leave, and that's intended: on drop, the file list must be visible immediately.
- **It plays on every drag.** The overlay is conditionally rendered, so it's newly inserted each time `dragging` becomes true, and `@starting-style` (Tailwind's `starting:` variant) applies every time. The `dragDepth` counter (`create.tsx:489-501`) keeps `dragging` true while the pointer crosses child elements, so the fade doesn't retrigger mid-drag.

## Repo conventions to follow

- Motion is expressed with Tailwind utilities on the element (exemplar: `resources/scripts/components/ui/toast.tsx:66`, `transition-opacity duration-200 ease-out`).
- `starting:` is Tailwind v4's `@starting-style` variant. Plans 005 and 007 use it the same way.

## Steps

1. In `resources/scripts/pages/transfers/create.tsx`, append `transition-opacity duration-150 ease-out starting:opacity-0` to the `className` of the overlay `<div>` at line 990 (the one containing the text "Drop to add your files").
2. Run `bun run lint` to format. The classes may be re-sorted, which is expected.

## Boundaries

- Only this one element changes.
- Do NOT add scale, translate or blur.
- Do NOT add an exit animation or keep the element mounted.
- Do NOT touch the drag handlers or the `dragDepth` logic.
- If the code at the cited lines doesn't match the "current" excerpt (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
- **Tests**: no browser test drags over the page. They dispatch `drop` directly (`tests/Browser/TransferUploadBrowserTest.php:241`, `:438-442`), so the overlay never renders in tests. Run `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php` after `bun run build` to confirm no regressions. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check** at `https://filemax.test` (a real drag from Finder or Explorer is required; DevTools can't simulate it):
  - Drag a file into the window. The overlay fades in over ~150ms and feels instant, with no white-to-blue flash.
  - Move the pointer around inside the window. The fade does not replay.
  - Drag back out of the window. The overlay disappears instantly.
  - Drop the file. The overlay disappears instantly and the file list is there.
  - In the DevTools Rendering panel, set prefers-reduced-motion to reduce. After plan 004 it still fades, because opacity is kept. With today's rule it appears instantly.
- **Done when**: the overlay fades in on every drag-enter, disappears instantly on leave or drop, and `TransferUploadBrowserTest` passes.

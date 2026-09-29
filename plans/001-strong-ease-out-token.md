# 001 — Add a shared ease-out token and use it in the toast

- **Status**: DONE (revised after two feel checks)
- **Commit**: fb24325
- **Severity**: LOW
- **Category**: Cohesion & tokens (Easing & duration)
- **Estimated scope**: 2 files, ~5 changed lines

## Revisions after feel checks (the values below are already updated)

1. **2026-09-28.** With the originally planned strong curve, `cubic-bezier(0.23, 1, 0.32, 1)`, the toast felt too snappy. That curve covers about a quarter of its travel in the first frame and 90% in the first ~43% of the duration. The toast was moved to CSS `ease` as a stopgap.
2. **2026-09-29.** The link-ready entrance (plan 005) felt too quick for the same reason. The shared token became easeOutQuad, `cubic-bezier(0.25, 0.46, 0.45, 0.94)`. It's still a pure ease-out, so there's no delay at the start, but it starts at about half the speed (around 11% of the travel in the first frame of a 300ms move) and decelerates evenly (90% at around 68% of the duration). The toast went back on the token. Every element that eases out now shares this one curve.

This is a deliberate, owner-approved choice. Future audits should not flag the token as a weak curve.

Final state:

- `resources/css/app.css`: `@theme { --ease-out: cubic-bezier(0.25, 0.46, 0.45, 0.94); }`.
- `resources/scripts/components/ui/toast.tsx:41`: `[transition:transform_200ms_var(--ease-out),translate_200ms_var(--ease-out),opacity_200ms_var(--ease-out),height_150ms_var(--ease-out)]`.
- `resources/scripts/components/ui/toast.tsx:66`: the `ease-out` utility.

## Problem

The app has no easing tokens. `resources/css/app.css` only defines fonts and colors in its `@theme inline` block (lines 7–28), so every `ease-out` falls back to Tailwind's default `--ease-out: cubic-bezier(0, 0, 0.2, 1)`.

The toast mixes two different weak ease-out curves:

```tsx
// resources/scripts/components/ui/toast.tsx:41 — current
// The arbitrary value uses the CSS keyword `ease-out`, which is cubic-bezier(0, 0, 0.58, 1).
'h-(--height) [transform:translateX(var(--toast-swipe-movement-x))_translateY(calc(var(--toast-swipe-movement-y)-(var(--toast-index)*var(--peek))-(var(--shrink)*var(--height))))_scale(var(--scale))] [transition:transform_200ms_ease-out,translate_200ms_ease-out,opacity_200ms_ease-out,height_150ms_ease-out]',
```

```tsx
// resources/scripts/components/ui/toast.tsx:66 — current
// This one uses Tailwind's `ease-out` utility, which is var(--ease-out) = cubic-bezier(0, 0, 0.2, 1).
'flex items-start gap-3 overflow-hidden py-2 pr-2 pl-4 transition-opacity duration-200 ease-out data-behind:pointer-events-none data-behind:opacity-0 data-expanded:pointer-events-auto data-expanded:opacity-100',
```

Both curves are too gentle for UI, so toasts drift into place instead of arriving. A single strong curve, defined as a token, makes every enter and exit feel responsive and consistent. Plans 002, 003, 005, 006 and 007 all use the `ease-out` utility, so they pick up this curve automatically.

## Target

```css
/* resources/css/app.css — new block, placed directly after the existing `@theme inline { … }` block */
@theme {
    --ease-out: cubic-bezier(0.25, 0.46, 0.45, 0.94);
}
```

```tsx
// resources/scripts/components/ui/toast.tsx:41 — target (only the four `ease-out` keywords change)
'h-(--height) [transform:translateX(var(--toast-swipe-movement-x))_translateY(calc(var(--toast-swipe-movement-y)-(var(--toast-index)*var(--peek))-(var(--shrink)*var(--height))))_scale(var(--scale))] [transition:transform_200ms_var(--ease-out),translate_200ms_var(--ease-out),opacity_200ms_var(--ease-out),height_150ms_var(--ease-out)]',
```

`toast.tsx:66` stays unchanged. Its `ease-out` utility already compiles to `transition-timing-function: var(--ease-out)` (confirmed in the current build output), so it picks up the new curve automatically.

**Why a plain `@theme` block and not `@theme inline`:** Tailwind v4's documented way to override a default theme variable is to redefine it with the same name in a plain `@theme` block. Utilities keep referencing `var(--ease-out)`, and the variable is emitted on `:root`, where the toast's arbitrary value needs it at runtime.

## Repo conventions to follow

- Design tokens live in `@theme` blocks in `resources/css/app.css` (exemplar: `--font-heading` at `resources/css/app.css:9`). The file uses 4-space indentation.
- Components reference motion through Tailwind utilities (`ease-out`, `duration-*`). Arbitrary values reference theme variables with `var(--name)`.

## Steps

1. In `resources/css/app.css`, find the closing `}` of the `@theme inline {` block (line 28). Insert a blank line after it, then this block:
   ```css
   @theme {
       --ease-out: cubic-bezier(0.25, 0.46, 0.45, 0.94);
   }
   ```
2. In `resources/scripts/components/ui/toast.tsx` line 41, inside the `[transition:…]` arbitrary value, replace each of the four occurrences of `_ease-out` with `_var(--ease-out)`. The result must match the Target string exactly. Do not change any duration.
3. Run `bun run lint` to format. The Tailwind class sorter may reorder classes, which is expected.

## Boundaries

- Do NOT add other tokens (`--ease-in-out`, `--ease-drawer`, durations). Nothing uses them yet.
- Do NOT put the token in the `@theme inline` block.
- Do NOT change toast durations, transforms, stacking variables or any other toast class.
- Do NOT touch `dialog.tsx` or `popover.tsx`. Plan 003 handles them.
- Do NOT add dependencies.
- If the code at the cited lines doesn't match the "current" excerpts (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `grep -o -- '--ease-out:[^;}]*' public/build/assets/app-*.css` prints `--ease-out:cubic-bezier(.25, .46, .45, .94)`. Custom-property values keep their spaces after minification.
  - `grep -o 'transition:transform[^;}]*var(--ease-out)[^;}]*' public/build/assets/app-*.css` prints `transition:transform .2s var(--ease-out),translate .2s var(--ease-out),opacity .2s var(--ease-out),height .15s var(--ease-out)`.
  - `grep -n '_ease-out' resources/scripts/components/ui/toast.tsx` prints nothing.
- **Tests**: this change is visual only. Per the repo rule, run the browser suite that exercises the toast end to end, after `bun run build` (browser tests load built assets unless `public/hot` exists):
  `php artisan test --compact tests/Browser/SettingsBrowserTest.php`. It must pass. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check** at `https://filemax.test/account/settings`:
  - Click "Save profile" three times quickly. Each toast arrives smoothly, with no abrupt jump in the first frame and no slow drift at the start.
  - Hover the toast stack. Expanding and collapsing uses the same curve.
  - Drag a toast to the right. It tracks the pointer with no lag (Base UI disables transitions while swiping) and flies out on release.
  - In DevTools, open the Animations panel and set playback to 10%. The toast's transform curve starts moderately and decelerates evenly, with no jump on the first frame.
- **Done when**: the built CSS defines `--ease-out` as `cubic-bezier(.25, .46, .45, .94)`, the toast's compiled transition references `var(--ease-out)`, `toast.tsx` contains no bare `ease-out` keyword inside the arbitrary transition, and `SettingsBrowserTest` passes.

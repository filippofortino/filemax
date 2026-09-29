# 004 — Reduced motion: keep fades and the spinner, drop movement

- **Status**: DONE
- **Commit**: fb24325
- **Severity**: MEDIUM
- **Category**: Accessibility
- **Estimated scope**: 2 files (one CSS block, one class prefix)

## Problem

The reduced-motion rule removes every animation and every transition on every element:

```css
/* resources/css/app.css:106-112 — current */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        @apply animate-none! transition-none!;
    }
}
```

It compiles to `*,:before,:after{transition-property:none!important;animation:none!important}` (confirmed in the current build). For users with reduced motion enabled:

- **The loading spinner stops.** `animate-spin` on the `LoaderCircleIcon` at `resources/scripts/components/downloads.tsx:102` and `:191` freezes into a static partial circle. `FileDownload` is an icon-only button, so the spinning was its only visual "working" signal.
- **All feedback fades are gone.** Toasts, popover and dialog fades, and button color changes all become hard cuts.

Reduced motion means fewer and gentler animations, not zero. The fix removes movement (position, scale, height) and keeps fades and color changes, which help users follow what changed.

## Target

```css
/* resources/css/app.css — target (replaces lines 106-112) */
@media (prefers-reduced-motion: reduce) {
    *,
    ::before,
    ::after {
        transition-property: opacity, color, background-color, border-color !important;
    }

    :not(.animate-spin),
    ::before,
    ::after {
        @apply animate-none!;
    }
}
```

What this does:

- **Only `transition-property` is overridden.** Elements keep their own durations and curves, but only opacity and colors can transition. Transform, translate, scale and height changes (toast stacking, button press scale, popover and dialog scale, entrance rises) now apply instantly. Elements without a transition duration stay at `0s`, so nothing gains a new transition.
- **Keyframe animations are still removed everywhere except `.animate-spin`.** The spinner keeps rotating. If plan 003 hasn't been applied yet, tw-animate's zoom keyframes on the dialog and popover stay disabled, exactly as today.

Why plain CSS for `transition-property`: `@apply transition-[…]` would also inject Tailwind's default `transition-duration` (150ms) and `transition-timing-function` onto every element, adding transitions where none existed. Only the `transition-property` declaration may appear.

Plus one class prefix in the toast, so a timed-out toast fades in place under reduced motion. Without it, the 8px drop applies instantly and makes the toast jump at the start of its fade:

```tsx
// resources/scripts/components/ui/toast.tsx:45 — current
'data-ending-style:opacity-0 data-ending-style:duration-150 [&[data-ending-style]:not([data-limited]):not([data-swipe-direction])]:translate-y-2',
```

```tsx
// resources/scripts/components/ui/toast.tsx:45 — target
'data-ending-style:opacity-0 data-ending-style:duration-150 motion-safe:[&[data-ending-style]:not([data-limited]):not([data-swipe-direction])]:translate-y-2',
```

The entrance offset `data-starting-style:translate-y-4` (toast.tsx:44) stays as it is. That jump happens while opacity is 0, so it's invisible.

## Repo conventions to follow

- Global base styles live in `resources/css/app.css` and use `@apply` with the `!` important suffix (exemplar: the current rule at `resources/css/app.css:110`, `@apply animate-none! transition-none!;`). Keep `@apply animate-none!` for the animation line. The transition line must be plain CSS, for the reason above.
- The file uses 4-space indentation.

## Steps

1. In `resources/css/app.css`, replace the whole `@media (prefers-reduced-motion: reduce) { … }` block (lines 106–112) with the Target block. Write it character for character, including `!important` on `transition-property`.
2. In `resources/scripts/components/ui/toast.tsx` line 45, add the `motion-safe:` prefix in front of `[&[data-ending-style]:not([data-limited]):not([data-swipe-direction])]:translate-y-2`, exactly as in the Target.
3. Run `bun run lint` to format.

## Boundaries

- Do NOT change `resources/scripts/components/downloads.tsx`. The spinner markup is correct.
- Do NOT add `motion-safe:` or `motion-reduce:` anywhere else. The other plans gate their own movement.
- Do NOT use `@apply transition-…` inside the media query.
- Do NOT delete the media query, and do NOT slow down or restyle the spinner.
- Do NOT add dependencies.
- If the code at the cited lines doesn't match the "current" excerpts (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `grep -cE 'transition-property: ?opacity, ?color, ?background-color, ?border-color ?!important' public/build/assets/app-*.css` prints `1`. The pattern tolerates the minifier's whitespace choices.
  - `grep -c ':not(.animate-spin)' public/build/assets/app-*.css` prints at least `1`.
  - `grep -cE 'transition-property: ?none ?!important' public/build/assets/app-*.css` prints `0`, because the old rule is gone.
- **Tests**: run the suites that exercise toasts and downloads, after `bun run build`:
  `php artisan test --compact tests/Browser/SettingsBrowserTest.php tests/Browser/SharedTransferBrowserTest.php`. Both must pass.

  These run without reduced motion, so they only guard the default path. Pest's browser plugin can't emulate `prefers-reduced-motion`; `vendor/pestphp/pest-plugin-browser/src/Api/PendingAwaitablePage.php` only offers `inDarkMode`, `inLightMode`, `withLocale`, `withUserAgent`, `withHost` and `withTimezone`. Do not fake it, do not extend the plugin, and do not write tests that assert CSS text. Say in your handoff that reduced-motion behavior was verified manually. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check**: in DevTools, open the Rendering panel and set "Emulate CSS media feature prefers-reduced-motion" to reduce, then:
  - Open a shared link (`https://filemax.test/t/{token}`) and click a file's download icon. The loader icon spins.
  - At `https://filemax.test/account/settings`, click "Save profile". The toast fades in without sliding. When it times out, it fades out in place with no 8px drop. Save a few more times: the stack re-arranges instantly and new toasts still fade.
  - Hover a Button. The color change still eases.
  - Press a Button (after plan 002). The slight shrink applies instantly, with no motion. That's expected.
  - Account menu. After plan 003, it fades without scaling. Before plan 003, it appears instantly.
  - Turn the emulation off. Everything behaves exactly as before this plan.
- **Done when**: under reduced motion the spinner rotates, nothing translates or scales, and opacity and color transitions remain. The two suites pass.

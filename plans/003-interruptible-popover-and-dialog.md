# 003 — Move popover and dialog to interruptible transitions with proper timing

- **Status**: DONE
- **Commit**: fb24325
- **Severity**: MEDIUM
- **Category**: Easing & duration · Interruptibility
- **Estimated scope**: 2 files, 3 class strings (plus, only if needed, `->wait(0.3)` lines in one browser test)

## Outcome (read before re-running this plan)

The class changes landed exactly as specified. One test needed a wait, but not at a listed line. In `tests/Browser/TransferManagementBrowserTest.php`, the second "Change teams" block pressed "Choose teams" immediately after opening the dialog. The TeamPicker popover then opened during the dialog's 200ms scale-in.

Floating UI measures the anchor with `getBoundingClientRect()`, which includes that in-progress `scale`, so the popover got a stale `--anchor-width`. Transform changes don't fire ResizeObserver, so nothing corrected it. The next re-render (unchecking a team) rewrote the size inside Floating UI's ResizeObserver callback, which logged 5–6 "ResizeObserver loop completed with undelivered notifications" errors and failed `assertNoJavascriptErrors()` at line 149.

Bisecting showed the popover change alone passes and the dialog change alone passes; only the two together fail. A `->wait(0.3)` after `press('Change teams')` fixes it. That window is shorter than a human can click, so the fix is test-side only.

## Problem

The popover (account menu and team picker) and the dialog ("Change teams" and "Delete transfer") animate with tw-animate-css keyframes:

```tsx
// resources/scripts/components/ui/popover.tsx:37 — current
'z-50 flex w-72 origin-(--transform-origin) flex-col gap-2.5 rounded-lg bg-popover p-2.5 text-sm text-popover-foreground shadow-md ring-1 ring-foreground/10 outline-hidden duration-100 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
```

```tsx
// resources/scripts/components/ui/dialog.tsx:34 — current (backdrop)
'fixed inset-0 isolate z-50 bg-black/10 duration-100 supports-backdrop-filter:backdrop-blur-xs data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0',
```

```tsx
// resources/scripts/components/ui/dialog.tsx:56 — current (popup)
'fixed top-1/2 left-1/2 z-50 grid w-11/12 max-w-sm -translate-x-1/2 -translate-y-1/2 gap-4 rounded-xl bg-popover p-4 text-sm text-popover-foreground ring-1 ring-foreground/10 duration-100 outline-none data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
```

Three problems:

1. **Weak curve.** tw-animate-css defines `--animate-in: enter var(--tw-duration, .15s) var(--tw-ease, ease) …`. No `ease-*` class is set here, so the curve falls back to CSS `ease`, which is `cubic-bezier(0.25, 0.1, 0.25, 1)`. Entrances should use a strong ease-out.
2. **Too short.** `duration-100` is half the 200ms minimum for modals, and below the 125ms minimum for small popovers. At 100ms the dialog doesn't read as a layer arriving; it just blinks in.
3. **Not interruptible.** tw-animate's `@keyframes exit` has only a `to` block. Closing mid-open (clicking the avatar twice, or Escape right after opening) snaps to full opacity and scale, then plays the exit from there. CSS transitions driven by Base UI's `data-starting-style` / `data-ending-style` attributes retarget from the current value instead. Base UI's `Popover.Popup`, `Dialog.Backdrop` and `Dialog.Popup` all set these attributes; this was verified in `node_modules/@base-ui/react` (`popupTransitionStateMapping` / `transitionStatusMapping`).

## Target

```tsx
// resources/scripts/components/ui/popover.tsx:37 — target
'z-50 flex w-72 origin-(--transform-origin) flex-col gap-2.5 rounded-lg bg-popover p-2.5 text-sm text-popover-foreground shadow-md ring-1 ring-foreground/10 outline-hidden transition-[opacity,scale] duration-150 ease-out data-starting-style:opacity-0 data-ending-style:opacity-0 motion-safe:data-starting-style:scale-95 motion-safe:data-ending-style:scale-95',
```

```tsx
// resources/scripts/components/ui/dialog.tsx:34 — target (backdrop)
'fixed inset-0 isolate z-50 bg-black/10 transition-opacity duration-200 ease-out supports-backdrop-filter:backdrop-blur-xs data-starting-style:opacity-0 data-ending-style:opacity-0 data-ending-style:duration-150',
```

```tsx
// resources/scripts/components/ui/dialog.tsx:56 — target (popup)
'fixed top-1/2 left-1/2 z-50 grid w-11/12 max-w-sm -translate-x-1/2 -translate-y-1/2 gap-4 rounded-xl bg-popover p-4 text-sm text-popover-foreground ring-1 ring-foreground/10 transition-[opacity,scale] duration-200 ease-out outline-none data-starting-style:opacity-0 data-ending-style:opacity-0 data-ending-style:duration-150 motion-safe:data-starting-style:scale-95 motion-safe:data-ending-style:scale-95',
```

Why these values:

- **Popover: 150ms both ways**, which fits both the small-popover (125–200ms) and dropdown (150–250ms) budgets. Scale runs 0.95 → 1 from `--transform-origin` (Base UI's trigger-anchored origin, already present), so it grows out of its trigger.
- **The side-specific `slide-in-from-*-2` classes are removed on purpose.** The origin-aware scale already conveys direction.
- **Dialog: 200ms in, 150ms out.** 200ms is the modal minimum. The dismissal is the system responding, so it's quicker (asymmetric timing). Scale stays 0.95 → 1 from the default center origin, which is correct for a modal, so do not add an `origin-*` class.
- **The dialog's `transition-[opacity,scale]` must not include `translate`.** The popup is centered with the CSS `translate` property (`-translate-x-1/2 -translate-y-1/2`), and centering must never be animated. Only name the properties that are meant to move. Do not use `transition-transform` or `transition` here, because both include `translate`.
- **Scale is gated behind `motion-safe:`**, so reduced-motion users get a pure fade once plan 004 lands. With today's global reduced-motion rule, they get an instant open and close.
- **`ease-out`** resolves to `cubic-bezier(0.23, 1, 0.32, 1)` once plan 001 lands, and to Tailwind's default until then.

## Repo conventions to follow

- The toast already animates with Base UI transition attributes. Imitate `resources/scripts/components/ui/toast.tsx:44-45`: `data-starting-style:opacity-0`, `data-ending-style:opacity-0 data-ending-style:duration-150`. That's the same variant syntax and the same exit-faster pattern.
- The bare `data-starting-style:` / `data-ending-style:` variants are plain attribute selectors. `shadcn/tailwind.css` does not redefine them; it only customizes `data-open`, `data-closed`, `data-checked` and similar. So `data-ending-style:duration-150` correctly outranks `duration-200` while the element is closing.
- Class strings are merged with `cn`, which keeps both `duration-200` and `data-ending-style:duration-150`. This was verified.

## Steps

1. Replace the class string at `resources/scripts/components/ui/popover.tsx:37` with the popover Target.
2. Replace the class string at `resources/scripts/components/ui/dialog.tsx:34` with the backdrop Target.
3. Replace the class string at `resources/scripts/components/ui/dialog.tsx:56` with the popup Target.
4. Confirm nothing from tw-animate remains in the two files:
   `grep -nE "animate-(in|out)|zoom-(in|out)|fade-(in|out)|slide-in" resources/scripts/components/ui/popover.tsx resources/scripts/components/ui/dialog.tsx`
   This must print nothing.
5. Run `bun run lint` to format. The Tailwind class sorter may reorder classes, which is expected.
6. Run `bun run build`, then the browser tests listed under Verification. If one of the timing-sensitive assertions listed there fails, insert `->wait(0.3)` immediately before that assertion. This is the repo's existing convention for letting motion settle in tests (see `tests/Browser/SettingsBrowserTest.php:136`, `:159`, `:163` and `tests/Browser/PasskeyBrowserTest.php:90`). Change nothing else in the test.

## Boundaries

- Only `popover.tsx`, `dialog.tsx` and, if step 6 requires it, `tests/Browser/TransferManagementBrowserTest.php` change.
- Do NOT remove the `tw-animate-css` import from `resources/css/app.css`. That's out of scope.
- Do NOT add a `translate` transition to the dialog, and do NOT add `origin-*` to the dialog.
- Do NOT shorten durations or remove transitions to make a test pass. Use `->wait(0.3)` as described in step 6.
- Do NOT touch the toast, the button or any consumer's `className`.
- Do NOT add dependencies.
- If the code at the cited lines doesn't match the "current" excerpts (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - The step 4 grep prints nothing.
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
- **Tests** (after `bun run build`; browser tests load built assets unless `public/hot` exists):
  `php artisan test --compact tests/Browser/TransferManagementBrowserTest.php tests/Browser/AuthTeamBrowserTest.php tests/Browser/SettingsBrowserTest.php tests/Browser/PasskeyBrowserTest.php tests/Browser/TransferUploadBrowserTest.php`
  All must pass. Run `TransferManagementBrowserTest.php` three times, to catch flakiness.

  Pest's `assertDontSee`, `assertMissing` and `assertScript` check once and do not wait, and `press()` doesn't wait after clicking. The timing-sensitive spots, which all run immediately after an open or close, are in `tests/Browser/TransferManagementBrowserTest.php`:
  - lines 112–118: popover width measured with `getBoundingClientRect()` right after opening. Scale affects this rect.
  - line 122 and line 136: `assertMissing('#team-search')` right after Escape.
  - line 126: `assertDontSee('Save changes')` right after Cancel.
  - line 138: `assertDontSee('Save changes')` right after "Save changes".
  - line 149: `assertDontSee('Delete this transfer?')` right after Escape.

  Clicks inside an opening popover or dialog are safe, because Playwright waits for the element to stop moving before clicking. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check** at `https://filemax.test`:
  - Click the avatar (Account menu). The menu grows out of the avatar corner in about 150ms and feels immediate.
  - Click the avatar twice quickly. The menu reverses from wherever it is, with no flash to full size before closing.
  - On a transfer page (`/transfers/{id}`), click "Delete transfer". The backdrop and dialog fade in and grow from the center over 200ms. Press Escape: the exit is visibly quicker. Press Escape mid-open: it reverses smoothly.
  - On a team-shared transfer, open "Change teams", then "Choose teams". The nested popover still grows from its trigger inside the dialog.
  - In DevTools, open the Animations panel and set playback to 10%. Only opacity and scale animate, and the dialog stays exactly centered for the whole animation.
  - In the DevTools Rendering panel, set "Emulate CSS media feature prefers-reduced-motion" to reduce. With the current global rule, the popover and dialog appear instantly. After plan 004, they fade without scaling.
- **Done when**: popover and dialog use only transition classes, open and close are interruptible, and all listed browser suites pass.

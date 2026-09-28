# 002 — Add press feedback to every Button

- **Status**: DONE
- **Commit**: fb24325
- **Severity**: LOW
- **Category**: Physicality & origin
- **Estimated scope**: 1 file, 2 changed lines

## Problem

`Button` has no `:active` state. Every `<Button>` and every link styled with `buttonVariants()` ("Browse files", "Create transfer", the header's "New transfer", "Send another", pagination, dialog actions, toast actions) gives no physical response when pressed. The only visible change is the hover color, which already appeared before the press, so clicks feel dead.

```tsx
// resources/scripts/components/ui/button.tsx:5 — current (base classes)
'inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-transparent text-sm font-semibold whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:cursor-not-allowed disabled:border-transparent disabled:bg-slate-200 disabled:text-slate-500 [&_svg]:shrink-0',
```

```tsx
// resources/scripts/components/ui/button.tsx:18 — current (link variant)
link: 'text-primary hover:text-primary/90',
```

## Target

The button settles 3% smaller while pressed, over 160ms with the strong ease-out, then returns on release:

```tsx
// resources/scripts/components/ui/button.tsx:5 — target
'inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-transparent text-sm font-semibold whitespace-nowrap transition-[color,background-color,border-color,scale] duration-160 ease-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:scale-97 disabled:pointer-events-none disabled:cursor-not-allowed disabled:border-transparent disabled:bg-slate-200 disabled:text-slate-500 [&_svg]:shrink-0',
```

```tsx
// resources/scripts/components/ui/button.tsx:18 — target
link: 'text-primary hover:text-primary/90 active:scale-100',
```

Why these values:

- **`scale-97`** is `scale(0.97)`. Press feedback stays subtle, in the 0.95–0.98 range.
- **160ms** is the top of the 100–160ms budget for press feedback.
- **`ease-out`** resolves to `cubic-bezier(0.23, 1, 0.32, 1)` once plan 001 lands, and to Tailwind's default until then.
- **The transition list names `scale`, not `transform`.** In Tailwind v4, `scale-97` sets the individual CSS `scale` property.
- **The list is explicit** so nothing unrelated animates. Never use `transition-all`.
- **The `link` variant opts out** with `active:scale-100`, because text links ("Show all N files", "Change teams") shouldn't shrink. The repo's `cn` (package `cn`, a clsx + tailwind-merge replacement) resolves the conflict last-wins. Verified: `cn('… active:scale-97', 'active:scale-100')` returns `… active:scale-100`.

Disabled buttons never show the press state, because `disabled:pointer-events-none` already blocks `:active`.

## Repo conventions to follow

- Button styling lives entirely in the `cva` call in `resources/scripts/components/ui/button.tsx`: base string first, then `variants`. Keep the edits inside those strings.
- State classes follow the same `state:utility` form already used there (for example `hover:bg-muted`, `disabled:bg-slate-200`).

## Steps

1. In `resources/scripts/components/ui/button.tsx` line 5, replace `transition-colors` with `transition-[color,background-color,border-color,scale] duration-160 ease-out`, and add `active:scale-97` to the same string. The result must match the Target. The class order is cosmetic, because the formatter re-sorts it.
2. In the same file, line 18, change the `link` variant to `'text-primary hover:text-primary/90 active:scale-100'`.
3. Run `bun run lint` to format. The Tailwind class sorter may reorder classes, which is expected.

## Boundaries

- Only `resources/scripts/components/ui/button.tsx` changes.
- Do NOT add hover scale or any other hover motion.
- Do NOT change sizes, colors, focus styles or `disabled:` classes.
- Do NOT add press styles at call sites (`className` props in pages and components).
- Do NOT add dependencies.
- If the code at the cited lines doesn't match the "current" excerpts (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
- **Tests**: this change is visual only. Per the repo rule, run the button-heavy browser suites after `bun run build`:
  `php artisan test --compact tests/Browser/NavigationBrowserTest.php tests/Browser/TransferManagementBrowserTest.php`. Both must pass. They check that buttons stay clickable and that focus order is unchanged. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check** at `https://filemax.test`:
  - Press and hold "Browse files". The button visibly settles slightly smaller within ~160ms. On release it returns promptly, and the click still fires.
  - Press the full-width "Create transfer" button (add a file first so it's enabled). The shrink reads as a press, not a jump. If it feels too strong on this widest button, report it. Do not change the value.
  - On a transfer page with more than four files, press "Show all N files" (`link` variant). It does not shrink.
  - In the DevTools device toolbar (touch emulation), tap a button. The press state shows on tap.
  - In DevTools, open the Animations panel and set playback to 10%. Only `scale` and the colors animate, and they finish together.
- **Done when**: every non-link Button visibly compresses on press, link-variant buttons don't, and the two browser suites pass.

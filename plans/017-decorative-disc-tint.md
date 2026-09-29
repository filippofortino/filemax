# 017 — Stop decorative discs from looking like the primary action

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: MEDIUM
- **Category**: Colors
- **Estimated scope**: 1 file, 2 changed class strings

## Implementation outcome — 2026-09-29

Both decorative icon discs now use the existing primary tint and blue icon. Preserved every entrance-animation class. Inspected screenshots from the existing empty and ready browser flows; both render as intended. No additional test was needed for the two class substitutions.

A separate subagent prepared this task and independent review found no actionable issues. The original plan below records the earlier baseline; implementation notes here take precedence. See README for final combined verification and remaining manual checks.

## Problem

The new-transfer page draws two decorative icon discs with the same solid fill as a primary button: `bg-primary text-primary-foreground`, the recipe of the default `Button` variant (`resources/scripts/components/ui/button.tsx:10`).

- **Empty state.** The 72px solid blue upload disc is the strongest control-like element on the page, but it can't be clicked. The real action, "Browse files", is an outline button, and "Create transfer" is disabled grey.
- **Ready view.** The solid tick disc competes with the one real primary action, the solid "Copy link" button just below it.

The better-colors rule is "Fill exactly one action per view". A solid accent fill says "press me", so a decorative shape must not have one.

```tsx
// resources/scripts/pages/transfers/create.tsx:527 — current (empty state, on the bg-muted panel)
                            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                <HugeiconsIcon
                                    icon={Upload01Icon}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:405 — current (ready view, on the white card; entrance classes from plan 005)
                            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-[opacity,scale] [transition-delay:80ms] duration-300 ease-out starting:opacity-0 motion-safe:starting:scale-90">
                                <HugeiconsIcon
                                    icon={Tick02Icon}
```

At d15b32d these are the only two elements in `resources/scripts` that pair `rounded-full` with `bg-primary text-primary-foreground`.

## Target

Both discs use the project's existing primary tint: a pale blue disc with a blue icon.

```tsx
// resources/scripts/pages/transfers/create.tsx:527 — target
                            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
```

```tsx
// resources/scripts/pages/transfers/create.tsx:405 — target
                            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary transition-[opacity,scale] [transition-delay:80ms] duration-300 ease-out starting:opacity-0 motion-safe:starting:scale-90">
```

The values below were computed from the declared tokens: `--color-primary` is `#2140e0`, and the Tailwind oklch values were converted to sRGB. The build compiles `bg-primary/10` to the primary color at 10% alpha, which the browser composites in sRGB.

| Disc (surface) | Fill | Disc vs surface | Icon vs fill |
| --- | --- | --- | --- |
| Empty (`bg-muted`, `#f1f5f9`) | today: `bg-primary` | 6.67:1 | 7.31:1 |
| | review's proposal: `bg-accent` (`#eff6ff`) | **1.01:1** | 6.71:1 |
| | **target: `bg-primary/10`** (`#dce3f7`) | 1.17:1 | 5.70:1 |
| Ready (white card) | today: `bg-primary` | 7.31:1 | 7.31:1 |
| | review's proposal: `bg-accent` | 1.09:1 | 6.71:1 |
| | **target: `bg-primary/10`** (`#e9ecfc`) | 1.18:1 | 6.22:1 |

Why:

- **The review's `bg-accent text-accent-foreground` fails in the empty state.** `blue-50` on the `slate-100` panel is 1.01:1, so the disc disappears and the arrow floats on its own. It would work on the white ready card, but the two sibling discs would then need two different recipes.
- **This reuses a recipe the project already has.** `bg-primary/10 … text-primary` is the app's tinted pill: the visibility pill at `pages/transfers/show.tsx:65` and the "Public" pill at `pages/transfers/index.tsx:181`. There is no new token and no new value.
- **The disc still reads as a shape on both surfaces.** Both discs separate from their surface by about 1.17:1, a little more than the white file cards separate from the same muted panel (1.10:1). The icon stays well above 3:1, the threshold for graphics, although it is decorative and `aria-hidden`.
- **One solid fill per view, after this plan.** The header's "New transfer" button is hidden on this page (`showsNewTransfer` is false when `active` is `'new'`).
  - Empty state: no solid fill before 013. After 013, only "Create transfer".
  - Files added: only "Create transfer".
  - Upload failed: only "Retry and create link".
  - Uploading: none, because the submit button is hidden.
  - Ready: only "Copy link".
- **This deliberately departs from the artboard.** The v2 artboard (`Filemax-v2.html`, boards "New transfer, empty", "New transfer, teams selected" and "Link ready") draws both discs solid `#2140E0` with a white icon. The departure is kept as small as possible. The hue, shape, size and icon stay the same, and only the fill strength changes. That follows the same practice as plan 008, where a quality rule overrides one artboard value.
- **Plan 005's entrance still plays.** It animates `opacity` and `scale` on this same `<span>`, and neither depends on the fill. The class string keeps every one of 005's classes.

## Dependencies

None have to land first. The files are shared, so land plans one at a time:

- **005 (DONE)** owns the ready disc's `transition-*`, `[transition-delay:80ms]`, `duration-300`, `ease-out` and `starting:*` classes. Only the two color classes change.
- **013** makes "Create transfer" an enabled solid button, which changes the empty state's one solid action. It doesn't touch the discs. The manual check covers both orders.
- **018** routes colors through semantic tokens and must not re-touch these discs. If 018 lands first and replaces the `bg-primary/10 … text-primary` pills at `show.tsx:65` and `index.tsx:181` with a named token pair, use that same pair here, so the discs keep matching the pills.
- **009** removes the hidden file `<input>` just above the empty disc (~513–524), and **012** / **015** edit the ready `<h1>` and count line just below the ready disc (~412–414). 010, 013, 016, 019, 021 and 023 edit other parts of `create.tsx`. None of them edits these two `<span>` lines, so they only shift the line numbers.

## Repo conventions to follow

- Colors are Tailwind utilities on the element and reference the semantic tokens in `@theme inline` in `resources/css/app.css`. Tints are opacity modifiers on `primary` (`bg-primary/5`, `bg-primary/10`), and the text on a tint is `text-primary`.
- Decorative icons keep `aria-hidden="true"` on the `HugeiconsIcon`.

## Steps

1. In `resources/scripts/pages/transfers/create.tsx` at line 527, inside the `{!entries.length ? (` branch, find the `<span>` directly above `icon={Upload01Icon}`. Its class is `inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground`. Replace `bg-primary text-primary-foreground` with `bg-primary/10 text-primary`. The result must match the Target.
2. At line 405, inside the `if (ready)` branch, find the `<span>` directly above `icon={Tick02Icon}`. Its class starts with `inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-[opacity,scale]`. Make the same replacement, and leave every other class as it is. The result must match the Target.
3. Run `grep -rn "rounded-full bg-primary text-primary-foreground" resources/scripts`. It must print nothing.
4. Run `bun run lint`. The Tailwind class sorter may reorder classes, which is expected.

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only the two color classes on these two `<span>`s change.
- Do NOT change the disc size (`size-18`), the shape, the icons, the icon `size` or `strokeWidth`, or any of plan 005's entrance classes.
- Do NOT make "Browse files" a solid button, and do NOT change any `Button` variant. The artboard has "Browse files" as an outline button, and 013 owns the submit button.
- Do NOT add or edit tokens in `resources/css/app.css` (018), and do NOT touch other raw colors in `create.tsx` such as `text-slate-700` or `text-emerald-700` (018).
- Do NOT touch the neutral discs elsewhere (`pages/transfers/index.tsx:106`, `pages/shared/unavailable.tsx:24`, `pages/shared/denied.tsx:42`) or the passkey tile (`pages/account/settings.tsx:468`).
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - No PHP file changes, so Pint has nothing to do.
- **Tests**: this change is visual only. Its outcome is visual weight, and a test for it could only assert computed colors, which plan 002's precedent rules out. Per the repo rule, run the suites that render both discs after `bun run build`:
  `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/HomeTest.php`. Both must pass. Between them they render the empty state ("Drop files here", "Browse files") and the ready view ("Your link is ready") many times, and they click "Send another" from the ready card. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`, at 1280×940 and at 390 and 320px wide:
  1. **Empty state.** The upload disc is a pale blue circle with a blue arrow. It is clearly visible as a circle on the grey left panel, not a floating arrow. Without 013, nothing on the page has a solid blue fill. With 013, "Create transfer" is the only one.
  2. **Add two files.** "Create transfer" is the only element with a solid blue fill.
  3. **Create the transfer.** On the ready card, the tick sits in a pale blue circle on white, and "Copy link" is the only element with a solid blue fill. The entrance still plays: the card rises, and the disc pops in about 80ms later.
  4. **Force a failure** (DevTools Network set to Offline during a large upload). "Retry and create link" is the only element with a solid blue fill.
  5. Look at the page from across the room, or squint. The first thing that reads as "press me" is the solid button, not the disc.
- **Done when**: neither disc has a solid fill, both still read as circles on their surfaces, each state has at most one solid blue action, and both browser suites pass.

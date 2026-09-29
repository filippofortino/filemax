# 007 — Animate the Copy link confirmation icon

- **Status**: DONE
- **Commit**: fb24325
- **Severity**: LOW
- **Category**: Missed opportunities
- **Estimated scope**: 1 file, 1 element (2 new props)

## Problem

Copying the share link is the main action on the "Your link is ready" screen and on the transfer page. The visible confirmation is a copy glyph that swaps to a tick in a single frame. It's easy to miss, because nothing draws the eye.

```tsx
// resources/scripts/components/filemax.tsx:464-471 — current
<Button type="button" onClick={copy} className="px-4">
    <HugeiconsIcon
        icon={copied ? Tick02Icon : Copy01Icon}
        size={18}
        aria-hidden="true"
    />
    {copied ? 'Copied' : 'Copy link'}
</Button>
```

## Target

When the tick appears, it fades in, sharpens from a 2px blur and grows from 0.9, over 150ms with the shared ease-out:

```tsx
// resources/scripts/components/filemax.tsx:464-471 — target
<Button type="button" onClick={copy} className="px-4">
    <HugeiconsIcon
        key={copied ? 'copied' : 'copy'}
        icon={copied ? Tick02Icon : Copy01Icon}
        size={18}
        aria-hidden="true"
        className={
            copied
                ? 'transition-[opacity,scale,filter] duration-150 ease-out starting:opacity-0 starting:blur-[2px] motion-safe:starting:scale-90'
                : undefined
        }
    />
    {copied ? 'Copied' : 'Copy link'}
</Button>
```

Why this shape:

- **`key` forces a new `<svg>` when `copied` flips.** Without it, React reuses the same `<svg>` and only swaps its paths. `@starting-style` (Tailwind's `starting:` variant) only applies to newly inserted elements, so the entrance would never play.
- **The classes apply only to the tick (`copied ? … : undefined`).** `@starting-style` also applies on first render, so putting the classes on both states would make the copy icon fade in on every page load.
- **150ms** fits the small-element budget (125–200ms).
- **Scale 0.9** is the minimum; nothing should appear from nothing. It's gated behind `motion-safe:`.
- **A 2px blur** masks the glyph swap. It stays well under the 20px cost ceiling, and it's trivial on an 18px icon.
- **`ease-out`** resolves to `cubic-bezier(0.25, 0.46, 0.45, 0.94)` once plan 001 lands.
- **The label change ("Copy link" → "Copied") stays instant.** The icon carries the moment.
- **`copied` never resets to `false`** in the existing code, so the tick animates once per page and repeat clicks don't replay it. That's existing behavior; do not change it.

## Repo conventions to follow

- A conditional `className` on `HugeiconsIcon` is already the pattern: `resources/scripts/components/downloads.tsx:102`, `className={busy ? 'animate-spin' : undefined}`. `HugeiconsIcon` forwards `className` to its `<svg>`.
- `starting:` is Tailwind v4's `@starting-style` variant. Plans 005 and 006 use it the same way.

## Steps

1. In `resources/scripts/components/filemax.tsx`, inside `CopyLink` (the `<Button type="button" onClick={copy} …>` at line 464), add to the `HugeiconsIcon`:
   - `key={copied ? 'copied' : 'copy'}` as its first prop.
   - The `className` prop exactly as in the Target.
2. Run `bun run lint` to format. JSX may be re-wrapped and the class string re-sorted, which is expected.

## Boundaries

- Only the `HugeiconsIcon` inside `CopyLink` changes.
- Do NOT animate the label text, the button, the input or the `role="status"` span.
- Do NOT change `copy()`, the `copied` or `error` state, or add a reset timer.
- Do NOT add dependencies.
- If the code at the cited lines doesn't match the "current" excerpt (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit, including no type errors on the new props.
  - `bun run build` succeeds.
- **Tests**: no browser test clicks "Copy link" (grepped `tests/` for "Copy link" and "Copied": no hits). Run the suites that render `CopyLink`, after `bun run build`:
  `php artisan test --compact tests/Browser/TransferManagementBrowserTest.php tests/Browser/TransferUploadBrowserTest.php`. Both must pass. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check** at `https://filemax.test/transfers/{id}` (any transfer that still has a link; HTTPS is needed for the clipboard API):
  - Load the page. The copy icon does NOT animate.
  - Click "Copy link". The tick fades in, sharpens and grows slightly in ~150ms, and the label reads "Copied".
  - Click again. Nothing replays. That's expected, because `copied` stays true.
  - In DevTools, open the Animations panel and set playback to 10%. The blur resolves together with the fade, and there's no frame where both icons are visible.
  - In the DevTools Rendering panel, set prefers-reduced-motion to reduce. After plan 004 the tick fades without scaling. With today's rule it appears instantly.
- **Done when**: the tick animates in exactly once per copy, the copy icon never animates on load, and both suites pass.

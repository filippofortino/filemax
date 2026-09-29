# 005 — Give "Your link is ready" a small entrance

- **Status**: DONE
- **Commit**: fb24325
- **Severity**: LOW
- **Category**: Missed opportunities
- **Estimated scope**: 1 file, 1 new `key` prop, 2 class strings

## Problem

When the last file finishes uploading, `setReady(result.transfer)` swaps the whole upload screen for the success card in a single frame (`resources/scripts/pages/transfers/create.tsx:395`, `if (ready) return (…)`). This is the app's one rare, high-emotion moment: a possibly huge upload just finished. It gets no motion; the card and its check badge simply appear.

```tsx
// resources/scripts/pages/transfers/create.tsx:399-408 — current
<main className="flex flex-1 items-center justify-center bg-muted px-5 py-6 md:p-10">
    <section className="flex w-full max-w-2xl flex-col gap-6 rounded-xl border bg-background px-5 py-7 md:p-10">
        <div className="flex flex-col items-center gap-3.5 text-center">
            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                <HugeiconsIcon
                    icon={Tick02Icon}
                    size={30}
                    aria-hidden="true"
                />
            </span>
```

## Target

The card fades in while rising 8px over 300ms. The check badge scales up from 0.9 and fades in over 300ms, starting 80ms later: one small beat of delight, then everything is in place and usable. It's CSS only (`@starting-style` through Tailwind's `starting:` variant): no JS, no state, no timers.

```tsx
// resources/scripts/pages/transfers/create.tsx:400-402 — target
<section
    key="ready"
    className="flex w-full max-w-2xl flex-col gap-6 rounded-xl border bg-background px-5 py-7 transition-[opacity,translate] duration-300 ease-out starting:opacity-0 motion-safe:starting:translate-y-2 md:p-10"
>
    <div className="flex flex-col items-center gap-3.5 text-center">
        <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-[opacity,scale] duration-300 ease-out [transition-delay:80ms] starting:opacity-0 motion-safe:starting:scale-90">
```

**Why `key="ready"` is required.** `@starting-style` only applies to elements that are newly inserted into the DOM. Both render branches of this component return `<Shell>` → `<Head>` + `<main>`, and the first child of `<main>` is a `<section>` in both: the upload panel at `create.tsx:509`, and this card at `create.tsx:400`. With no key, React reuses the upload panel's `<section>` DOM node for the card, just changing its classes and children, so the entrance never plays. The key forces a fresh node. It's a React identity hint and does not change the rendered markup.

Why these values:

- **300ms with the shared ease-out.** A rare celebration moment is allowed more delight than everyday UI. This stays within the 200–500ms modal budget, and the curve decelerates evenly into place.
- **`translate-y-2`** is 0.5rem (8px). A small rise, not a slide.
- **Badge `scale-90`** is scale 0.9. Never go lower; nothing should appear from nothing.
- **An 80ms stagger**, the top of the 30–80ms range. It never blocks interaction: the "Copy link" button can be clicked immediately, because opacity doesn't block pointer events.
- **`[transition-delay:80ms]` instead of `delay-80`.** tw-animate-css (imported at `resources/css/app.css:2`) defines its own `delay-*` utility for `animation-delay`. The arbitrary property is unambiguous.
- **Movement (translate, scale) is gated behind `motion-safe:`.** Under reduced motion there's a fade only once plan 004 lands, and an instant appearance with today's global rule.
- **`ease-out`** resolves to `cubic-bezier(0.25, 0.46, 0.45, 0.94)` once plan 001 lands.

## Repo conventions to follow

- Motion is expressed with Tailwind utilities on the element (exemplar: `resources/scripts/components/ui/toast.tsx:66`, `transition-opacity duration-200 ease-out`).
- The `starting:` variant (Tailwind v4, compiles to `@starting-style`) isn't used anywhere in the repo yet. This is its first use, and plans 006 and 007 use it the same way.

## Steps

1. In `resources/scripts/pages/transfers/create.tsx`, on the `<section>` at line 400 (the one inside the `if (ready)` branch, whose class starts with `flex w-full max-w-2xl`), add `key="ready"` and replace its `className` with the Target.
2. On the badge `<span>` at line 402 (class starts with `inline-flex size-18`), replace its `className` with the Target.
3. Run `bun run lint` to format. JSX attributes may be re-wrapped and classes re-sorted, which is expected.

## Boundaries

- Only these two elements in the `if (ready)` branch change. Do NOT animate the heading, the copy-link field, the file list or the "Send another" row individually.
- Do NOT add animation to the upload view, and do NOT add exit animations.
- Do NOT add a motion library, a spring or JS timers.
- Do NOT go below `scale-90`, above 300ms, or above an 80ms delay.
- If the code at the cited lines doesn't match the "current" excerpt (drift since commit fb24325), STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
- **Tests** (after `bun run build`):
  `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php`. It must pass. It reaches "Your link is ready" several times and clicks "Send another" (line 363). Playwright waits for the moving card to settle before clicking. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Feel check** at `https://filemax.test`:
  - Upload two small files and create the transfer. When the last file lands, the card rises into place and the check badge lands a beat later with a small pop. Everything is settled in under ~400ms.
  - Click "Copy link" during the entrance. It works immediately.
  - In DevTools, open the Animations panel and set playback to 10%. The card starts moving on the first frame (no delay). The badge starts 80ms later, and nothing overshoots.
  - Click "Send another", then complete another upload. The entrance plays again.
  - In the DevTools Rendering panel, set prefers-reduced-motion to reduce. After plan 004 there's a fade only, with no rise and no scale. With today's rule it appears instantly.
  - If the fade plays but the rise or scale doesn't (the card appears already in place), report it. Do not replace this with JavaScript.
- **Done when**: the success card and badge animate in on every successful upload, the Copy link button is usable immediately, and `TransferUploadBrowserTest` passes.

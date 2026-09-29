# 011 — Keep the press shrink without dropping edge clicks

## Implementation outcome — 2026-09-29

Implemented the shared counter-scaled hit layer with the 1px border included. The original sign-in edge regression is unchanged and passes in both motion modes. The new right-edge upload test verifies visible shrink and a ready heading in both modes. The 0.97 scale, 160ms duration and easing remain unchanged. The left-edge regression established RED; the planned right-edge test needed two corrections: measure during the press, and use an explicit CSS heading selector because Pest treats bare h1 as text.

Verification: client/SSR build, resource formatting, Pint and git diff --check passed. The combined six browser suites passed 52 tests / 681 assertions. Resource lint/type checking reports zero errors and four pre-existing uploader warnings; the repository-wide check stops on five unchanged config-formatting issues.

> **For agentic workers:** Use `superpowers:executing-plans` or `superpowers:subagent-driven-development` when implementation is requested. Complete this plan independently; do not start sibling plans automatically.

**Goal:** Keep the button's click shrink while making edge presses activate reliably.

**Architecture:** Preserve `active:scale-97`, the 160ms duration and the shared easing. Correct hit testing once in the shared button styles with an invisible counter-scaled hit layer; validate both visible shrink and successful activation.

**Tech Stack:** React 19, Base UI, Tailwind CSS 4, Pest Browser.

**Spec:** The 2026-09-29 `better-interface` finding and the user's explicit request to keep the shrink effect; preserve [Filemax's interface conventions](../FILEMAX_IMPLEMENTATION_PLAN.md) and [motion decisions](README.md#decisions-from-feel-checks).

- **Status**: IMPLEMENTED
- **Review baseline**: `8adfa07` on 2026-09-29; the older line references below originated at `d15b32d`.
- **Severity**: HIGH
- **Category**: UI polish
- **Estimated scope**: 2 files: 1 changed line in `button.tsx`, 1 new browser test

## Global constraints and review focus

- Follow `AGENTS.md`, activate the relevant frontend skills and use Boost `search-docs` before changing application code.
- Keeping the visible shrink is an acceptance requirement, not an optional enhancement. Removing the scale is not a solution for this plan.
- The current review freshly reproduced both existing sign-in edge-click failures after a build, with `no-preference` and `reduce`. Older coordinate measurements below are historical context.
- Preserve the existing sign-in regression test. Add the complementary upload test without rewriting that test or weakening assertions.
- Check enabled buttons, disabled controls, the non-shrinking link variant, and dialog/toast controls whose positioning or pseudo-elements differ. A hit layer must not steal clicks from adjacent controls.
- Other numbered plans are optional collision notes, not prerequisites. Do not commit or push unless separately requested.

## Problem

In plain words: when you press a button, it shrinks slightly toward its centre (plan 002). The browser only counts a click when you **release over the same thing you pressed**. If you press near the edge of a button, the button shrinks out from under your cursor or finger while you are still holding it. When you let go, the pointer is over whatever sits behind the button, so the browser sees "pressed on the button, released somewhere else" and no click happens. The button visibly bounced, but nothing was sent and no message appears.

The shrink comes from one place, the base class string that every `<Button>` and every link styled with `buttonVariants()` shares:

```tsx
// resources/scripts/components/ui/button.tsx:5 — current (base classes)
    'inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-transparent text-sm font-semibold whitespace-nowrap transition-[color,background-color,border-color,scale] duration-160 ease-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:scale-97 disabled:pointer-events-none disabled:cursor-not-allowed disabled:border-transparent disabled:bg-slate-200 disabled:text-slate-500 [&_svg]:shrink-0',
```

`active:scale-97` shrinks the whole element, and its clickable box shrinks with it. The dead zone grows with the button's width: 1.5% of the width on each side at full shrink.

- **"Create transfer"** (512px wide at 1280×940): about 7.7px per side. Measured: a press at x=706 (the button spans 704–1216) held for 100ms released while the button spanned 710.07–1209.93 (scale 0.9763). The `mouseup` target was the surrounding `DIV`, and no click fired. The same happened 3px inside the right edge.
- **"Sign in"** (310px): about 4.7px per side. The user's uncommitted test `signs in when pressing near the edge of the submit button` (`tests/Browser/AuthTeamBrowserTest.php:81-95`) presses 2px inside the left edge. It fails today for this reason, with reduced motion both on and off.
- **Icon buttons** (32–44px): under 1px, so they're barely affected.

Download buttons (`components/downloads.tsx`) are Buttons too. A dropped press there is a missed download and a missed click count, which the product counts per button press.

## Target

```tsx
// resources/scripts/components/ui/button.tsx:5 — target
    'relative inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-transparent text-sm font-semibold whitespace-nowrap transition-[color,background-color,border-color,scale] duration-160 ease-out after:absolute after:-inset-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:scale-97 active:after:scale-[calc(1/0.97)] disabled:pointer-events-none disabled:cursor-not-allowed disabled:border-transparent disabled:bg-slate-200 disabled:text-slate-500 [&_svg]:shrink-0',
```

The button keeps shrinking. It gains an invisible layer (`::after`) that stays at the original size while the button is pressed:

- **How it works.** A pseudo-element belongs to its button for hit-testing, so a press or release on the layer counts as the button. At rest `-inset-px` covers the full button, including its 1px border; `inset-0` would cover only the padding box and leave that outer pixel unprotected. While pressed, the button is at `scale(0.97)` and the layer inside it at `scale(1/0.97)`. On screen that makes 0.97 × 1/0.97 = the original outline, centred on the same point. The layer is transparent, so the visible shrink is unchanged. If the shared border width changes, reassess the inset along with the edge tests.
- **`relative`** gives the layer its containing block. It reuses the project's existing hit-layer pattern: `ToastClose` already uses `relative after:absolute after:-inset-2` (`components/ui/toast.tsx:130`). Tailwind v4's `after:` variant already emits `content: var(--tw-content)` (empty by default), so `after:content-['']` isn't needed.
- **`calc(1/0.97)` rather than `1.031`.** It is the exact inverse and names the value it depends on. It compiles to `scale: calc(1 / 0.97)` (checked with the project's Tailwind 4.3.3). **If the press scale ever changes, the counter-scale must change in the same edit to `calc(1/<new scale>)`.** For example, `active:scale-96` needs `active:after:scale-[calc(1/0.96)]` (≈1.042), or the dead zone comes back.
- **The layer snaps instead of animating.** The `transition-[…,scale]` list applies to the button, not its `::after`. While the button eases in, the layer is already at full counter-scale, so the hit area is briefly up to 3% larger than the button at rest, never smaller. The pointer is already down on this button at that point, so it can't take a press away from a neighbour. On release the click has already been decided. Skipped: animating the layer too (`after:transition-[scale] after:duration-160 after:ease-out`). It would only matter for a second press within 160ms on the outermost pixels. Add it only if that shows up.
- **Reduced motion.** `resources/css/app.css:112-118` limits transitions to opacity and colours, so both scales apply instantly: 0.97 × 1/0.97 = 1, still covered. Both tests below run in both modes.
- **Call-site interactions, checked with the repo's `cn`:**
  - The Dialog close button passes `className="absolute top-2 right-2"` (`components/ui/dialog.tsx:66-70`). `cn` resolves `relative` vs `absolute` last-wins → `absolute`, which is still a containing block.
  - `ToastClose` passes `relative … after:absolute after:-inset-2`. `cn` must drop the base `after:-inset-px` for its `after:-inset-2`; verify this with the installed merger. The counter-scale applies to that larger layer too.
  - Links that call `buttonVariants()` (`components/filemax.tsx:142`, `pages/transfers/create.tsx:467`, `pages/transfers/index.tsx:127,246,266`, `pages/shared/denied.tsx:75,81,90`) get the classes unmerged. None sets a position or its own `::after`.
  - No Button or buttonVariants consumer uses `static`, `sticky`, `fixed` or `overflow-hidden`, and none has absolutely positioned children.
  - The remove-file icon buttons sit inside `overflow-hidden` rows (`create.tsx:604`). The settled pressed layer matches the resting border box. The brief expansion during the transition may be clipped, so verify activation at the row edge as part of the manual check.
- **The `link` variant needs no change.** It keeps `active:scale-100`, so it doesn't shrink. Its layer still grows by 1/0.97 while pressed (about 1.5% per side), but only while the pointer is already down on it, which is harmless. Don't add `active:after:scale-100`.
- **Disabled buttons stay inert.** `pointer-events: none` is inherited by `::after`.
- **Stacking.** `relative` without a z-index creates no stacking context. Every overlay (dialog, popover, toast, drop overlay) is `fixed` or portalled with `z-50`/`z-60`, so buttons don't paint over them.
- **Why not the alternatives.**
  - Removing the shrink fixes it, but the user wants the shrink.
  - A fixed `active:after:-inset-2` expansion only covers buttons up to about 515px wide, and `w-full` buttons get wider. The counter-scale is exact at any width.
  - Scaling an inner wrapper instead would change markup at 40+ call sites.

## Dependencies

- **None must land first.** Plan 002 (DONE) introduced the shrink. This plan keeps all of 002's values.
- **Plan 018 edits the same line.** It replaces the raw palette classes `disabled:bg-slate-200 disabled:text-slate-500` in this base string, and the `destructive` variant. Step 3 inserts at three anchors that 018 doesn't touch, so either order works. Don't run the two in parallel.
- **`tests/Browser/TransferUploadBrowserTest.php` is also edited by 008, 012, 013, 014, 016 and 019.** 008 inserts above `function controlledTransferUploads()`. This plan inserts one test after the XSRF test instead.
  - 013 changes when "Create transfer" is disabled. The new test adds a file and keeps the default "public" visibility, so it presses an enabled button either way.
  - 012 hides the submit button during an upload, but the press here happens before the upload starts.

## Repo conventions to follow

- **Button styling** lives entirely in the `cva` call in `resources/scripts/components/ui/button.tsx`. Keep the edit inside the base string. Tailwind v4 reads stacked variants left to right, so `active:after:x` is `:active::after`.
- **Browser tests:**
  - Inline JS goes in `$page->script(<<<'JS' … JS)` heredocs, and test names are lowercase `it('…')` sentences.
  - Upload tests use `Storage::fake('local'); config(['filemax.disk' => 'local']);` and drop files with `DataTransfer` + `DragEvent('drop')` on `main`. The exemplar is `tests/Browser/TransferUploadBrowserTest.php:179-189`.
  - `visit($url, ['reducedMotion' => …])` passes the option to Playwright's browser context, as the user's `AuthTeamBrowserTest.php` test does.
- **Playwright `click` `position`** is measured from the padding box, inside the 1px border.
  - `x => 1` lands 2px inside the left edge.
  - `x => $width - 4` lands 3px inside the right edge.
  - `$width - 1` lands exactly on the outer edge, so the press never starts on the button. Don't use it.

## Steps

- [x] **Add the test first.** In `tests/Browser/TransferUploadBrowserTest.php`, find the line `})->with(['local upload' => false, 'object storage upload' => true]);` (line 220 at d15b32d, the end of the test `uses the current XSRF cookie only for same-origin upload requests`). Insert this test after it, with one blank line before and after. `User` and `Storage` are already imported.

   ```php
   it('creates a transfer when pressing near the right edge of the shrinking submit button', function (string $reducedMotion): void {
       Storage::fake('local');
       config(['filemax.disk' => 'local']);
       $this->actingAs(User::factory()->create());
       $page = visit('/', ['reducedMotion' => $reducedMotion])->resize(1280, 940)->assertSee('Drop files here');
       $page->script(<<<'JS'
   () => {
       const data = new DataTransfer();
       data.items.add(new File(['hello'], 'edge.txt'));
       document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
   }
   JS);
       $page->assertSee('edge.txt');
       $width = $page->script(<<<'JS'
   () => {
       const button = document.querySelector('button[type=submit]');
       document.addEventListener('mouseup', () => { window.pressedWidth = button.getBoundingClientRect().width; }, {capture: true, once: true});
       return button.getBoundingClientRect().width;
   }
   JS);

       $page->page()->locator('button[type=submit]')->click([
           'position' => ['x' => $width - 4, 'y' => 24],
           'delay' => 100,
           'force' => true,
       ]);

       expect($page->script('() => window.pressedWidth'))->toBeLessThan($width);
       $page->assertSee('Your link is ready')->assertNoJavascriptErrors();
   })->with(['no-preference', 'reduce']);
   ```

   What it asserts:
   - **The button still shrinks.** Its rendered width at the moment of release is smaller than at rest. This guards the user's requirement against a "fix" that deletes the scale.
   - **The press counts.** Pressing 3px inside the right edge and holding for 100ms still submits, and the transfer is created.

   This adds a right-edge case, the widest button and the upload page to the user's left-edge test on `/login`.

- [x] **Prove both edge tests fail before the fix.** Run `bun run build`, then:
   - `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter='right edge'`
   - `php artisan test --compact tests/Browser/AuthTeamBrowserTest.php --filter='near the edge'`

   All four cases (two per test) must fail on the final `assertSee`, because "Your link is ready" or "Transfer details" never appears. The `pressedWidth` expectation must pass. If a case passes, or fails for any other reason, stop and report.

- [x] **Add the hit layer.** In `resources/scripts/components/ui/button.tsx`, the first argument of `cva(` (line 5 at d15b32d), make three insertions and change nothing else:
   - Replace `'inline-flex shrink-0` with `'relative inline-flex shrink-0`.
   - Replace `ease-out focus-visible:outline-2` with `ease-out after:absolute after:-inset-px focus-visible:outline-2`.
   - Replace `active:scale-97 disabled:pointer-events-none` with `active:scale-97 active:after:scale-[calc(1/0.97)] disabled:pointer-events-none`.

   If plan 018 hasn't landed, the line must match the Target exactly. If it has, the line keeps 018's token classes in place of `disabled:bg-slate-200 disabled:text-slate-500`. This order is the one the formatter's Tailwind sorter produces.

- [x] **Format.**
   - Run `bun run lint`.
   - Run `vendor/bin/pint --dirty --format agent` as required by `AGENTS.md`. Review formatter changes and exclude unrelated formatting edits, preserving the pre-existing sign-in regression test.

- [x] **Build and re-run.** Run `bun run build`, then the suites under Verification → Tests. Check the link variant, disabled state and adjacent-target hit testing as well as the new edge-press test.

## Boundaries

- Only these change: the base string on line 5 of `resources/scripts/components/ui/button.tsx`, and one new test in `tests/Browser/TransferUploadBrowserTest.php`.
- Do NOT remove or change `active:scale-97`, the transition list, `duration-160` or `ease-out`. They are plan 002's feel-checked values. The better-ui skill's "always 0.96" doesn't override that decision here.
- Do NOT change the variants. `link` keeps `active:scale-100`, with no `active:after:scale-100`.
- Do NOT add `pointer-events-none` to the layer. The layer is the hit area.
- Do NOT add press or hit-layer classes at call sites. Do NOT change `ToastClose`'s `after:-inset-2` or the Dialog close button's `absolute`.
- Do NOT touch the raw palette classes on this line or in the variants. Plan 018 owns them.
- Do NOT modify `tests/Browser/AuthTeamBrowserTest.php`. The user's uncommitted test `signs in when pressing near the edge of the submit button` is this plan's acceptance test, so keep it exactly as is.
- Do NOT add dependencies.
- Line numbers are historical and may shift as sibling plans land. Locate the equivalent shared class string and named tests, preserve earlier fixes, and reassess only if the button behavior has changed.

## Verification

- **Mechanical**:
  - `bun run lint`, then `bun run test:lint`, passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
- **Tests** (after `bun run build`):
  `php artisan test --compact tests/Browser/AuthTeamBrowserTest.php tests/Browser/TransferUploadBrowserTest.php tests/Browser/SettingsBrowserTest.php tests/Browser/TransferManagementBrowserTest.php tests/Browser/NavigationBrowserTest.php`

  Everything must pass, including all four edge-press cases. The other suites cover the special cases:
  - `SettingsBrowserTest.php:151` presses the toast's "Dismiss" button (`ToastClose` merge).
  - `TransferManagementBrowserTest.php` drives dialogs.
  - `NavigationBrowserTest.php` uses the header's `buttonVariants()` link.

  If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at the current worktree URL returned by Boost `get-absolute-url` (do not assume the default worktree's host):
  - Add a file on `/`. Press and hold "Create transfer" a few pixels inside its left edge. It still visibly shrinks. On release, the upload starts.
  - Do the same near the edge of "Sign in" on `/login`.
  - Open the Delete confirmation on a transfer page. The close ✕ still sits in the dialog's top-right corner and closes it.
  - Save the profile in `/account/settings`, then press the toast's ✕ near its edge. The toast dismisses.
  - On a transfer with more than four files, press "Show all N files". It doesn't shrink and still works.
  - Nothing looks different at rest. The layer is invisible.
- **Done when**: enabled buttons retain their existing shrink (the link variant stays unscaled), a press and release at the same point inside a button's resting outline fires its click, the user's edge-press test and the new right-edge test pass in both motion modes, and the listed suites pass.

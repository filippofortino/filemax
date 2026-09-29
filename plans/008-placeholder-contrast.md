# 008 — Raise placeholder contrast to 4.5:1

## Implementation outcome — 2026-09-29

Implemented with the existing muted-foreground token. The browser contrast check passes for Title, Message and team search; the live login preview resolves the same token. No layout or copy changes.

Verification: client/SSR build, resource formatting, Pint and git diff --check passed. The combined six browser suites passed 52 tests / 681 assertions. Resource lint/type checking reports zero errors and four pre-existing uploader warnings; the repository-wide check stops on five unchanged config-formatting issues.

> **For agentic workers:** Use `superpowers:executing-plans` or `superpowers:subagent-driven-development` when implementation is requested. Complete this plan independently; do not start sibling plans automatically.

**Goal:** Make placeholder text readable without changing field layout or copy.

**Architecture:** Change the existing global placeholder utility to the existing secondary-text token. Measure rendered contrast with the project's browser tests; no new component, palette or dependency.

**Tech Stack:** Tailwind CSS 4, React 19/Inertia 3, Pest Browser.

**Spec:** The 2026-09-29 `better-interface` finding in this chat, reproduced below; preserve the interface conventions in [Filemax Implementation Plan](../FILEMAX_IMPLEMENTATION_PLAN.md).

- **Status**: IMPLEMENTED
- **Review baseline**: `8adfa07` on 2026-09-29; the older line references below originated at `d15b32d`.
- **Severity**: HIGH
- **Category**: Colors
- **Estimated scope**: 2 files: 1 changed CSS line, 1 new browser test

## Global constraints and review focus

- Follow `AGENTS.md`, activate the relevant frontend skills and use Boost `search-docs` before changing application code.
- Keep this independently implementable. Other numbered plans are optional collision notes, not prerequisites; references to absent 018/022 do not require creating or implementing them.
- Preserve all existing uncommitted work, particularly the sign-in edge-click test. Do not commit or push unless separately requested.
- Verify placeholder contrast is at least 4.5:1 and remains lower than typed-text contrast; do not weaken the assertion to fit a chosen token.
- Check Title, Message, team search and sign-in placeholders. Do not treat disabled controls as the enabled-text acceptance case.

## Problem

Every placeholder in the app renders `slate-400` (`#90a1b9`) on the white input background. That measures **2.63:1**. WCAG 1.4.3 requires 4.5:1 for this 16px/400 text. axe-core does not flag it.

The color comes from a single place, the base rule that styles every text input, select and textarea:

```css
/* resources/css/app.css:74-80 — current */
    input:not([type='checkbox']):not([type='radio']):not([type='file']):not(
            [type='hidden']
        ),
    select,
    textarea {
        @apply h-11 w-full min-w-0 rounded-md border border-input bg-background px-3 text-base text-foreground placeholder:text-slate-400 disabled:bg-muted disabled:text-muted-foreground aria-invalid:border-destructive;
    }
```

No call site overrides it. `git grep "placeholder:" d15b32d -- resources/` matches only this line. These are the inputs affected:

| Page | Field | Placeholder |
| --- | --- | --- |
| `/` (`pages/transfers/create.tsx:781-790`) | `#transfer-title` | "What is this?" |
| `/` (`pages/transfers/create.tsx:802-811`) | `#transfer-message` | "A note for whoever opens the link" |
| `/` team picker (`components/filemax.tsx:359-367`) | `#team-search` | "Search teams…" |
| `/login` (`pages/auth/login.tsx:37-45`, `63-71`) | `#email`, `#password` | "you@mediamaxcommunication.it", "••••••••" |
| `/register` (`pages/auth/register.tsx:54-63`) | `#email` | "you@mediamaxcommunication.it" |
| `/forgot-password` (`pages/auth/forgot-password.tsx:25-33`) | `#email` | "you@mediamaxcommunication.it" |
| `/teams`, admin only (`pages/teams/index.tsx:49-56`) | `#team-name` | "e.g. Creative Studio" |
| `/account/settings`, Passkeys (`pages/account/settings.tsx:433-441`) | `#passkey-name` | "Work MacBook" |

Team search is the worst case. Its `<label>` is `sr-only`, so the placeholder is the only visible label:

```tsx
// resources/scripts/components/filemax.tsx:359-364 — current
                    <label className="sr-only" htmlFor="team-search">
                        Search teams
                    </label>
                    <input
                        id="team-search"
                        placeholder="Search teams…"
```

## Target

```css
/* resources/css/app.css:79 — target */
        @apply h-11 w-full min-w-0 rounded-md border border-input bg-background px-3 text-base text-foreground placeholder:text-muted-foreground disabled:bg-muted disabled:text-muted-foreground aria-invalid:border-destructive;
```

Why this value:

- **Reuse the existing token.** `--color-muted-foreground` (`slate-600`, `#45556c`) is the project's secondary-text role, and a placeholder is secondary text. It measures **7.58:1** on white. On the disabled `bg-muted` (`#f1f5f9`) it measures 6.92:1, although WCAG exempts disabled controls anyway. The same rule already uses the token for `disabled:text-muted-foreground`, and it matches the shadcn convention for placeholders.
- **It still reads as a hint.** Typed text is `text-foreground` (`slate-900`, 17.83:1). The placeholder is clearly lighter, a 2.35:1 step between the two.
- **Why not `slate-500`?** It measures 4.76:1 and would read slightly lighter. But it is a raw palette class with no semantic token. Plan 018 would then have to invent a token for it, and it clears the floor by only 0.26. Reusing a token beats adding a value.
- **This deliberately departs from the artboard.** `FILEMAX_IMPLEMENTATION_PLAN.md` lists the placeholder color as `#8A93A0`, which measures 3.11:1 and also fails. The same document requires preserving labels and accessibility. The artboard's secondary text, `#5B6572`, is the role `muted-foreground` already stands in for.

## Dependencies

- **None must land first.**
- **Shared file, so don't run in parallel:** plan 018 edits `@theme` tokens in `resources/css/app.css`, and plan 022 edits the `h1, h2, h3` base rule at lines 56-60 of the same file. Neither touches line 79. If 018 has already replaced `placeholder:text-slate-400`, the Step 3 excerpt won't match. Follow the drift clause.
- **`tests/Browser/TransferUploadBrowserTest.php` is also edited by 012, 013, 014, 016 and 019.** This plan only inserts a new test above the helper function at the end of the file.
- **Plan 014 replaces `aria-label="Choose teams"`.** The new test opens the picker by its visible text "Choose teams", which 014 keeps, so it does not add another occurrence of that selector.

## Repo conventions to follow

- Colors come from the semantic tokens in the `@theme inline` block of `resources/css/app.css` (for example `--color-muted-foreground` at line 16). Base rules reference them as utilities inside `@apply`. The file uses 4-space indentation.
- In browser tests, inline JS goes in a `$page->script(<<<'JS' … JS)` heredoc. Pickers open by visible text (`->click('Specific teams')`), and test names are lowercase `it('…')` sentences. The exemplar is `tests/Browser/TransferUploadBrowserTest.php:222-234`.

## Steps

- [x] **Add the test first.** In `tests/Browser/TransferUploadBrowserTest.php`, find `function controlledTransferUploads(): string` (line 422 at d15b32d). Insert this test directly above it, followed by one blank line. `Team` and `User` are already imported at the top of the file.

   ```php
   it('keeps every upload form placeholder at 4.5:1 contrast and lighter than typed text', function (): void {
       $user = User::factory()->create();
       $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
       $this->actingAs($user);
       $page = visit('/')->assertSee('Drop files here');
       $page->click('Specific teams')->click('Choose teams');
       $page->page()->locator('#team-search')->waitFor();

       $contrast = $page->script(<<<'JS'
   () => {
       const paint = (...colors) => {
           const context = document.createElement('canvas').getContext('2d');
           for (const color of ['#fff', ...colors]) {
               context.fillStyle = color;
               context.fillRect(0, 0, 1, 1);
           }
           return [...context.getImageData(0, 0, 1, 1).data.slice(0, 3)];
       };
       const luminance = (rgb) => {
           const [r, g, b] = rgb.map((value) => value / 255).map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
           return 0.2126 * r + 0.7152 * g + 0.0722 * b;
       };
       const ratio = (a, b) => {
           const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x);
           return (lighter + 0.05) / (darker + 0.05);
       };
       return Object.fromEntries([...document.querySelectorAll('[placeholder]')].map((field) => {
           const style = getComputedStyle(field);
           const background = paint(style.backgroundColor);
           return [field.id, {
               placeholder: ratio(paint(style.backgroundColor, getComputedStyle(field, '::placeholder').color), background),
               text: ratio(paint(style.backgroundColor, style.color), background),
           }];
       }));
   }
   JS);

       expect($contrast)->toHaveKeys(['transfer-title', 'transfer-message', 'team-search']);
       foreach ($contrast as $field => $ratios) {
           expect($ratios['placeholder'])
               ->toBeGreaterThanOrEqual(4.5, "#{$field} placeholder contrast")
               ->toBeLessThan($ratios['text'], "#{$field} placeholder should stay lighter than typed text");
       }
   });
   ```

   What it does: it measures every `[placeholder]` field on the upload form, including team search. The canvas resolves Tailwind's `oklch()` colors to sRGB. Painting white first composites any alpha over the white page. Opening the picker by visible text keeps the test independent of plan 014.

- [x] **Prove the test fails before the fix.** Run `bun run build`, then:
   `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter='upload form placeholder'`.
   It must fail on `#transfer-title placeholder contrast` with a value of about 2.63. If it fails for any other reason, stop and report.

- [x] **Change the color.** In `resources/css/app.css`, find line 79, the `@apply` line of the `input:not([type='checkbox'])…, select, textarea` rule. Replace `placeholder:text-slate-400` with `placeholder:text-muted-foreground`, changing nothing else. Preserve unrelated classes if a sibling plan has already changed them.

- [x] **Format.**
   - Run `bun run lint`.
   - Run `vendor/bin/pint --dirty --format agent` as required by `AGENTS.md`. Review formatter changes and exclude unrelated formatting edits, preserving the pre-existing sign-in regression test.

- [x] **Build and re-run.** Run `bun run build`, then the full file (Verification → Tests).

## Boundaries

- Only these change: line 79 of `resources/css/app.css` (the placeholder class) and one new test in `tests/Browser/TransferUploadBrowserTest.php`.
- Do NOT add a token or use a raw palette class such as `slate-500`. Reuse `muted-foreground`.
- Do NOT touch the other raw palette classes: `bg-slate-200` in the `progress` rules of `app.css`, and `text-slate-400` in `ui/toast.tsx`. Plan 018 owns them.
- Do NOT touch the `h1, h2, h3` base rule. Plan 022 owns it.
- Do NOT add a visible label to team search or change its `sr-only` label. That is a possible follow-up, not part of this plan.
- Do NOT change placeholder copy.
- Do NOT change the TeamPicker trigger's `aria-label`, or the existing `[aria-label="Choose teams"]` selectors in tests. Plan 014 owns them.
- Do NOT modify `tests/Browser/AuthTeamBrowserTest.php`. It holds the user's uncommitted edit.
- Do NOT add dependencies.
- Line numbers are historical and may shift as sibling plans land. Locate the equivalent current rule/test by name, preserve earlier fixes, and reassess only if the behavior or required token has changed.

## Verification

- **Mechanical**:
  - `bun run lint`, then `bun run test:lint`, passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
- **Tests** (after `bun run build`): `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php`. Every test must pass, including the new one, which now measures about 7.58 for each placeholder. The existing tests in this file fill `#transfer-title` and use `#team-search`, so they confirm the inputs still behave. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at the current worktree URL returned by Boost `get-absolute-url` (do not assume the default worktree's host):
  - On `/`, look at the Title, Message and team search ("Specific teams" → "Choose teams") placeholders. Each should be readable and clearly lighter than text you type into the same field.
  - Check the same on `/login` (email, password), `/register`, `/forgot-password`, `/teams` (as an admin) and the Passkeys form in `/account/settings`.
  - If a placeholder, especially the email example on `/login`, reads like a pre-filled value, report it. Do not change the value.
- **Done when**: no rule in `resources/` sets a placeholder color other than `placeholder:text-muted-foreground`, and the new test and the rest of `TransferUploadBrowserTest.php` pass.

# Animation plans

These plans come from an `improve-animations` audit at commit `fb24325`. Each plan is self-contained: hand one file to any agent, or run `improve-animations execute plans/<file>`.

The audit found nothing feel-breaking: no `ease-in`, no `transition: all`, no `scale(0)`, and list hovers are correctly instant. These plans cover curve quality, interruptibility, press feedback, reduced-motion handling and three missed moments.

| # | Plan | Severity | Status |
|---|---|---|---|
| 001 | [Add a strong ease-out token (the toast keeps a standard ease)](001-strong-ease-out-token.md) | LOW | DONE |
| 002 | [Add press feedback to every Button](002-button-press-feedback.md) | LOW | DONE |
| 003 | [Move popover and dialog to interruptible transitions with proper timing](003-interruptible-popover-and-dialog.md) | MEDIUM | DONE |
| 004 | [Reduced motion: keep fades and the spinner, drop movement](004-reduced-motion-keep-fades.md) | MEDIUM | DONE |
| 005 | [Give "Your link is ready" a small entrance](005-link-ready-entrance.md) | LOW | TODO |
| 006 | [Fade the drop overlay in instead of flashing it](006-drop-overlay-fade.md) | LOW | TODO |
| 007 | [Animate the Copy link confirmation icon](007-copy-link-confirmation.md) | LOW | TODO |

## Decisions from feel checks

- **The toast uses the standard CSS `ease`, not the `--ease-out` token** (2026-09-28). The strong curve made it land too abruptly at 200ms. The token is for direct UI responses: presses, popovers, dialogs and entrances. See the revision in plan 001.

## Recommended order

001 → 002 → 003 → 004 → 005 → 006 → 007, one at a time.

## Dependencies

- **001 comes first.** It redefines `--ease-out` as `cubic-bezier(0.23, 1, 0.32, 1)`. Plans 002, 003, 005, 006 and 007 use the `ease-out` utility and get the strong curve only after 001. They still work without it, just with Tailwind's weaker default curve.
- **003 and 004 work in either order.** 003 gates scale behind `motion-safe:`, and 004 keeps opacity transitions under reduced motion. Together, reduced-motion users get pure fades.
- **Shared files, so don't run these in parallel:**
  - 001 and 004 both edit `resources/css/app.css` (different blocks) and `resources/scripts/components/ui/toast.tsx` (lines 41 and 45).
  - 005 and 006 both edit `resources/scripts/pages/transfers/create.tsx`.
- **003 may need `->wait(0.3)` in `tests/Browser/TransferManagementBrowserTest.php`.** Pest's `assertDontSee`, `assertMissing` and `assertScript` don't wait, and the plan lists the exact lines to watch.
- **Opening a popover inside a dialog that is still scaling in gives it a stale anchor measurement** (see the outcome in plan 003). Any test that opens a nested popover right after opening its dialog needs `->wait(0.3)` between the two.

## Verification shared by all plans

- **Format:** `bun run lint`, then `bun run test:lint`.
- **Lint and types:** `node_modules/.bin/vp check` must add no new errors.
- **Build:** `bun run build`. Browser tests load built assets unless `public/hot` exists.
- **Browser tests:** `php artisan test --compact <files listed in the plan>`.
- **Reduced motion:** Pest can't emulate `prefers-reduced-motion`, so it's checked by hand in the DevTools Rendering panel.

## Running a plan in a git worktree

A fresh worktree lacks the gitignored files. Link or copy them from the main checkout first:

- `node_modules` can be symlinked.
- `.env` can be symlinked.
- Copy `resources/scripts/{actions,routes,wayfinder}` (Wayfinder output).
- `vendor` **must be a real directory**. Use `cp -cR <main>/vendor vendor` (an APFS clone, no network). If `vendor` is a symlink, Composer and Pest resolve the project root to the main checkout, and every test fails with "Target class [config] does not exist".
- Executor worktrees branch from `main`, not from the feature branch. They don't contain earlier plans' changes (such as 001's `--ease-out` token), so re-run the plan's verification after applying the diff to the feature branch.

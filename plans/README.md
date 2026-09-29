# Interface improvement plans

## Interface review implementation

All listed non-animation plans from the interface review are implemented. Existing animations and the button shrink remain intact. The batches below record their scope and checks; the final batch contains the latest verification results.

| Suggested order | Plan | Outcome | Status |
| --- | --- | --- | --- |
| 1 | [011 — Keep the button shrink and fix edge clicks](011-button-press-hit-area.md) | Visual press feedback stays at 0.97; presses near the resting edge still activate. | IMPLEMENTED |
| 2 | [008 — Readable placeholders](008-placeholder-contrast.md) | Shared placeholder text passes 4.5:1 using an existing token. | IMPLEMENTED |
| 3 | [023 — Actionable request errors](023-actionable-request-errors.md) | Session, network and server failures explain recovery while preserving selected files. | IMPLEMENTED |
| 4 | [024 — Confirm upload cancellation](024-confirm-upload-cancellation.md) | Declining cancellation preserves uploaded progress and the active draft. | IMPLEMENTED |
| 5 | [025 — Accessible sign-in errors](025-sign-in-validation-accessibility.md) | Field errors are associated and focus moves to the first invalid field. | IMPLEMENTED |

**Execution rules:** Pick one plan, implement its scoped changes, run its focused checks, and review the result before starting the next. This order is recommended, not a prerequisite chain. Several plans share the upload browser-test file, so execute them sequentially and preserve earlier changes. Keep all unrelated uncommitted work; do not automatically commit or push.

**Button requirement:** Keep the shrink effect, its `active:scale-97`, 160ms duration and existing easing. Plan 011 fixes the hit area; deleting the animation does not satisfy the request. Its acceptance test checks both shrinking and successful edge activation.

**Review baseline (before implementation):** The fresh build passed. Of 20 selected sign-in/upload browser tests, 18 passed and the two existing sign-in edge-click cases failed (normal and reduced motion). These failures belong to 011, not later plans. Preserve that pre-existing regression test. Resolve the active worktree's preview URL through Boost `get-absolute-url` and rebuild before browser checks.

**First-batch verification:** 52 browser tests / 681 assertions passed across sign-in, uploads, settings, transfer management, navigation and shared downloads. Client/SSR build, resource formatting, Pint and diff checks passed. Resource lint/type checking has zero errors and four unchanged uploader warnings; the full repository formatter also flags five unchanged config files. Native cancellation-dialog interaction, screen-reader speech and real session recovery across tabs remain manual checks.

## Follow-up fixes — implemented 2026-09-29

Each of these five tasks was assigned to a separate subagent, prepared as an independent patch and reviewed before integration. Existing animations and button shrink are preserved.

| Plan | Outcome | Status |
| --- | --- | --- |
| [009 — File-input focus](009-file-input-focus-stop.md) | Native hidden inputs remove invisible tab stops; Upload photo announces its requirements. | IMPLEMENTED |
| [010 — Long text wrapping](010-long-filename-wrap.md) | Draft title and message wrap at 320px without clipping. | IMPLEMENTED |
| [012 — Upload focus](012-upload-focus-and-announcements.md) | Focus survives upload, removal, cancellation and completion without stealing deliberate header focus. | IMPLEMENTED |
| [013 — Submit validation](013-submit-validation-on-submit.md) | Submit stays available and focuses missing file/team requirements before requests begin. | IMPLEMENTED |
| [019 — Failure copy](019-failure-state-copy.md) | One recovery alert replaces duplicate and misleading draft instructions. | IMPLEMENTED |

**First follow-up verification:** 61 browser tests / 752 assertions pass across the six suites above; 33 upload feature tests / 158 assertions also pass. All newly exposed failures were reproduced before their fixes. Client/SSR build, resource formatting, Pint and diff checks pass. Lint/type checking has zero errors and the same four uploader warnings. Screen-reader speech and native chooser checks across browsers remain manual.

## Remaining plans — implemented 2026-09-29

Each plan was prepared by a separate subagent and independently reviewed.

| Plan | Outcome | Status |
| --- | --- | --- |
| [014 — Accessible trigger names](014-trigger-accessible-names.md) | Account name includes the visible user name; team picker announces its selection state. | IMPLEMENTED |
| [015 — Singular and plural counts](015-pluralize-counts.md) | File, member, transfer and remaining-time counts use the correct noun. | IMPLEMENTED |
| [016 — Clear failure heading](016-plain-failure-heading.md) | The heading says “Upload incomplete”. | IMPLEMENTED |
| [017 — Decorative icon tint](017-decorative-disc-tint.md) | Both decorative discs use the existing pale primary tint; animation classes stay intact. | IMPLEMENTED |
| [021 — Disabled visibility card](021-disabled-visibility-card.md) | Unavailable team sharing uses the existing muted background and text. | IMPLEMENTED |

**Empty-draft recovery:** Removing the last failed file now revokes the old draft before returning to file selection. Cleanup failure preserves the file, draft and transfer details for retry. A successful replacement retains title, message, sharing teams and expiry. Both cleanup success and failure/retry paths have browser regressions.

**Uploader warnings:** All four former warnings are resolved without suppression. Upload timing starts in the submit event, rendered draft state derives from uploaded-entry metadata, and the redundant void operator is removed. The empty-draft cleanup maintains that state invariant.

**Latest combined verification:** All eight affected Chrome/Chromium browser suites pass: 73 tests / 892 assertions, reconfirmed with `--browser chrome` as requested. Ten focused WebKit checks also pass (111 assertions), as do three revocation/purge feature checks (21 assertions). The earlier Firefox attempt could not launch its temporary profile; Chrome is the selected verification target, so Firefox is not an outstanding requirement. Client/SSR build, resource formatting, Pint and diff checks pass. Resource lint/type checking has zero warnings and zero errors. Empty, ready and disabled-card screenshots were inspected.

**Pre-PR verification:** The full application suite passes with Chrome: 304 tests / 3,878 assertions, using a local 512 MB PHP memory limit. An existing passkey retry test now awaits the second credential call before checking the toast, avoiding a race with the previous toast's exit. Resource checks, Pint and staged diff checks pass.

**Remaining manual verification:** Screen-reader speech, native OS file-picker interaction, native cancellation-dialog interaction and real session recovery across tabs. Earlier full-repository formatting warnings concern five unchanged configuration files; resource checks are clean. References in older documents to missing 018 or 022 do not create extra dependencies.

## Earlier animation audit

These plans come from an `improve-animations` audit at commit `fb24325`. Each plan is self-contained: hand one file to any agent, or run `improve-animations execute plans/<file>`.

The audit found nothing feel-breaking: no `ease-in`, no `transition: all`, no `scale(0)`, and list hovers are correctly instant. These plans cover curve quality, interruptibility, press feedback, reduced-motion handling and three missed moments.

| # | Plan | Severity | Status |
|---|---|---|---|
| 001 | [Add a shared ease-out token and use it in the toast](001-strong-ease-out-token.md) | LOW | DONE |
| 002 | [Add press feedback to every Button](002-button-press-feedback.md) | LOW | DONE |
| 003 | [Move popover and dialog to interruptible transitions with proper timing](003-interruptible-popover-and-dialog.md) | MEDIUM | DONE |
| 004 | [Reduced motion: keep fades and the spinner, drop movement](004-reduced-motion-keep-fades.md) | MEDIUM | DONE |
| 005 | [Give "Your link is ready" a small entrance](005-link-ready-entrance.md) | LOW | DONE |
| 006 | [Fade the drop overlay in instead of flashing it](006-drop-overlay-fade.md) | LOW | DONE |
| 007 | [Animate the Copy link confirmation icon](007-copy-link-confirmation.md) | LOW | DONE |

## Decisions from feel checks

- **One shared curve: `--ease-out` is easeOutQuad, `cubic-bezier(0.25, 0.46, 0.45, 0.94)`** (2026-09-29). The originally planned `cubic-bezier(0.23, 1, 0.32, 1)` made the toast (200ms) and the link-ready entrance (300ms) feel too quick, because it covers about a quarter of its travel in the first frame. easeOutQuad is still a pure ease-out but starts at about half that speed and decelerates evenly. Every element that eases out uses the token, the toast included. This is deliberate, so don't flag the token as weak in future audits. See the revisions in plan 001.

## Recommended order

001 → 002 → 003 → 004 → 005 → 006 → 007, one at a time.

## Dependencies

- **001 comes first.** It redefines `--ease-out` as `cubic-bezier(0.25, 0.46, 0.45, 0.94)`. Plans 002, 003, 005, 006 and 007 use the `ease-out` utility and get the shared curve only after 001. They still work without it, just with Tailwind's default ease-out.
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
- **Reduced motion:** Run the relevant browser tests with `visit($url, ['reducedMotion' => 'reduce'])` and `'no-preference'`, as the existing sign-in edge-click test does. Use DevTools for the separate visual feel check.

## Running a plan in a git worktree

A fresh worktree lacks the gitignored files. Link or copy them from the main checkout first:

- `node_modules` can be symlinked.
- `.env` can be symlinked.
- Copy `resources/scripts/{actions,routes,wayfinder}` (Wayfinder output).
- `vendor` **must be a real directory**. Use `cp -cR <main>/vendor vendor` (an APFS clone, no network). If `vendor` is a symlink, Composer and Pest resolve the project root to the main checkout, and every test fails with "Target class [config] does not exist".
- Executor worktrees branch from `main`, not from the feature branch. They don't contain earlier plans' changes (such as 001's `--ease-out` token), so re-run the plan's verification after applying the diff to the feature branch.

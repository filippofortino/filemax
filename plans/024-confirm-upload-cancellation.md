# 024 — Confirm upload cancellation implementation plan

## Implementation outcome — 2026-09-29

Implemented the native confirmation before mutations or aborts. Browser coverage verifies refusal retains progress and usable requests, acceptance preserves abort/revocation ordering, repeated acceptance is guarded, and failed revocation can be declined then retried. Native browser dialog interaction was not manually verified; these cases control window.confirm answers in the existing test helper.

Verification: client/SSR build, resource formatting, Pint and git diff --check passed. The combined six browser suites passed 52 tests / 681 assertions. Resource lint/type checking reports zero errors and four pre-existing uploader warnings; the repository-wide check stops on five unchanged config-formatting issues.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` when implementation is requested. Steps use checkboxes. This document authorizes planning only; do not implement, commit or push without the user's instruction.

**Goal:** Let the sender decline cancellation before any uploaded progress is discarded.

**Architecture:** Add one native `window.confirm()` guard to the existing `cancel()` function, after its concurrent-operation guard and before any mutation or abort. Acceptance continues through the existing cancellation/revocation path; dismissal returns immediately.

**Tech Stack:** React 19, TypeScript, Inertia 3, Laravel 13, Pest Browser and the existing controlled upload helper.

**Spec:** [Filemax implementation plan, Phase 3](../FILEMAX_IMPLEMENTATION_PLAN.md#phase-3--complete-upload-workflow), plus the interface-review finding: “Cancel upload is a neutral action that immediately aborts requests, deletes the draft and clears the selected files without confirmation.”

**Status:** IMPLEMENTED. Implementation and automated verification completed on 2026-09-29; manual limits are listed below.

**Severity:** HIGH. **Review baseline:** `8adfa07` on 2026-09-29.

## Global constraints

- Follow `AGENTS.md`; use `search-docs` for relevant version-specific documentation before implementation.
- Preserve the existing four-worker upload pool, completed-file recovery, revocation retry and navigation confirmation.
- No dependency, dialog component, new React state, backend change or unrelated button restyling.
- Dismissal changes no requests, progress, draft, entries, error or application focus. Ongoing upload work may naturally continue.
- Only clear the draft/files after successful revocation, as the current `try` block already does.

## Review focus

- Declining during active uploads must leave their existing requests usable through completion.
- Declining after a failed revocation must preserve the displayed error and retryable draft.
- Accepting with held upload/signing requests must retain the existing wait-before-revocation order.
- Repeated cancellation while already cancelling/removing must return before showing another prompt.
- Keyboard dismissal must leave focus on Cancel upload; an existing focus-restoration implementation must run only after acceptance.

## Files and collisions

- Modify `resources/scripts/pages/transfers/create.tsx`, only the start of `async function cancel()` (currently line 364).
- Modify `tests/Browser/TransferUploadBrowserTest.php`, its cancellation cases and `controlledTransferUploads()` (currently line 422).
- Plans **023** and **012** share these files: execute sequentially and locate edits by function/test names, not stale line numbers.
- Plan **012** adds `refocusRow.current = 0` to `cancel()`. Keep that assignment after confirmation; declining must not schedule focus restoration. Its cancellation focus test must explicitly accept the new prompt.
- Do not edit those plans, `FILEMAX_IMPLEMENTATION_PLAN.md`, or the user's `AuthTeamBrowserTest.php` while executing this plan.

### Task 1: Guard cancellation and pin both answers

**Interfaces:** Keep `async function cancel()` and its existing `onClick={() => void cancel()}` caller. Reuse `controlledTransferUploads()`, `window.dropUploadFiles()`, `window.finishUploads()` and its existing request counters; do not add another harness.

- [x] **1. Separate cancellation answers from navigation answers in the existing test helper.** Replace its two-line `leavePrompts`/`confirm` stub with the following. Navigation must still default to refusal and retain its own counter.

```js
window.leavePrompts = 0;
window.cancelPrompts = [];
window.allowCancellation = false;
window.confirm = (message) => {
    if (message.startsWith('Leave this upload?')) {
        window.leavePrompts++;
        return false;
    }
    window.cancelPrompts.push(message);
    return window.allowCancellation;
};
```

- [x] **2. Add one test inside `describe('concurrent upload chunks', ...)`:** `it('keeps an upload running when cancellation is declined', ...)`. Reuse that group's existing database/storage setup. Initialize the helper, drop `[24, 4]`, press Create transfer, and wait for four held uploads. Record the sole transfer ID; emit `progress(2)` on `file0.txt:1` and assert overall progress is `7`.
- [x] Focus Cancel upload and activate it with `->keys('button:has-text("Cancel upload")', 'Enter')`. With the helper's default refusal, assert the following before releasing any held request:

```php
expect($page->script('() => window.cancelPrompts'))->toBe([
    'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
]);
$page->assertScript('window.abortedUploads', 0)
    ->assertScript('window.revocations', 0)
    ->assertScript('window.activeUploads', 4)
    ->assertScript('window.uploads.length', 4)
    ->assertScript('window.uploads.every(upload => upload.state === "held")')
    ->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', 7)
    ->assertVisible('button:has-text("Cancel upload"):focus')
    ->assertSee('file0.txt')->assertSee('file1.txt');
```

- [x] Also assert the sole transfer still has the recorded ID, `status === 'uploading'` and `revoked_at === null`, and `window.finalizations === 0`. Call `window.finishUploads()`, assert Your link is ready, one finalization and no JavaScript errors. This proves refusal did not abort requests or leave the operation locked.
- [x] **3. Update every existing test that actually activates Cancel upload.** Search the test file for `Cancel upload`, including any tests added by plan 012. In each accepting case set `window.allowCancellation = true` after initializing the helper; do not make acceptance the global default. Preserve all existing assertions.
- [x] In `waits for four aborted uploads and draft revocation before allowing another upload`, assert `cancelPrompts` contains the exact prompt once after the initial acceptance. While DELETE is held, activate Cancel upload again and assert the prompt count stays one and `revocations` stays one; then release DELETE and retain the current late-event/new-upload checks. Keep its `leavePrompts === 0` assertion unchanged.
- [x] In `waits for an aborted signing response without starting its upload`, accept cancellation and assert one exact prompt. Retain the three-aborted-uploads, zero-revocations-before-signing-release and successful-revocation assertions.
- [x] In `preserves the draft when cancellation fails and allows revocation to be retried`, accept the first cancellation. After the 503 error, set `allowCancellation = false` and activate Cancel upload from the keyboard. Assert the exact prompt appeared twice, the error and both filenames remain, focus remains on Cancel upload, `abortedUploads === 4`, `revocations === 1`, `finalizations === 0`, and the same draft remains unrevoked. Set the flag true, accept again, and assert three exact prompts, two revocation attempts, empty upload state and successful revocation.

- [x] **4. Run the red checks before changing production code.** Build fresh assets, then run the focused tests:

```sh
bun run build
php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter='cancellation|draft revocation|aborted signing'
```

Expected: the new prompt/refusal assertions fail because `cancel()` never calls `confirm()`. Unrelated infrastructure failures do not establish the red state; diagnose them without weakening assertions.

- [x] **5. Add the guard immediately after `if (cancelling.current || removing) return;`.** Use this exact copy; leave the rest of `cancel()` unchanged:

```tsx
if (
    !window.confirm(
        'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
    )
) return;
```

This must precede `cancelling.current = true`, `operating.current = true`, `abort.current?.abort()`, `setBusy(true)` and any plan-012 focus assignment. Keep current `catch`/`finally` and successful-revocation cleanup intact. The native prompt supplies confirmation; no destructive styling change is required.

- [x] **6. Format the touched files, rebuild and run green checks.** Inspect formatter changes; retain unrelated pre-existing edits.

```sh
NODE_OPTIONS='--experimental-strip-types' node_modules/.bin/vp fmt resources/scripts/pages/transfers/create.tsx
vendor/bin/pint --dirty --format agent
bun run build
php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter='cancellation|draft revocation|aborted signing'
php artisan test --compact tests/Browser/TransferUploadBrowserTest.php
git diff --check
```

Expected: focused and complete upload browser tests pass, including navigation guards and cancellation recovery. If browser binaries are unavailable, report verification blocked; do not install dependencies or claim success.

- [ ] **7. Check the real native prompt locally with a disposable upload.** Resolve the URL with `get-absolute-url`. Activate Cancel upload by keyboard; Escape must return focus to the button and upload progress must continue. Repeat and accept; the existing cancellation path must finish and return to the empty uploader. Do not rely solely on the stub to claim native-dialog behavior.
- [x] **8. Review scope and report.** Confirm exactly the two listed implementation/test files changed for this task, summarize red/green results and any blocked native check. Do not commit or push automatically.

**Acceptance:** The exact consequence warning appears before cancellation changes anything; declining preserves the usable draft and focus; acceptance retains cancellation ordering and failed-revocation retry. All existing cancellation tests explicitly choose an answer, and navigation prompts remain independently asserted.

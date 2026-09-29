# 023 — Actionable request errors Implementation Plan

## Implementation outcome — 2026-09-29

Implemented status-first session/server copy, network recovery copy and defensive validation extraction in request(). Nine browser cases preserve the selected file and retry to one ready transfer. Existing signing, revocation, cookie-rotation and download scenarios pass. Seventeen direct request checks also pass, including abort identity and empty successful responses. Real expired-session recovery across two tabs was not manually verified.

Verification: client/SSR build, resource formatting, Pint and git diff --check passed. The combined six browser suites passed 52 tests / 681 assertions. Resource lint/type checking reports zero errors and four pre-existing uploader warnings; the repository-wide check stops on five unchanged config-formatting issues.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking. This document authorizes planning only; do not implement, commit or push without the user's execution instruction.

**Status:** IMPLEMENTED · **Severity:** HIGH · **Category:** Writing

**Review baseline:** `8adfa07` on 2026-09-29.

**Goal:** Replace raw session, network and server diagnostics with usable recovery instructions while preserving validation, uploads and cancellation.

**Architecture:** Correct the existing shared `request()` helper. Its callers already render caught messages; keep those callers, request signatures and state machines intact. Use local branches and string literals, with no error registry, new abstraction or dependency.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, native Fetch, Pest 5 Browser.

**Spec:** [Filemax implementation plan](../FILEMAX_IMPLEMENTATION_PLAN.md), especially upload recovery and preserving completed files; the current interface review's actionable-error finding.

## Global constraints

- Keep button shrink/press feedback unchanged, as explicitly requested.
- Read `AGENTS.md`; use Boost `search-docs` for relevant testing/error-response documentation before code changes. Resolve manual-preview URLs with Boost `get-absolute-url`; do not hardcode a Herd URL or start another app server.
- Preserve `request<T>(url: string, method = 'POST', data?: unknown, signal?: AbortSignal): Promise<T>`, current XSRF-cookie lookup, JSON request headers and caller-owned retry behavior.
- Do not change `uploadPart()` XHR messages, cancellation mechanics, the upload recovery panel, passkey errors or Inertia's network toast. Those have separate owners/plans.
- No new dependencies, automatic retry, navigation, upload restart or session-storage persistence. Keep all unrelated uncommitted work.

## Evidence and scope

At `resources/scripts/lib/http.ts:28–35`, `body.message` wins before the 419 fallback. Laravel's actual 419 JSON contains `CSRF token mismatch.`, so the friendly fallback is bypassed. A rejected `fetch()` also escapes without translation. `create.tsx:353` renders the caught message; its recovery paragraph at `:943` requires a draft and does not explain initial request failures.

All affected callers were traced: `create.tsx` creates drafts, signs/completes files, repairs sharing, finalizes, removes files and cancels; `components/downloads.tsx` starts/polls archives and starts individual downloads. `pages/auth/login.tsx` imports an unrelated route helper named `request`; do not edit it.

## Error precedence and exact copy

Apply in this order; a malformed body must never override an HTTP status or cause a secondary exception.

| Condition | Required behavior / exact displayed copy |
|---|---|
| Intentional abort while fetching or reading JSON | Rethrow the original rejection when `signal?.aborted` or the caught error's name is `AbortError`; never translate it into a network/server error. |
| Other fetch rejection | `Connection lost. Check your connection and try again.` |
| HTTP 401 or 419, regardless of body | `Your session expired. Open Filemax in another tab, sign in if needed, then retry here. Keep this tab open.` |
| HTTP 500–599, regardless of body | `Filemax is temporarily unavailable. Please try again in a moment.` |
| HTTP 422 with usable validation errors | Keep server field messages unchanged, flattened and joined with one space as today; accept nonempty strings only. Do not replace team-membership repair instructions. |
| Other non-OK JSON with a nonempty string `message` | Preserve that existing application message; broader business-error rewriting is outside this finding. |
| Non-OK HTML, invalid JSON, null/primitive body, malformed `errors`/`message`, or no usable message | `This request could not be completed. Please try again.` |

For 422, use a nonempty string `message` only if no usable field messages remain; otherwise use the generic fallback. Treat arrays/objects in `message` as unusable. Do not append a retry suffix to valid field messages.

401 is included because authenticated upload mutations can receive it after session loss; the existing helper obtains the current XSRF cookie on every call. Another-tab recovery lets the user sign in without discarding selected files or the active draft. The same instruction is neutral for download callers. Do not tell users to refresh this tab or claim their unfinished upload persists after navigation.

Successful response parsing/shape validation is outside this fix: preserve its existing behavior, including empty-body handling. For non-OK malformed JSON, preserve the original HTTP status and select the table's fallback. Avoid a broad outer catch that accidentally relabels deliberately thrown HTTP errors as network failures.

## Review focus

- 401/419 with raw diagnostic or conflicting validation bodies must still show session recovery; covered by the table-driven test.
- Network loss before draft creation must preserve selected files and offer retry; covered by the same test and its successful second submission.
- 5xx JSON/HTML and malformed 4xx bodies must not leak diagnostics or throw while extracting messages; covered by the same dataset.
- 422 field validation must retain its actionable text; covered by the dataset and existing team-repair browser scenario.
- Intentional cancellation must remain silent and preserve existing revocation/retry behavior; retain the cancellation regressions below and add no-alert assertions.

## Task 1: Normalize shared request failures

**Modify:** `resources/scripts/lib/http.ts` (`request()` only).
**Test:** `tests/Browser/TransferUploadBrowserTest.php`; regression coverage also uses `tests/Browser/SharedTransferBrowserTest.php` unchanged.
**Interfaces:** Consumes native `fetch`, Laravel JSON errors and existing `controlledTransferUploads()` test seam; produces the same `Promise<T>` or an `Error` carrying the table's copy. Abort rejections retain their original identity.

- [x] Add one dataset-driven test named `shows actionable request errors before a draft exists` inside the existing concurrent-upload describe. Use `controlledTransferUploads()`, `window.dropUploadFiles([4])`, and a one-shot outer `window.fetch` wrapper matching only `POST /transfers`; subsequent requests delegate to the captured fetch. Install the wrapper after the existing harness, so synthetic failed responses bypass its successful-draft parser.
- [x] Dataset cases: rejected `TypeError('Failed to fetch')`; 401 `{message: 'Unauthenticated.'}`; 419 `{message: 'CSRF token mismatch.', errors: {email: ['Wrong precedence']}}`; 500 `{message: 'Private provider diagnostics'}`; 503 HTML; 400 invalid JSON; 400 JSON `null`; 400 `{errors: 'invalid', message: {internal: 'diagnostic'}}`; and 422 `{errors: {message: ['The message field must not be greater than 5000 characters.']}, message: 'Generic validation summary'}`. Each case has the exact expected text from the table as dataset data; the 422 case expects only its field message.
- [x] In each case, submit and assert the expected text in `[role="alert"]`, selected `file0.txt` still visible, no `Your link is ready`, no network/provider/CSRF internals, no JavaScript errors, `Transfer::query()->count() === 0`, and zero `window.uploads`. Enable `window.autoReleaseUploads`, submit again and assert `Your link is ready` and exactly one ready transfer. The injected failure is synthetic; this checks retry wiring without actually expiring the test login.
- [x] Update the signing-failure branch in `retains successful parts after a concurrent failure and retries only missing bytes` (currently `:73`) to expect the 5xx copy instead of `Signing temporarily unavailable.`. Keep its XHR branch's `Connection lost.` assertion and all missing-byte/count checks unchanged.
- [x] Update `preserves the draft when cancellation fails and allows revocation to be retried` (currently `:154`) to expect the 5xx copy instead of `Revocation temporarily unavailable.`. Keep both Cancel upload interactions and draft/revocation assertions. Keep synthetic fixture messages unchanged so tests prove normalization rather than merely changed fixtures. The file-completion 503 test asserts the aggregate upload error and needs no text replacement.
- [x] In `waits for an aborted signing response without starting its upload` and `waits for four aborted uploads and draft revocation before allowing another upload`, add no-alert assertions after cancellation settles. Retain their abort counts, no-new-upload and revocation assertions; do not introduce a cancel confirmation here.
- [x] **RED:** run `bun run build`, then `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter="actionable request errors"`. Expect failures on raw/default error copy, not setup/build failures. Record the meaningful failure before editing source.
- [x] Implement the table inside existing `request()`: catch fetch rejection narrowly; preserve aborts; parse error bodies defensively; resolve 401/419 and 5xx before server content; retain 422 strings and existing other 4xx messages; use the exact fallback. No caller changes are needed.
- [x] Format touched TypeScript with `NODE_OPTIONS='--experimental-strip-types' node_modules/.bin/vp fmt resources/scripts/lib/http.ts`; run `vendor/bin/pint --dirty --format agent` for changed PHP tests, reviewing formatter changes against the pre-existing dirty worktree.
- [x] **GREEN:** run `bun run build`, then rerun the RED test command. Expect all dataset cases to pass.
- [x] Run `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/SharedTransferBrowserTest.php` once, sequentially with other browser runs; expect both suites to pass. This covers per-file/finalize retry, membership repair, cancellation, cookie rotation and both download callers.
- [x] Run `bun run test:lint`, `node_modules/.bin/vp check`, and `git diff --check`; distinguish pre-existing worktree failures from this change rather than modifying unrelated files.

## Acceptance and handoff

- [x] Initial offline/419/500 submissions show exactly the chosen recovery copy without losing selected files; retry works after connectivity/authentication is restored.
- [x] Existing partial-upload retries send only missing bytes, team-validation text remains actionable, and cancellation still aborts/revokes without a new error. Preserve any confirmation introduced by 024; this plan neither adds nor removes one.
- [x] Successful shared downloads still work; no generic message contains upload-only advice, raw diagnostics or a forced refresh.
- [ ] On a Boost-resolved local URL, manually verify a session-expired active draft can recover after signing in in another tab, while keeping the original tab open. Do this only with disposable local test data; do not terminate a real user session. If unavailable, report this interaction as unverified rather than inferred from synthetic responses.
- [x] Mark this plan complete only after the focused checks pass. Do not commit or push unless the user separately asks.

## Dependencies and collisions

No implementation prerequisite. Execute shared-file plans sequentially. Plan 019 edits `uploadPart()` and upload-state copy; this plan edits only `request()` in that file, and its 5xx normalization intentionally supersedes display of any server 503 message. Plan 024 owns cancellation confirmation and may adjust the same cancellation tests: retain whichever interaction it establishes if it has already landed; never add or remove its confirmation here. Plans 009/012/013/015/016 also edit the upload test file, so locate tests by name rather than frozen line numbers. Preserve the existing button shrink feedback throughout.

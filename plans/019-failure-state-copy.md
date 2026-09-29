# 019 — Say the recovery instruction once in the failed state

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: LOW
- **Category**: Writing
- **Estimated scope**: 3 source files (`create.tsx`: 1 string, 1 condition, 1 deleted block, 1 import; `lib/http.ts`: 2 strings; `TransferUploadMutation.php`: 1 string), 2 test files (4 added lines, 1 chained assertion)

## Implementation outcome — 2026-09-29

Recovery guidance now appears once in the upload-failure alert. Removed the generic draft recovery panel, shortened per-file XHR errors, and show the upload tab reminder only while busy. Partial-upload, session-expiry and failed-cancellation regressions failed before the fix and pass afterward. The original backend 503-copy edit is superseded by implemented plan 023: request() already normalizes all 5xx responses. The 33 upload feature tests also pass (158 assertions). The backend and request() behavior remain unchanged; native cancellation remains intact. VoiceOver speech remains unverified.

Each task was prepared by its own subagent and reviewed independently. The combined six browser suites pass: 61 tests / 752 assertions. Client/SSR build, resource formatting, Pint and diff checks pass; lint/type checking reports zero errors and the four unchanged uploader warnings. The original plan below records the review baseline; current implementation details above take precedence.

## Problem

When a file fails mid-upload, the new-transfer page says "completed files are safe" three times and gives the recovery instruction twice. It also keeps saying "Keep this tab open until the upload finishes." when nothing is uploading. Measured at 320px and 1280px, "safe" appears 3 times: in the per-file error, the red alert and the blue info box. All that reassurance buries the one instruction the user needs.

The red alert (`ErrorMessage`, `role="alert"`) is the one place that should carry the reassurance and the instruction:

```tsx
// resources/scripts/pages/transfers/create.tsx:331 — current
                setError(
                    'Some files could not be uploaded. Completed files are safe — retry sends only what is missing.',
                );
```

The info box under it repeats the reassurance. It shows for **every** error while a draft exists, including a failed finalize, a failed team repair and a failed remove or cancel. In those cases no file has failed, so "Retry failed files" is wrong:

```tsx
// resources/scripts/pages/transfers/create.tsx:942 — current
                        <ErrorMessage>{error}</ErrorMessage>
                        {error && hasDraft && (
                            <p className="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-slate-700">
                                <HugeiconsIcon
                                    className="mr-1 inline"
                                    icon={Alert02Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                Completed files are safe. Retry failed files or
                                remove them to finish with the rest.
                            </p>
                        )}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:2 — current (only used by the info box, line 947)
    Alert02Icon,
```

The tab line shows whenever a draft exists, so it stays up after the failure:

```tsx
// resources/scripts/pages/transfers/create.tsx:692 — current
                            {hasDraft && (
                                <p className="text-center text-sm text-muted-foreground">
                                    Keep this tab open until the upload
                                    finishes.
                                </p>
                            )}
```

Each per-file error repeats half of the alert. `uploadPart` has one caller, the upload worker (`create.tsx:287`), and its errors only reach `entry.error`, the red line under each failed row. `request()` and its fallback messages are shared by every request and are not part of this plan.

```ts
// resources/scripts/lib/http.ts:56 — current
        xhr.onload = () =>
            xhr.status >= 200 && xhr.status < 300
                ? resolve()
                : reject(
                      new Error(
                          'Upload interrupted. Retry sends only what is missing.',
                      ),
                  );
        xhr.onerror = () =>
            reject(
                new Error('Connection lost. Your completed files are safe.'),
            );
```

The server's storage-outage message does the same. The review missed it. It reaches the page through `request()`:
- as the per-file error when signing or completing a file fails;
- as the alert when removing a draft file fails. There, "retry the missing files" is wrong advice.

```php
// app/Services/TransferUploadMutation.php:34 — current
                return response()->json(['message' => 'Storage is temporarily unavailable. Your completed files are safe; retry the missing files.'], 503);
```

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:331 — target
                setError(
                    'Some files could not be uploaded. Completed files are safe — retry sends only what’s missing, or remove the failed files to finish with the rest.',
                );
```

```tsx
// resources/scripts/pages/transfers/create.tsx:942 — target (the info box is gone)
                        <ErrorMessage>{error}</ErrorMessage>
                        <Button
                            size="lg"
                            type="submit"
```

```tsx
// resources/scripts/pages/transfers/create.tsx:1 — target (Alert02Icon removed)
import {
    Cancel01Icon,
    Globe02Icon,
```

```tsx
// resources/scripts/pages/transfers/create.tsx:692 — target
                            {busy && hasDraft && (
                                <p className="text-center text-sm text-muted-foreground">
                                    Keep this tab open until the upload
                                    finishes.
                                </p>
                            )}
```

```ts
// resources/scripts/lib/http.ts:56 — target (as the formatter prints it)
        xhr.onload = () =>
            xhr.status >= 200 && xhr.status < 300
                ? resolve()
                : reject(new Error('Upload interrupted.'));
        xhr.onerror = () => reject(new Error('Connection lost.'));
```

```php
// app/Services/TransferUploadMutation.php:34 — target
                return response()->json(['message' => 'Storage is temporarily unavailable.'], 503);
```

Why:

- **One reassurance and one instruction, both in the alert.** The per-file errors say what happened ("Connection lost.", "Upload interrupted."). The alert says what is safe and what to do. Nothing else repeats it.
- **The alert keeps the product copy.** `FILEMAX_IMPLEMENTATION_PLAN.md` "Global constraints" fixes the recovery line as "The other two files are safe — retry sends only what's missing." The review's proposal ("Retry to send only what's missing, or …") dropped the reassurance and reworded the spec's phrase. This target keeps "safe — retry sends only what’s missing" and uses the spec's contraction instead of "what is".
- **The info box's one unique idea moves into the alert.** The box was the only place that said you can remove failed files and finish without them. "Remove" matches the rows' `Remove {name}` buttons, and "failed" matches the rows' "Failed" label. Deleting the box is cheaper than rewording it. It also stops the wrong "Retry failed files" advice on non-upload errors, and it frees the `Alert02Icon` import.
- **The first sentence stays "Some files could not be uploaded."** `TransferUploadBrowserTest.php:77, 103, 215, 267` assert it, and they keep passing unchanged.
- **The apostrophe is ’.** UI copy in this repo uses the typographic apostrophe (`passkey-button.tsx:77` "aren’t", `settings.tsx:63` "You’re"), alongside the Unicode "—" and "…" already in this file.
- **`busy && hasDraft`, not just `busy`.** The line keeps its current timing during an upload. It appears when the draft exists, at the same moment "Add more files" disappears, so the layout changes once, not twice. It now hides whenever nothing is uploading: the failed state, "Ready to finish", and after a failed cancel. It also shows briefly while a draft file is being removed or the upload is cancelled. The page treats those moments as `busy` too, and the heading reads "Uploading…" then, so the two stay consistent.
- **"Connection lost." stays word for word.** `TransferUploadBrowserTest.php:73` asserts it for the network-failure path, and it keeps passing.
- **Skipped: a count ("The other two files are safe").** It needs pluralisation and a zero case (a single-file upload that fails has no completed files). Plan 015 owns counts. "Completed files" already covers any number. Add a count only if the artboard comparison demands it.
- **Skipped: the `TransferStorage.php` messages ("A part is missing. Retry this file to continue." and similar).** They don't repeat the reassurance. "Retry this file" names a per-file retry that doesn't exist (retry is global), but that is a separate terminology issue. Leave them.

## Dependencies

None have to land first. The files are shared, so land plans one at a time:

- **016** changes the first `assertSee(...)` on `TransferUploadBrowserTest.php:267` ("A little interruption" → "Upload incomplete"). This plan does **not** edit line 267. It inserts new lines before `$page->screenshot(filename: 'upload-failed');`, so 016's note that 019 rewrites that line no longer applies, and either order works.
- **018** replaces raw palette classes in `create.tsx`, including the info box's `text-slate-700` (~944). If 018 landed first, the box's `className` differs. Delete the block anyway, located by its copy (step 6). If this plan lands first, 018 has one fewer occurrence to convert.
- **012** relies on the failure being announced through `ErrorMessage`'s `role="alert"`. This plan keeps `ErrorMessage` and changes only the string passed to `setError`. 012 also edits `send()`, `removeFile()`, `cancel()` and the status `<h1>`, which shifts line numbers only.
- **013** edits the submit `<Button>` directly below the deleted info box (its `disabled` prop, `aria-describedby`) and the hint `<p>` further down (~983). Delete only the `{error && hasDraft && ( … )}` block. Leave the `<Button>` opening tag exactly as you find it.
- **010** edits the draft-state Title/Message boxes. **009, 015, 017, 021, 023** edit other lines of `create.tsx`. They only shift line numbers.
- **`TransferUploadBrowserTest.php`** is also edited by 008, 009, 010, 011, 012, 013, 014, 015 and 016. This plan inserts lines at two anchors inside `it('recovers a failed multi-file upload while preserving finished files and sharing', …)`.

## Repo conventions to follow

- UI copy is inline string literals in sentence case, with "’", "—" and "…" as Unicode characters. There is no translation layer.
- Browser tests assert visible copy with `->assertSee()` / `->assertDontSee()`, and read page text with `$page->script('() => document.body.innerText')` (see lines 230 and 271 of the same test).
- Feature tests chain response assertions on `$this->postJson(...)`.

## Steps

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

1. Run `grep -rn "files are safe\|only what is missing\|Keep this tab open\|Retry failed files" resources/scripts app tests`. At d15b32d it prints exactly six hits: `TransferUploadMutation.php:34`, `http.ts:61`, `http.ts:66`, `create.tsx:332`, `create.tsx:694`, `create.tsx:951`. If it prints another hit, STOP and report.
2. In `tests/Browser/TransferUploadBrowserTest.php`, inside `it('recovers a failed multi-file upload while preserving finished files and sharing', …)`, insert this line directly **before** `    $page->screenshot(filename: 'upload-progress');` (line 265):
   ```php
       $page->assertSee('Keep this tab open until the upload finishes.');
   ```
   It passes today and afterwards. It guards against deleting the line instead of hiding it after a failure.
3. In the same test, insert these lines directly **before** `    $page->screenshot(filename: 'upload-failed');` (line 268). That puts them after line 267's `->assertSee('Some files could not be uploaded.');`, which is where the failure is fully rendered. Leave line 267 itself alone (016 owns its first half):
   ```php
       expect($page->script('() => (document.body.innerText.match(/safe/g) ?? []).length'))->toBe(1);
       $page->assertDontSee('Keep this tab open')
           ->assertSee('Completed files are safe — retry sends only what’s missing, or remove the failed files to finish with the rest.');
   ```
   Type "—" and "’" as Unicode characters, not ASCII.
4. In `tests/Feature/TransferUploadTest.php`, in `it('keeps the upload identifier after a provider error so retry does not orphan parts', …)`, line 227, find:
   `$this->postJson(route('transfers.uploads.sign', [$transfer, $file, 1]))->assertStatus(503)->assertDontSee('Private provider diagnostics');`
   Insert `->assertJsonPath('message', 'Storage is temporarily unavailable.')` after `->assertStatus(503)`. Change nothing else on the line.
5. Run `vendor/bin/pint --dirty --format agent`. Then confirm both tests fail on the old copy:
   - `php artisan test --compact tests/Feature/TransferUploadTest.php --filter="keeps the upload identifier"` must fail on the `message` JSON path.
   - Run `bun run build`, then `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter="recovers a failed multi-file upload"`. It must fail at the new `expect(...)` with 3 instead of 1. The 3 hits are the per-file "Connection lost. Your completed files are safe.", the alert and the info box.
   If either passes, STOP and report.
6. In `resources/scripts/pages/transfers/create.tsx`, directly below `<ErrorMessage>{error}</ErrorMessage>` (~942), delete the whole `{error && hasDraft && ( … )}` block. It contains `Completed files are safe. Retry failed files or` / `remove them to finish with the rest.` and ends with the `)}` before `<Button` (~943–954, 12 lines). Keep `<ErrorMessage>` and the `<Button>` below it untouched.
7. In the same file, line 2, delete `    Alert02Icon,` from the `@hugeicons/core-free-icons` import. Then run `grep -n Alert02Icon resources/scripts/pages/transfers/create.tsx`, which must print nothing.
8. In the same file (~331–333), inside `runUpload`, replace the `setError(` string `'Some files could not be uploaded. Completed files are safe — retry sends only what is missing.'` with `'Some files could not be uploaded. Completed files are safe — retry sends only what’s missing, or remove the failed files to finish with the rest.'`.
9. In the same file (~692), find the `{hasDraft && (` that directly precedes `<p className="text-center text-sm text-muted-foreground">` / `Keep this tab open until the upload`. Change it to `{busy && hasDraft && (`. Leave the other `{hasDraft && (` blocks in the file alone, such as the percentage `<strong>` (~565) and "Cancel upload" (~972).
10. In `resources/scripts/lib/http.ts` (~56–67), inside `uploadPart`, change `'Upload interrupted. Retry sends only what is missing.'` to `'Upload interrupted.'` and `'Connection lost. Your completed files are safe.'` to `'Connection lost.'`. Don't touch `request()`.
11. In `app/Services/TransferUploadMutation.php` line 34, change `'Storage is temporarily unavailable. Your completed files are safe; retry the missing files.'` to `'Storage is temporarily unavailable.'`.
12. Run `vendor/bin/pint --dirty --format agent`, then `bun run lint`. The formatter collapses the `http.ts` rejects into the one-line form shown in Target.

## Boundaries

- Only the strings, the one condition, the deleted info box and its import change in source, plus the added test lines.
- Do NOT change the first sentence "Some files could not be uploaded.", or `ErrorMessage` / its `role="alert"` (012 relies on it).
- Do NOT touch the status heading (016), the status `<h1>`'s attributes, `send()`, `removeFile()` or `cancel()` (012), the submit `<Button>` or the hint paragraphs (013), the counts (015), the percentage (023), or the per-row "Failed" label.
- Do NOT change `request()` or its fallback messages in `lib/http.ts`. They serve every request.
- Do NOT change the `TransferStorage.php` "Retry this file" messages, or the unrelated "Connection lost" toast title in `resources/scripts/app.tsx:15`.
- Do NOT add a file count to the alert, or any new component, icon or state.
- Do NOT edit line 267 of `TransferUploadBrowserTest.php` (016), and do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `vendor/bin/pint --dirty --format agent` leaves nothing to fix.
  - `grep -rn "files are safe\|only what is missing\|Retry failed files" resources/scripts app` prints nothing. The alert now says "Completed files are safe", which the pattern doesn't match.
  - `grep -rn "safe" resources/scripts/lib/http.ts app/Services/TransferUploadMutation.php` prints nothing. The non-2xx "Upload interrupted." path has no browser test, so this grep covers it.
- **Tests**:
  - `php artisan test --compact tests/Feature/TransferUploadTest.php`: all tests pass.
  - After `bun run build`: `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php`. The updated test must pass (it failed in step 5), and so must the rest of the file, including line 73 ("Connection lost.") and lines 77, 103 and 215 ("Some files could not be uploaded."). If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  1. On the new-transfer page, add one small file and one large file (a few hundred MB). Press "Create transfer". While it uploads, "Keep this tab open until the upload finishes." shows under the file list.
  2. Set the DevTools Network panel to Offline. In the failed state, the large file's row says "Connection lost." only. The red text reads the new alert, and there is no blue box above "Retry and create link". The tab line is gone. Cmd-F "safe" finds 1 match.
  3. Resize to 320px wide. The alert wraps without horizontal scrolling.
  4. Go back online. Remove the failed file with its × button, then press "Retry and create link". The page shows "Your link is ready" with the remaining file.
  5. With VoiceOver on, repeat step 2. The alert is announced once, with the new text.
- **Done when**: the failed state says "safe" once and gives the retry-or-remove instruction once, in the alert; there is no info box; the tab line shows only while uploading; and both test files pass.

# 016 — Say plainly that the upload is incomplete

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: MEDIUM
- **Category**: Writing
- **Estimated scope**: 1 source file (1 string), 1 test file (1 assertion)

## Implementation outcome — 2026-09-29

The failure heading now says “Upload incomplete”. Updated its existing recovery assertion, confirmed it failed before the copy edit, and verified the recovery flow afterward. Focus handling and the alert text remain intact.

A separate subagent prepared this task and independent review found no actionable issues. The original plan below records the earlier baseline; implementation notes here take precedence. See README for final combined verification and remaining manual checks.

## Problem

When a file fails mid-upload, the new-transfer page's status heading reads "A little interruption". It is the page's `<h1>`, shown beside the percentage and above the per-file "Failed" labels and the red alert. The idiom plays down a failed upload and doesn't say what happened. Errors need calm, plain wording with no playfulness, and an idiom is also a translation risk.

```tsx
// resources/scripts/pages/transfers/create.tsx:552 — current
                                    <h1 className="text-3xl">
                                        {busy
                                            ? 'Uploading…'
                                            : hasDraft
                                              ? entries.some(
                                                    (entry) =>
                                                        entry.status ===
                                                        'failed',
                                                )
                                                  ? 'A little interruption'
                                                  : 'Ready to finish'
                                              : 'Ready to send'}
                                    </h1>
```

```php
// tests/Browser/TransferUploadBrowserTest.php:267 — current
    $page->assertSee('A little interruption')->assertSee('Some files could not be uploaded.');
```

At d15b32d those are the only two occurrences of the string in `resources/` and `tests/`. The app has no translation layer, so copy is written inline.

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:561 — target
                                                  ? 'Upload incomplete'
```

```php
// tests/Browser/TransferUploadBrowserTest.php:267 — target
    $page->assertSee('Upload incomplete')->assertSee('Some files could not be uploaded.');
```

Why:

- **It says what happened, plainly.** The transfer didn't finish uploading. That fits the better-writing error tone ("calm, plain, zero playfulness") and translates word for word.
- **"Incomplete", not "failed".** The v2 artboard's failed state titles its red alert "Upload failed". Here, though, the heading sits beside the overall percentage (for example 67%), above rows that still say "Done", and above an alert that says completed files are safe. "Upload failed 67%" contradicts all of that. "Upload incomplete" matches it, and the per-file "Failed" label already marks what failed. It is also as cheap as the review's proposal: one string.
- **It matches the other headings in the ternary.** "Uploading…", "Ready to finish" and "Ready to send" each name the transfer's state in sentence case with no end punctuation. "Upload incomplete" does the same. Those three are already plain, so they stay unchanged.
- **The layout is unaffected.** The new string is shorter (17 characters against 21), so the heading can't wrap onto more lines than before.
- **The test only swaps the string.** `assertSee` checks for visible text, which is the outcome here. Keeping the same assertion keeps the test's timing as it is: the check runs right after the forced failure.

## Dependencies

None have to land first. The files are shared, so land plans one at a time:

- **012** adds attributes to this same status `<h1>` (~552) and may add announcement text. This plan changes only the string literal inside it, so locate it by the literal, not by the `<h1>` tag. If 012 has moved the ternary into a variable or added an announcement that repeats the string, step 1's grep finds every copy.
- **019** rewrites the second half of the same test line (`->assertSee('Some files could not be uploaded.')`). This plan changes only the first `assertSee(...)` call on that line.
- **023** edits the percentage `<strong>` just below (~566). **015** edits the counts at ~586–588. Other `create.tsx` plans (009, 010, 013, 017, 021) shift line numbers only.

## Repo conventions to follow

- UI copy is inline string literals in JSX, in sentence case. Headings have no end punctuation, and "…" is the Unicode character.
- Browser tests assert visible copy with `->assertSee('…')`.

## Steps

1. Run `grep -rn "A little interruption" resources/scripts tests`. At d15b32d it prints exactly `create.tsx:561` and `TransferUploadBrowserTest.php:267`. If a sibling plan has added more copies, change each one to `Upload incomplete` in the steps below. If a hit can't be mapped to one of them, STOP and report.
2. In `tests/Browser/TransferUploadBrowserTest.php`, line 267, inside `it('recovers a failed multi-file upload while preserving finished files and sharing', …)`, find `$page->assertSee('A little interruption')`. Change it to `$page->assertSee('Upload incomplete')` and leave the rest of the line alone.
3. Run `vendor/bin/pint --dirty --format agent`.
4. Confirm the test catches the old copy. Run `bun run build`, then:
   `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter="recovers a failed multi-file upload"`.
   It must fail at line 267 with "Expected to see text [Upload incomplete]", because the page still renders "A little interruption". If it passes, STOP and report.
5. In `resources/scripts/pages/transfers/create.tsx`, line 561, find `? 'A little interruption'`. The next line is `: 'Ready to finish'`. Replace it with `? 'Upload incomplete'`. Change nothing else in the ternary or the `<h1>`.
6. Run `bun run lint`.

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only the one string literal and the one test assertion change.
- Do NOT change "Uploading…", "Ready to finish" or "Ready to send", or the submit button's labels.
- Do NOT touch the `<h1>`'s attributes or structure (012), the percentage `<strong>` (023), the per-file "Failed" label, the alert or info-box copy and the `lib/http.ts` error strings (019), the count strings (015), or heading wrapping in `app.css` (022).
- Do NOT touch `->assertSee('Some files could not be uploaded.')` on line 267 (019).
- Do NOT add a translation layer or pull the copy out into constants.
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `vendor/bin/pint --dirty --format agent` leaves nothing to fix.
  - `grep -rn "A little interruption" resources tests` prints nothing.
- **Tests**: after `bun run build`, run `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php`. The updated test must pass (it failed in step 4), and so must every other test in the file. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  1. On the new-transfer page, add one small file and one large file (a few hundred MB). Press "Create transfer". While it reads "Uploading…", set the DevTools Network panel to Offline. The heading changes to "Upload incomplete", beside the percentage, with the large file marked "Failed".
  2. Resize to 390px wide. The page doesn't scroll sideways, and the percentage stays to the right of the heading.
  3. With VoiceOver on, the rotor's headings list reads "Upload incomplete".
  4. Go back online and press "Retry and create link". The heading reads "Uploading…", then the page shows "Your link is ready".
- **Done when**: the failed-upload heading reads "Upload incomplete", no source or test file contains "A little interruption", and `TransferUploadBrowserTest.php` passes.

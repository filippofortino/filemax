# 012 — Keep focus and announce state changes during an upload

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: MEDIUM
- **Category**: Accessibility
- **Estimated scope**: 1 source file (`create.tsx`: 5 refs, 2 small effects, 3 one-line inserts, 4 JSX attributes), 1 test file (2 new browser tests)

## Implementation outcome — 2026-09-29

Focus now moves to the upload status, the nearest surviving file control after removal, Browse files after an empty list or confirmed cancellation, and the ready heading after completion. It only restores lost focus and preserves deliberate header focus. Both planned regressions failed before the fix and pass afterward; an additional header-focus regression passes. Cancellation sets the focus target only after the existing native confirmation is accepted. VoiceOver speech remains unverified.

Each task was prepared by its own subagent and reviewed independently. The combined six browser suites pass: 61 tests / 752 assertions. Client/SSR build, resource formatting, Pint and diff checks pass; lint/type checking reports zero errors and the four unchanged uploader warnings. The original plan below records the review baseline; current implementation details above take precedence.

## Problem

Every state change in the upload flow removes or disables the element that has focus. Focus falls to `<body>`, and a screen reader hears nothing about the new state. The review measured `document.activeElement === document.body` after pressing Enter on "Create transfer", after pressing Enter on a file's remove button, after the upload failed, and when "Your link is ready" appeared.

**Upload start.** `send()` sets `busy`, which disables every form control, the submit included. Once the draft exists, the submit also gets `hidden`. The status `<h1>` switches to "Uploading…", but it isn't focused and isn't a live region.

```tsx
// resources/scripts/pages/transfers/create.tsx:141 — current
    function send() {
        if (operating.current || !currentEntries.current.length) return;
        operating.current = true;
        setBusy(true);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:955 — current
                        <Button
                            size="lg"
                            type="submit"
                            className={cn(busy && hasDraft && 'hidden')}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:550 — current
                            <div className="flex flex-col gap-3 rounded-xl border bg-background p-6">
                                <div className="flex items-baseline justify-between gap-3">
                                    <h1 className="text-3xl">
                                        {busy
```

**Remove.** The pressed button unmounts along with its row. `removeFile()` also sets `busy`, and every remove button renders only while `!busy`. So while a draft file's DELETE request runs, all of the remove buttons are gone.

```tsx
// resources/scripts/pages/transfers/create.tsx:115 — current
    async function removeFile(entry: Entry) {
        if (operating.current) return;
        operating.current = true;
        setRemoving(true);
        setBusy(true);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:645 — current
                                            {!busy &&
                                                entry.status !== 'done' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Remove ${entry.file.name}`}
```

**Cancel.** A successful cancel empties the list. "Cancel upload" unmounts, and the empty state's "Browse files" takes its place.

```tsx
// resources/scripts/pages/transfers/create.tsx:364 — current
    async function cancel() {
        if (cancelling.current || removing) return;
        cancelling.current = true;
```

**Ready.** `setReady()` swaps the whole page for the ready card (`<section key="ready">`). The status heading unmounts with the old view, and nothing gets focus.

```tsx
// resources/scripts/pages/transfers/create.tsx:412 — current
                            <h1 className="text-4xl">Your link is ready</h1>
```

**Failure** is already announced. `ErrorMessage` renders `role="alert"` (`filemax.tsx:278`), and it appears when the upload fails. But by then focus was already lost when the upload started.

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:47 — target (refs added after `picker`)
    const picker = useRef<HTMLInputElement>(null);
    const statusHeading = useRef<HTMLHeadingElement>(null);
    const readyHeading = useRef<HTMLHeadingElement>(null);
    const fileList = useRef<HTMLDivElement>(null);
    const browseFiles = useRef<HTMLButtonElement>(null);
    // File row to put focus back near once a remove or cancel settles.
    const refocusRow = useRef<number | null>(null);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:98 — target (two effects added after the beforeunload guard's `}, [ready]);`)
    }, [ready]);
    useEffect(() => {
        if (ready && document.activeElement === document.body)
            readyHeading.current?.focus();
    }, [ready]);
    useEffect(() => {
        const index = refocusRow.current;
        if (busy || index === null) return;
        refocusRow.current = null;
        if (document.activeElement !== document.body) return;
        const rows = Array.from(fileList.current?.children ?? []);
        const target =
            [...rows.slice(index), ...rows.slice(0, index).reverse()]
                .map((row) => row.querySelector('button'))
                .find(Boolean) ??
            statusHeading.current ??
            browseFiles.current;
        target?.focus();
    }, [busy, entries]);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:115 — target
    async function removeFile(entry: Entry) {
        if (operating.current) return;
        operating.current = true;
        refocusRow.current = currentEntries.current.findIndex(
            (item) => item.key === entry.key,
        );
        setRemoving(true);
        setBusy(true);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:143 — target (inside send())
        operating.current = true;
        setBusy(true);
        statusHeading.current?.focus();
        setError('');
```

```tsx
// resources/scripts/pages/transfers/create.tsx:364 — target
    async function cancel() {
        if (cancelling.current || removing) return;
        cancelling.current = true;
        refocusRow.current = 0;
```

```tsx
// resources/scripts/pages/transfers/create.tsx:412 — target
                            <h1
                                ref={readyHeading}
                                tabIndex={-1}
                                className="text-4xl"
                            >
                                Your link is ready
                            </h1>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:541 — target
                            <Button
                                ref={browseFiles}
                                variant="outline"
                                onClick={() => picker.current?.click()}
                            >
                                Browse files
                            </Button>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:552 — target
                                    <h1
                                        ref={statusHeading}
                                        tabIndex={-1}
                                        className="text-3xl"
                                    >
                                        {busy
```

```tsx
// resources/scripts/pages/transfers/create.tsx:601 — target
                            <div ref={fileList} className="flex flex-col gap-2">
                                {entries.map((entry) => (
```

The submit's `className={cn(busy && hasDraft && 'hidden')}` (955–958) stays as it is.

Why:

- **Moving focus is the announcement.** better-accessibility's first rule for dynamic content is that when focus moves to the new content, nothing else is needed. VoiceOver reads "Uploading…, heading level 1" when the upload starts and "Your link is ready, heading level 1" when it finishes. `tabIndex={-1}` lets script focus the headings without adding them to the Tab order.
- **The status heading is focused inside `send()`, before the submit disables and hides.** The heading is already on screen, because `send()` returns early when there are no files. So this needs no effect. React commits the "Uploading…" text during the same submit event, before the browser tells assistive tech about the focus move, so the heading is read with its new text. Focus has left the submit before it hides, so the `hidden` class is harmless and stays. 013 owns that button.
- **No `aria-live` on the status heading.** The review offered it as an option, but it would announce things twice or wrongly:
  - The focus move already reads "Uploading…".
  - On failure, it would read the heading and then the `role="alert"` message. The alert is enough, and it already exists.
  - The heading also switches to "Uploading…" while a draft file is being removed (`busy`), which a live region would announce as if an upload had started.
  - It unmounts when the ready card appears, so it could never announce "ready" anyway.
- **On failure, focus stays on the status heading.** It is the same DOM node from the start of the upload until the failure, so nothing new is needed. The existing alert reads the error once.
- **Remove and cancel share one effect.** Focus goes to the first match of:
  1. the next row's remove button;
  2. the previous row's remove button;
  3. the status heading, when the remaining rows are all "Done" in a draft and so have no remove button. The heading then reads "Ready to finish" or the failure heading;
  4. "Browse files", when the list is empty.

  A cancel starts the search at row 0. No rows remain after a successful cancel, so focus lands on "Browse files". The review's "Add more files" fallback can never be reached. It only renders before a draft exists, and at that point every row has a remove button. Once the list is empty, the empty state shows "Browse files" instead.
- **The effect waits for `busy` to clear.** Removing a draft file hides every remove button until the DELETE request returns. `[busy, entries]` covers every case:
  - A no-draft removal renders once, with `busy` unchanged and `entries` changed.
  - A draft removal toggles `busy`.
  - A failed removal toggles `busy` and returns focus to the same row's button.
- **Focus only moves when it was lost** (`document.activeElement === document.body`). An upload that finishes while someone is on a header link or in the account menu leaves them there. A failed cancel leaves focus on "Cancel upload". The upload start doesn't need this guard, because the user has just submitted, and every control in the form disables or unmounts.
- **The headings keep the global focus ring (decision).** `app.css:91–93` draws the 2px `outline-ring` on everything that matches `:focus-visible`, headings included.
  - Browsers carry the last input method over to script focus. After a mouse click the headings don't match `:focus-visible` and show no ring. After Enter or Space they show the same ring as every other stop.
  - For a sighted keyboard user, that ring is the only sign that focus jumped, and it shows where the next Tab starts: "Cancel upload" during an upload, the share-link field on the ready card.
  - Hiding it would add the app's only `outline-none` and help no one. WCAG doesn't require a ring on a non-interactive heading, but nothing here needs it gone.
- **`fileList` children are the rows**, one per entry, and a row's only `<button>` is its remove button. Querying the rendered DOM after commit means `busy`, `status` and the rendering rules stay in one place.

## Dependencies

None have to land first. `create.tsx` and the test file are shared with many plans, so land them one at a time and locate each edit by its excerpt:

- **013** replaces `send()`'s first line with validation that returns early (and focuses "Browse files" or the team trigger). It also adds `const browseFiles = useRef<HTMLButtonElement>(null);` and `ref={browseFiles}` on "Browse files", and it owns the submit's `disabled` prop (~959).
  - This plan uses the same ref name. Whichever of the two lands second reuses the existing declaration and `ref` rather than adding a second one. A `<Button>` takes only one `ref`.
  - This plan anchors its `send()` edit on `operating.current = true;` + `setBusy(true);`, which 013 keeps, so the focus call still runs only once validation has passed.
  - This plan doesn't touch the submit.
- **016** changes the `'A little interruption'` literal inside the status `<h1>` (~561). This plan only changes the `<h1>`'s opening tag and adds no copy, so the literal still appears exactly once.
- **023** edits the percentage `<strong>` just after the status `<h1>` (~566).
- **015** edits the ready count line just below the ready `<h1>` (~414).
- **017** edits the ready tick disc just above it (~405) and the empty-state disc above "Browse files" (~527).
- **009** removes the hidden file `<input>` (~513–524), which shifts later lines. It keeps the `onClick` of "Browse files", which this plan leaves alone. This plan only adds `ref={browseFiles}`.
- **019** rewrites the failure copy. The new tests only assert that a non-empty `role="alert"` exists in `<main>`, so they don't depend on its wording. If 019 stops rendering the failure through `ErrorMessage`, the failure must still be `role="alert"`, because that is its only announcement.
- **Test file:** 008 and 009 insert above `function controlledTransferUploads()`, 011 after the XSRF test, and 016/019 edit line 267. This plan inserts above `it('shows the first four ready files until all of them are requested', …)`.

## Repo conventions to follow

- DOM refs are `useRef<HTMLXElement>(null)` declared at the top of `Create`, like `picker`. Effects list their deps, like the `beforeunload` guard. The file writes single-statement `if`s without braces.
- The shared `Button` spreads its props onto Base UI's `Button`, whose props include `ref` (React 19 passes `ref` as a prop). `ref={browseFiles}` reaches the `<button>`.
- Browser tests in `TransferUploadBrowserTest.php`:
  - They are top-level `it()` calls that set up with `Storage::fake('local')` and `config(['filemax.disk' => 'local'])`.
  - They use `controlledTransferUploads()` and `window.dropUploadFiles([...])` to add files and hold uploads, and `upload.fail()` / `upload.release()` to settle them.
  - Keyboard steps use `->keys(selector, 'Enter')`, which focuses the selector first, and `->keys(':focus', 'Enter')` for whatever is focused.
  - Focus is asserted with `->assertVisible('<selector>:focus')`, as in `TransferManagementBrowserTest.php:123,127,151`. `assertVisible` and `assertScript` retry until they pass or time out (`AwaitableWebpage::__call` wraps them in `waitForExpectation`).

## Steps

1. In `tests/Browser/TransferUploadBrowserTest.php`, find `it('shows the first four ready files until all of them are requested', function (): void {` (line 400). Insert these two tests directly above it, each followed by one blank line:

   ```php
   it('keeps keyboard focus on the nearest file control after a remove or cancel', function (): void {
       Storage::fake('local');
       config(['filemax.disk' => 'local']);
       $this->actingAs(User::factory()->create());
       $page = visit('/')->assertSee('Browse files');
       $page->script(controlledTransferUploads());
       $page->script('() => window.dropUploadFiles([1, 2, 3])');

       $page->keys('[aria-label="Remove file1.txt"]', 'Enter')
           ->assertVisible('[aria-label="Remove file2.txt"]:focus')
           ->keys(':focus', 'Enter')
           ->assertVisible('[aria-label="Remove file0.txt"]:focus')
           ->keys(':focus', 'Enter')
           ->assertVisible('button:has-text("Browse files"):focus');

       $page->script('() => window.dropUploadFiles([4])');
       $page->keys('button[type=submit]', 'Enter')->assertScript('window.uploads.length', 1);
       $page->keys('button:has-text("Cancel upload")', 'Enter')
           ->assertVisible('button:has-text("Browse files"):focus')
           ->assertNoJavascriptErrors();
   });

   it('keeps keyboard focus on the upload status through a failure and moves it to the ready heading', function (): void {
       Storage::fake('local');
       config(['filemax.disk' => 'local']);
       $this->actingAs(User::factory()->create());
       $page = visit('/')->assertSee('Browse files');
       $page->script(controlledTransferUploads());
       $page->script('() => window.dropUploadFiles([1, 2])');

       $page->keys('button[type=submit]', 'Enter')
           ->assertScript('window.uploads.length', 2)
           ->assertVisible('h1:focus')
           ->assertScript('document.activeElement.closest("[aria-live], [role=status], [role=alert]") === null');

       $page->script('() => { window.uploads.find(upload => upload.key === "file1.txt:1").fail(); window.uploads.find(upload => upload.key === "file0.txt:1").release(); }');
       $page->assertScript('document.querySelector("main [role=alert]")?.innerText.trim().length > 0')
           ->assertVisible('h1:focus');

       $page->keys('[aria-label="Remove file1.txt"]', 'Enter')
           ->assertVisible('h1:focus')
           ->keys('button[type=submit]', 'Enter')
           ->assertVisible('h1:has-text("Your link is ready"):focus')
           ->assertNoJavascriptErrors();
   });
   ```

   What they assert:
   - **Test 1: remove, then cancel.** Removing the middle file moves focus to the next file's remove button. Removing the last file moves it to the previous one. Removing the only file left moves it to "Browse files". Cancelling a held upload from the keyboard also lands on "Browse files". Today it fails on the first `assertVisible`, because focus is on `<body>`.
   - **Test 2: upload, failure, ready.**
     - Submitting from the keyboard puts focus on the status heading, and that heading isn't inside a live region (no double announcement).
     - After `file1` fails and `file0` finishes, the failure is in a `role="alert"`, and focus is still on the heading.
     - Removing the failed file from the draft leaves only a "Done" row with no remove button, so focus falls back to the status heading.
     - Retrying from the keyboard lands focus on "Your link is ready".
     - Today it fails on the first `assertVisible('h1:focus')`, because focus is on `<body>`.
   - `h1:focus` can't pass early after the remove step. `->keys()` first moves focus onto the remove button, so the heading only gets it back through the new effect. The last check names the ready heading's text, because the status heading is also an `h1` and is focused until the ready card replaces it.
2. Run `vendor/bin/pint --dirty --format agent`.
3. Confirm the tests catch the bug. Run `bun run build`, then:
   `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter="keeps keyboard focus"`.
   Both tests must fail at their first focus assertion: `[aria-label="Remove file2.txt"]:focus` and `h1:focus`. If either passes, STOP and report.
4. In `resources/scripts/pages/transfers/create.tsx`, line 47, find `const picker = useRef<HTMLInputElement>(null);`. Directly below it, add the four refs, the comment and `refocusRow` from the Target. If 013 has already declared `const browseFiles = useRef<HTMLButtonElement>(null);`, keep that one and don't declare it again.
5. Line 93–98: find the `beforeunload` effect's end:
   ```tsx
           return () => {
               window.removeEventListener('beforeunload', warn);
               stopGuardingVisits();
               abort.current?.abort();
           };
       }, [ready]);
   ```
   Directly below `}, [ready]);`, add the two `useEffect` calls from the Target.
6. Line 115: in `async function removeFile(entry: Entry) {`, find `if (operating.current) return;` followed by `operating.current = true;`. Directly below those, add the `refocusRow.current = currentEntries.current.findIndex(…)` statement from the Target.
7. Line 143: in `function send() {`, find `operating.current = true;` directly followed by `setBusy(true);`. That pair only occurs in `send()`: `removeFile()` and `cancel()` follow `operating.current = true;` with other lines. Directly below `setBusy(true);`, add `statusHeading.current?.focus();`.
8. Line 364: in `async function cancel() {`, find `if (cancelling.current || removing) return;` followed by `cancelling.current = true;`. Directly below those, add `refocusRow.current = 0;`.
9. Line 412: replace `<h1 className="text-4xl">Your link is ready</h1>` with the ready `<h1>` from the Target (`ref={readyHeading}`, `tabIndex={-1}`, same class and text).
10. Line 541: find the `<Button` whose children are `Browse files` (not the "Add more files" one, which has the same `variant` and `onClick`). Add `ref={browseFiles}` as its first prop. If 013 has already added `ref={browseFiles}` there, leave it as it is.
11. Line 552: find `<h1 className="text-3xl">` directly followed by `{busy`. Add `ref={statusHeading}` and `tabIndex={-1}` to it, as in the Target. Leave its children alone.
12. Line 601: find `<div className="flex flex-col gap-2">` directly followed by `{entries.map((entry) => (`. Add `ref={fileList}`. Other `<div className="flex flex-col gap-2">` elements in the file stay as they are.
13. Run `bun run lint`. The formatter may wrap lines differently from the Target, which is fine.

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only `resources/scripts/pages/transfers/create.tsx` and the two new tests change.
- Do NOT add `aria-live`, `role="status"` or another live region for the upload states. The focus moves and the existing `role="alert"` are the announcements.
- Do NOT change heading wording ("Uploading…", "Ready to finish", "Ready to send", "Your link is ready") or the failure heading literal (016).
- Do NOT touch the submit button's `hidden` class, `disabled` prop or labels (013), the alert and info-box copy (019), the percentage `<strong>` (023), the count strings (015) or the discs (017).
- Do NOT change `ErrorMessage` or anything else in `filemax.tsx`.
- Do NOT suppress the focus ring on the headings (no `outline-none`). See the decision in Target.
- Do NOT use `focus({ preventScroll: true })`. The focused heading should scroll into view.
- Do NOT add focus handling to "Send another". It is an Inertia visit, outside this finding.
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `vendor/bin/pint --dirty --format agent` leaves nothing to fix.
- **Tests**: after `bun run build`, run
  `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/HomeTest.php`.
  Both new tests must pass (they failed in step 3), and so must every existing test. Several existing tests start, fail, cancel and finish uploads with mouse clicks, which proves the focus moves don't get in their way. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`, keyboard only, with VoiceOver on (Chrome, then Safari with "Press Tab to highlight each item" on):
  1. Add three files. Tab to the middle file's × and press Enter. Focus moves to the next file's ×. Remove the last file, and focus moves to the previous ×. Remove the last one left, and focus lands on "Browse files".
  2. Add a large file (a few hundred MB). Tab to "Create transfer" and press Enter. VoiceOver says "Uploading…, heading level 1" once, and the heading shows the normal focus ring. The next Tab goes to "Cancel upload".
  3. In the DevTools Network panel, go Offline. VoiceOver reads the error once, and focus stays on the heading.
  4. Go back online and press Enter on "Retry and create link". When it finishes, VoiceOver says "Your link is ready, heading level 1". The next Tab goes to the share-link field.
  5. Start another upload and press Enter on "Cancel upload". Focus lands on "Browse files".
  6. Start an upload, then Tab back into the header and leave focus on "My transfers" until it finishes. Focus stays on "My transfers".
  7. Repeat 2–4 with the mouse only. Neither heading shows a focus ring. If one does, report it. Do not add `outline-none`.
- **Done when**: focus never falls to `<body>` after keyboard-driven remove, cancel, upload start, failure or ready; each state change is announced exactly once; and both browser test files pass.

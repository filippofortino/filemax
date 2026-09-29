# 010 — Wrap long filenames in the draft summary

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: HIGH
- **Category**: Typography
- **Estimated scope**: 2 files: 2 class strings in `create.tsx`, 1 new browser test

## Implementation outcome — 2026-09-29

Added `wrap-anywhere` to the draft title and message only. The 320px regression failed before the fix and now verifies no document overflow and no overflow inside either full-text summary box. Existing file-row truncation and animations are unchanged.

Each task was prepared by its own subagent and reviewed independently. The combined six browser suites pass: 61 tests / 752 assertions. Client/SSR build, resource formatting, Pint and diff checks pass; lint/type checking reports zero errors and the four unchanged uploader warnings. The original plan below records the review baseline; current implementation details above take precedence.

## Problem

Once an upload starts, the details form becomes a read-only summary. The Title box shows the title or, if there is none, the first file's name. The Message box shows the message. Neither box lets an unbroken string wrap. A long filename or a pasted URL runs out of its bordered box and off the card, and the page gets a horizontal scrollbar.

The review measured this at a 320px viewport, with the first file named `Lenergy_Spot30s_v3_FINAL_approvato_cliente_versione_definitiva_2026.mp4` and the upload failed. The document's `scrollWidth` was 634 against an `innerWidth` of 320. The Title box's `scrollWidth` was 613 against a `clientWidth` of 278. With short names the same state measures 320 against 320, so nothing else overflows.

```tsx
// resources/scripts/pages/transfers/create.tsx:715 — current
<div className="rounded-md border bg-muted px-3.5 py-3 text-base">
    {title || entries[0]?.file.name}
</div>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:723 — current
<div className="rounded-md border bg-muted px-3.5 py-3 text-base whitespace-pre-wrap">
    {message || 'No message'}
</div>
```

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:715 — target
<div className="rounded-md border bg-muted px-3.5 py-3 text-base wrap-anywhere">
    {title || entries[0]?.file.name}
</div>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:723 — target
<div className="rounded-md border bg-muted px-3.5 py-3 text-base wrap-anywhere whitespace-pre-wrap">
    {message || 'No message'}
</div>
```

Why:

- **`wrap-anywhere` is `overflow-wrap: anywhere`.** It breaks a string only when the string can't fit on the line. Ordinary titles still wrap at spaces, and short names stay whole.
- **The project already uses this class for the same values.** The transfer page renders the title through the base `h1` rule (`app.css:59`), and renders the message with `wrap-anywhere whitespace-pre-wrap` (`transfers/show.tsx:258`). The shared page does the same (`shared/show.tsx:49`, `:53`). The draft summary is the only place these values render without it. better-typography recommends `overflow-wrap: break-word`, which renders identically for a block that stretches to its column. The project standardised on `anywhere`, so keep one.
- **No `min-w-0` or width change is needed.** The form already has `min-w-0`, and at 320px the box is already its correct 278px width. Only the text escapes it.
- **Don't truncate.** The summary is where the sender confirms what they are sending, and truncation hides content. The file list on the left already truncates the name and shows it in full in a `title` tooltip.
- **Don't make it global.** Putting `overflow-wrap: anywhere` on `body` would lower every element's min-content width. Flex items such as pills, button labels and the header would then break mid-word instead of holding their shape.
- **Nothing else in the draft state has this root cause.** `TeamBadges` and the TeamPicker's selected chips already have `wrap-anywhere max-w-full` (`filemax.tsx:293`, `:414`). "Public link" and the expiry box ("7 days") are short fixed strings. `FileRow` truncates inside `min-w-0`.

## Dependencies

None. Other plans edit `create.tsx` or the same test file, but none of them touches these lines:

- 019 edits the "Keep this tab open…" paragraph just above (~692–697).
- 018 may recolour the "Public link" span inside this same summary block (~749).
- 012, 013, 015, 016, 017, 021 and 023 edit other parts of `create.tsx`.
- 014, 016 and 019 edit other tests in `tests/Browser/TransferUploadBrowserTest.php`.

Land the plans one at a time. This plan locates its code by excerpt, so it can go in any position in that order.

## Repo conventions to follow

- User-entered text (titles, messages, filenames, team names) wraps with `wrap-anywhere`, and messages add `whitespace-pre-wrap`. See `transfers/show.tsx:258`, `shared/show.tsx:53`, `create.tsx:666` and `filemax.tsx:278`.
- Browser tests in `tests/Browser/TransferUploadBrowserTest.php` follow a few patterns:
  - They are top-level `it()` calls that set up with `Storage::fake('local')` and `config(['filemax.disk' => 'local'])`.
  - They add files with `DataTransfer` and a `DragEvent('drop')` dispatched on `main`.
  - They fail uploads by patching `XMLHttpRequest.prototype.send` for `Blob` bodies (the XSRF test, lines 208–212).
  - They assert overflow as `document.documentElement.scrollWidth <= window.innerWidth` (line 258).
- Class order is cosmetic, because `bun run lint` sorts it.

## Steps

1. In `tests/Browser/TransferUploadBrowserTest.php`, find the end of `it('recovers a failed multi-file upload while preserving finished files and sharing', …` (lines 222–282, which end with `$page->screenshot(filename: 'transfer-history');` and `});`). Add this test right after it, before `it('asks before leaving an unfinished upload and respects the choice'` (line 284):

   ```php
   it('wraps a long filename and message in the draft summary at 320px', function (): void {
       Storage::fake('local');
       config(['filemax.disk' => 'local']);
       $this->actingAs(User::factory()->create());
       $name = 'Lenergy_Spot30s_v3_FINAL_approvato_cliente_versione_definitiva_2026.mp4';
       $link = 'https://drive.example.com/materiali/Lenergy_Spot30s_v3_FINAL_approvato_cliente_versione_definitiva_2026';
       $page = visit('/')->resize(320, 900)->assertSee('Drop files here');
       $page->fill('#transfer-message', $link);
       $page->script(<<<JS
   () => {
       const data = new DataTransfer(); data.items.add(new File(['hello'], '{$name}'));
       document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
       const send = XMLHttpRequest.prototype.send;
       XMLHttpRequest.prototype.send = function(body) {
           if (body instanceof Blob) { this.dispatchEvent(new ProgressEvent('error')); return; }
           return send.call(this, body);
       };
   }
   JS);
       $page->press('Create transfer')->assertSee('Retry and create link');

       expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue()
           ->and($page->script('() => [...document.querySelectorAll("form *")].filter(box => '.json_encode([$name, $link], JSON_THROW_ON_ERROR).'.includes(box.textContent)).map(box => box.scrollWidth <= box.clientWidth)'))->toBe([true, true]);
       $page->assertNoJavascriptErrors();
   });
   ```

   What it asserts:
   - The page has no horizontal scroll at 320px in the failed-upload state. This is the finding's outcome.
   - Exactly two elements in the form (the Title box and the Message box) hold the full name and the full URL, and neither one's content is wider than the box. A fix that clips the text instead (`truncate`, `overflow-hidden`) fails this check.

   It waits for "Retry and create link" because the button shows that label only when a draft exists and nothing is uploading. It doesn't use "A little interruption" or "Some files could not be uploaded.", because 016 and 019 reword those.

2. Run `vendor/bin/pint --dirty --format agent`.
3. Confirm that the test catches the bug. Run `bun run build`, then `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter="wraps a long filename"`. It must fail on the first expectation, because the document's `scrollWidth` is well over 320. If it passes before the fix, STOP and report.
4. In `resources/scripts/pages/transfers/create.tsx`, line 715, change `<div className="rounded-md border bg-muted px-3.5 py-3 text-base">` (the one directly above `{title || entries[0]?.file.name}`) to the Target.
5. In the same file, line 723, change `<div className="rounded-md border bg-muted px-3.5 py-3 text-base whitespace-pre-wrap">` (directly above `{message || 'No message'}`) to the Target.
6. Run `bun run lint`.

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only these change: the two class strings in `create.tsx` and the one new test.
- Do NOT truncate, clamp or hide the title or message.
- Do NOT add `wrap-anywhere` (or `overflow-wrap`) globally or to `app.css`.
- Do NOT touch `FileRow`, `TeamBadges`, `TeamPicker`, or the editable title and message fields.
- Do NOT change colours in this block. The `text-slate-700` on "Public link" belongs to 018.
- Do NOT change the "Keep this tab open…" paragraph (019), the status `<h1>` (012, 016) or the submit button (012, 013).
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `vendor/bin/pint --dirty --format agent` leaves nothing to fix.
- **Tests**: after `bun run build`, run `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php`. The new test must pass (it failed in step 3), and so must every existing test in the file. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  1. In the DevTools device toolbar, set the width to 320px.
  2. Add a file whose name is 70+ characters with no spaces, and type a message containing a long URL.
  3. Throttle the network to Slow 4G and press Create transfer, so the upload state stays on screen.
  4. Check the Title and Message boxes. Both wrap inside their borders, and the page doesn't scroll sideways.
  5. Repeat with a short title like "Spot autunno". It stays on one line with no mid-word break.
- **Done when**: at 320px, a long filename or URL wraps inside the summary boxes with no horizontal page scroll, short text is unchanged, and `TransferUploadBrowserTest.php` passes.

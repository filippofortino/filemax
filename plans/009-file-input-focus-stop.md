# 009 — Remove the invisible file-input tab stop

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: HIGH
- **Category**: Accessibility
- **Estimated scope**: 2 source files (1 attribute swap in `create.tsx`; 1 attribute swap and 1 moved attribute in `settings.tsx`), 2 new browser tests

## Implementation outcome — 2026-09-29

Both file inputs now use native `hidden`; the visible Upload photo button carries its requirements description. The two keyboard regressions failed before the fix and pass afterward, including Browse files activation. Existing photo-upload coverage also passes. Native chooser behavior in Firefox/Safari and VoiceOver speech remain manual checks.

Each task was prepared by its own subagent and reviewed independently. The combined six browser suites pass: 61 tests / 752 assertions. Client/SSR build, resource formatting, Pint and diff checks pass; lint/type checking reports zero errors and the four unchanged uploader warnings. The original plan below records the review baseline; current implementation details above take precedence.

## Problem

The new-transfer page keeps its file `<input>` "visually hidden" with `sr-only`, and "Browse files" / "Add more files" open it with `picker.current?.click()`. A `sr-only` input is still focusable, so it is a Tab stop. Its box is 1×1px and clipped, so the focus ring can't be seen. Keyboard users land on a blank stop between the header and "Browse files". This is a keyboard-reachable control with no visible focus indicator, and it is there in every state except the ready screen.

The review's Tab walk on the empty page at 1280px: Filemax, New transfer, My transfers, Account menu, **`input[type=file]` (1×1, clipped, focus outline drawn but clipped)**, Browse files, title, message, visibility radio, expiry radio, then `<body>`.

```tsx
// resources/scripts/pages/transfers/create.tsx:513 — current
                    <input
                        ref={picker}
                        type="file"
                        multiple
                        className="sr-only"
                        aria-label="Choose files"
                        onChange={(event) => {
                            if (event.target.files)
                                addFiles(event.target.files);
                            event.target.value = '';
                        }}
                    />
```

The review only flagged `create.tsx`, but the settings page uses the same pattern. Its "Profile photo" input is an invisible Tab stop just before "Upload photo":

```tsx
// resources/scripts/pages/account/settings.tsx:233 — current
                            <input
                                ref={photoInput}
                                id="avatar"
                                type="file"
                                accept="image/jpeg,image/png"
                                className="sr-only"
                                aria-label="Profile photo"
                                aria-describedby="avatar-requirements"
                                aria-invalid={!!profile.errors.avatar}
                                disabled={profile.processing}
```

```tsx
// resources/scripts/pages/account/settings.tsx:258 — current
                            <Button
                                type="button"
                                variant="outline"
                                disabled={profile.processing}
                                onClick={() => photoInput.current?.click()}
                            >
                                Upload photo
                            </Button>
```

Those are the only two `type="file"` inputs in `resources/scripts`.

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:513 — target
                    <input
                        ref={picker}
                        type="file"
                        multiple
                        hidden
                        onChange={(event) => {
                            if (event.target.files)
                                addFiles(event.target.files);
                            event.target.value = '';
                        }}
                    />
```

```tsx
// resources/scripts/pages/account/settings.tsx:233 — target
                            <input
                                ref={photoInput}
                                id="avatar"
                                type="file"
                                accept="image/jpeg,image/png"
                                hidden
                                disabled={profile.processing}
```

```tsx
// resources/scripts/pages/account/settings.tsx:258 — target (line shifts to ~255)
                            <Button
                                type="button"
                                variant="outline"
                                disabled={profile.processing}
                                aria-describedby="avatar-requirements"
                                onClick={() => photoInput.current?.click()}
                            >
                                Upload photo
                            </Button>
```

Why:

- **`hidden` is `display: none`.** Tailwind's preflight enforces it with `!important`, and `app.css` doesn't override `[hidden]`. That takes the input out of the tab order and out of the accessibility tree in one attribute. The visible buttons are the real controls. They already have visible text, a focus ring, and a click handler that opens the chooser.
- **`.click()` still opens the chooser on a `display: none` file input.** HTML ties a file input's activation to user activation, not to rendering. MDN's "Using files from web applications" documents this pattern (`display:none` plus `click()`) as the supported way to replace the native control. While planning, it was checked with Playwright's `filechooser` event against a `hidden` input whose button calls `.click()`. It fired in Chromium 153 and WebKit 26.6, and neither engine would focus the input. Firefox couldn't launch in the planning sandbox, so the manual check covers Firefox and Safari.
- **Why not `tabIndex={-1}` + `aria-hidden`.** The review offered this as a fallback. It takes two attributes where one will do, and the input stays focusable by script and by click. better-accessibility lists `aria-hidden` on a focusable element as a never-do. There's no compatibility risk that would justify it.
- **The `aria-label`s go.** A `display: none` element isn't in the accessibility tree, so the name is dead. The buttons' visible text names the action.
- **Settings keeps its description on the button.** The "JPG or PNG, up to 5 MB…" requirements used to be announced on the hidden input. Moving `aria-describedby="avatar-requirements"` to "Upload photo" keeps them announced. `aria-invalid` is dropped, not moved. ARIA doesn't support it on `button`, and the avatar error already renders through `ErrorMessage`, which is `role="alert"` (`filemax.tsx:278`).
- **Keep `id="avatar"`.** `SettingsBrowserTest.php:51` attaches the photo with `->attach('#avatar', …)`. Playwright's `setInputFiles` doesn't require visibility (checked in Chromium against a `hidden` input: the file arrived and `change` fired), so that test keeps working.
- **Drag and drop is unaffected.** Drops land on `<main>`'s `onDrop`, never on the input.

## Dependencies

None have to land first. Shared files, so land the plans one at a time:

- `create.tsx` is also edited by 010, 012, 013, 015, 016, 017, 019, 021 and 023, all on other lines. 017 edits the upload disc just below this input (~527). This plan removes one line above it, so its line numbers shift by −1.
- Other plans (010 and 012–016, 019) add or edit tests in `tests/Browser/TransferUploadBrowserTest.php`. This plan inserts its test just before the `controlledTransferUploads()` helper, away from theirs.
- The new tests don't use the `[aria-label="Account menu"]` selector, which 014 renames. They also don't assume the header comes first in the Tab order, which 020's skip link changes. They only assert that focus moves between the picker button and the `<header>`.
- No sibling plan touches `settings.tsx` or `SettingsBrowserTest.php`.

## Repo conventions to follow

- Hidden file inputs are opened from a visible `Button` through a ref (`picker.current?.click()`, `photoInput.current?.click()`). Keep that wiring unchanged.
- Browser tests are top-level `it()` calls with `$this->actingAs(User::factory()->create())` and `visit(...)`. Keyboard steps use `->keys(selector, key)`, which focuses the selector first, and `->keys(':focus', key)` for whatever is focused. See `TransferManagementBrowserTest.php:145–147` and `HomeTest.php:13`.
- Pest's browser wrapper doesn't expose Playwright's `filechooser` event. `Page` has no event API, and `waitForEvent()` only wraps `waitForLoadState`. So the test records the chooser request by stubbing `HTMLInputElement.prototype.click`, the same way other tests patch `XMLHttpRequest.prototype.send`.

## Steps

1. In `tests/Browser/TransferUploadBrowserTest.php`, find `function controlledTransferUploads(): string` (line 422, right after the `it('shows the first four ready files until all of them are requested', …)` test). Insert this test directly above it:

   ```php
   it('tabs from the header straight to Browse files, which still opens the file chooser', function (): void {
       $this->actingAs(User::factory()->create());
       $page = visit('/')->assertSee('Browse files');

       $page->keys('main button:has-text("Browse files")', 'Shift+Tab')
           ->assertScript('Boolean(document.activeElement.closest("header"))')
           ->keys(':focus', 'Tab')
           ->assertScript('document.activeElement.innerText', 'Browse files');

       $page->script(<<<'JS'
   () => {
       window.fileChoosers = 0;
       HTMLInputElement.prototype.click = function () {
           if (this.type === 'file') window.fileChoosers++;
       };
   }
   JS);
       $page->keys(':focus', 'Enter')
           ->assertScript('window.fileChoosers', 1)
           ->assertNoJavascriptErrors();
   });
   ```

   What it asserts:
   - Shift+Tab from "Browse files" goes straight back into the header, and Tab from there comes back to "Browse files". No stop sits in between. Today the first assertion fails, because Shift+Tab lands on the `sr-only` input inside `<main>`.
   - Pressing Enter on "Browse files" still asks the file input to open its chooser, exactly once. The stub also stops a real dialog opening in the headless browser. This part passes today too, and it guards against the fix breaking the wiring.

2. In `tests/Browser/SettingsBrowserTest.php`, append this test at the end of the file:

   ```php
   it('tabs from the header straight to Upload photo, which announces the photo requirements', function (): void {
       $this->actingAs(User::factory()->create());

       visit('/account/settings')
           ->keys('#profile-form button:has-text("Upload photo")', 'Shift+Tab')
           ->assertScript('Boolean(document.activeElement.closest("header"))')
           ->keys(':focus', 'Tab')
           ->assertScript('document.activeElement.innerText', 'Upload photo')
           ->assertScript('document.getElementById(document.activeElement.getAttribute("aria-describedby"))?.innerText.startsWith("JPG or PNG")')
           ->assertNoJavascriptErrors();
   });
   ```

   Today the first assertion fails, because Shift+Tab lands on the `sr-only` `#avatar` input. The last assertion checks that the requirements text is still the focused button's description once it leaves the input.

3. Run `vendor/bin/pint --dirty --format agent`.
4. Confirm that the tests catch the bug. Run `bun run build`, then:
   `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/SettingsBrowserTest.php --filter="tabs from the header straight to"`.
   Both tests must fail on the first `assertScript`. If either passes before the fix, STOP and report.
5. In `resources/scripts/pages/transfers/create.tsx`, line 513, find the `<input` whose next lines are `ref={picker}`, `type="file"`, `multiple`, `className="sr-only"`, `aria-label="Choose files"`. Replace the two lines `className="sr-only"` and `aria-label="Choose files"` with the single line `hidden`. Leave `onChange` untouched. The result must match the Target.
6. In `resources/scripts/pages/account/settings.tsx`, line 233, find the `<input` with `ref={photoInput}` and `id="avatar"`. Replace the four lines `className="sr-only"`, `aria-label="Profile photo"`, `aria-describedby="avatar-requirements"` and `aria-invalid={!!profile.errors.avatar}` with the single line `hidden`. Keep `ref`, `id`, `type`, `accept`, `disabled` and `onChange` as they are.
7. In the same file, line 258, find the `<Button` whose `onClick` is `() => photoInput.current?.click()` (the "Upload photo" button). Add `aria-describedby="avatar-requirements"` after `disabled={profile.processing}`.
8. Run `bun run lint`.

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only these change: the two `<input>` elements, the "Upload photo" `Button`'s attributes, and the two new tests.
- Do NOT change the `onChange` handlers, the refs, or the `onClick` wiring of "Browse files", "Add more files" or "Upload photo".
- Do NOT use `tabIndex={-1}` or `aria-hidden` instead of `hidden`.
- Do NOT remove `id="avatar"`. `SettingsBrowserTest.php:51` uses it.
- Do NOT touch the drop handlers on `<main>` or the drop overlay.
- Do NOT touch the upload disc below the input (017), the status and ready headings (012, 016), the submit button (012, 013), the account-menu trigger (014) or the Shell/skip link (020).
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `vendor/bin/pint --dirty --format agent` leaves nothing to fix.
- **Tests**: after `bun run build`, run `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/SettingsBrowserTest.php`. Both new tests must pass (they failed in step 4), and so must every existing test in both files. That includes `->attach('#avatar', …)` in the photo test, which proves a `hidden` input still receives files. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`, in Chrome, Firefox and Safari (in Safari, turn on "Press Tab to highlight each item on a webpage" or use Option+Tab):
  1. On the new-transfer page, Tab from the top. After the account menu, the next stop is "Browse files", and every stop shows a visible ring.
  2. Press Enter on "Browse files". The system file chooser opens. Pick two files and they appear in the list. Press "Add more files". The chooser opens again and adds to the list.
  3. Drag a file onto the page. It is still added.
  4. On `/account/settings`, Shift+Tab from "Upload photo" goes to the header. "Upload photo" opens a chooser limited to JPG and PNG. With VoiceOver on, focusing "Upload photo" reads the "JPG or PNG, up to 5 MB…" requirements.
- **Done when**: neither page has an invisible Tab stop before its picker button, both buttons still open the file chooser in all three browsers, and both browser test files pass.

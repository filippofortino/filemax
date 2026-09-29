# 013 — Keep Create transfer enabled and validate on submit

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: MEDIUM
- **Category**: Accessibility
- **Estimated scope**: 2 source files (`create.tsx`: 2 refs, 1 state, the `send()` guard, 1 shared hint, 5 JSX edits; `filemax.tsx`: 2 optional `TeamPicker` props), 2 new browser tests

## Implementation outcome — 2026-09-29

Create transfer stays enabled until busy. Submit checks missing files and teams before starting any request, focuses the relevant visible control, and associates its requirement text. TeamPicker gained optional trigger-ref and description props for both editable states. Both regressions failed before the fix and pass afterward, including team repair on the same interrupted draft. The Browse ref is shared with 012 and validation runs before upload-status focus.

Each task was prepared by its own subagent and reviewed independently. The combined six browser suites pass: 61 tests / 752 assertions. Client/SSR build, resource formatting, Pint and diff checks pass; lint/type checking reports zero errors and the four unchanged uploader warnings. The original plan below records the review baseline; current implementation details above take precedence.

## Problem

"Create transfer" is disabled until the form is valid. A disabled button leaves the tab order, so keyboard and screen-reader users never reach the page's main action. They never learn why it's unavailable, and mouse users get no response when they click it. The disabled default button also blocks Enter-to-submit from the Title field.

```tsx
// resources/scripts/pages/transfers/create.tsx:955-965 — current
                        <Button
                            size="lg"
                            type="submit"
                            className={cn(busy && hasDraft && 'hidden')}
                            disabled={
                                busy ||
                                entries.length === 0 ||
                                (visibility === 'teams' &&
                                    selectedTeams.length === 0)
                            }
                        >
```

`send()` silently returns when there are no files, and it has no team check at all:

```tsx
// resources/scripts/pages/transfers/create.tsx:141-142 — current
    function send() {
        if (operating.current || !currentEntries.current.length) return;
```

The two requirement hints are visible, but they aren't tied to any control, so assistive tech never reads them with the button or the picker:

```tsx
// resources/scripts/pages/transfers/create.tsx:904-908 — current
                                        {!selectedTeams.length && (
                                            <p className="text-sm text-muted-foreground">
                                                Select at least one team.
                                            </p>
                                        )}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:983-989 — current
                        <p className="text-center text-sm text-muted-foreground">
                            {!entries.length
                                ? 'Add at least one file to continue'
                                : busy
                                  ? 'Your link appears as soon as the last file lands.'
                                  : 'Your files stay private until your link is ready.'}
                        </p>
```

In the review's Tab walk of the empty page, focus went through the expiry radios and then to `<body>`. It never reached "Create transfer".

The draft state ("Retry and create link") uses the same button and the same `send()`. Its `TeamPicker` (`create.tsx:734-738`) stays editable, but it has no hint. If a user removes every team there, the button just turns grey.

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:47 — target (two lines added below)
    const picker = useRef<HTMLInputElement>(null);
    const browseFiles = useRef<HTMLButtonElement>(null);
    const teamsTrigger = useRef<HTMLButtonElement>(null);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:55 — target (one line added below)
    const [error, setError] = useState('');
    const [validationFailed, setValidationFailed] = useState(false);
```

```tsx
// resources/scripts/pages/transfers/create.tsx:141-143 — target
    function send() {
        if (operating.current) return;
        const filesMissing = !currentEntries.current.length;
        const teamsMissing = visibility === 'teams' && !selectedTeams.length;
        setValidationFailed(filesMissing || teamsMissing);
        if (filesMissing) {
            browseFiles.current?.focus();
            return;
        }
        if (teamsMissing) {
            teamsTrigger.current?.focus();
            return;
        }
        operating.current = true;
```

```tsx
// resources/scripts/pages/transfers/create.tsx:394 — target (hint added below)
    const hasDraft = !!draft.current;
    const teamsHint = !selectedTeams.length && (
        <p
            id="teams-hint"
            className={cn(
                'text-sm text-muted-foreground',
                validationFailed && 'text-destructive',
            )}
        >
            Select at least one team.
        </p>
    );
```

```tsx
// resources/scripts/pages/transfers/create.tsx:541-546 — target (Browse files)
                            <Button
                                ref={browseFiles}
                                variant="outline"
                                aria-describedby="submit-hint"
                                onClick={() => picker.current?.click()}
                            >
                                Browse files
                            </Button>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:733-739 — target (draft state)
                                        !busy ? (
                                            <>
                                                <TeamPicker
                                                    teams={teams}
                                                    selected={selectedTeams}
                                                    onChange={setSelectedTeams}
                                                    triggerRef={teamsTrigger}
                                                    describedBy={
                                                        selectedTeams.length
                                                            ? undefined
                                                            : 'teams-hint'
                                                    }
                                                />
                                                {teamsHint}
                                            </>
                                        ) : (
```

```tsx
// resources/scripts/pages/transfers/create.tsx:896-910 — target (form state)
                                {visibility === 'teams' && (
                                    <>
                                        <TeamPicker
                                            teams={teams}
                                            selected={selectedTeams}
                                            onChange={setSelectedTeams}
                                            disabled={busy}
                                            triggerRef={teamsTrigger}
                                            describedBy={
                                                selectedTeams.length
                                                    ? undefined
                                                    : 'teams-hint'
                                            }
                                        />
                                        {teamsHint}
                                    </>
                                )}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:955-961 — target (submit button opening tag)
                        <Button
                            size="lg"
                            type="submit"
                            className={cn(busy && hasDraft && 'hidden')}
                            disabled={busy}
                            aria-describedby="submit-hint"
                        >
```

```tsx
// resources/scripts/pages/transfers/create.tsx:983 — target (opening tag only; the children stay as they are)
                        <p
                            id="submit-hint"
                            className={cn(
                                'text-center text-sm text-muted-foreground',
                                validationFailed &&
                                    !entries.length &&
                                    'text-destructive',
                            )}
                        >
```

```tsx
// resources/scripts/components/filemax.tsx:15 — target
import { useState, type ReactNode, type Ref } from 'react';
```

```tsx
// resources/scripts/components/filemax.tsx:317-331 — target
export function TeamPicker({
    teams,
    selected,
    onChange,
    disabled = false,
    triggerRef,
    describedBy,
}: {
    teams: Team[];
    selected: string[];
    onChange: (ids: string[]) => void;
    disabled?: boolean;
    triggerRef?: Ref<HTMLButtonElement>;
    describedBy?: string;
}) {
```

```tsx
// resources/scripts/components/filemax.tsx:341-349 — target (the aria-label line is gone if 014 has landed)
                <PopoverTrigger
                    render={<Button variant="outline" />}
                    type="button"
                    className="w-full flex-wrap justify-between gap-y-1 py-2 whitespace-normal"
                    aria-label="Choose teams"
                    ref={triggerRef}
                    aria-describedby={describedBy}
                    disabled={disabled}
                >
```

Why:

- **`disabled={busy}`** keeps the button enabled until the request starts, which is better-accessibility's submit rule. The `operating.current` guard still blocks double submits. The three existing tests that expect `button[type=submit]` to be disabled (`TransferUploadBrowserTest.php:75, 118, 168`) all check while `busy`, so they still hold.
- **Validation lives in `send()`, the one path** for "Create transfer", "Retry and create link" and Enter in the Title field. It checks the same conditions as the old `disabled` expression, so nothing that used to be blocked gets through, and nothing that used to go through is blocked. The check deliberately stays `selectedTeams.length`. After a membership loss, stale team ids still go to the server, which replies "Your team membership changed…" (`TransferUploadController.php:34`). That is today's behaviour, and the repair test at `:368-398` covers it.
- **Focus goes to the first thing to fix, in DOM order.** "Browse files" (left column) comes first, then the team trigger. better-accessibility's screen-readers reference says the focus move is the announcement. `aria-describedby` adds the reason, for example "Browse files, button, Add at least one file to continue".
- **The existing hints become the errors.** The review suggested `setError('Add at least one file.')`. That would repeat the hint that sits right under the button, and it would go out through `ErrorMessage`'s `role="alert"` while focus is also moving, so screen readers would hear two announcements. The skill reserves `role="alert"` for errors not tied to a control, so `ErrorMessage` keeps server errors only. No copy changes.
- **The hints turn `text-destructive` after a failed submit.** A mouse click followed by programmatic focus doesn't match `:focus-visible`, so without this, mouse users would see no response. The red is an extra cue on top of the words, not the only signal. `validationFailed` is set on every submit, so a submit that passes clears it. Each hint also disappears once a file or team is added. `cn` resolves the color conflict last-wins. `cn('text-sm text-muted-foreground', 'text-destructive')` returns `text-sm text-destructive` (checked).
- **No `aria-invalid`.** Both focus targets are buttons, and ARIA doesn't support `aria-invalid` on the `button` role (plan 009 dropped it from "Upload photo" for the same reason). The skill's `aria-invalid` rule is for fields.
- **`teamsHint` renders in both branches.** In the draft state, the picker is still editable. Without a visible hint there, clicking "Retry and create link" with no team would do nothing a sighted mouse user could see.
- **`describedBy` is conditional**, so the trigger never points at an id that isn't rendered.
- **`TeamPicker` gets two optional props.** React 19 passes `ref` as an ordinary prop, so no `forwardRef` is needed. It's named `triggerRef` because it lands on the trigger, not on the root `<div>`. `show.tsx:168` passes neither prop, so the "Change teams" dialog is unchanged.
- **Not Base UI's `focusableWhenDisabled`.** It would put the button back in the tab order, but pressing it would still do nothing and wouldn't lead to the fix.
- **Visible side effect.** On the empty page, "Create transfer" now renders in the primary style instead of disabled grey. Plan 017's manual check already expects this.

## Dependencies

- **None must land first.** Land the `create.tsx` plans one at a time.
- **012 also edits `send()` (focus when the upload starts) and the submit button's `className`.** The plans work in either order.
  - This plan replaces only `send()`'s first statement and keeps everything 012 adds after it.
  - 012 owns the `className={cn(busy && hasDraft && 'hidden')}` line and any focus move once validation passes. This plan owns `disabled` and `aria-describedby` on the same `<Button>`.
- **014 deletes `aria-label="Choose teams"` from the same `PopoverTrigger`.** The plans work in either order, because Step 12 anchors on `disabled={disabled}`. 014 leaves `aria-describedby` to this plan. The new tests reach the trigger by focus and check its visible text, so they never use `[aria-label="Choose teams"]`.
- **Neighbouring edits that only shift line numbers:**
  - 009: the file `<input>` above "Browse files".
  - 017: the upload disc between them.
  - 010: the draft summary boxes above the draft `TeamPicker`.
  - 019: the info box directly above the submit button.
  - 021: the radio-card classes above the form `TeamPicker`.
  - In `filemax.tsx`: 015 (members count, ~393) and 024 (`PopoverContent` className, ~356).
- **`TransferUploadBrowserTest.php` is also edited by 008, 009, 010, 012, 014, 015, 016 and 019.** The new tests avoid strings that other plans change ('Some files could not be uploaded.', 'A little interruption', `[aria-label="Choose teams"]`).

## Repo conventions to follow

- Refs are `useRef<HTML…Element>(null)` at the top of `Create` (see `picker`, line 47). Conditional classes use `cn(base, condition && 'class')` (see `create.tsx:875-880`). Errors are `text-destructive` and hints are `text-sm text-muted-foreground`.
- Fixed kebab-case ids are normal here: `transfer-title`, `share-link`, and `team-search` inside `TeamPicker`.
- TypeScript style: single-statement guards are written brace-less, as in `if (operating.current) return;`. Use braces for anything longer.
- Browser tests are lowercase `it('…')` sentences with inline JS in `<<<'JS'` heredocs. Files are dropped with `DataTransfer` plus `DragEvent('drop')` on `main`. A forced upload failure patches `XMLHttpRequest.prototype.send`, as in `TransferUploadBrowserTest.php:208-212`. Keyboard steps use `->keys(':focus', …)`.

## Steps

1. **Add the tests first.** In `tests/Browser/TransferUploadBrowserTest.php`, find `function controlledTransferUploads(): string` (line 422 at d15b32d). Insert these two tests directly above it, below any test another plan already put there, followed by one blank line. `Team`, `Transfer`, `User` and `Storage` are already imported.

   ```php
   it('keeps Create transfer in the tab order and sends focus to Browse files when no file is added', function (): void {
       $this->actingAs(User::factory()->create());
       $description = 'document.getElementById(document.activeElement.getAttribute("aria-describedby"))?.innerText';
       $hintColor = '() => getComputedStyle(document.getElementById("submit-hint")).color';

       $page = visit('/')->assertSee('Browse files')
           ->keys('input[name=expiry]:checked', 'Tab')
           ->assertScript('document.activeElement.innerText', 'Create transfer')
           ->assertScript($description, 'Add at least one file to continue');
       $hintColorBefore = $page->script($hintColor);

       $page->keys(':focus', 'Enter')
           ->assertScript('document.activeElement.innerText', 'Browse files')
           ->assertScript($description, 'Add at least one file to continue')
           ->assertNoJavascriptErrors();

       expect($page->script($hintColor))->not->toBe($hintColorBefore)
           ->and(Transfer::query()->count())->toBe(0);
   });

   it('sends focus to the team picker when no team is selected, before and after an interrupted upload', function (): void {
       Storage::fake('local');
       config(['filemax.disk' => 'local']);
       $user = User::factory()->create();
       $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
       $this->actingAs($user);
       $description = 'document.getElementById(document.activeElement.getAttribute("aria-describedby"))?.innerText';

       $page = visit('/')->click('Specific teams');
       $page->script(<<<'JS'
   () => {
       const data = new DataTransfer(); data.items.add(new File(['hello'], 'teams.txt'));
       document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
       const send = XMLHttpRequest.prototype.send;
       XMLHttpRequest.prototype.send = function(body) {
           if (body instanceof Blob) { this.dispatchEvent(new ProgressEvent('error')); return; }
           return send.call(this, body);
       };
   }
   JS);
       $page->assertSee('teams.txt')->press('Create transfer')
           ->assertScript('document.activeElement.innerText.startsWith("Choose teams")')
           ->assertScript($description, 'Select at least one team.');
       expect(Transfer::query()->count())->toBe(0);

       $page->keys(':focus', 'Enter')->check('[aria-label="Mediamax"]')->keys('#team-search', 'Escape')
           ->press('Create transfer')->assertSee('Retry and create link')
           ->click('[aria-label="Remove Mediamax"]')->press('Retry and create link')
           ->assertScript('document.activeElement.innerText.startsWith("Choose teams")')
           ->assertScript($description, 'Select at least one team.')
           ->assertNoJavascriptErrors();
   });
   ```

   What they assert:
   - **Test 1.** Tab from the expiry choice reaches "Create transfer", and its description is the hint. Pressing it with no files moves focus to "Browse files", which carries the same description. The hint visibly changes color, and nothing is created.
   - **Test 2.** Under "Specific teams" with no team, pressing "Create transfer" moves focus to the picker trigger, which is described by "Select at least one team.", and nothing is created. The test then opens the picker from the focused trigger with Enter and picks a team. The upload fails through the XHR patch, which leaves the draft state. After the chip is removed, "Retry and create link" sends focus back to the trigger, and the hint now renders in the draft branch too.

2. Run `vendor/bin/pint --format agent tests/Browser/TransferUploadBrowserTest.php`. The path is deliberate: `--dirty` would also reformat the user's uncommitted `tests/Browser/AuthTeamBrowserTest.php`.
3. **Prove the tests fail before the fix.** Run `bun run build`, then:
   `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter='sends focus to'`.
   - Test 1 must fail on its first `assertScript`. The disabled button is skipped, so focus lands on `<body>`.
   - Test 2 must fail on `press('Create transfer')` with a timeout, because the button is disabled.

   If either test passes, or fails anywhere else, STOP and report.
4. In `resources/scripts/pages/transfers/create.tsx`:
   - Directly below `const picker = useRef<HTMLInputElement>(null);` (line 47), add the `browseFiles` and `teamsTrigger` refs.
   - Directly below `const [error, setError] = useState('');` (line 55), add the `validationFailed` state.

   Both must match the Target.
5. In `send()` (line 142), replace the single line `if (operating.current || !currentEntries.current.length) return;` with the Target's lines from `if (operating.current) return;` through the second `return;` block. Leave every later line of `send()` as it is, including anything 012 added.
6. Directly below `const hasDraft = !!draft.current;` (line 394), add the `teamsHint` constant from the Target.
7. Find the empty-state `<Button` whose lines are `variant="outline"`, `onClick={() => picker.current?.click()}` and whose text is `Browse files` (lines 541-546). Add `ref={browseFiles}` above `variant="outline"` and `aria-describedby="submit-hint"` below it. Leave "Add more files" (~685) alone.
8. Find the draft-state block (lines 733-738): `!busy ? (` followed by `<TeamPicker`, `teams={teams}`, `selected={selectedTeams}`, `onChange={setSelectedTeams}` and `/>`, with **no** `disabled={busy}` line. Wrap the `TeamPicker` in `<>…</>`, add the `triggerRef` and `describedBy` props, and put `{teamsHint}` after it. The result must match the Target.
9. Find the form-state block (lines 896-910): `{visibility === 'teams' && (`, then a `<TeamPicker` that has `disabled={busy}`, then `{!selectedTeams.length && (` wrapping `<p className="text-sm text-muted-foreground">` with the text `Select at least one team.`.
   - Add `triggerRef` and `describedBy` below `disabled={busy}`.
   - Replace the whole `{!selectedTeams.length && ( … )}` block with `{teamsHint}`.

   The result must match the Target.
10. In the submit `<Button` (`size="lg"`, `type="submit"`), replace the six lines from `disabled={` through its closing `}` (lines 959-964, excerpt in Problem) with `disabled={busy}` and `aria-describedby="submit-hint"`. Don't touch the `className` line, which 012 owns, or the label ternary.
11. Find the `<p className="text-center text-sm text-muted-foreground">` whose next lines are `{!entries.length` and `? 'Add at least one file to continue'` (line 983). Replace that opening tag with the Target's opening tag. Keep its children unchanged.
12. In `resources/scripts/components/filemax.tsx`:
    - Line 15: add `type Ref` to the `react` import.
    - `TeamPicker` signature (lines 317-327): add `triggerRef` and `describedBy` to the destructuring and to the props type, as in the Target.
    - Inside `TeamPicker`'s `<PopoverTrigger render={<Button variant="outline" />}` (line 341), add `ref={triggerRef}` and `aria-describedby={describedBy}` directly above its `disabled={disabled}` line. That is not the `disabled={disabled}` on the chip's remove `<button>` (~422). If 014 has already deleted `aria-label="Choose teams"`, that is expected. Change nothing else.
13. Run `bun run lint`. The formatter may reflow the new JSX, and its output wins.

Line numbers are as of d15b32d and shift as sibling plans land, so locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only these change:
  - The `create.tsx` edits in Steps 4-11.
  - The `filemax.tsx` import, `TeamPicker` props and two trigger attributes in Step 12.
  - Two new tests.
- Do NOT add or change copy. Reuse "Add at least one file to continue" and "Select at least one team." as they are.
- Do NOT route these errors through `setError`/`ErrorMessage`. Do NOT add `aria-invalid` to either button.
- Do NOT change the server-side validation. Do NOT switch the team check to the "available" selection.
- Do NOT touch any of these, which belong to other plans:
  - the submit button's `className`, the status and ready headings, and focus after the upload starts (012)
  - the trigger's `aria-label` (014)
  - the info box and alert text (019)
  - count strings (015)
  - the file input (009)
  - the discs (017)
  - the draft summary boxes (010)
  - the radio-card classes (021)
  - the percentage (023)
  - `PopoverContent` classes (024)
- Do NOT change `pages/transfers/show.tsx`. Its "Change teams" dialog has the same disabled "Save changes" pattern, which is a separate finding.
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`, and do not run `pint --dirty`.
- Do NOT add dependencies.

## Verification

- **Mechanical**:
  - `bun run lint`, then `bun run test:lint`, passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `vendor/bin/pint --format agent tests/Browser/TransferUploadBrowserTest.php` leaves nothing to fix.
- **Tests** (after `bun run build`): `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/TransferManagementBrowserTest.php`. Everything must pass, including both new tests, which failed in Step 3.
  - Lines 75, 118 and 168 confirm that the button is still disabled while busy.
  - The repair test (`it('can repair sharing after losing membership during an upload'`) confirms that the draft `send()` path still creates the link after a team is picked again.
  - `TransferManagementBrowserTest` exercises `TeamPicker` in the "Change teams" dialog, which passes neither new prop.

  If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  1. On the empty page, Tab from the top. After the expiry chips, "Create transfer" gets focus with a visible ring. It is shown in the primary style now, not grey.
  2. Press Enter. Focus moves to "Browse files" with a ring, the hint under the submit button turns red, and the Network panel shows no request. With VoiceOver, "Browse files" is read with "Add at least one file to continue".
  3. Reload and click "Create transfer" with the mouse. The hint turns red. Add a file, and the hint becomes the muted "Your files stay private until your link is ready."
  4. Add a file, choose "Specific teams" and click "Create transfer". Focus moves to the "Choose teams" trigger, and "Select at least one team." turns red. Pick a team, and the hint disappears. "Create transfer" then uploads.
  5. At 390px wide, tap "Create transfer" with no files. The page scrolls up to "Browse files".
  6. In the Title field, press Enter with no files. It behaves like step 2.
- **Done when**:
  - "Create transfer" is in the tab order whenever it's visible and not busy.
  - Pressing it with a missing requirement focuses the control that fixes it, and that control announces the reason.
  - Both new tests and the two suites pass.

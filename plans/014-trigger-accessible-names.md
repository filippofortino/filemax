# 014 — Make trigger names match their visible text

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: MEDIUM
- **Category**: Accessibility
- **Estimated scope**: 1 source file (1 changed line, 1 deleted line), 12 selector edits across 6 browser test files, 1 new browser test

## Implementation outcome — 2026-09-29

The account menu is named with the user’s full name and its purpose; the team picker’s name now includes its visible selection state. Preserved its existing focus ref and requirement description. Updated 13 old selectors across six browser files and added a regression for computed names on desktop/mobile with empty/selected teams. The new regression failed before the fix and passes afterward.

A separate subagent prepared this task and independent review found no actionable issues. The original plan below records the earlier baseline; implementation notes here take precedence. See README for final combined verification and remaining manual checks.

## Problem

Two popover triggers have an `aria-label` that replaces their visible text. That breaks WCAG 2.5.3 Label in Name. axe-core does not flag either one.

The account trigger shows the user's first name ("Filippo") from `md` up, but its name is "Account menu". A voice-control user who says "click Filippo" gets nothing. The avatar is `aria-hidden="true"` (`components/avatar.tsx:18`), so on phones, and on pages that show the New transfer button, the label is the only name the trigger has:

```tsx
// resources/scripts/components/filemax.tsx:164-179 — current
                                <PopoverTrigger
                                    className="flex items-center gap-2.5 text-muted-foreground"
                                    aria-label="Account menu"
                                >
                                    <span
                                        className={cn(
                                            'hidden md:inline',
                                            showsNewTransfer && 'md:hidden',
                                        )}
                                    >
                                        {user.name.split(' ')[0]}
                                    </span>
                                    <Avatar
                                        name={user.name}
                                        url={user.avatar_url}
                                    />
```

The team picker trigger shows "Choose teams" and then "1 selected" (or "Select at least one"). Its label hides the selection state from assistive tech:

```tsx
// resources/scripts/components/filemax.tsx:341-354 — current
                <PopoverTrigger
                    render={<Button variant="outline" />}
                    type="button"
                    className="w-full flex-wrap justify-between gap-y-1 py-2 whitespace-normal"
                    aria-label="Choose teams"
                    disabled={disabled}
                >
                    Choose teams
                    <span>
                        {availableSelection.length
                            ? `${availableSelection.length} selected`
                            : 'Select at least one'}
                    </span>
                </PopoverTrigger>
```

`TeamPicker` renders on the upload page (`pages/transfers/create.tsx`) and in the transfer page's "Change teams" dialog (`pages/transfers/show.tsx:168`). One fix covers both.

These tests locate the triggers by their current labels. Line numbers are as of d15b32d. In the working tree, the user's uncommitted test moves `AuthTeamBrowserTest.php:87` to line 103.

| File | Line | Current excerpt |
| --- | --- | --- |
| `tests/Browser/AuthTeamBrowserTest.php` | 73 | `        ->click('[aria-label="Account menu"]')` |
| `tests/Browser/AuthTeamBrowserTest.php` | 87 | `        ->click('[aria-label="Account menu"]')` |
| `tests/Browser/NavigationBrowserTest.php` | 42 | `button[aria-label="Account menu"]` inside `header.querySelectorAll(…)` |
| `tests/Browser/PasskeyBrowserTest.php` | 15 | `        ->keys('[aria-label="Account menu"]', 'Enter')` |
| `tests/Browser/SettingsBrowserTest.php` | 33 | `        ->click('[aria-label="Account menu"]')` |
| `tests/Browser/TransferManagementBrowserTest.php` | 110 | `    $page->resize(390, 844)->press('[aria-label="Choose teams"]')` |
| `tests/Browser/TransferManagementBrowserTest.php` | 114 | `    const trigger = document.querySelector('[aria-label="Choose teams"]').getBoundingClientRect();` |
| `tests/Browser/TransferManagementBrowserTest.php` | 123 | `        ->assertVisible('[aria-label="Choose teams"]:focus')` |
| `tests/Browser/TransferManagementBrowserTest.php` | 133 | `        ->press('[aria-label="Choose teams"]')` |
| `tests/Browser/TransferUploadBrowserTest.php` | 234 | `->click('[aria-label="Choose teams"]')` |
| `tests/Browser/TransferUploadBrowserTest.php` | 375 | `->click('[aria-label="Choose teams"]')` |
| `tests/Browser/TransferUploadBrowserTest.php` | 394 | `    $page->click('[aria-label="Choose teams"]')` |

## Target

```tsx
// resources/scripts/components/filemax.tsx:164-167 — target
                                <PopoverTrigger
                                    className="flex items-center gap-2.5 text-muted-foreground"
                                    aria-label={`${user.name}, account menu`}
                                >
```

```tsx
// resources/scripts/components/filemax.tsx:341-346 — target
                <PopoverTrigger
                    render={<Button variant="outline" />}
                    type="button"
                    className="w-full flex-wrap justify-between gap-y-1 py-2 whitespace-normal"
                    disabled={disabled}
                >
```

Resulting names, checked on equivalent markup in headless Chromium through both the CDP accessibility tree and Playwright's role engine:

- Account trigger: "Filippo Fortino, account menu".
- Picker trigger: "Choose teams Select at least one" with nothing selected, "Choose teams 1 selected" after a selection.

Why:

- **The account name starts with the visible text and can't drift from it.** The visible text is `user.name.split(' ')[0]`, which is always a prefix of `user.name`, so "click Filippo" works. When only the avatar shows, the full name plus "account menu" still says what the button is and whose it is. The avatar initials stay `aria-hidden`: they picture the name, and the full name already covers them.
- **An `aria-label` on the account trigger is still needed.** Without it, the name would come from the first-name span. That span is `display: none` on phones and next to New transfer, which would leave the button unnamed.
- **The picker gets the cheapest fix: delete the label.** With no label, the name comes from the visible content, so screen readers now hear the selection count, and the name can't go out of sync with the UI. Chromium and Playwright both put a space between "Choose teams" and the `<span>`, because inside the `inline-flex` button the span is a blockified flex item. An `aria-label={`Choose teams, ${n} selected`}` would repeat the visible text and could drift from it.
- **Test selectors:**
  - Account menu: `[aria-label$="account menu"]`. It stays stable when the user renames themselves. `SettingsBrowserTest` does exactly that before it opens the menu, so a selector with the full name would break there.
  - Picker, in Pest calls: `button:has-text("Choose teams")`. It is Playwright's CSS extension, and the repo already uses it (`button:has-text("Change teams"):focus`, `TransferManagementBrowserTest.php:127`). It matches exactly the elements the old label matched. A bare `'Choose teams'` would **not** work. Pest turns a bare string into an exact match on an element's full text, and the button's full text is "Choose teams1 selected", so it matches nothing (verified).
  - Picker, in plain `document.querySelector`: `:has-text` isn't real CSS there, so find the button by its text instead.

## Dependencies

- **None must land first.**
- **Plan 013 may add `aria-invalid`/`aria-describedby` to the same `PopoverTrigger`.** Step 3 anchors on the single line `aria-label="Choose teams"`, so either order works. Don't take over 013's props.
- **Plans 024 (PopoverContent `className`, line 356), 015 (members count, line 393) and 020 (Shell, lines 68-88)** edit neighbouring lines in `filemax.tsx`. There is no overlap.
- **Other plans edit the same test files.** Plans 008, 012, 013, 016 and 019 edit `TransferUploadBrowserTest.php`, and 020 likely edits `NavigationBrowserTest.php`. Any test they add that still uses `[aria-label="Choose teams"]` or `[aria-label="Account menu"]` breaks with this change, so Step 6's grep covers every file.
- **Plan 008's new test opens the picker with `->click('Choose teams')`, which matches nothing, as explained under Target.** If that test is in the file, it fails with or without this plan. Report it. Don't fix it here.

## Repo conventions to follow

- Browser tests are lowercase `it('…')` sentences. They chain Pest calls and open pickers the way `TransferUploadBrowserTest.php:234` does: `->click('Specific teams')`, open the picker, `->check('[aria-label="<team>"]')`, then `->keys('#team-search', 'Escape')`.
- `internal:role=button[name="…"s]` is the selector Pest's own `getByRole()` builds (`vendor/pestphp/pest-plugin-browser/src/Support/Selector.php`, `getByRoleSelector`). Pest passes `internal:` selectors through unchanged, so `->assertVisible('internal:role=…')` checks the computed accessible name. The `s` flag means exact and case-sensitive.
- The user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php` ('signs in when pressing near the edge of the submit button') is theirs. This plan edits only two selector strings in that file.

## Steps

1. **Add the test first.** In `tests/Browser/TransferUploadBrowserTest.php`, find `function controlledTransferUploads(): string` (line 422 at d15b32d). Insert this test directly above it, below any test another plan already put there, followed by one blank line. `Team` and `User` are already imported.

   ```php
   it('names the account menu and team picker after their visible text', function (): void {
       $user = User::factory()->create(['name' => 'Filippo Fortino']);
       $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
       $this->actingAs($user);

       visit('/')->resize(1280, 940)
           ->assertSeeIn('header [data-slot="popover-trigger"]', 'Filippo')
           ->assertVisible('internal:role=button[name="Filippo Fortino, account menu"s]')
           ->click('Specific teams')
           ->click('button:has-text("Choose teams")')
           ->check('[aria-label="Mediamax"]')
           ->keys('#team-search', 'Escape')
           ->assertVisible('internal:role=button[name="Choose teams 1 selected"s]')
           ->assertNoJavascriptErrors();
   });
   ```

   What it asserts:
   - The account trigger shows "Filippo", and its accessible name is exactly "Filippo Fortino, account menu".
   - After a team is picked, the picker trigger's accessible name is exactly its visible text, "Choose teams 1 selected".

2. **Prove the test fails before the fix.** Run `bun run build`, then:
   `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter='after their visible text'`.
   It must fail on `Expected element [internal:role=button[name="Filippo Fortino, account menu"s]] to be visible`, because today's name is "Account menu". If it fails anywhere else, stop and report.

3. **Fix the triggers** in `resources/scripts/components/filemax.tsx`:
   - Line 166, inside the `Shell` account `<PopoverTrigger className="flex items-center gap-2.5 text-muted-foreground"`: replace `aria-label="Account menu"` with ``aria-label={`${user.name}, account menu`}``.
   - Line 345, inside `TeamPicker`'s `<PopoverTrigger render={<Button variant="outline" />}`: delete the whole line `aria-label="Choose teams"`.

   Both results must match the Target. Change nothing else in the file.

4. **Update the account-menu selectors.** Replace the selector string only and leave the rest of each line as it is.
   - `tests/Browser/AuthTeamBrowserTest.php` lines 73 and 87 (103 in the working tree): `'[aria-label="Account menu"]'` → `'[aria-label$="account menu"]'`.
   - `tests/Browser/PasskeyBrowserTest.php:15`: the same replacement.
   - `tests/Browser/SettingsBrowserTest.php:33`: the same replacement.
   - `tests/Browser/NavigationBrowserTest.php:42`: `button[aria-label="Account menu"]` → `button[aria-label$="account menu"]`. The test already expects 3 matched elements, so a selector that matches nothing fails it.

5. **Update the picker selectors.** Again, replace the selector string only.
   - `tests/Browser/TransferUploadBrowserTest.php` lines 234, 375 and 394: `'[aria-label="Choose teams"]'` → `'button:has-text("Choose teams")'`.
   - `tests/Browser/TransferManagementBrowserTest.php`:
     - Lines 110 and 133: `'[aria-label="Choose teams"]'` → `'button:has-text("Choose teams")'`.
     - Line 123: `'[aria-label="Choose teams"]:focus'` → `'button:has-text("Choose teams"):focus'`.
     - Line 114, inside the JS heredoc: replace `document.querySelector('[aria-label="Choose teams"]')` with `[...document.querySelectorAll('button')].find((button) => button.textContent.startsWith('Choose teams'))`. Keep `.getBoundingClientRect();` after it.

6. **Check that nothing still uses the old labels.** Both of these must print nothing:
   `grep -rn 'aria-label="Account menu"' resources/scripts tests`
   `grep -rn 'aria-label="Choose teams"' resources/scripts tests`
   If a match remains in a test that a sibling plan added, apply the same replacement from Step 4 or 5.

7. **Format.**
   - Run `bun run lint`.
   - Run `vendor/bin/pint --format agent tests/Browser/TransferUploadBrowserTest.php tests/Browser/TransferManagementBrowserTest.php tests/Browser/NavigationBrowserTest.php tests/Browser/PasskeyBrowserTest.php tests/Browser/SettingsBrowserTest.php`.
   - The paths are deliberate. `--dirty` would also reformat the user's uncommitted test in `AuthTeamBrowserTest.php`, where this plan changes only two string literals.

8. **Build and re-run.** Run `bun run build`, then the suites under Verification → Tests.

## Boundaries

- Only these change:
  - `filemax.tsx`: line 166 changes and line 345 is deleted.
  - The 12 selector strings listed in Steps 4 and 5, plus any a sibling plan added (Step 6).
  - One new test.
- Do NOT touch the per-team checkbox labels (`aria-label={team.name}`) or selectors such as `[aria-label="Mediamax"]`.
- Do NOT change any visible text or its classes:
  - the first-name span (`hidden md:inline`)
  - "Choose teams"
  - "`{n} selected`" and "Select at least one"
- Do NOT add `aria-invalid`/`aria-describedby` to the picker trigger. Plan 013 owns them.
- Do NOT touch the PopoverContent `className` (plan 024), the Shell layout or skip link (plan 020), or the `members` count (plan 015).
- Do NOT remove `aria-hidden` from `Avatar`. Do NOT change the header "New transfer" link's `aria-label`, whose visible text already matches.
- In `tests/Browser/AuthTeamBrowserTest.php`, change only the two selector strings. The user's test 'signs in when pressing near the edge of the submit button' must stay byte-identical.
- Do NOT edit plan 008's test, even if it's present and failing. Report it.
- Do NOT add dependencies.
- Line numbers are as of d15b32d and will shift as sibling plans land, so locate code by the quoted excerpts. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Verification

- **Mechanical**:
  - `bun run lint`, then `bun run test:lint`, passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - Both Step 6 greps print nothing.
- **Tests** (after `bun run build`):
  - `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/TransferManagementBrowserTest.php tests/Browser/NavigationBrowserTest.php tests/Browser/PasskeyBrowserTest.php tests/Browser/SettingsBrowserTest.php`
  - `php artisan test --compact tests/Browser/AuthTeamBrowserTest.php --filter='account menu'`. This runs the two tests whose selectors changed. Leave the user's own test out, because it tracks plan 011.
  - All must pass, including the new test. `TransferManagementBrowserTest` covers the "Change teams" dialog: it opens, focuses and measures the same trigger through the new selectors. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  - Nothing changes visually.
  - On `/` at desktop width, open the Accessibility pane in Chrome DevTools on the account trigger. Its computed name is "<your full name>, account menu". Narrow the window below 768px, or go to `/transfers`. The name is the same, and only the avatar shows.
  - On `/`, choose "Specific teams". The picker trigger's computed name is "Choose teams Select at least one". Pick a team, and it becomes "Choose teams 1 selected".
  - On a team-shared transfer, open "Change teams". The picker trigger's computed name includes the current count, for example "Choose teams 2 selected".
  - Optional: with VoiceOver (Cmd+F5), Tab to both triggers. Each is announced by its visible text.
- **Done when**:
  - Neither trigger has a label that hides its visible text.
  - Both Step 6 greps print nothing.
  - The new test and the listed suites pass.

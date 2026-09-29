# 015 — Pluralize counts correctly

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: MEDIUM
- **Category**: Writing
- **Estimated scope**: 6 files: 7 count strings in 4 source files, 2 new browser tests, 1 changed and 3 added assertions in existing tests

## Implementation outcome — 2026-09-29

All seven count strings now handle singular and plural forms using the existing inline convention. Added deterministic countdown and file/member-count regressions, plus plural and transfer-count coverage in existing flows. The two new regressions failed before the fix and pass afterward. No helper or dependency was added.

A separate subagent prepared this task and independent review found no actionable issues. The original plan below records the earlier baseline; implementation notes here take precedence. See README for final combined verification and remaining manual checks.

## Problem

Seven count strings hard-code the plural noun. A one-file transfer is the most common case, and it reads "1 files". The review saw "1 files · 5 B" after dropping one file, and "1 members" in the team picker for a one-member team. The source also shows that "About 1 seconds left" (at 1 second) and "About 1 minutes left" (at exactly 60 seconds) can appear.

A grep of `resources/scripts` for a count followed by a noun finds these seven strings and no others:

| Site (d15b32d) | Where it shows | In the reviewed upload flow |
| --- | --- | --- |
| `pages/transfers/create.tsx:414` | "Your link is ready" summary | yes |
| `pages/transfers/create.tsx:587`, `:588` | time left while uploading | yes |
| `pages/transfers/create.tsx:596` | draft summary above the file list | yes |
| `components/filemax.tsx:393` | TeamPicker rows (upload page, and the "Change teams" dialog on the transfer page) | yes |
| `pages/transfers/show.tsx:236` | team chips on the transfer page | **no** |
| `pages/transfers/index.tsx:43` | "My transfers" summary line | **no** |

```tsx
// resources/scripts/pages/transfers/create.tsx:413-417 — current
<p className="text-muted-foreground">
    {ready.files.length} files ·{' '}
    {bytes(ready.total_size)} · expires{' '}
    {dateTime(ready.expires_at)}
</p>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:586-588 — current
{remaining < 60
    ? `${remaining} seconds`
    : `${Math.ceil(remaining / 60)} minutes`}{' '}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:595-598 — current
<p className="text-muted-foreground">
    {entries.length} files ·{' '}
    {bytes(totalSize)}
</p>
```

```tsx
// resources/scripts/components/filemax.tsx:392-394 — current
<small className="text-muted-foreground">
    {team.users_count} members
</small>
```

```tsx
// resources/scripts/pages/transfers/show.tsx:235-237 — current
<span className="text-xs text-muted-foreground">
    {team.users_count} members
</span>
```

```tsx
// resources/scripts/pages/transfers/index.tsx:42-45 — current
<p className="text-muted-foreground">
    {totals.total} transfers · {totals.active} active ·{' '}
    {totals.expired} expired
</p>
```

Every other count in the app already pluralizes with an inline ternary. There are 8 such sites: `create.tsx:764`, `:893` and `:930`, `transfers/show.tsx:82` and `:93-94`, `transfers/index.tsx:171-174`, `shared/show.tsx:95-96` and `teams/index.tsx:98-99`.

Out of scope, deliberately:

- "Show all N files" (`create.tsx:454`, `transfers/show.tsx:319`, `shared/show.tsx:90`) renders only when N > 4.
- `${requirements.min} characters` (`lib/format.ts:12`) is at least 8.
- "N selected", "N active" and "N expired" end in adjectives, which don't take a plural.
- The backend renders no counts. `resources/views` holds only `app.blade.php`.

One test pins a buggy string: `tests/Browser/TransferUploadBrowserTest.php:280` asserts `'1 transfers'`.

## Target

These are the formatter's output (`vp fmt`, printWidth 80), so `bun run lint` should leave them unchanged.

```tsx
// resources/scripts/pages/transfers/create.tsx:413-417 — target
<p className="text-muted-foreground">
    {ready.files.length}{' '}
    {ready.files.length === 1 ? 'file' : 'files'} ·{' '}
    {bytes(ready.total_size)} · expires{' '}
    {dateTime(ready.expires_at)}
</p>
```

```tsx
// resources/scripts/pages/transfers/create.tsx:586-588 — target
{remaining < 60
    ? `${remaining} ${remaining === 1 ? 'second' : 'seconds'}`
    : `${Math.ceil(remaining / 60)} ${Math.ceil(remaining / 60) === 1 ? 'minute' : 'minutes'}`}{' '}
```

```tsx
// resources/scripts/pages/transfers/create.tsx:595-598 — target
<p className="text-muted-foreground">
    {entries.length}{' '}
    {entries.length === 1
        ? 'file'
        : 'files'}{' '}
    · {bytes(totalSize)}
</p>
```

```tsx
// resources/scripts/components/filemax.tsx:392-394 — target
<small className="text-muted-foreground">
    {team.users_count}{' '}
    {team.users_count === 1
        ? 'member'
        : 'members'}
</small>
```

```tsx
// resources/scripts/pages/transfers/show.tsx:235-237 — target
<span className="text-xs text-muted-foreground">
    {team.users_count}{' '}
    {team.users_count === 1
        ? 'member'
        : 'members'}
</span>
```

```tsx
// resources/scripts/pages/transfers/index.tsx:42-45 — target
<p className="text-muted-foreground">
    {totals.total}{' '}
    {totals.total === 1 ? 'transfer' : 'transfers'} ·{' '}
    {totals.active} active · {totals.expired} expired
</p>
```

The rendered text becomes "1 file · 5 B", "1 member", "About 1 second left", "About 1 minute left" and "1 transfer · 1 active · 0 expired". Every other count renders exactly as before.

Why:

- **Reuse the pattern the app already has.** All 8 correct sites use `n === 1 ? 'noun' : 'nouns'`, in JSX or in a template literal (`create.tsx:893`). The review suggested this too.
- **No `plural()` helper in `lib/format.ts`.** A helper for 7 sites would either be a second convention next to the 8 existing ternaries, or pull those 8 correct lines into this diff. Several of them sit next to lines that sibling plans edit.
- **No `Intl.PluralRules` or `Intl.NumberFormat` unit style.** The app is English-only and every noun here has a regular plural. `n === 1` is exactly the English "one" category, and 0 takes the plural. Intl would add a third pattern that gives the same result.
- **Minutes: `Math.ceil(remaining / 60) === 1` is true only at 60 seconds.** The branch runs only when `remaining >= 60`. Repeating the expression is plain and easy to read, so no new variable is needed.
- **The wording and word order stay the same.** better-writing asks for full templated strings with proper pluralization. The only word that changes is the noun.

## Dependencies

- **None must land first.**
- **`create.tsx` is edited by 009, 010, 012, 013, 016, 017, 019, 021 and 023.** The nearest edits: 012 edits the ready `<h1>` directly above excerpt 1, and the status `<h1>` above the time-left block. 023 edits the percentage `<strong>` (~566), and 016 edits the heading string (~561). None of them changes the lines quoted here.
- **`filemax.tsx` is edited by 014 (trigger, ~345), 024 (`PopoverContent`, ~356), 018, 020 and 025.** None of them touches line 393.
- **`transfers/show.tsx` and `transfers/index.tsx`:** 020 may edit their `<main>` elements, which are not these lines.
- **`TransferUploadBrowserTest.php` is also edited by 008, 010, 012, 013, 014, 016 and 019.**
  - 016 and 019 edit line 267, in the same test as this plan's line 280 edit.
  - 010 inserts a test after that test.
  - 008 inserts a test directly above `function controlledTransferUploads()`. This plan anchors its new test to the end of the previous test instead, so either order works.
- **014 replaces `[aria-label="Choose teams"]`.** The new test opens the picker with `button:has-text("Choose teams")`, the selector 014 adopts. It works before and after 014, and adds no occurrence for 014 to update. Don't use a bare `'Choose teams'`, because Pest matches that exactly against the button's full text ("Choose teamsSelect at least one") and finds nothing.
- **`TransferManagementBrowserTest.php`:** 014 edits lines 110-133. This plan edits the pagination test at lines 27-28.

## Repo conventions to follow

- JSX counts: `{n}{' '}{n === 1 ? 'noun' : 'nouns'}` (`teams/index.tsx:98-99`, `shared/show.tsx:95-96`). Template-literal counts: `` `${n} ${n === 1 ? 'team' : 'teams'}` `` (`create.tsx:893`, `transfers/show.tsx:82`).
- Browser tests are lowercase `it('…')` sentences. They drop files with `DataTransfer` and a `DragEvent('drop')` on `main` (`TransferUploadBrowserTest.php:235-241`), and pick teams with `->check('[aria-label="<team>"]')->keys('#team-search', 'Escape')` (line 234).
- The `concurrent upload chunks` describe block (lines 11-177) sets `part_size = 4` and provides `controlledTransferUploads()`, where `window.uploads[i].progress(bytes)` reports upload progress. Line 84 already shifts `Date.now` to fake elapsed time.
- `assertSee` is a case-insensitive substring match that retries until the timeout. So `'1 member'` also matches "1 members". Where the singular has no trailing context, assert `assertDontSee('<n> <plural>')` next to it.

## Steps

Line numbers are as of d15b32d and will shift as sibling plans land. Locate each edit by its quoted excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

1. **Add the time-left test.** In `tests/Browser/TransferUploadBrowserTest.php`, find the end of `it('waits for an aborted signing response without starting its upload'`. It ends with `->and(Transfer::query()->sole()->revoked_at)->not->toBeNull();`, then `    });`, then the describe's closing `});` (lines 175-177). Insert this between `    });` and `});`, after a blank line:

   ```php
       it('pluralizes the time left', function (): void {
           $page = visit('/')->assertSee('Browse files');
           $page->script(controlledTransferUploads());
           $page->script('() => { const now = Date.now; window.clockOffset = 0; Date.now = () => now() + window.clockOffset; window.dropUploadFiles([4, 4, 4, 4, 1]); }');
           $page->press('Create transfer')->assertScript('window.uploads.length', 4);

           foreach ([10 => 'About 1 second left', 26 => 'About 2 seconds left', 954 => 'About 1 minute left', 1400 => 'About 2 minutes left'] as $offset => $timeLeft) {
               $page->script("() => { window.clockOffset = {$offset} * 1000; window.uploads.forEach(upload => upload.progress(4)); }");
               $page->assertSee($timeLeft);
           }
           $page->assertNoJavascriptErrors();
       });
   ```

   How it works: the first four files are one 4-byte part each and fill the four upload slots. Once each reports 4 bytes, 1 of 17 bytes remains after 16 bytes in `elapsed` seconds, so `remaining = round(elapsed / 16)`. The offsets give 1 s, 2 s, 60 s ("1 minute") and 88 s ("2 minutes"). Each holds as long as less than 14 s of real time passes between pressing Create transfer and the last step. Don't tune the values. Today the test fails on "About 1 second left", because the page says "About 1 seconds left".

2. **Add the file and member count test.** In the same file, find the end of `it('shows the first four ready files until all of them are requested'`. It ends with `        ->assertNoJavascriptErrors();` and `});` (lines 419-420). Insert this directly after it, with one blank line on each side:

   ```php
   it('pluralizes file and member counts from upload to transfer detail', function (): void {
       Storage::fake('local');
       config(['filemax.disk' => 'local']);
       $user = User::factory()->create();
       $mediamax = Team::factory()->create(['name' => 'Mediamax']);
       $lenergy = Team::factory()->create(['name' => 'Lenergy']);
       $user->teams()->attach([$mediamax->id, $lenergy->id]);
       $lenergy->users()->attach(User::factory()->create());
       $this->actingAs($user);
       $page = visit('/')->assertSee('Drop files here');
       $page->click('Specific teams')->click('button:has-text("Choose teams")')
           ->assertSee('2 members')->assertSee('1 member')->assertDontSee('1 members')
           ->check('[aria-label="Mediamax"]')->check('[aria-label="Lenergy"]')->keys('#team-search', 'Escape');
       $page->script(<<<'JS'
   () => {
       const data = new DataTransfer(); data.items.add(new File(['hello'], 'brief.txt'));
       document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
   }
   JS);
       $page->assertSee('1 file · 5 B')->press('Create transfer')->assertSee('1 file · 5 B · expires');
       $page->click('View transfer')->assertSee('2 members')->assertSee('1 member')->assertDontSee('1 members')->assertNoJavascriptErrors();
   });
   ```

   It covers four sites in one flow: TeamPicker rows (1 and 2 members), the draft summary ("1 file · 5 B"), the ready summary ("1 file · 5 B · expires") and the team chips on the transfer page (1 and 2 members). Today it fails on `assertDontSee('1 members')` in the picker.

3. **Guard the plurals in existing tests** (these assertions pass before and after the fix):
   - Same file, same "first four ready files" test, line 412: change `    $page->press('Create transfer')->assertSee('Your link is ready')` to
     ```php
         $page->assertSee('5 files · 25 B')->press('Create transfer')->assertSee('Your link is ready')
             ->assertSee('5 files · 25 B · expires')
     ```
     Keep the following `->assertSee('file3.txt')` and the rest of the chain unchanged.
   - `tests/Browser/TransferManagementBrowserTest.php`, in `it('uses links for available pages and disabled buttons at the pagination boundaries'`, lines 27-28: between `    visit('/transfers')` and `        ->assertSee('Page 1 of 2')`, insert `        ->assertSee('21 transfers · 21 active · 0 expired')`.

4. **Update the pinned string.** In `TransferUploadBrowserTest.php` line 280, change `->assertSee('1 transfers')` to `->assertSee('1 transfer · 1 active · 0 expired')`. Leave the rest of the line (`$page->click('My transfers')…->assertSee('Spot autunno');`) as it is.

5. **Format the tests:** `vendor/bin/pint --format agent tests/Browser/TransferUploadBrowserTest.php tests/Browser/TransferManagementBrowserTest.php`. Pass the paths, because `--dirty` would also reformat the user's uncommitted `tests/Browser/AuthTeamBrowserTest.php`.

6. **Prove the new tests fail.** Run `bun run build`, then `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter=pluralizes`. Both tests must fail, one on "About 1 second left" and one on "1 members". If either passes, or fails for another reason, STOP and report.

7. **Fix the seven strings** as in Target. Each one is anchored on its content line:
   - `create.tsx`: the line `{ready.files.length} files ·{' '}` (line 414) becomes the two lines `{ready.files.length}{' '}` and `{ready.files.length === 1 ? 'file' : 'files'} ·{' '}`.
   - `create.tsx`: `` ? `${remaining} seconds` `` (line 587) and `` : `${Math.ceil(remaining / 60)} minutes`}{' '} `` (line 588) become the Target's two lines.
   - `create.tsx`: the two lines `{entries.length} files ·{' '}` and `{bytes(totalSize)}` (lines 596-597) become the Target's five lines.
   - `components/filemax.tsx`: `{team.users_count} members` (line 393, inside `<small className="text-muted-foreground">`) becomes the Target's four lines.
   - `pages/transfers/show.tsx`: `{team.users_count} members` (line 236, inside `<span className="text-xs text-muted-foreground">`) becomes the Target's four lines.
   - `pages/transfers/index.tsx`: the two lines `{totals.total} transfers · {totals.active} active ·{' '}` and `{totals.expired} expired` (lines 43-44) become the Target's three lines.
8. Run `bun run lint`. It should change nothing in these lines. If it reflows them, that's fine, because only the rendered text matters.

## Boundaries

- Only the 7 count strings above change, plus the test edits in steps 1-4.
- Do NOT add a pluralization helper, touch `lib/format.ts`, or rewrite the 8 sites that already pluralize.
- Do NOT change any other wording: separators, "About … left", "expires", "active", "expired", the 60-second threshold, or the "About 0 seconds left" case. Plans 016 and 019 own the failure copy ("A little interruption", "Some files could not be uploaded.", the per-file errors in `lib/http.ts`).
- Do NOT touch "Show all N files", `shared/show.tsx` or `teams/index.tsx`. They are correct, and `SharedTransferBrowserTest.php:29` and `AuthTeamBrowserTest.php:124` pin them.
- Do NOT change the TeamPicker trigger or its `aria-label` (014), the `PopoverContent` classes (024), the ready or status `<h1>` (012), the percentage (023) or any color classes (018).
- Do NOT modify `tests/Browser/AuthTeamBrowserTest.php`. It holds the user's uncommitted edit.
- Do NOT add dependencies.

## Verification

- **Mechanical**:
  - `bun run lint`, then `bun run test:lint`, passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - `grep -rnE "\{[a-zA-Z_.]+\} (files|members|transfers)\b|\\\$\{[^}]+\} (seconds|minutes)" resources/scripts/pages resources/scripts/components` prints only the three "Show all N files" lines.
- **Tests** (after `bun run build`): `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php tests/Browser/TransferManagementBrowserTest.php`. Every test must pass, including the two new ones. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  - On `/`, drop one file. The summary reads "1 file · …". Drop another and it reads "2 files · …".
  - Pick "Specific teams", then "Choose teams". A team where you're the only member reads "1 member".
  - Send one file. "Your link is ready" reads "1 file · … · expires …". "View transfer" shows the team chips with "1 member" where that applies.
  - On "My transfers" with a single transfer, the line reads "1 transfer · 1 active · 0 expired".
  - Optional: upload a larger file with DevTools network throttling on. The countdown ends on "About 1 second left".
- **Done when**: no count in `resources/scripts` renders a plural noun after 1, and the two browser suites pass.

# 021 — Show that 'Specific teams' is unavailable

- **Status**: IMPLEMENTED
- **Review baseline**: d15b32d
- **Severity**: LOW
- **Category**: UI polish
- **Estimated scope**: 1 file, 1 changed class string

## Implementation outcome — 2026-09-29

The disabled visibility card now uses the existing muted background and text tokens. Enabled choices retain their styling. Added a screenshot to the existing no-team Home browser test and inspected it alongside the team-enabled empty state. No new component, opacity rule or CSS assertion was added.

A separate subagent prepared this task and independent review found no actionable issues. The original plan below records the earlier baseline; implementation notes here take precedence. See README for final combined verification and remaining manual checks.

## Problem

For a user with no team memberships, the "Specific teams" radio is natively `disabled`. Its card still looks exactly like the enabled "Public link" card: the same white fill and border, and the same dark title and icon. The only differences are the small native radio, which greys itself, and the cursor, which changes on hover. The option invites taps that do nothing. Every new user sees this, because registration never grants a team membership (`FILEMAX_IMPLEMENTATION_PLAN.md:99,108`).

Both cards come from one `.map()`, and nothing in the class string reacts to `:disabled` except the cursor:

```tsx
// resources/scripts/pages/transfers/create.tsx:834-837 — current
                                        <label
                                            className="flex cursor-pointer flex-col gap-2 rounded-lg border border-input p-4 has-checked:border-primary has-checked:bg-primary/5 has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed"
                                            key={option.value}
                                        >
```

```tsx
// resources/scripts/pages/transfers/create.tsx:852-858 — current (why the teams radio is disabled)
                                                    disabled={
                                                        busy ||
                                                        hasDraft ||
                                                        (option.value ===
                                                            'teams' &&
                                                            teams.length === 0)
                                                    }
```

The reason is already given in visible text just below the cards (`create.tsx:891-895`, "No team memberships yet. You can share public links, or ask an admin to add you to a team.").

## Target

```tsx
// resources/scripts/pages/transfers/create.tsx:835 — target
                                            className="flex cursor-pointer flex-col gap-2 rounded-lg border border-input p-4 has-checked:border-primary has-checked:bg-primary/5 has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed has-disabled:bg-muted has-disabled:text-muted-foreground"
```

Why:

- **It reuses the project's own disabled recipe.** The base field rule in `resources/css/app.css:79` greys disabled fields with `disabled:bg-muted disabled:text-muted-foreground`, and the Title and Message fields on this page use it. The card gets the same pair, with no new token or value. The review proposed the same fix, and it holds.
- **Everything inside the card follows.**
  - The icon uses `currentColor` through its unstyled `<span>`, so it greys.
  - The `<strong>` title has no colour of its own unless it is selected, so it greys too.
  - The description is already `text-muted-foreground`.
  - The native radio greys itself.
- **It stays legible.** Computed from Tailwind 4.3.3's oklch values:
  - `slate-600` on `slate-100` is 6.90:1. Disabled controls are exempt from contrast minimums, but this still clears 4.5:1.
  - The title drops from 17.84:1 to 6.90:1.
  - The fill changes from white to grey, 1.10:1 against the white form. That is the same step as the disabled fields.
  - The `border-input` border is unchanged.
- **Enabled cards don't change.** `:has(:disabled)` matches only the label whose own radio is disabled. "Public link", and "Specific teams" for a user with teams, keep every current class.
- **Side effect: the card greys while the draft is created, like the fields.** `send()` sets `busy` while the POST that creates the draft is in flight, before `draft.current` exists. For that moment every control in the form is disabled, so both cards turn grey at the same moment as the Title and Message fields. The compiled CSS emits `has-disabled:*` after `has-checked:*`, at equal specificity. The selected card therefore goes grey but keeps its primary border, ring, title and icon. This is consistent and needs no guard.
- **Skipped: `aria-describedby` linking the radio to the explanation.** The reason is already visible text directly after the cards, as `better-accessibility` recommends for native `disabled`. A disabled radio isn't in the tab order, so a screen reader reaches it only while reading the page in order, and the explanation is read next.
- **Not `opacity`.** Opacity would fade the border and the already-grey native radio too. The project already has a disabled look for fields, and this plan reuses it.

## Dependencies

- **None must land first.**
- **018 (semantic colour tokens)** may rename tokens. If 018 has already changed the disabled pair in the base field rule (`resources/css/app.css`, the `disabled:bg-… disabled:text-…` classes on the `input…, select, textarea` rule), use that same pair here, so the card keeps matching the fields.
- **`create.tsx` is also edited by 009, 010, 012, 013, 015, 016, 017, 019 and 023, all on other lines.** 013 edits the "Select at least one team." hint just below the cards (~904), and possibly the `TeamPicker` props. The others only shift line numbers. Land the `create.tsx` plans one at a time.

## Repo conventions to follow

- Radio-card state lives in `has-*` variants on the `<label>`, keyed off the native input inside it (`has-checked:`, `has-focus-visible:`, `has-disabled:`). Keep the edit inside that class string.
- Colours come from the semantic tokens in `@theme inline` in `resources/css/app.css` (`bg-muted`, `text-muted-foreground`), never from raw palette classes.

## Steps

1. In `resources/scripts/pages/transfers/create.tsx` at line 835, find the `<label` inside the `.map((option) => (` over the two visibility options (`label: 'Public link'`, `label: 'Specific teams'`). Its `className` ends with `has-focus-visible:outline-primary has-disabled:cursor-not-allowed"`, and the next line is `key={option.value}`. Append ` has-disabled:bg-muted has-disabled:text-muted-foreground` inside the closing quote. The result must match the Target.
   - The expiry-pill `<label>` (~920) ends with the same `has-disabled:cursor-not-allowed"`, but it follows `key={days}`. Do not edit it.
2. Run `grep -c "has-disabled:bg-muted has-disabled:text-muted-foreground" resources/scripts/pages/transfers/create.tsx`. It must print `1`.
3. Run `bun run lint`. The Tailwind class sorter may reorder classes, which is expected.

Line numbers are as of d15b32d and shift as sibling plans land. Locate each edit by its excerpt. If an excerpt can't be found verbatim, STOP and report instead of improvising.

## Boundaries

- Only this one `<label>` class string changes.
- Do NOT change the enabled or selected styling (`border-input`, `has-checked:*`, `has-focus-visible:*`), the `cursor-*` classes, the `disabled` condition or the explanation text.
- Do NOT hide or remove the "Specific teams" card. The form must offer "Public link versus Specific teams" (`FILEMAX_IMPLEMENTATION_PLAN.md:165`), and the explanation below it refers to both.
- Do NOT add `aria-describedby`, `aria-disabled` or a tooltip.
- Do NOT touch the expiry-pill labels (~920). They are disabled only during the draft POST, just before the draft summary replaces the form.
- Do NOT add or edit tokens in `resources/css/app.css` (018). Do NOT touch the "Select at least one team." hint or the `TeamPicker` (013, 014).
- Do NOT modify the user's uncommitted test in `tests/Browser/AuthTeamBrowserTest.php`.

## Verification

- **Mechanical**:
  - `bun run test:lint` passes.
  - `node_modules/.bin/vp check` reports no errors beyond those it reported before your edit.
  - `bun run build` succeeds.
  - No PHP file changes, so Pint has nothing to do.
- **Tests**: this change is visual only. Its only measurable outcome is a computed colour, which plan 002's precedent rules out. Per the repo rule, after `bun run build` run the suites that render both card states:
  - `php artisan test --compact tests/Browser/HomeTest.php`. The test `shows the new transfer form to verified staff` renders the form for a user with no teams, so the "Specific teams" card is disabled, and it asserts no JavaScript errors.
  - `php artisan test --compact tests/Browser/TransferUploadBrowserTest.php --filter=sharing`. The two matching tests (`recovers a failed multi-file upload while preserving finished files and sharing` and `can repair sharing after losing membership during an upload`) click the enabled "Specific teams" card as a user with teams, then create a restricted transfer.

  All must pass. Do not write tests that assert class strings or CSS values. If Playwright reports a missing browser, stop and report. Do not install anything.
- **Manual check** at `https://filemax.test`:
  1. **No teams.** Sign in as a user with no team memberships.
     - The "Specific teams" card has a grey fill, and its icon, title and description are grey. The title is still easy to read.
     - "Public link" looks as before: blue border, ring and tint.
     - Hovering "Specific teams" shows the not-allowed cursor, and clicking it does nothing.
  2. **With a team.** Sign in as a user who belongs to a team. Both cards are white. Choosing "Specific teams" gives it the blue border, ring and tint, exactly as before.
  3. **During the draft POST.** Set DevTools Network to "Slow 3G", add a file and click "Create transfer". Until the summary appears, both cards grey out together with the Title and Message fields, and the selected card keeps its blue border and ring.
- **Done when**: the disabled "Specific teams" card reads as unavailable at a glance, enabled cards are unchanged, and the three browser tests pass.

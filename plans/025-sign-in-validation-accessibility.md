# 025 — Associate sign-in errors and focus the invalid field

## Implementation outcome — 2026-09-29

Implemented optional ErrorMessage IDs and Login-local focus handling. The regression passes through successive Email/Password failures and successful sign-in. The live local preview also confirmed keyboard focus returns to Email and its error association resolves. Screen-reader speech was not verified. The pre-existing edge-click test is unchanged.

Verification: client/SSR build, resource formatting, Pint and git diff --check passed. The combined six browser suites passed 52 tests / 681 assertions. Resource lint/type checking reports zero errors and four pre-existing uploader warnings; the repository-wide check stops on five unchanged config-formatting issues.

> **For agentic workers:** Use `superpowers:executing-plans` or `superpowers:subagent-driven-development` when implementation is requested. Complete this plan independently; do not start sibling plans automatically.

**Goal:** After a failed sign-in, focus the first invalid field and expose its error as that field's accessible description.

**Architecture:** Give the existing `ErrorMessage` component an optional DOM ID. Keep focus handling local to Login using two input refs and Inertia's `onError` callback; leave validation and authentication on the server.

**Tech Stack:** React 19, Inertia 3, Laravel/Fortify, Pest Browser.

**Spec:** The 2026-09-29 `better-interface` sign-in finding in this chat; [Filemax interface conventions](../FILEMAX_IMPLEMENTATION_PLAN.md) require labels, validation announcements and keyboard support.

- **Status:** IMPLEMENTED
- **Severity:** MEDIUM
- **Review baseline:** `8adfa07` on 2026-09-29
- **Files to modify:** `resources/scripts/components/filemax.tsx` (`ErrorMessage`), `resources/scripts/pages/auth/login.tsx` (`Login`), `tests/Browser/AuthTeamBrowserTest.php` (one new test).

## Problem

Login sets `aria-invalid` but neither field has `aria-describedby`. `ErrorMessage` renders an alert without an ID. A keyboard submission that rejected Email left focus on Password during the review. The error is visible, but returning to Email does not expose the message as its description.

## Global constraints

- Follow `AGENTS.md`; activate `inertia-react-development` and use Boost `search-docs` for form callbacks and browser testing before changing application code.
- Preserve native `required`, email validation, autocomplete, labels, password reset behavior and the button shrink effect.
- No new form library, generic validation wrapper, backend changes, new dependencies, or changes to other authentication screens.
- Preserve the existing uncommitted edge-click regression test byte-for-byte. Do not commit or push unless separately requested.
- This plan does not depend on any sibling fix. If 011 is still pending, its two known edge-click failures are a separate baseline issue; do not weaken or remove them.

## Review focus

- Focus order follows the form: Email before Password when both fail.
- A second submission failing a different field must not refocus an old invalid field.
- Error descriptors exist only while the corresponding error exists, and every descriptor resolves to a rendered element.
- A subsequent successful sign-in still clears the password and navigates normally.
- Existing `ErrorMessage` consumers without an ID keep their current behavior.

## Task: Accessible sign-in validation

**Interfaces:** Extend `ErrorMessage` props to `{ children?: ReactNode; id?: string }`; forward `id` to the existing `<p role="alert">`. The Inertia Form callback receives current errors; its ref is an Inertia control object, not a native form element.

- [x] **Add one browser regression test** named `associates sign in errors and focuses the first invalid field`. Append it without changing the existing edge-click test. Use a factory user and the real authentication endpoint, following this sequence:

  ```php
  $user = User::factory()->create();
  $page = visit('/login');
  $page->assertScript('document.getElementById("email").hasAttribute("aria-describedby")', false)
      ->assertScript('document.getElementById("password").hasAttribute("aria-describedby")', false)
      ->fill('email', $user->email)
      ->fill('password', 'incorrect-password')
      ->press('form button[data-slot="button"]')
      ->assertSee(__('auth.failed'))
      ->assertAttribute('#email', 'aria-invalid', 'true')
      ->assertAttribute('#email', 'aria-describedby', 'login-email-error')
      ->assertSeeIn('#login-email-error', __('auth.failed'))
      ->assertScript('document.activeElement.id', 'email');

  $page->fill('password', '');
  $page->script('() => { document.querySelector("form").noValidate = true; }');
  $page->press('form button[data-slot="button"]')
      ->assertAttribute('#password', 'aria-invalid', 'true')
      ->assertAttribute('#password', 'aria-describedby', 'login-password-error')
      ->assertSeeIn('#login-password-error', __('validation.required', ['attribute' => 'password']))
      ->assertAttribute('#email', 'aria-invalid', 'false')
      ->assertScript('document.getElementById("email").hasAttribute("aria-describedby")', false)
      ->assertScript('document.activeElement.id', 'password');

  $page->fill('password', 'password')
      ->press('form button[data-slot="button"]')
      ->assertSee('Transfer details')->assertNoJavascriptErrors();
  ```

  Wrap this in the usual `it(..., function (): void { ... })`. `noValidate` is a test-only step to exercise the server-required Password error. Do not add it to application markup. Inertia form errors arrive via redirects and page props; do not mock a standalone 422 JSON response.

- [x] **Run the test before implementation:** `bun run build`, then `php artisan test --compact tests/Browser/AuthTeamBrowserTest.php --filter='associates sign in errors'`. Expect failure on missing association/focus; resolve setup failures before attributing them to the finding.

- [x] **Extend `ErrorMessage`** with the optional `id` prop. Keep its existing classes, conditional rendering and `role="alert"`.

- [x] **Wire Login's fields.** Add `emailInput` and `passwordInput` using `useRef<HTMLInputElement>(null)`. Set each input's `ref`. Use `aria-describedby={errors.email ? 'login-email-error' : undefined}` and the equivalent password expression. Pass these IDs to their matching `ErrorMessage` instances.

- [x] **Focus from current callback errors.** Add this `onError` to Login's existing `<Form>`:

  ```tsx
  onError={(errors) => {
      requestAnimationFrame(() => {
          if (errors.email) {
              emailInput.current?.focus();
          } else if (errors.password) {
              passwordInput.current?.focus();
          }
      });
  }}
  ```

  Schedule focus after the error update; do not query stale `[aria-invalid]` elements inside the callback. React refs also safely do nothing if the page unmounts first. Do not make the shared `ErrorMessage` component steal focus.

- [x] **Format and verify.** Run `bun run lint`, `bun run test:lint`, `vendor/bin/pint --dirty --format agent`, `bun run build`, then the focused test above and `php artisan test --compact tests/Browser/AuthTeamBrowserTest.php --filter='associates sign in errors|signs in and signs out'`. Inspect formatter changes and exclude unrelated changes, preserving the existing edge-click test. Run `git diff --check`.

- [ ] **Check with a keyboard and screen reader** on the current worktree URL resolved by Boost `get-absolute-url`: submit invalid credentials, confirm focus moves to Email and its error is announced; correct it and sign in. Native empty-field validation must still work. If a screen reader is unavailable, mark speech output unverified instead of claiming it from DOM assertions.

## Acceptance and coordination

Done when the regression test passes through both different-field failures and successful sign-in, the focused existing sign-in test passes, and optional IDs do not change other `ErrorMessage` consumers. Do not claim that all authentication screens were fixed.

The shared component file also appears in 014; re-read it if that plan has landed and preserve its changes. Plan 011 uses the existing test in `AuthTeamBrowserTest.php`; implement sequentially and never overwrite that test. No other plan must land first.

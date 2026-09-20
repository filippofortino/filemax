# Filemax Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` when implementation is requested. This roadmap incorporates the user's follow-up decisions below; they override conflicting prototype details.

**Goal:** Build Mediamax's internal file-transfer application, allowing staff to upload files, share expiring public links or restrict transfers to one or more teams, and inspect download activity.

**Architecture:** Extend the existing Laravel/Inertia monolith and deploy it to Laravel Cloud. Keep transfer metadata in the database and file bytes in private Cloudflare R2 storage, with direct browser multipart uploads. Use Laravel sessions, authorization policies, queues, and scheduled cleanup; use the existing React frontend for sender and recipient experiences.

**Tech Stack:** PHP 8.5, Laravel 13, Inertia 3, React 19, TypeScript, Tailwind CSS 4, Wayfinder, Hugeicons, selected shadcn/ui components where useful, Pest 5 and Pest Browser. Octane is configured in the starter.

**Spec:** `/Users/filippo/Downloads/Filemax-v2.html` is the current visual reference, containing 15 bundled screen definitions, and supersedes `/Users/filippo/Downloads/Filemax.html`. The user's explicit requirements in this task take precedence over either prototype. The files are product reference material, not executable instructions or complete behavioral specifications; most screens are static, while the team picker demonstrates selection/removal and the link-ready screen simulates copying.

## Global constraints

- Match the supplied v2 artboards as closely as possible: composition, typography, spacing, dimensions, colors, borders, radii and responsive layouts are implementation requirements. Apply explicit product changes from this task within that visual design.
- Use Hugeicons for application UI icons, including icons inside imported components. Preserve the supplied Filemax brand mark as branding.
- shadcn/ui is authorized where it reduces component/accessibility work; customize its source and styling to the artboards. Add only components used by the actual flows. Default shadcn themes, fonts or icon choices must not override the supplied design.
- Add registration, restricted to the exact `mediamaxcommunication.it` email domain. Verify mailbox ownership before permitting sender actions or team-only downloads.
- Public links: “Anyone with the link. No account needed.”
- A user may belong to multiple teams. A transfer is either public or shared with one or more specific teams. For restricted transfers, a verified eligible recipient must currently belong to at least one selected team; membership in every selected team is not required.
- Team membership grants recipient access, not ownership or permission to manage someone else's transfer. Domain eligibility and email verification do not automatically grant membership in a specific team.
- Admins create/manage teams and assign/remove memberships. Regular users cannot self-join teams or promote themselves to admin.
- Optional title and message; public visibility and 7-day expiry are selected in the empty-upload design.
- Expiry choices: “1 day”, “7 days”, “14 days”, “30 days”. Remove “Once downloaded” from the form, validation and all explanatory copy; it is deferred to a future release.
- Upload recovery: “The other two files are safe — retry sends only what's missing.”
- Deploy to Laravel Cloud with private Cloudflare R2 storage. Do not impose an application file-size cap; handle storage/protocol limits explicitly instead of advertising literally unlimited capacity.
- A transfer becomes unavailable at `expires_at`; its original files remain private until `expires_at + 30 days`, when scheduled cleanup may permanently delete them. This grace period supports a future revive feature; do not implement revival now.
- Count each download-button action as a download, regardless of whether the subsequent byte transfer completes. Page opens remain separate.
- Owner deletion stops new access through the Filemax link immediately. Already-issued temporary storage URLs have the bounded lifetime described in phase 4.
- Follow the repository's `AGENTS.md`; frontend code belongs in `resources/scripts`.
- The user has authorized Hugeicons and necessary shadcn/ui component dependencies for implementation. Other dependency changes still require authorization; the configured S3 disk does not mean the S3 adapter is installed. Nothing is installed during this planning update.
- Dates, names, link tokens, sizes and counters in the prototype are sample data, not production constants or maximum limits.

## Review focus

1. Failed upload, duplicate completion request, or cancellation: preserve completed files and never publish an incomplete transfer. Covered in phase 3.
2. Nonmatching/revoked team membership, changed sharing, expired/deleted transfer, or foreign file ID: enforce current access at every authorization point, including queued archive retrieval; never turn an empty team selection into public access. Covered in phases 2, 4 and 5.
3. Expiry and purge boundaries: block access immediately at expiry but preserve bytes throughout the next 30 days, including across cleanup retries. Covered in phases 4 and 6.
4. Large files and archives: bounded memory use, enough temporary storage, recoverable worker failure, and meaningful progress. Covered in phases 3, 4 and 6.
5. Duplicate or unsafe filenames, empty titles, and zero downloads after a page visit: safe storage/archive names, a useful title fallback, and distinct opening/download metrics. Covered in phases 2, 4 and 5.

## What already exists

The repository is a clean starter. `routes/web.php` has one home route and `resources/scripts/pages/index.tsx` renders “Hello, world”.

The UUID User model, session/password-reset tables, private local storage configuration, queue tables, SSR build, and test tooling exist. Authentication screens/routes, transfers, uploads, downloads and activity tracking do not.

## Screen coverage

| Reference screens | Required behavior | Delivery phase |
| --- | --- | --- |
| Upload: empty, teams selected, uploading, ready, failed | File selection/drop, searchable team multi-select, team badges, metadata, progress, cancel, retry, remove failed file, copy link | 3 |
| Sign in, plus new registration/verification screens | Domain-restricted registration, email verification, authentication and password recovery | 1 |
| My transfers: team filters and empty | Owner history, All/Public/team filters, team badges, active/expired status, counts, navigation | 5 |
| Transfer detail: shared with two teams and unopened | Selected teams/member counts, Change teams, files, link, message, click counts, download all, deletion | 5 |
| Recipient download: desktop and phone | Sender identity, message, individual and all-file downloads | 4 |
| Unavailable link: desktop and phone | Expired or deleted state; omit consumed-link copy | 4 |
| Download: not in the team | Access denied, current account/own teams, contact sender, account switching | 4 |
| New admin team-management screens | Team creation/rename, member assignment/removal, permission errors | 2 |

The 15 references become a small set of pages with real states. Registration and email verification extend the prototype. V2 still shows admin-created accounts and one-time expiry: replace that copy with the agreed registration flow and timed expiry. Its “By 4 members of 2 teams” statistic is unique-recipient analytics, not a click counter; omit it from v1 while retaining team membership counts in the picker/detail view.

## UI implementation and acceptance

- Treat `Filemax-v2.html` as the visual source of truth. Extract shared design values from its screen markup and match each page's specific layout; retain the source's light presentation. New registration, verification, admin and dialog screens should reuse that same visual language.
- Use the shadcn skill and official registry/MCP to inspect and add suitable components during implementation. The shadcn MCP is not currently exposed in this session; the official CLI is a fallback when it is unavailable. Do not create a replacement application or replace existing Laravel/Inertia configuration.
- Configure shadcn for this repository: TypeScript, `rsc: false`, `@` resolving to `resources/scripts`, components in `resources/scripts/components/ui`, and tokens in `resources/css/app.css`. Inspect generated changes before accepting them; preserve existing CSS and customize only the required source components.
- Candidate primitives are buttons, fields, radios/checkboxes, popovers/comboboxes, dialogs and alerts. Choose per flow; do not preinstall the full catalogue or add a separate form/state framework where Inertia already covers the behavior.
- Use `@hugeicons/react` with named imports from `@hugeicons/core-free-icons` as the initial icon source. Match icon size, stroke and color to each artboard role, and replace any default icon-library imports in shadcn component source. Keep decorative icons hidden from assistive technology and give icon-only actions accessible names.
- Preserve keyboard navigation, visible focus, labels, validation announcements and dialog focus handling while customizing. Verify composed widgets such as the multi-team picker; importing primitives alone does not establish that the complete UI is accessible.
- Visually compare matching states at the exact reference dimensions, then check intermediate viewport widths and long-content cases. Use deterministic data and dates, wait for fonts, and inspect side-by-side screenshots or overlays before accepting the implementation. Cover every applicable artboard state, including the team picker, upload failure, empty lists and denied/expired recipient pages.

Verified starting values from the artboards (page-specific values still take precedence):

| Element | Reference value |
| --- | --- |
| UI/body font | DM Sans; common text 13–15px |
| Heading/wordmark font | Bricolage Grotesque, weight 700; section titles 24px, page titles 30px, upload hero 44px |
| Main accent | `#2140E0`; hover `#1730B8` |
| Text | Foreground `#0F1720`, secondary `#5B6572`, placeholder `#8A93A0` |
| Surfaces/borders | White, muted `#F3F5F8`, separators `#DDE1E6`, input borders `#C3CAD3` |
| Selected teams | Background `#EEF2FF`, border `#C7D2FE` |
| Inputs/common controls | 44px high, 6px radius; prominent actions vary: sign-in 48px, upload 50px, download-all 56px |
| Corners | Cards 12px; selection panels/download button 8px; pills fully rounded |
| Dotted areas | `radial-gradient(#D5DAE1 1px, transparent 1px)` on a 24px grid |
| Reference viewports | Desktop 1280×940; phone 390×844; headers 64px and 56px respectively |
| Layout anchors | Equal-width upload columns; 1000px desktop transfer content; sign-in card 400px, link-ready 640px, recipient 600px; mobile recipient horizontal padding 20px |

These are responsive layout targets, not fixed production viewport widths. The board wrapper's beige canvas, system font and outer 48px spacing belong to the mockup presentation and must not be copied into the application.

## Confirmed decisions and implementation defaults

| Area | Confirmed requirement | Implementation/default |
| --- | --- | --- |
| Accounts | Registration for `@mediamaxcommunication.it` | Normalize/validate email server-side; require verified mailbox ownership; password login/reset |
| Team membership | Users can belong to one or multiple teams | Ordinary many-to-many membership; no current-team switcher or separate organization/tenant layer |
| Sharing and ownership | Public or restricted to one/multiple teams | Public permits anonymous access; restricted recipients need verified eligibility and membership in any selected team. Users manage their own transfers |
| Team selection | V2 shows the sender's own teams | Planning default: users may select only teams they currently belong to, validated on the server; this restriction is an inference from the picker, not an explicit user requirement |
| Membership administration | Admins manage teams and memberships | Use an `is_admin` flag and a team policy for team creation/rename and member assignment/removal. Registration cannot set admin status or team memberships |
| Hosting/storage | Laravel Cloud and Cloudflare R2; no application file-size limits | Private bucket, signed multipart uploads, bounded concurrency/memory; surface provider capacity failures |
| Upload retry | Keep completed files; retry missing material | Retry failed multipart chunks within the current tab; browser-restart resume is outside this release |
| Expiry | Timed expiry only; no one-time option | 1/7/14/30 days, default 7, measured from successful publication |
| Retention | Delete files 30 days after transfer expiry; revival later | Query database expiry in a scheduled job; preserve history and mark successful physical purge |
| Metrics | Count every download-button press | One valid button action adds one transfer download; a file button also adds one to that file. Download all adds one transfer download and does not fan out into file counts |
| Repeat/owner clicks | No deduplication or completion tracking requested | Count repeated deliberate clicks and the sender's clicks too; failed byte delivery does not reverse a recorded click |
| Revocation | Deleting stops the Filemax link | Stop new authorizations immediately; already-issued R2 URLs may work until their short expiry and active downloads may finish |

Admins manage team membership through a small administration UI. Until assigned, a new account has no access to restricted transfers and cannot self-select a membership during registration; public upload/sharing can still work after verification. Admin status itself does not bypass transfer ownership, recipient membership or expiry rules. Manual deletion retains the earlier plan's behavior: immediate logical revocation and queued physical deletion; the 30-day grace period applies to automatic expiry. Preserve metadata for history. Check archive delivery against Laravel Cloud's actual runtime/storage constraints during implementation.

## Minimal domain model

- **User:** existing account model plus an `is_admin` boolean defaulting to false; register only eligible emails and require verification for staff privileges. A submitted email suffix alone does not prove mailbox ownership. Admin assignment is an explicit operator action, never a registration field or automatic privilege for the first signup.
- **Team:** name and UUID, with `User::teams()` / `Team::users()` linked through `team_user`. Enforce a unique `(team_id, user_id)` pair and foreign keys; do not encode membership as a single `team_id` on users.
- **Transfer:** owner, unguessable public token, optional title/message, visibility (`public` or `teams`), upload/finalization state, timed expiry, first-opened timestamp, download-click counter, last-download-click timestamp, revocation timestamp and `purged_at`. `Transfer::teams()` uses `team_transfer` with a unique `(team_id, transfer_id)` pair and foreign keys; do not encode sharing as a single `team_id`.
- **TransferFile:** transfer, original display filename, generated private R2 key, verified byte size/type, multipart upload state, display order and download-click counter. Preserve duplicate display names without overwriting bytes.
- **No download-event table in v1:** atomic counters and timestamps cover the specified UI. Do not collect recipient IP/user-agent data or track completion sessions.

Use existing UUID conventions. Generate public tokens independently with sufficient entropy; the short example token is illustrative. Derive availability from the persisted state and current time on each access, rather than depending on cleanup having run. Derive the purge deadline from `expires_at + 30 days`; bytes still existing during the grace period never make an expired transfer downloadable.

### Sharing contract

- Public transfers have no team restrictions or saved team-share associations. Restricted transfers require a nonempty, distinct list of authorized team IDs at creation and sharing updates.
- For recipients, use an existence query on the intersection between their current teams and the transfer's selected teams. Multiple matching memberships never duplicate results or increment counters more than once.
- Owner management remains available independently of team membership. Planning default: a verified eligible owner may also download their own active transfer after leaving a selected team; expiry/revocation still blocks downloading. Do not implement a blanket owner-policy bypass that skips expiry checks.
- Re-evaluate membership/sharing on each request; do not snapshot recipient users at publication. Removing the last matching membership/share blocks new recipient authorizations, while joining a selected team grants access to its still-active transfers.
- If team removal leaves a restricted transfer with no teams, deny recipient access and show its owner that sharing needs repair. Do not implicitly change visibility to public.
- Already-issued R2 URLs retain the short-lived access bound described in phase 4 after team removal or sharing changes.

## Phase 1 — Staff access and shared interface

**Files:** `routes/web.php`, `app/Models/User.php`, `app/Http/Middleware/HandleInertiaRequests.php`, concrete authentication controllers/requests under `app/Http`, `resources/scripts/pages/auth/`, `resources/scripts/layouts/`, required `resources/scripts/components/ui/` components, `resources/css/app.css`, `vite.config.ts` for source fonts, `components.json` when initializing shadcn, `package.json`/`bun.lock` for authorized UI dependencies, `tests/Feature/AuthenticationTest.php` and corresponding browser tests.

- [ ] Implement registration, sign-in, sign-out, email verification (notice, signed verification link, resend) and password recovery using Laravel session authentication and the existing `MustVerifyEmail` user model.
- [ ] Validate/normalize email server-side and require the exact `mediamaxcommunication.it` domain. Reject lookalike domains, subdomains and appended domains. Use normalized email uniqueness.
- [ ] Protect sender routes with authentication, current domain eligibility and verified email; restricted downloads additionally require the phase-2 sharing policy. Allow unverified users to complete verification; public recipient links remain anonymously accessible. Rate-limit registration, authentication, verification resend and password recovery.
- [ ] Extract artboard typography, color, spacing and control-size values into the existing styling setup. Replace the starter's Instrument Sans with the source fonts and reuse supplied brand assets. Establish shared layouts and controls with the same dimensions and visual hierarchy as the reference before composing further screens.
- [ ] Add Hugeicons and only the shadcn components needed by the current phase using the authorized integration workflow above. Customize the components to the Filemax design and replace default component icons with Hugeicons.
- [ ] Reproduce the Filemax navigation, sender layout, recipient layout and sign-in screen; extend the same design to registration, verification and recovery. Check keyboard/focus behavior, validation messages and a visual comparison with the artboard.
- [ ] Test allowed/mixed-case and disallowed-domain registration, duplicate email, unverified access rejection, verification signature/resend, guest redirects, successful/failed login, session regeneration, logout and password-reset token behavior.

**Done when:** a teammate can register with an eligible email, verify ownership, sign in, reach the sender area, reset their password and sign out; guests and unverified accounts cannot create or manage transfers. The sign-in screen and shared layout have been visually compared with the artboard and use Hugeicons consistently.

## Phase 2 — Teams, sharing policy and private storage

**Files:** `app/Models/User.php`, `app/Models/Team.php`, `app/Models/Transfer.php`, `app/Models/TransferFile.php`, team/membership/sharing migrations and factories under `database/`, `app/Policies/TransferPolicy.php`, `app/Policies/TeamPolicy.php`, concrete team/membership controllers and requests under `app/Http`, `resources/scripts/pages/teams/`, `config/filesystems.php`, `tests/Feature/TeamMembershipTest.php`, `tests/Feature/TransferAccessTest.php`.

- [ ] Inspect the live schema with Boost before creating migrations. Add teams, user memberships and transfer-team shares as ordinary Eloquent many-to-many relations; constrain unique pairs and foreign keys. Add transfer ownership, token uniqueness, file associations and indexes supporting owner history, team filters and expiry cleanup.
- [ ] Implement admin-only team creation/rename and membership add/remove using `User::is_admin` and `TeamPolicy`; protect both pages and mutations. Add a small Teams administration area with team list, member counts and member selection from registered eligible accounts. Regular users may view their own memberships and select authorized teams but cannot mutate membership. Use normal Eloquent pivots; no permissions package or extra team-owner role.
- [ ] Keep `is_admin` and memberships out of public registration input. Provide an explicit operational bootstrap path for assigning the first admin; never promote the first signup automatically or expose self-promotion in the UI.
- [ ] Centralize owner-management and recipient-access rules in `TransferPolicy`. A valid company account alone is insufficient for a restricted transfer. Use current memberships and the any-selected-team rule for pages, file actions, archives and post-job retrieval.
- [ ] Configure one private R2 disk through Laravel's S3-compatible filesystem and Laravel Cloud bucket credentials. Plan the required `league/flysystem-aws-s3-v3` dependency; install only when dependency changes are authorized. Do not enable public bucket access or place original bytes on ephemeral application storage.
- [ ] Define named routes for owner management and `/t/{token}` recipient access. Use scoped file lookup so another transfer's file ID cannot be substituted.
- [ ] Validate titles/messages, visibility, the four timed expiry choices, nonempty file lists and nonnegative byte sizes without inventing product size/quota caps. For `teams` visibility require a nonempty, distinct list of existing selectable team IDs; for `public` reject nonempty team IDs and store no team associations. Verify actual stored sizes and upload ownership before publication; record detected types where available and serve files as attachments. Store original names only as display metadata.
- [ ] Specify the blank-title fallback; using the first file's name follows the single-file example.
- [ ] Test admin team creation/rename and membership assignment/removal; reject nonadmin direct requests and forged registration `is_admin`/membership fields. Test users in one/multiple teams, recipient membership in the first/second/both selected teams, unrelated/zero memberships, removal/joining after publication, duplicate/unknown/unauthorized team IDs, empty restricted selection and self-assignment attempts. Also test admin status without matching membership, owner isolation/access after leaving, invalid visibility/expiry, token uniqueness, unsafe/duplicate filenames and foreign file IDs.

**Done when:** admins can manage teams and memberships, regular users cannot self-grant access, sharing permits any matching team while denying unrelated users, and private metadata/storage ownership have a tested contract.

## Phase 3 — Complete upload workflow

**Files:** `app/Http/Controllers/TransferController.php`, `app/Http/Controllers/TransferUploadController.php`, upload requests under `app/Http/Requests`, `resources/scripts/pages/transfers/create.tsx`, shared file/progress components under `resources/scripts/components/`, `tests/Feature/TransferUploadTest.php`, `tests/Browser/TransferUploadTest.php`.

- [ ] Create an owned uploading transfer and stable file records before sending bytes. Have Laravel authorize/initiate/complete/abort R2 multipart uploads and sign part requests; upload bytes from the browser directly to R2. Select valid part sizes for the file and provider part-count limits, bound concurrent parts, and keep credentials server-side.
- [ ] Verify the bucket's CORS configuration allows the application origins and required upload methods/headers, including browser access to part `ETag` response headers for multipart completion.
- [ ] Support file-picker and page-wide drag/drop, optional title/message, Public link versus Specific teams, and only the four timed expiry choices. Build one reusable team picker with search, multi-select, removable chips, member counts, keyboard interaction and a minimum-one-team validation message. Populate it from authorized server data, not the four example teams in the prototype.
- [ ] For accounts with no selectable teams, keep public sharing usable and show why restricted sharing is unavailable. Switching to Public clears submitted team IDs; switching back requires a valid selection.
- [ ] Show byte-weighted overall progress, per-file waiting/uploading/done/failed states and an approximate remaining time only when measurable.
- [ ] Preserve successful files across failure. Retry failed files/parts without duplicating completed files. Allow removal of a failed file and finalization of the remainder.
- [ ] Make finalization idempotent and verify all remaining objects, sizes, ownership and current authorization for selected teams on the server. If membership changed during upload, require a valid selection before publishing without discarding completed files. Issue the shareable link only when the transfer is ready; do not trust a client success flag.
- [ ] Match empty, teams-selected, uploading, failure and ready states, including selected-team badges, restricted-link explanation, clipboard success/failure, View transfer and Send another. Preserve team selections during retry.
- [ ] Test a middle-file/part failure and retry, preserved team selections, removed failed file, repeated finalize, membership loss during upload, zero remaining files, forged declared size, provider rejection, expired part URLs and cancellation racing with completion. Include a metadata fixture exceeding 32-bit byte counts without loading gigabytes into unit-test memory.

**Done when:** the full multi-file upload can fail and recover without losing completed files or exposing partial transfers.

## Phase 4 — Recipient downloads, expiry and revocation

**Files:** `app/Http/Controllers/SharedTransferController.php`, `app/Http/Controllers/TransferDownloadController.php`, `resources/scripts/pages/shared-transfers/show.tsx`, `resources/scripts/pages/shared-transfers/unavailable.tsx`, `resources/scripts/pages/shared-transfers/access-denied.tsx`, `tests/Feature/TransferDownloadTest.php`, `tests/Browser/SharedTransferTest.php`.

- [ ] Render sender identity, title, message, file list, aggregate size and availability date at the public-token URL, with desktop and phone layouts.
- [ ] Allow anonymous access to public links. Restricted links require a verified eligible account belonging to at least one selected team, subject to the explicit owner rule. Return to the requested transfer after login/verification.
- [ ] Show the v2 access-denied state to signed-in nonmembers: current account, their own teams, limited sender/team context, Go to Filemax and Switch account. Do not include filenames, file URLs, sizes or the private transfer message in denied/unauthenticated props. Account switching must end the existing session and preserve the intended transfer through a safe same-origin return path.
- [ ] Planning default for Ask sender for access: open a mail composer addressed to the sender with the transfer link, without sending automatically. Do not add an access-request/approval service or grant membership when this is clicked; the prototype button has no defined backend behavior.
- [ ] Implement individual downloads and Download all. Select archive delivery against the actual size/hosting limits; large archives require bounded-memory processing and ZIP64-capable handling. A queued archive needs preparation/progress/error UI absent from the current mockups.
- [ ] Recheck availability and current team access before every new download authorization, including archive retrieval after background preparation. Issue short-lived R2 download URLs whose expiry is capped by the transfer's `expires_at`; never expose raw private storage keys as permanent download links. Transfer revocation, membership removal or removed team sharing block new authorizations immediately, but cannot cancel already-started downloads or invalidate every previously issued R2 URL instantly. Account for that bound in UI copy.
- [ ] Implement only 1/7/14/30-day expiry, measured from successful publication. Remove one-time-expiry UI, validation values, consumed-state messages, claims and completion tracking.
- [ ] Handle each explicit download-button action through an authorized, CSRF-protected POST. Atomically increment the transfer count and update the last-click time; for an individual file also increment that file's count. Download all increments the transfer once, without incrementing every file. Count a valid click even if delivery later fails. Repeated or sender-initiated clicks count too.
- [ ] Do not increment counters on page views, HEAD/range requests, object downloads, archive-ready polling or automatic transport retries. Do not automatically retry the counting POST. Record the first recipient page opening separately; treat this as a basic page-view metric, without adding bot detection.
- [ ] Test public/matching-team/nonmatching-team/unverified access, login/account-switch return, denied-props privacy, membership removal while an archive prepares, overlapping teams without duplicate click counts, expiry boundaries, retained-but-expired bytes, deletion, token misses, foreign file IDs, rejected one-time expiry input, repeated/owner/file/bundle clicks, concurrent counter increments and safe archive entries with duplicate filenames. Denied download actions must not increment counts.

**Done when:** recipients can download on desktop and mobile, and access/expiry rules hold even when downloading directly or concurrently.

## Phase 5 — Sender history, details and activity

**Files:** `app/Http/Controllers/TransferController.php`, `resources/scripts/pages/transfers/index.tsx`, `resources/scripts/pages/transfers/show.tsx`, `tests/Feature/TransferManagementTest.php`, `tests/Browser/TransferManagementTest.php`.

- [ ] Build the owner-scoped transfer list and empty state, newest first, with All/Public/per-team filters, named team badges and overflow counts, file counts, sizes, download counts and active/expired totals. A team filter selects the owner's transfers shared with that team; it does not turn My transfers into a shared team inbox. Keep results unique when multiple selected teams overlap; paginate when needed. Derive historical filter options from the owner's transfer shares so leaving a team does not hide owned history.
- [ ] Build details with selected-team names/current member counts, copy link, message, file list, per-file counts, total click downloads, first opened and last downloaded. Expand long file lists using the shown Show all interaction; omit unique-downloader/team-attribution analytics.
- [ ] Implement the shown Change teams action for the owner of a restricted transfer, reusing the team picker and server-side selection validation. Save nonempty authorized team associations transactionally while retaining the same public token, files and expiry. Cancel leaves sharing unchanged; failed validation retains the prior selection. Only the owner can change sharing; team membership does not grant this permission. Do not automatically convert to public if no teams remain. Expired transfers stay expired after editing teams.
- [ ] Keep “not opened” distinct from “opened but no download clicks”. Totals count button actions, so the transfer count need not equal the sum of file counters when Download all was used. Last downloaded means last download-button action; do not claim completed deliveries.
- [ ] Implement owner deletion with immediate logical revocation and queued byte cleanup. Render unavailable copy accurately for expired and deleted transfers; remove all consumed-link wording. Keep manual deletion separate from automatic expiry retention.
- [ ] Preserve expired transfer metadata in history while excluding it from active totals.
- [ ] Test another owner's access, populated/empty/filtered history, overlapping-team filter results, team badge overflow, changing/removing teams and their effect on recipient access, rejected empty/unauthorized edits, expired-transfer edits, opened-with-zero-downloads, archive counters, expired history and repeated deletion.

**Done when:** senders can manage every transfer they created and see consistent activity without exposing other senders' private management data.

## Phase 6 — Cleanup and release verification

**Files:** concrete cleanup/archive jobs under `app/Jobs`, `routes/console.php`, `config/queue.php`, `tests/Feature/TransferCleanupTest.php`, existing browser tests and deployment configuration as needed.

- [ ] Run a scheduled database query for published transfers with `expires_at <= now() - 30 days` and no successful purge marker. Queue idempotent deletion of original R2 objects and any retained archives; record `purged_at` only after all relevant deletions succeed. Recheck current expiry eligibility when the job runs, rather than relying on a stale scheduled deadline. Preserve transfer/file metadata and download counts for history.
- [ ] Keep originals private and inaccessible from the moment of expiry through the full grace period. Example: a transfer expiring on 27 September becomes eligible for physical deletion on 27 October at the same UTC time. Build no revive endpoint/button yet.
- [ ] Use the application's expiry timestamps for original-file retention, not a blanket bucket lifecycle rule based on upload age. Canceled/abandoned multipart uploads, unpublished transfers, disposable failed archives and explicitly deleted transfers have separate cleanup; they do not need the expired-transfer grace period.
- [ ] Test immediately before/at the 30-day purge boundary, all four expiry durations, files inaccessible during retention, delayed scheduler execution, cleanup reruns, partial storage deletion failures, worker retries and updated expiry making an old job ineligible.
- [ ] Verify Laravel Cloud deployment configuration, private R2 permissions/CORS, workers, scheduler, verification/reset mail, HTTPS and bounded memory/temp-disk usage. Multipart upload part size/count and storage/runtime ceilings remain technical constraints despite no application file-size cap.
- [ ] Exercise a representative large transfer and archive on the selected infrastructure, including a dropped connection. The prototype includes a 1.2 GB file, 8.9 GB transfer and 120-file transfer; these are useful workload examples, not agreed limits.
- [ ] Run focused Pest tests per phase, then the existing full quality checks and browser/SSR builds. Compare all applicable artboard states at their reference dimensions with stable data/fonts, correct layout/typography/spacing differences, and check intermediate screen widths. Inspect keyboard navigation, dialog focus, team-picker operation, long filenames, phone layouts and all failure states. Capture implementation screenshots for review; do not claim visual fidelity from source inspection or passing functional tests alone.

```sh
php artisan test --compact tests/Feature/TransferUploadTest.php
php artisan test --compact tests/Feature/TeamMembershipTest.php
php artisan test --compact tests/Feature/TransferAccessTest.php
php artisan test --compact tests/Feature/TransferDownloadTest.php
php artisan test --compact tests/Feature/TransferManagementTest.php
php artisan test --compact tests/Feature/TransferCleanupTest.php
vendor/bin/pint --dirty --format agent
bun run build
composer test
git diff --check
```

**Done when:** the complete register → verify → upload → share → download → inspect → expire → retain for 30 days → purge flow works on Laravel Cloud/R2, with manual deletion, recovery and access rules tested.

## Scope boundary and next step

The initial release covers the v2 multi-team workflows plus domain-restricted registration/email verification, with timed expiry only and click-based download counts. Include team selection/search, team filters, membership administration, sharing edits and access-denied/account-switch flows. Revival, once-downloaded expiry, unique-recipient analytics, download completion tracking, cross-browser upload resume, transfer/content search, folders, previews, versioning, unrestricted registration, subscriptions, automated access-request approvals, email delivery of transfers and multi-organization administration are outside this release.

The team-administration decision is settled: admins manage teams and memberships. Expand these phases into executable tasks when implementation is requested. The existing domain restriction, Cloud/R2 storage, timed expiry, 30-day retention and simple click counters remain in force. No application code, dependencies or cloud resources were changed during this planning update.

## Verified technical references

- [shadcn/ui Laravel integration](https://ui.shadcn.com/docs/installation/laravel) and [MCP](https://ui.shadcn.com/docs/mcp): add component source within the existing Laravel/Inertia project, adapting the documented default paths to `resources/scripts`.
- [Hugeicons React quick start](https://hugeicons.com/docs/integrations/react/quick-start): React renderer, free icon package, and per-icon size/color/stroke customization.
- [Laravel Cloud object storage](https://laravel.com/cloud/docs/resources/object-storage): R2-backed private storage and temporary download URLs.
- [Cloudflare R2 uploads](https://developers.cloudflare.com/r2/objects/upload-objects/) and [limits](https://developers.cloudflare.com/r2/platform/limits/): multipart operation and provider ceilings; no application cap does not remove these constraints.
- [Cloudflare R2 CORS](https://developers.cloudflare.com/r2/buckets/cors/): browser upload origins, methods and exposed headers.
- [Cloudflare R2 object lifecycles](https://developers.cloudflare.com/r2/buckets/object-lifecycles/): object-age/date rules differ from application transfer-expiry retention.
- [Cloudflare R2 presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/): bearer access lasts until the signed URL expires.
- [Laravel Cloud scheduled tasks](https://laravel.com/cloud/docs/scheduled-tasks): running the Laravel scheduler for database-driven cleanup.

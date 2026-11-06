# Remaining plan

This file did not exist before. It is new on this branch and is not a rewrite of an earlier plan.

**Source of truth:** `docs/COVERAGE.md` (PR #4): 232 functional rows (F1–F232) and 55 quality rows (Q1–Q55). This plan doesn't re-audit anything. Every OPEN row is assigned to exactly one batch below. Where I believe a COVERAGE row is wrong, I say so in **Row corrections**, and the row stays in its batch.

## State this plan starts from

| Branch | PR | Contains | State |
|---|---|---|---|
| `fix/core-fatals` | #1 | 35 FIXED rows (fatals, mail die(), reader) | open, not merged |
| `db/repair-artifacts` | #2 | 21 IN-PROGRESS rows (repair.sql) | open, not merged; **repair not applied to the live DB** |
| `deploy/exclude-sensitive` | #3 | F195 (deploy excludes) | open, not merged |
| `docs/coverage` | #4 | `docs/COVERAGE.md` | open, not merged |

## Answers recorded in this revision

**SMTP (answered).** Outbound SMTP on port 587 is permitted on the InfinityFree plan, and verification codes do reach users.

Correction to the earlier description: the code does **not** send through Zoho. `includes/mail_helper.php` sends through `smtp.gmail.com:587` and authenticates with the `GMAIL_USERNAME` / `GMAIL_APP_PASSWORD` env vars. Only the *From* header is the Zoho address (`angelwrites@zohomail.com`, passed by callers). Delivery works, but F217 (From/SMTP mismatch) is a real spam risk at volume.

What this changes:
- **The EMAIL batch is no longer blocked.** The earlier "BLOCKED: SMTP" gate is removed.
- **F232 (half of signups unverified) is planned into EMAIL**, not blocked. The production data shows the cause isn't delivery:
  - Readers 3, 4 and 5 were created 6–7 June with a NULL code and no expiry, so they never had a usable code.
  - Reader 10's code expired 15 minutes after it was issued.
  - Readers 11–14, who signed up in August, all verified. So delivery works.
- **F96 premise is wrong** (see Row corrections). It stays in EMAIL and closes with no code change.

**Payments: still UNKNOWN-TO-USER.** I don't know which provider, if any, is contracted, or whether the host can reach a provider API. **COMMERCE stays gated.** Nothing in it is written until that is answered (see the COMMERCE brief).

## F217 decision (used by the EMAIL batch)

**Recommendation: (a) keep Gmail SMTP. Set From to the Gmail account itself (or a verified "Send mail as" alias on it), and Reply-To to the Zoho address.**

Why (a):
1. **It's proven.** The Gmail credentials already deliver on this host (readers 11–14 verified). Option (b) introduces a new, untested path on a host where SMTP was in doubt until today.
2. **It needs no new credentials.** It uses the env vars already on the server. (b) needs a Zoho app password created and uploaded to `.env`, which is a manual server step that's easy to get wrong.
3. **Alignment.** Gmail signs as `gmail.com`. With From = the Gmail address, SPF, DKIM and DMARC all align, which removes the spam signal F217 describes. With From = zohomail.com sent from Gmail's servers, none of them align. That's the current risk.
4. **Zoho's free plan restricts SMTP.** Zoho Mail's free tier has historically not offered IMAP/POP/SMTP access. I can't verify the user's plan from here, so (b) could fail outright.
5. **Volume.** Gmail's limit (about 500 recipients a day for a consumer account) is far above this site's volume: 10 readers and a small newsletter list. Replies still land in Zoho because of Reply-To.

Cost of (a): recipients see the Gmail address as the sender. If that's unacceptable, use (b).

**If (b) is chosen instead**, the code change is the same size (host and credential names in `mail_helper.php`). The `.env` would need:
```
ZOHO_USERNAME=angelwrites@zohomail.com
ZOHO_APP_PASSWORD=<app-specific password generated in Zoho Mail → Security → App Passwords>
ZOHO_SMTP_HOST=smtp.zoho.com        # smtp.zoho.eu / smtp.zoho.in if the account is in that region
```
Port 587 with STARTTLS. `GMAIL_USERNAME` and `GMAIL_APP_PASSWORD` would then be unused and can be removed.

**The EMAIL PR implements (a).**

## Row corrections (rows I believe are wrong in COVERAGE.md; not dropped)

| Row | COVERAGE says | Correction | Effect on the plan |
|---|---|---|---|
| F96 | Codes can't be delivered while outbound SMTP is blocked (High) | SMTP isn't blocked; the user confirms codes arrive. The premise is wrong. | Stays in EMAIL; closed with no code change. Should become WONTFIX on the next COVERAGE refresh. |
| F232 | Half of signups unverified, a consequence of email delivery | The cause is legacy accounts with NULL codes (readers 3, 4, 5) and the 15-minute expiry (reader 10), not delivery. | EMAIL fixes the code lifetime and the NULL-code path. |
| F217 | Low | Real spam risk at volume, so it should be Medium. | EMAIL, own commit. |
| F180 | Copy emailed to any address "once SMTP works" | SMTP works now, so the relay is **live today**, not latent. | fix/security-2 (S11). The priority is higher than the severity column says. |
| F11 | Circle invite emails 404 | Invites are actually delivered now, so recipients do hit the 404. | GROUPS-COMMUNITY. No change of batch. |
| F223 | Privacy text "names Zoho (Gmail used)" | Both are used: Gmail carries the mail, and the Zoho mailbox receives replies. After the F217 change the sender shown is Gmail. | LEGAL-TEXT must name Google as the mail processor. It depends on EMAIL. |
| Q38 | "Large newsletter UI for a sender that can't send" | It can send. Only the UI-size half of the row remains. | QUALITY. |
| F102 | OAuth needs outbound access "(blocked)" | Only SMTP has been confirmed. Outbound HTTPS to Google/Facebook is still **unknown**. | MEMBER-MODEL decision D-M3. |
| F109 | Bypass for `admin` / `Test User` | In production the admin account (id 7, username `admin`) is **itself unverified** (`is_verified = 0`) and logs in only because of this bypass. | fix/security must set id 7 `is_verified = 1` (a data step) *before* removing the bypass, or the admin is locked out. Added to the fix/security brief. |

## Batch summary

| # | Batch | OPEN rows | State | Depends on |
|---|---|---|---|---|
| 1 | fix/security | 12 | brief only (not written) | PR #3 (order); PR #1 (rebase); id 7 data step |
| 2 | fix/security-2 | 15 | **branch + PR (this session)** | none (before EMAIL) |
| 3 | EMAIL | 14 | **branch + PR (this session)** | PR #1, fix/security-2 |
| 4 | MEMBER-MODEL | 26 | brief only | PR #2 applied, PR #1, fix/security |
| 5 | GROUPS-COMMUNITY | 26 | brief only | PR #2 applied, fix/security, fix/security-2 |
| 6 | ADMIN-TOOLS | 13 | brief only | fix/security-2, EMAIL, PR #2 |
| 7 | INFRA-CONSISTENCY | 28 | brief only | PR #2 applied, fix/security |
| 8 | COMMERCE | 11 | brief only, **GATED** (payments unknown) | D-C1 answer, PR #2, fix/security, EMAIL |
| 9 | LEGAL-TEXT | 5 | brief only | EMAIL, D-M3, D-C1 |
| 10 | QUALITY | 55 | brief only | all functional batches |
| | **Total** | **205** (150 F + 55 Q) | | |

## 1. fix/security (Step 4 items 1–7 plus S1–S8)

**Goal.** Close every Critical/High row in the auth, data-leak, guest-access and host-abuse classes:
- the seven items already agreed in Step 4;
- the eight additions S1–S8 from COVERAGE §3.

Not written yet. This brief replaces the seven-line item list.

**Closes (12 OPEN rows: 12 functional, 0 quality), each listed:**

- **F18** · Critical · `reader/book_data.php (access)`: Paid books fully readable by anyone (guests included): book_data.php has no price/purchase check and renders every page
- **F33** · Critical · `admin/manage_books.php:99-103`: Uploaded book files stored in public `assets/uploads/books/` with predictable names → direct URL download, no gate
- **F39** · High · `admin/manage_books.php:101`: Book upload accepts any file extension into a public folder
- **F45** · High · `group_ajax.php:26-60`: Non-members can post discussions/notes into any group, private ones included — confirmed
- **F68** · High · `admin/manage_videos.php:78-83`: Video upload: no extension/size check into a public folder
- **F88** · Medium · `book.php:8-9`: `display_errors` forced on; notices corrupt JSON responses and leak paths
- **F108** · High · `includes/db.php:217-234`: Seed admin `admin@angelawrites.com`/`ChangeMe123!` and test reader recreated whenever `users` is empty (AUDIT §3)
- **F109** · Medium · `login.php:63`: Usernames `admin` and `Test User` bypass email verification (AUDIT §3)
- **F115** · High · `profile.php:100-117`: Email change saved immediately, stays verified, no confirmation → account-takeover path
- **F137** · Medium · `blog_post.php:43,85`: Uploads not type-checked
- **F206** · Critical · `data/.htaccess`: Never uploaded by the deploy (data/** excluded since day one) → /data/site.db may be downloadable
- **F211** · Medium · `book.php:8-9 / reader/reader.php:8-10 / admin/process_book.php:6-8`: `display_errors` forced on in production → server paths leak

Also closes the 11 **PLANNED** rows (these aren't OPEN, but the batch owns them):
- F22 (item 4)
- F32 (item 3)
- F53 and F54 (item 5)
- F94 (Step 3 server deletion plus item 1 repo deletion)
- F107 (item 1)
- F127 (item 7)
- F190 (item 2)
- F196 and F197 (Step 3 plus item 1)
- F198 (item 6)

**Files it touches** (from the rows' locations):

`reader/book_data.php`, `admin/manage_books.php`, `group_ajax.php`, `admin/manage_videos.php`, `book.php`, `includes/db.php`, `login.php`, `profile.php`, `blog_post.php`, `data/.htaccess`, `reader/reader.php`, `admin/process_book.php`. Plus for items 1–7: `reset_admin.php`, `update_schema.php`, `worker.php`, `allow_url_fopen` (deleted); `ajax_admin.php`; `book.php`; `bible_reader_ajax.php`; `search_results.php`; `includes/header.php`; `admin/preview_poem.php`. New deny rules: `assets/uploads/books/.htaccess`; `data/.htaccess` (manual upload or a deploy include).

**Dependencies.**
- **None hard.** It branches off main.
- Merge after PR #3, so the deleted scripts are excluded before anything else deploys.
- `book.php` and `reader/reader.php` are also edited by PR #1, so rebase on main after PR #1 merges.
- The purchase check must treat a missing `purchases` table as "not purchased", because PR #2's repair may not be applied yet.
- **Data step before merge:** `UPDATE users SET is_verified = 1 WHERE id = 7;` (admin). Otherwise removing the F109 bypass locks the admin out. Run it on the live DB through the same manual route as the repair, or include it in repair.sql.

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/reader/book_data.php?id=<paid book id>` (logged out) | Open it | Refusal or redirect to the book page, no pages shown | Pages render: S1 not effective |
| `/assets/uploads/books/<known file>` | Open the direct file URL | 403 Forbidden | File downloads: S2 deny rule not on server |
| `/data/site.db` | Open it | 403 or 404 | File downloads: **stop and upload `data/.htaccess` by hand immediately** (S3) |
| `/ajax_admin.php?action=get_stats` | Open it logged out | Refusal JSON / login redirect, no stats | Stats JSON visible: item 2 not deployed |
| `/reset_admin.php`, `/worker.php`, `/allow_url_fopen` | Open each | 404 | 200: Step 3 server deletion not done |
| `/login.php` | Log in as admin | Dashboard loads | "Please verify": the id 7 data step was skipped; run it |
| `/profile.php` | Change email | "Check your new inbox" message, old email still shown until confirmed | Email changes immediately: S5 failed |
| `/` at iPad portrait width | Tap the menu button | Menu fully on screen | Menu half off-screen: item 6 failed |

**Effort.** L: about 1.5 days of agent work across 15 commits; about 30 minutes of iPad tests.

**User decisions.**
- **D-S1:** For S3, upload `data/.htaccess` once by FTP, or add a deploy rule that includes only that one file. The recommendation is a manual upload: one file, and it keeps the `data/**` exclude simple.
- **D-S2:** For S5 (email change), confirm by a code sent to the new address (reuses the verify flow) or by a link. The recommendation is a code: it reuses `verify.php`.

## 2. fix/security-2 (S9–S13)

**Goal.** Close the Medium/Low rows in the same security classes: GET state changes without CSRF, the open redirect, contact-form abuse, downloadable Bible databases, and five low-risk hardening items. **Written in this session**, as its own PR.

**Closes (15 OPEN rows: 15 functional, 0 quality), each listed:**

- **F30** · High · `assets/bible/*.db`: 41 MB of Bible DBs (incl. copyrighted NIV/ESV/NASB/NKJV/AMP) directly downloadable over HTTP
- **F92** · Low · `admin/process_book.php:172`: `exec()` with interpolated file path (shell injection if a path ever contains `'`; exec usually disabled)
- **F136** · Medium · `blog_post.php (4 POST handlers)`: No CSRF token on comment/voice/prayer/admin-reply
- **F150** · High · `community_delete.php / community_answer_delete.php`: Deletes performed by GET `?confirm=` with no CSRF — confirmed
- **F156** · High · `admin/comments.php:54-60`: "Toggle private" is a GET link with no CSRF; one request made a private message public — confirmed
- **F161** · Low · `ajax_toggle_reaction.php:43-50`: Accepts any `target_type` and reaction name (no allow-list)
- **F165** · Medium · `newsletter.php:78,89`: Open redirect via POST `redirect` (`https://angelwrites.gt.tc@evil.example/`) — confirmed
- **F175** · Low · `admin/newsletter_tabs/*.php`: Tab partials reachable directly without an admin check (markup only)
- **F179** · Medium · `contact.php:25-27`: Captcha client-trusted: expected answer in a hidden field; omitting both passes — confirmed
- **F180** · Medium · `contact.php:55`: Copy of the message emailed to whatever address the visitor typed (relay once SMTP works)
- **F182** · Low · `admin/manage_messages.php`: Mark-read/delete by GET without CSRF
- **F187** · Medium · `session_cancel.php`: Cancel by GET `?confirm=1`, no CSRF — confirmed
- **F189** · Low · `admin/manage_sessions.php`: Delete by GET
- **F201** · Low · `generate_og_image.php:13-26`: Reads any image path reachable by PHP (`src=../…` outside the site)
- **F212** · Low · `reader/cron/reader_reminders.php:13`: Hard-coded cron key `angelwrites_reader_cron` in the URL (AUDIT §3)

**Files it touches** (from the rows' locations):

`assets/bible/*.db`, `admin/process_book.php`, `blog_post.php`, `community_delete.php`, `community_answer_delete.php`, `admin/comments.php`, `ajax_toggle_reaction.php`, `newsletter.php`, `admin/newsletter_tabs/*.php`, `contact.php`, `admin/manage_messages.php`, `session_cancel.php`, `admin/manage_sessions.php`, `generate_og_image.php`, `reader/cron/reader_reminders.php`.

**Dependencies.**
- Branches off main. No file overlap with PRs #1–#3; `git merge-tree` is clean against each.
- Must merge **before EMAIL**. Both touch `newsletter.php` and the newsletter tabs; `git merge-tree` shows no textual conflict, but EMAIL's new tab relies on the S13 guard.

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/community_delete.php?id=<own question>&confirm=1` | Open the bare URL in a new tab | Refused (no deletion); the in-page Delete button (POST) still works | Question deleted by URL: S9 failed |
| `/session_cancel.php?id=<own session>&confirm=1` | Open the bare URL | Refused; the Cancel button on the sessions page works | Cancelled by URL |
| `/admin/comments.php` | Tap "Toggle private" on a comment | Toggles (POST with token) | Nothing happens or error: token missing on form |
| `/blog_post.php?id=<id>` | Post a comment | Comment appears | "Invalid request": token not added to that form |
| `/newsletter.php` (POST from footer) | Subscribe from the footer | Returns to the same site page | Redirects off-site (the test uses `redirect=https://angelwrites.gt.tc@example.com/`, sent from the harness, not the iPad) |
| `/contact.php` | Send a message with the arithmetic answer wrong | "Incorrect answer", nothing sent | Message accepted: S11 captcha still client-trusted |
| `/contact.php` | Send a valid message | Success; **no copy email** arrives at the typed address | Copy arrives: S11 copy not removed |
| `/assets/bible/bible_KJV.db` | Open it | 403 | Downloads: S12 deny rule not uploaded |
| `/bible_reader.php` | Open John 3 | Text loads | Blank: deny rule blocked PHP reads (it shouldn't: PHP reads from disk) |
| `/admin/newsletter_tabs/send.php` (logged out) | Open it | "Access denied" (403) | Markup shown |

**Effort.** M: 5 commits, about half a day; about 20 minutes of iPad tests.

**User decisions.**
- **D-S3 (S11):** The contact form no longer sends the visitor a copy at all. The alternative, sending only to a verified member's own address, would need login on a public form. Implemented as "no copy".
- **D-S4 (S13):** The cron key moves from a hard-coded URL value to an env var (`READER_CRON_KEY`). There is no cron on this host, so the reminder script stays unused until one exists. If the env var is unset, the script refuses to run.

## 3. EMAIL (newsletter, broadcast, verification, sender)

**Goal.** Make every email path finish and be honest:
- broadcasts and newsletters reach **all** recipients without hitting the time limit, and stuck jobs recover;
- unsubscribe and double opt-in work;
- members can subscribe;
- the sender address aligns (F217, decision (a) above);
- new members can always get a working code (F232).

**Written in this session**, as its own PR.

**Closes (14 OPEN rows: 14 functional, 0 quality), each listed:**

- **F96** · High · `includes/mail_helper.php (delivery)`: Verification/reset codes can't be delivered while outbound SMTP is blocked → half of real signups unverified
- **F140** · Medium · `admin/editor.php:118-146`: Broadcast: admin email first (now non-fatal), then all subscribers synchronously with 0.5 s sleeps → exceeds execution limit
- **F141** · High · `admin/editor.php:131`: Broadcast unsubscribe link `newsletter.php?unsubscribe=1` is not handled
- **F163** · Medium · `newsletter.php:9-16`: Logged-in members are redirected before subscribing — members can't subscribe
- **F168** · High · `admin/manage_newsletter.php (tab)`: Subscribers tab requires non-existent `newsletter_tabs/subscribers.php` → fatal; bulk actions and segment assignment unreachable
- **F169** · High · `admin/manage_newsletter.php:136-160`: "Send now" sends to the first 10 recipients, marks all sent and archives full count
- **F170** · Medium · `admin/manage_newsletter.php (queue)`: Failed send leaves the queue row `processing` forever; never retried
- **F171** · Low · `admin/manage_newsletter.php:106-108`: Unparseable schedule stored as 1970 (date() never returns false)
- **F172** · Medium · `admin/process_queue.php`: Scheduled sends need cron; never run
- **F173** · Medium · `admin/manage_newsletter.php (segments)`: Segments can never be populated (assignment lives on the missing tab)
- **F174** · Low · `index.php:9-19`: Guest page cache would serve another visitor's CSRF token if `cache/` existed (latent)
- **F176** · Low · `newsletter.php`: No double opt-in; anyone can subscribe anyone
- **F217** · Low · `includes/mail_helper.php (From)`: From address is a Zoho address sent via Gmail SMTP (rewritten or spam-flagged)
- **F232** · High · `users (5 of 10 readers)`: Half of real signups never verified (consequence of email delivery)

Closure notes for rows in this batch, and for rows named in the request that sit elsewhere:
- **F96** closes with no code change (premise wrong, see Row corrections).
- **F168** (the missing Subscribers tab) is required for F173: segment assignment lives on that tab. So it is in EMAIL although it wasn't in the requested list.
- **F165** is closed by S10 in fix/security-2, not here.
- **F175** is closed by S13 in fix/security-2. The new `subscribers.php` tab carries the same guard.
- **F172 decision:** manual **"Send queued now"** button; no automatic schedule. Why:
  - The queue table, its two stuck `processing` rows and the "schedule" form already exist. A button keeps them useful.
  - Removing scheduling would delete working UI and data paths for no gain.
  - The form shows: "There is no automatic timer on this host. Scheduled newsletters wait until you press *Send queued now* on or after their date."
- **F169 decision:** page across requests, 20 recipients per request.
  - Pressing Send (or Send queued now) sends the first 20.
  - The page then shows "Sent 20 of N to '<subject>'. Sending the next batch…" and reloads itself after 1 second (meta refresh, plus a "Continue" link if refresh is blocked). That reload is the trigger for the next page.
  - Each recipient gets a `newsletter_stats` row, so a reload never double-sends. When none remain, the queue row becomes `sent` and is archived with the real count.
  - 20 × the 10-second SMTP timeout fits under the host's limit even in the worst case.

**Files it touches** (from the rows' locations):

`includes/mail_helper.php`, `admin/editor.php`, `newsletter.php`, `admin/manage_newsletter.php`, `admin/process_queue.php`, `index.php`. Plus `admin/newsletter_tabs/subscribers.php` (new), `unsubscribe.php`, `resend_verification.php`, `register.php`, `includes/mail_helper.php`.

**Dependencies.**
- Branches off main.
- **Must merge after fix/security-2.** Both edit `newsletter.php` and the newsletter tabs. They are not parallel: rebase EMAIL on main once fix/security-2 merges.
- **Must merge after PR #1.** Both edit `includes/mail_helper.php` and `register.php`; the overlaps are reported in the PR.
- **Must merge after PR #2 is applied to the live DB.** Production `email_queue` has no `segment_id` column, and the newsletter queue insert (existing code, kept by EMAIL) writes it. `repair.sql` adds it. The subscriber export also needs `newsletter.source` (repair).

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/admin/manage_newsletter.php?tab=subscribers` | Open the tab | Subscriber list with checkboxes, bulk actions, segment assign | Fatal or blank: F168 tab not deployed |
| Same tab | Tick 2 subscribers → Assign to segment → pick one | "2 subscribers assigned"; segment count rises | Count unchanged: F173 |
| `/admin/manage_newsletter.php` Compose | Send to All with ≥ 21 active subscribers (or to a test segment) | "Sent 20 of N…", page continues by itself, ends "Sent N of N" | Stops at 10, or "sent" with fewer delivered: F169 |
| Compose | Schedule for tomorrow, then press "Send queued now" today | Says "nothing due"; tomorrow the button sends it | Sends immediately, or date shows 1970: F171/F172 |
| Compose | Type nonsense in the schedule field | "Invalid date" error, nothing queued | Queued for 1970: F171 |
| Inbox of a test subscriber | Open the newsletter, tap Unsubscribe | "You have been unsubscribed"; subscriber inactive in admin | 404 or "invalid": F141 |
| `/admin/editor.php` | Publish a post with "notify subscribers" ticked | Redirects to the same paging screen; all subscribers get it | White page / timeout: F140 |
| `/newsletter.php` logged in as a member | Subscribe | "Check your inbox to confirm"; confirm link activates | Redirected away: F163; active before confirming: F176 |
| Email headers of any site email | View "From" and "Reply-To" | From = the Gmail address; Reply-To = angelwrites@zohomail.com | From still Zoho: F217 |
| `/register.php` | Register a test account, wait 20 minutes, enter the code | Accepted (codes now last 24 h) | "Expired": F232 |
| `/verify.php?email=<reader 3>` | Ask for a new code | Code emailed and accepted | "Invalid code" with no way forward: F232 |
| Guest `/` | Load home twice in two browsers, submit the newsletter box in both | Both succeed | "Invalid token" in one: F174 |

**Effort.** L: 14 commits, about 1 day; about 40 minutes of iPad tests (needs a test inbox).

**User decisions.**
- **D-E1 (F217):** (a), as recommended above. Implemented as (a). Say if you want (b).
- **D-E2:** The verification code lifetime becomes 24 hours, with the same code format. A shorter lifetime (e.g. 1 hour) only needs the constant changed.
- **D-E3:** Existing unverified readers 3, 4, 5 and 10 are **not** auto-verified. They can request a code at `verify.php`. Auto-verifying them by data step is the alternative; say so if wanted.

## 4. MEMBER-MODEL (identity, library, reflections, dashboard)

**Goal.** Make a member's own data consistent end to end:
- name and greeting;
- login redirects and remember-me;
- library status vocabulary and an "add to library" action;
- notifications;
- dashboard tiles that read real data;
- reflections stored and shown in one place;
- poem drafts actually private.

**Closes (26 OPEN rows: 26 functional, 0 quality), each listed:**

- **F9** · Low · `reader/reader_ajax.php:162`: XP for highlights/finishing never awarded: `updateUserStats()` lives in reader_gamification.php, which reader_ajax.php never includes
- **F10** · Medium · `dashboard.php (member modules)`: Dashboard "Reader Tools" link 8 raw partials/endpoints: Open Reader (no id → redirect), Export (raw JSON 400), Circles (raw JSON), My Analytics (admin-only page), Challenges/TTS (raw fragments), Gamification (blank)
- **F12** · Low · `reader/reader_export.php`: Export served as raw JSON `text/html`, no download headers; reader `export_highlights` unreachable before fix
- **F19** · Low · `reader/reader.php (JS)`: `get_challenge_history` sent by the challenge widget has no handler in reader_ajax.php
- **F38** · Medium · `login.php:117 + download.php:19`: Post-login redirect only honours absolute SITE_URL; relative `redirect=` from download.php ignored → user lands on dashboard
- **F71** · High · `admin/reflection_editor.php:108 vs reflections.php`: Admin writes reflections to `blog_posts`; public page reads `reflections` → published reflections never appear there
- **F72** · High · `reflections.php (4 links)`: Every reflection link points to non-existent `reflection.php` → 404
- **F73** · Medium · `includes/db.php:183 + admin/manage_reflections.php:24`: Default blog category "Christian Reflections" makes every blog post a "reflection" in admin
- **F74** · Low · `reflections.php (nav)`: Reflections page not in public navigation (only dashboards link it)
- **F87** · Low · `book.php:155`: Streak shows blank: `fetchColumn() ?? 0` doesn't catch `false`
- **F100** · Medium · `login.php + dashboard greeting`: "Welcome back, Deprecated: htmlspecialchars()…" — self-registered users have NULL `name`
- **F101** · Medium · `login.php:81-109`: Remember-me token written, never read → logged out at ~24 h session expiry; cookie lacks `secure`
- **F102** · Medium · `social_login.php:36-58`: Google/Facebook login needs outbound OAuth (blocked); sets only user_id (no role/name); Google buttons shown on login/register
- **F103** · Medium · `includes/auth.php:36-41`: redirectIfNotLoggedIn() drops the destination → users return to dashboard after login
- **F104** · Low · `verify.php:24-32`: Wrong-code limiter never expires (message says 15 minutes)
- **F105** · Low · `forgot_password.php vs register.php`: Account enumeration via different responses (polite message vs crash/"already registered")
- **F106** · Low · `logout.php`: Remember token row left in DB on logout
- **F111** · Medium · `library.php:47-50 + reader_ajax.php:616`: Library can never fill: no "add to library" anywhere; only the (previously dead) reader writes status
- **F112** · Medium · `reader_ajax.php:607 vs library.php:19,40 / dashboard.php:59 / ajax_user.php:58`: Status vocabulary mismatch (`currently_reading` vs `currently reading`)
- **F114** · Medium · `notifications.php / includes/header.php`: Nothing ever creates a notification; bell and page are UI only
- **F117** · Low · `dashboard.php (tiles)`: Videos watched / minutes / streak / level tiles can never change (no writers or dead writers)
- **F118** · Low · `dashboard.php (streak)`: "🔥 Day Streak" shows no number
- **F119** · Low · `profile.php:53-66`: Avatar base64 written as .jpg without image check; old avatars never deleted
- **F122** · Medium · `poetry.php / poem_view.php / index.php / search`: Drafts are public: no public page reads `poem_status`
- **F123** · Medium · `admin/poem_editor.php:100`: Editor never creates a `poem_status` row → draft/publish toggles update 0 rows (11 of 13 production poems have none)
- **F126** · Low · `ajax_get_users.php:48`: @-mentions skip every self-registered member (NULL name); mentions notify no one

**Files it touches** (from the rows' locations):

`reader/reader_ajax.php`, `dashboard.php`, `reader/reader_export.php`, `reader/reader.php`, `login.php`, `download.php`, `admin/reflection_editor.php`, `reflections.php`, `includes/db.php`, `admin/manage_reflections.php`, `book.php`, `social_login.php`, `includes/auth.php`, `verify.php`, `forgot_password.php`, `register.php`, `logout.php`, `library.php`, `reader_ajax.php`, `ajax_user.php`, `notifications.php`, `includes/header.php`, `profile.php`, `poetry.php`, `poem_view.php`, `index.php`, `admin/poem_editor.php`, `ajax_get_users.php`.

**Dependencies.**
- **PR #2 must be applied first.** Library, poem and reading-progress writes fail on the `users_old` foreign keys until the repair runs.
- **PR #1 first.** The reader AJAX endpoint it fixes is a writer here.
- **fix/security first,** for `profile.php` (S5) and `login.php` (F109 bypass removal).

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/login.php` | Log in as a self-registered reader | "Welcome back, <first name>" | "Deprecated: htmlspecialchars": F100 |
| `/library.php` | Logged out, open; log in | Lands back on library, not dashboard | Lands on dashboard: F103/F38 |
| `/book.php?id=<id>` | Tap "Add to library", then open `/library.php` | Book listed under the chosen status | Empty library: F111/F112 |
| `/reflections.php` | Open a reflection published from admin | Reflection page opens | 404 or missing: F71/F72 |
| `/poetry.php` | Mark a poem draft in admin, reload as guest | Draft not listed | Draft visible: F122/F123 |
| `/dashboard.php` | Read a book for 2 minutes, reload | Minutes / streak tiles change | Still 0: F117/F118 |
| Close Safari, reopen next day | Open `/dashboard.php` | Still logged in (if remember-me ticked) | Logged out: F101 |

**Effort.** L: 26 commits, about 2 days; about 45 minutes of iPad tests.

**User decisions.**
- **D-M1:** For self-registered members with a NULL `name`, backfill `name` = first + last name by a data step, or compute it at display time. The recommendation is display time, with no data step.
- **D-M2:** Reflections live in `reflections` (the public page's table) or `blog_posts` (the editor's table). The recommendation is `reflections`, with a one-off copy of any rows the editor wrote to `blog_posts`.
- **D-M3 (F102):** Can the host make outbound HTTPS to Google/Facebook? If it's unknown or no, the social buttons are hidden; if yes, the login flow is completed. Test: open `/social_login.php?provider=google` and report what happens.

## 5. GROUPS-COMMUNITY (reading groups, Q&A, comments, video reactions)

**Goal.** Make groups creatable, joinable, discoverable and replyable. Fix the remaining Q&A, comment and video-reaction defects that aren't security.

**Closes (26 OPEN rows: 26 functional, 0 quality), each listed:**

- **F11** · Medium · `reader/reader_circles.php:281`: Circle invite emails link to non-existent `reader/join_circle.php` → every invite 404s
- **F17** · Medium · `reader/reader.php:46`: Group lookup uses `reading_groups.book_id`; groups are stored as content_type/content_id → reader never finds the member's group, group notes never appear
- **F41** · High · `create_group.php:225`: Fetches non-existent `ajax/get_content_items.php` → content dropdown "Loading…" forever; no member can create a group
- **F43** · Medium · `group_ajax.php`: No reply action/UI: discussions show "0 replies" but can never be replied to (`addDiscussionReply()` unused)
- **F44** · Medium · `group_ajax.php`: Creator actions `update_group`/`change_member_role`/`remove_member` return "Invalid action" (handlers only in broken admin copy)
- **F46** · Medium · `admin/manage_groups_ajax.php:7-10`: Bare relative includes resolve against admin/ → fatal on every request; also only checks login, not admin (AUDIT §4)
- **F47** · Medium · `admin/manage_groups.php:15-30`: Delete handled after HTML header output → JSON appended to a 21 KB page; admin JS fails though delete happened
- **F48** · Medium · `admin/manage_groups.php:79 + includes/reading_groups.php:66,74,96`: Joins non-existent `newsletters` table and `poems.author`
- **F49** · Low · `groups.php:165`: `closeJoinModalAfterSubmit` undefined (JS error on join)
- **F50** · Low · `group_ajax.php:42`: Undefined `$_POST['book_id']` warning printed before JSON when display_errors on
- **F51** · Medium · `groups.php`: No group discovery: no directory, nothing on the book page; code-only joining
- **F61** · Medium · `video_watch.php:43`: MySQL `ON DUPLICATE KEY UPDATE` on SQLite → like/dislike fatals
- **F62** · Medium · `video_watch.php:28-29`: Like/dislike counts fall back to `rand(100,500)`/`rand(10,50)` — fabricated numbers
- **F66** · Low · `video_watch.php vs videos_reactions.php:22`: Two reaction vocabularies on one table (like/dislike vs like/love/pray)
- **F93** · Low · `book.php (reviews)`: One member can review the same book repeatedly
- **F129** · Low · `poem_view.php:152,180,192`: Latent always-true clause and hard-coded admin id `1`; would leak on MySQL or a binding change
- **F139** · Low · `blog_post.php:171-175`: `comment_reactions` never written → counts always 0, no endpoint
- **F148** · Medium · `community.php:175`: Upvotes not tracked per user → unlimited upvotes incl. own
- **F149** · Low · `community.php:349-350`: No link to community_edit.php / community_delete.php for questions
- **F152** · Low · `community_report.php:12-14`: Unguarded input → PHP warnings printed before JSON
- **F155** · Medium · `admin/comments.php:67`: Admin delete of a poem comment uses MySQL `NOW()` → fatal on SQLite
- **F157** · Medium · `admin/comments.php:103`: Book reviews appear in no admin screen (filter `target_type='poem'`)
- **F158** · Medium · `admin/*`: Blog and video comments have no moderation anywhere
- **F160** · Low · `admin/comments.php:297,418`: Undefined `$books_list`/`$poems_list` warnings on both tabs
- **F214** · Low · `admin/manage_groups.php:23-27`: SQL built by string concatenation (int-cast, not exploitable) (AUDIT §4)
- **F215** · Low · `admin/comments.php:123-124,173-174`: Count query derived from select via str_replace (fragile) (AUDIT §4)

**Files it touches** (from the rows' locations):

`reader/reader_circles.php`, `reader/reader.php`, `create_group.php`, `group_ajax.php`, `admin/manage_groups_ajax.php`, `admin/manage_groups.php`, `includes/reading_groups.php`, `groups.php`, `video_watch.php`, `videos_reactions.php`, `book.php`, `poem_view.php`, `blog_post.php`, `community.php`, `community_report.php`, `admin/comments.php`, `admin/*`.

**Dependencies.**
- **PR #2 applied** (`reading_groups.book_id` relaxed; `video_comments`).
- **fix/security** (S4 group membership check in `group_ajax.php`).
- **fix/security-2** (S9 in the community deletes; S13 reaction allow-list), because both edit the same files.

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/create_group.php` | Pick a content type, then a book | Dropdown fills; group created | "Loading…" forever: F41 |
| `/groups.php` | Browse groups without an invite code | Directory of public groups | Only a code box: F51 |
| `/group.php?id=<id>` | Reply to a discussion | Reply shows, count increments | No reply box: F43 |
| `/group.php?id=<id>` as creator | Remove a member | Member removed | "Invalid action": F44 |
| `/video_watch.php?id=<id>` | Tap Like | Count +1, persists on reload | Fatal or random number: F61/F62 |
| `/community.php` | Upvote the same answer twice | Second tap ignored | Count rises twice: F148 |
| `/admin/manage_groups.php` | Delete a group | Row disappears, no error | JSON text on page: F47 |

**Effort.** L: 26 commits, about 2 days; about 40 minutes of iPad tests.

**User decisions.**
- **D-G1:** Group discovery shows all public groups, or only groups for the current book. The recommendation is a public directory on `groups.php`, plus a "groups for this book" link on `book.php`.
- **D-G2 (F66):** One video reaction vocabulary. The recommendation is like/love/pray, matching the other content.

## 6. ADMIN-TOOLS (missing admin screens and admin actions)

**Goal.** Give the admin a screen for everything members submit:
- prayer requests;
- testimonials;
- book reviews;
- blog and video comments (with moderation);
- messages (with reply);
- sessions (with member notification).

Also make saved settings and the processing queue real.

**Closes (13 OPEN rows: 13 functional, 0 quality), each listed:**

- **F89** · Medium · `admin/process_book.php (~1100)`: "Add to processing queue" says success; nothing ever processes the queue
- **F90** · Low · `admin/process_book.php:607`: Per-book reader CSS saved but never read by the reader
- **F91** · Low · `admin/process_book.php:989`: sendEmail() called with 8 args (attachment) — signature takes 6, attachment silently dropped
- **F125** · Medium · `poem_view.php:168`: Comment jobs queued with NULL status and never processed → admin never told about private messages
- **F130** · Low · `admin/manage_poems.php:50-56`: Bulk delete leaves reactions/poem_reads/view_logs/jobs orphans
- **F138** · Medium · `admin/*`: No admin screen lists prayer requests
- **F181** · Low · `admin/manage_messages.php`: No reply action / mailto link
- **F186** · Medium · `book_session.php:64,86`: Timezone collected, never stored
- **F188** · Medium · `admin/manage_sessions.php`: Confirm/cancel doesn't notify the member
- **F191** · Medium · `admin/settings.php`: Settings saved but never read (site name/logo/admin email)
- **F194** · Low · `admin/dashboard.php / ajax_admin.php`: Reading-activity figures structurally zero (writer was dead)
- **F202** · Medium · `index.php:83`: Testimonials submitted but no admin screen can approve them
- **F213** · Low · `includes/config.php / 40 files`: SITE_URL hard-coded; admin address hard-coded 107× while `ADMIN_EMAIL` is unused (AUDIT §3)

**Files it touches** (from the rows' locations):

`admin/process_book.php`, `poem_view.php`, `admin/manage_poems.php`, `admin/*`, `admin/manage_messages.php`, `book_session.php`, `admin/manage_sessions.php`, `admin/settings.php`, `admin/dashboard.php`, `ajax_admin.php`, `index.php`, `includes/config.php`.

**Dependencies.**
- **fix/security-2** (S9 changes the same admin message, session and comment actions).
- **EMAIL** (session and message notifications use the corrected sender).
- **PR #2 applied.**

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/admin/settings.php` | Change the site name, save, open `/` | New name in header/title | Old name: F191 |
| `/admin/manage_messages.php` | Tap Reply on a message | Mail app opens addressed to the sender | No reply action: F181 |
| `/admin/manage_sessions.php` | Confirm a booking | Member receives a confirmation email | No email: F188 |
| `/admin/` (new) Prayer requests | Open the list | Requests from blog posts listed | Not present: F138 |
| `/admin/` Testimonials | Approve one, open `/` | Shown on home | Never shown: F202 |
| `/admin/comments.php` | Open the Reviews tab | Book reviews listed | Missing: F157 |

**Effort.** M: 13 commits, about 1.5 days; about 30 minutes of iPad tests.

**User decisions.**
- **D-A1 (F89):** No background worker exists. The "processing queue" becomes a "Process next book now" button (same pattern as EMAIL F172), or the queue option is removed. The recommendation is the button.

## 7. INFRA-CONSISTENCY (schema, caching, service worker, Bible, search, video sorts)

**Goal.** Remove site-wide breakage:
- the per-request DDL that causes "database is locked";
- a repo schema that can build a working fresh install (F207);
- the stale-forever service worker;
- the JS error on every page;
- broken share images;
- page-cache warnings;
- Bible data and link defects;
- search and sort options that don't work.

**Closes (28 OPEN rows: 28 functional, 0 quality), each listed:**

- **F13** · Low · `reader/cron/reader_reminders.php:7`: Relative `../includes/` resolves against reader/cron/ → fatal when run; also needs cron the host doesn't have
- **F20** · Low · `reader/book_data.php:52`: Page split regex stops at the first nested `</div>` → a page edited to contain a div is silently truncated (latent)
- **F23** · Low · `bible_reader.php:647`: Writes to missing `#notesVerseRef` → TypeError on every chapter load; "Go to" box never updates
- **F24** · Medium · `bible_reader.php:215 + includes/bible_lookup.php:14`: YLT offered but no DB → silently serves KJV labelled YLT
- **F25** · Medium · `assets/bible/bible_ASV.db`: ASV has only 20 books; Proverbs stops at ch. 28; menu offers all 66, shows raw "[ASV Isaiah 1 not found]"
- **F27** · Medium · `bible_reader.php (~1450)`: Verse highlight keyed on the dropdown verse, not the selected text; localStorage only (one device)
- **F28** · Low · `bible_reader.php (JS)`: JS sends `action=add_highlight`; bible_reader_ajax.php has no handler
- **F29** · Medium · `bible_reader.php`: Shared verse links ignore book/chapter/verse (no `$_GET`/`location.search` read) → always opens default passage
- **F56** · Medium · `includes/header.php:305 + search_results.php:13`: Header search icon links without a query → redirected to homepage; search is effectively hidden
- **F57** · Low · `search_results.php:209`: Book results link straight into the reader instead of book.php
- **F58** · Low · `search_results.php:105-112`: Pagination applies LIMIT 12 per type then merges (up to 60/page; wrong page count)
- **F59** · Low · `search_results.php (sort)`: "Relevance" isn't implemented; "Most commented" sorts on a column nothing writes
- **F63** · Medium · `videos.php:63`: "Most commented" sort → fatal (`videos.comment_count` doesn't exist)
- **F64** · Low · `videos.php (views)`: "Most viewed" meaningless: `videos.views` never incremented (column also missing in production)
- **F67** · Low · `video_watch.php:111-114`: Only `watch?v=` YouTube links embed; youtu.be/shorts/&t= fail
- **F135** · Medium · `blog_post.php:43,85`: Photo/voice uploads written to `../assets/uploads/…` (outside web root) but stored as in-site paths → 404
- **F142** · Low · `blog.php:76`: "Most commented" sorts on a column that's always 0
- **F143** · Medium · `blog_post.php:187`: Reading-progress query on non-existent `blog_posts.book_id`
- **F199** · Medium · `assets/js/main.js:170`: Populates a non-existent Bible modal → JS error on every page; active-nav code never runs
- **F200** · Medium · `serve_og.php:16,31`: Uses `$db` without including db.php; fallback logo file missing → every poem share image broken
- **F204** · Medium · `sw.js`: Cache-first service worker with fixed name → returning visitors get stale homepage/CSS/JS forever
- **F205** · High · `includes/db.php:205-255`: DDL + seed check on every request; with per-view writes → "database is locked" on 4 of 60 concurrent views — confirmed
- **F207** · High · `includes/db.php (definitions)`: Repo schema can't build a working fresh install (code needs 86 tables, repo creates 23; definitions conflict with inserts)
- **F208** · Low · `includes/db.php:31 + 54 statements`: SQLite-only SQL throughout; MySQL-only SQL in 3 places (NOW, ON DUPLICATE KEY)
- **F209** · Medium · `reader_reminders / process_queue / worker / book_processing_queue`: Features that need cron/background workers the host doesn't provide
- **F210** · Low · `index.php:9,1225`: Page cache writes to a non-existent `cache/` folder → warning on every guest visit
- **F218** · Medium · `admin/process_book.php (extract)`: PDF/EPUB/DOCX extraction runs inside the request (smalot/pdfparser, ZipArchive) → large books hit host time/memory limits (AUDIT §6.2)
- **F219** · Low · `includes/db.php:21,31 + includes/config.php:8`: SQLite DSN defined twice; `DB_PATH` constant unused (AUDIT §3)

**Files it touches** (from the rows' locations):

`reader/cron/reader_reminders.php`, `reader/book_data.php`, `bible_reader.php`, `includes/bible_lookup.php`, `assets/bible/bible_ASV.db`, `includes/header.php`, `search_results.php`, `videos.php`, `video_watch.php`, `blog_post.php`, `blog.php`, `assets/js/main.js`, `serve_og.php`, `sw.js`, `includes/db.php`, `index.php`, `admin/process_book.php`, `includes/config.php`. Plus a new `db/schema.sql` (fresh-install schema generated from production plus the repair).

**Dependencies.**
- **PR #2 applied first** (F207's fresh schema is production plus the repair).
- **fix/security** (it edits `includes/db.php` for F108).
- Merge after MEMBER-MODEL and GROUPS-COMMUNITY if possible, because `includes/db.php` is the shared hot spot.

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/` then `/poetry.php` … | Browse 10 pages quickly | No "database is locked" | Error page: F205 |
| Any page (Safari, previously visited) | Reload after deploy | New CSS/JS visible without clearing data | Old look persists: F204 |
| `/bible_reader.php?book=John&chapter=3&verse=16` | Open a shared link | Opens John 3:16 | Opens default passage: F29 |
| `/bible_reader.php` | Choose ASV → Isaiah | Clear "not available in ASV" note | "[ASV Isaiah 1 not found]": F25 |
| `/search_results.php` via header icon | Tap the search icon | Search box page | Bounced to home: F56 |
| `/videos.php?sort=most_commented` | Open it | Sorted list | Fatal: F63 |
| Share a poem link in Messages | Look at the preview | Poem image or logo | Broken image: F200 |

**Effort.** XL: 28 commits, about 3 days (F207 alone is about a day); about 45 minutes of iPad tests.

**User decisions.**
- **D-I1 (F24):** YLT has no database. Remove it from the menu, or supply a YLT DB. The recommendation is to remove it.
- **D-I2 (F25):** ASV is incomplete. Hide the missing books or source a full ASV. The recommendation is to hide them, with a note.
- **D-I3 (F218):** Large-book extraction can't run in one request. Cap the upload size and show a message, or process in chunks across requests. The recommendation is the cap plus a message (smaller).

## 8. COMMERCE (payments, purchases, receipts) — GATED

**Goal.** Make paid books purchasable for real, or clearly not for sale:
- record real payments;
- have the download gate read the same table the payment writes;
- give members receipts and the admin a payments screen.

**GATED: payments are UNKNOWN-TO-USER.** No code is written until D-C1 is answered. Until then, the simulated payment (F75, Critical) keeps recording "completed" for money that was never taken.

**Closes (11 OPEN rows: 11 functional, 0 quality), each listed:**

- **F34** · High · `download.php:46-50 + process_payment.php:58-63`: Payments written to `payments`, gate reads `purchases` → every paid download refused forever
- **F35** · High · `process_payment.php:23`: Payment amount taken from the browser
- **F37** · Low · `books.php (?error=not_purchased)`: Refused download redirects to `books.php?error=not_purchased`, which never shows the message
- **F40** · Low · `download.php:26,35,62`: Errors are bare `die()` white pages without site layout
- **F75** · Critical · `process_payment.php:58-63`: "Payment" recorded as `completed` with no provider contacted — simulated payment shown as real
- **F76** · High · `process_payment.php:60`: Phone number entered by the payer is never stored
- **F77** · Medium · `dashboard.php/profile.php/library.php`: No "My purchases"/receipt for members
- **F78** · High · `admin/*`: No admin screen to see or confirm payments
- **F79** · Low · `books.php:121-122`: Sale shows the normal price in a "sale" colour (no sale price field)
- **F80** · Low · `process_payment.php:41`: Price 0 + not free → "Invalid amount", book unobtainable
- **F86** · Medium · `books.php`: Every card shows "Read", including unprocessed books → empty reader "1 / 0"

**Files it touches** (from the rows' locations):

`download.php`, `process_payment.php`, `books.php`, `dashboard.php`, `profile.php`, `library.php`, `admin/*`.

**Dependencies.**
- **D-C1 answered** (hard gate).
- **PR #2 applied** (the `purchases` table).
- **fix/security** (S1/S2 gate the files; item 3 gates links).
- **EMAIL** (receipts).

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/books.php` | Buy a paid book (test amount) | Provider page or manual-payment instructions; status "pending" until confirmed | "Completed" with no money taken: F75 |
| `/admin/` Payments (new) | Confirm the pending payment | Member can now download | Download refused: F34 |
| `/download.php?id=<paid>` as a non-buyer | Open it | Site-styled refusal with a buy link | Bare white `die()` page: F40/F37 |
| `/dashboard.php` | Open "My purchases" | Receipt listed with phone number and amount | Missing: F77/F76 |

**Effort.** L if manual confirmation; XL if a provider API. Can't be estimated tighter until D-C1.

**User decisions.**
- **D-C1 (gate):** How are payments taken?
  - (1) A provider API (e.g. Airtel Money or TNM Mpamba merchant API). This needs outbound HTTPS from InfinityFree, which is unconfirmed, plus merchant credentials.
  - (2) Manual: the member pays to a published number and enters the reference; the admin confirms in a new screen.
  - (3) No sales: paid books are hidden or made free, and the payment code is removed.
  - The recommendation is (2). It works on this host with no new dependency, and nothing is "completed" until a person confirms it.
- **D-C2:** Until D-C1 is answered, should the Buy buttons be hidden now (a one-commit stopgap, outside this batch) so no one is shown a fake "completed"? Recommended: yes.

## 9. LEGAL-TEXT (privacy, terms, accessibility, 404)

**Goal.** Make the legal and static pages true: accurate processors and data collected, real "last updated" dates, an honest accessibility statement, a 404 page that returns 404, and an About page without unrelated member queries.

**Closes (5 OPEN rows: 5 functional, 0 quality), each listed:**

- **F221** · Low · `about.php:55-65`: About page runs the visitor's reputation/achievement/streak queries (member fatal on repo schema; unrelated to the page)
- **F222** · Medium · `privacy.php:59 / terms.php:59 / accessibility.php:118`: "Last updated" is always today's date
- **F223** · High · `privacy.php:20`: Privacy text inaccurate: names Zoho (Gmail used); omits IP logging, DOB/gender/phone, camera/voice uploads, Google sign-in, CDNs
- **F224** · Medium · `accessibility.php`: Claims WCAG 2.1 AA conformance that the site doesn't meet
- **F225** · Low · `.htaccess + 404.php`: No `ErrorDocument 404`; 404.php returns HTTP 200

**Files it touches** (from the rows' locations):

`about.php`, `privacy.php`, `terms.php`, `accessibility.php`, `.htaccess`, `404.php`.

**Dependencies.**
- **EMAIL** (F223 must name the processor actually used after the F217 change).
- **MEMBER-MODEL** D-M3 (whether Google sign-in stays).
- **COMMERCE** D-C1 (whether payment data is collected). Can be written with placeholders, but merge last of the functional batches.

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/privacy.php` | Read the processors and data list | Names Google (mail), lists DOB/gender/phone, IP logging, uploads, CDNs | Still "Zoho" only: F223 |
| `/privacy.php` tomorrow | Check "Last updated" | Fixed date, not today | Today's date: F222 |
| `/accessibility.php` | Read the claim | "Working towards WCAG 2.1 AA" with known issues | "Conforms to AA": F224 |
| `/no-such-page` | Open it | Site 404 page (HTTP 404, checked in the harness) | Host default page or 200: F225 |

**Effort.** S: 5 commits, about half a day; about 10 minutes of iPad tests.

**User decisions.**
- **D-L1:** The legal owner name, contact address and jurisdiction (presumably Malawi) to print on the privacy and terms pages.
- **D-L2:** The effective date for the corrected policies.

## 10. QUALITY (works-but-ugly)

**Goal.** Polish after the functional batches: phone layout and tap targets, heavy images, polling cost, feedback via alert(), duplicate flows, dead code, and token-bypassing inline CSS. **Last, and only when authorised.** Several rows touch files every other batch edits, so they go in after those.

**Closes (55 OPEN rows: 0 functional, 55 quality), each listed:**

- **Q1** · Medium · `reader/reader.php`: Phone width: 20-icon sidebar permanently on screen; title and status dropdown clipped
- **Q2** · Medium · `reader/reader.php`: Icon-only controls: 0 aria-labels; tooltips useless on touch
- **Q3** · Low · `reader/reader.php:292`: Reader is standalone; back arrow is a JS button, not a link
- **Q4** · Low · `reader/reader.php:825`: Synchronous XHR in loadBookmarkStatus() freezes the page
- **Q5** · Low · `reader/reader.php`: Many small AJAX calls per reading session (hit cost)
- **Q6** · Medium · `reader/reader.php`: No loading/empty/error states (unprocessed book shows "1 / 0")
- **Q7** · Low · `reader/reader.php`: Guests not told progress/highlights need an account
- **Q8** · Low · `reader/reader_audio.php, reader_renderer.php, reader/includes/auth_check.php, reader/assets/sw.js`: Dead code; two TTS implementations
- **Q9** · Low · `bible_reader.php`: alert()/prompt() used for all feedback; raw bracketed errors
- **Q10** · Low · `assets/bible/bible_WEB.db`: 330 verses show raw `{footnote}` text
- **Q11** · Medium · `includes/footer.php (cookie bar)`: Cookie banner covers controls (e.g. Bible "Post Note") until dismissed
- **Q12** · Low · `bible_reader.php`: Guest actions show technical alert, no sign-in link
- **Q13** · Low · `bible_reader.php`: Theme keys split across 6 localStorage entries; can disagree with site theme
- **Q14** · Medium · `books.php / book.php`: No "Owned" state, no buy button on detail page, guests not told sign-in unlocks downloads
- **Q15** · Low · `admin/manage_books.php`: No upload size check or host-limit message
- **Q16** · Low · `groups.php / group.php`: Weak empty state; alert() feedback; invite code can't be regenerated; 400+ lines inline CSS
- **Q17** · Low · `includes/reading_groups.php`: `group_activity_log` grows without pruning
- **Q18** · Low · `search_results.php`: 38 sub-32 px tap targets on phone; no match highlighting; snippet quality; 10 full LIKE scans per search
- **Q19** · Low · `video player`: Plyr loaded after the (now fixed) fatal; no view counting UI
- **Q20** · Low · `admin/reflection_editor.php`: Recorded media uploaded in the same post as text (upload limit loses the text)
- **Q21** · Low · `books.php`: Price shown with tambala decimals; text-only provider buttons; mock flow presented as real
- **Q22** · Low · `book.php`: Phone: cover fills first screen, actions below the fold; "(1 reviews)" pluralisation
- **Q23** · Low · `admin/process_book.php`: 2,800-line single page, 40+ actions, display_errors into JSON
- **Q24** · Medium · `register.php`: 13 required fields (gender, DOB, phone…) before reading free content
- **Q25** · Low · `verify.php / resend_verification.php / reset_password.php`: Duplicate resend and request-reset flows
- **Q26** · Low · `login.php:152-157`: Leftover dark-mode button and reading progress bar on the login page
- **Q27** · Low · `register.php / profile.php`: No password-strength feedback or show-password toggle
- **Q28** · Medium · `dashboard.php:378,399`: Polling every 60 s ×2 → ~2,880 hits/day per open tab, no pause when hidden
- **Q29** · Low · `dashboard.php / ajax_user.php`: Chart.js loaded unpinned from jsdelivr
- **Q30** · Low · `dashboard.php`: Gamification shown prominently while inert
- **Q31** · Low · `profile.php / register.php`: first/last name at signup vs single name in profile
- **Q32** · Medium · `poem_view.php`: No `$pageTitle`; OG tags emitted in `<body>`; 35 sub-32 px tap targets
- **Q33** · Low · `poem_view.php / ajax_get_reactions.php`: Reaction vocabulary differs from every other content type
- **Q34** · Low · `blog.php / blog_post.php`: Category naming confusion ("Christian Reflections" is title, default category and type); 40 small tap targets
- **Q35** · Low · `admin/manage_community.php / manage_questions.php`: Two admin screens for one content type; no answer list
- **Q36** · Medium · `all comment systems`: Seven comment systems / six reaction systems with different forms, feedback and vocabularies
- **Q37** · Low · `newsletter.php / footer`: No name field or inline success on footer subscribe
- **Q38** · Low · `admin/manage_newsletter.php`: Large newsletter UI for a sender that can't send; From/Gmail mismatch
- **Q39** · Low · `contact.php / about.php`: Two contact forms with different fields and validation
- **Q40** · Low · `book_session.php`: No availability rules; no meeting link/place on confirmation; no calendar view
- **Q41** · Medium · `admin/dashboard.php:293,398`: Admin polling ~3 req/min (~4,300 hits/day per open tab)
- **Q42** · Low · `admin/dashboard.php`: No "needs attention" summary; real numbers buried among zeros; two analytics pages
- **Q43** · High · `assets/images/logo.png, hero-logo.png`: 2.1 MB logo (1536×1024) + 1.3 MB hero → 2.1–3.4 MB first page view
- **Q44** · Low · `includes/header.php:57`: favicon.ico missing → 404 hit on every page
- **Q45** · Low · `manifest.json`: No icons
- **Q46** · Medium · `assets/css/style.css + 70 PHP files`: Inline CSS bypasses tokens: 135 hex colours, 74 margin / 117 padding values, 38 pages redefine `:root` (dark mode not followed) (AUDIT §5)
- **Q47** · Low · `includes/admin_mail_helper.php`: Safer mail helper exists but is never called (dead code)
- **Q48** · Low · `includes/auth.php:7-11`: Creates unused `tmp/sessions/` on every request
- **Q49** · Low · `login.php:30 etc.`: Rate-limit files in the shared system temp dir (best-effort only)
- **Q50** · Low · `view_logs, poem_reads, blog_reads, group_activity_log, jobs, remember_tokens…`: Append-only tables never pruned
- **Q51** · Low · `includes/db.php`: 14 unused production tables + clutter (activity_logs, media_files, …)
- **Q52** · Low · `pages (15–40 per page)`: Sub-32 px tap targets on phone across the site
- **Q53** · Low · `external CDNs`: TinyMCE in two versions; Chart.js unpinned; several third-party dependencies
- **Q54** · Low · `privacy.php / terms.php`: Generic legal templates
- **Q55** · Low · `about.php`: Personal gamification mixed into the author biography

Suggested sub-batches, to keep each PR reviewable:
- **Q-PERF:** Q5, Q18, Q28, Q41, Q43, Q44, Q50, Q53
- **Q-MOBILE:** Q1, Q2, Q11, Q22, Q32, Q34, Q52
- **Q-FEEDBACK:** Q3, Q4, Q6, Q7, Q9, Q10, Q12, Q14, Q15, Q16, Q19, Q20, Q21, Q26, Q27, Q37
- **Q-CONSOLIDATE:** Q8, Q13, Q17, Q23, Q24, Q25, Q29, Q30, Q31, Q33, Q35, Q36, Q38, Q39, Q40, Q42, Q45, Q46, Q47, Q48, Q49, Q51, Q54, Q55

**Files it touches** (from the rows' locations):

`reader/reader.php`, `reader/reader_audio.php`, `reader_renderer.php`, `reader/includes/auth_check.php`, `reader/assets/sw.js`, `bible_reader.php`, `assets/bible/bible_WEB.db`, `includes/footer.php`, `books.php`, `book.php`, `admin/manage_books.php`, `groups.php`, `group.php`, `includes/reading_groups.php`, `search_results.php`, `admin/reflection_editor.php`, `admin/process_book.php`, `register.php`, `verify.php`, `resend_verification.php`, `reset_password.php`, `login.php`, `profile.php`, `dashboard.php`, `ajax_user.php`, `poem_view.php`, `ajax_get_reactions.php`, `blog.php`, `blog_post.php`, `admin/manage_community.php`, `manage_questions.php`, `newsletter.php`, `admin/manage_newsletter.php`, `contact.php`, `about.php`, `book_session.php`, `admin/dashboard.php`, `assets/images/logo.png`, `hero-logo.png`, `includes/header.php`, `manifest.js`, `assets/css/style.css`, `includes/admin_mail_helper.php`, `includes/auth.php`, `includes/db.php`, `privacy.php`, `terms.php`.

**Dependencies.**
- All functional batches merged.
- Q43 (the 2.1 MB logo) can go at any time: one image swap, and the biggest page-weight win. It's a candidate to pull forward.

**iPad tests after deploy:**

| URL | Action | Expected | Failure means |
|---|---|---|---|
| `/` on iPad and iPhone | Load the home page on mobile data | Under 1 MB transferred; logo sharp | Over 2 MB: Q43 |
| `/reader/reader.php?id=<id>` on iPhone width | Open a book | Text area full width, sidebar collapsed | Sidebar covers text: Q1 |
| `/dashboard.php` | Leave the tab open in the background for 5 minutes | No requests while hidden (checked in the harness) | Polling continues: Q28 |
| Any page | Tap small icons | Each target ≥ 44 px | Mis-taps: Q52 |

**Effort.** XL in total: Q-PERF M, Q-MOBILE L, Q-FEEDBACK L, Q-CONSOLIDATE XL.

**User decisions.**
- **D-Q1:** Authorise QUALITY at all, and which sub-batch first. The recommendation is Q-PERF (Q43 first).
- **D-Q2 (Q24):** Which signup fields are really required. The recommendation is username, email and password only, with the rest optional in the profile.

## Coverage statement

Every row in `docs/COVERAGE.md` is accounted for: **232 functional, 55 quality, 287 in total.**

| COVERAGE status | Functional | Quality | Where it goes |
|---|---|---|---|
| FIXED | 35 | 0 | 34 on `fix/core-fatals` (PR #1); F195 on `deploy/exclude-sensitive` (PR #3). Awaiting merge. |
| IN-PROGRESS | 21 | 0 | `db/repair-artifacts` (PR #2). Awaiting merge **and** applying repair.sql to the live DB. |
| PLANNED | 11 | 0 | fix/security (items 1–7 and Step 3): F22, F32, F53, F54, F94, F107, F127, F190, F196, F197, F198. |
| OPEN | 150 | 55 | Mapped above, each row exactly once: fix/security 12F; fix/security-2 15F; EMAIL 14F; MEMBER-MODEL 26F; GROUPS-COMMUNITY 26F; ADMIN-TOOLS 13F; INFRA-CONSISTENCY 28F; COMMERCE 11F; LEGAL-TEXT 5F; QUALITY 0F+55Q. |
| WONTFIX | 15 | 0 | Not mapped. Reasons below. |
| **Total** | **232** | **55** | |

**Unmapped OPEN rows: 0.** That was checked mechanically: the 205 OPEN rows in COVERAGE (150 F + 55 Q) equal the union of the batch lists above, with no row in two batches and no non-OPEN row in a batch.

**WONTFIX rows (15), not assigned to any batch, with the reason from COVERAGE:**
- F21: Missing `u.username` / `display_name` / `books.author_id` happens on the repo schema only; production has the columns. The fresh-install problem is tracked by F207 (INFRA).
- F31: Bible session rows exist in production. The per-visit hit cost is tracked as quality (Q5 class).
- F52: `reading_groups` missing on the repo schema only. Covered by F207.
- F69: The AUDIT claim of runtime CREATE/ALTER is wrong: the statements are inside a comment.
- F110: Missing `users` columns / `name NOT NULL` on the repo schema only. Production is fine. Covered by F207.
- F120: `poem_reads` / `notifications` exist in production. Covered by F207.
- F128: The private-comment leak doesn't reproduce on SQLite. The latent clause is tracked as F129 (GROUPS-COMMUNITY).
- F131: `poem_reads` exists in production. Covered by F207.
- F144: `comment_count` exists in production. Covered by F207.
- F154: `user_reputations` / `reports` exist in production. Covered by F207.
- F177: `unsubscribe_token` exists in production. Covered by F207.
- F192: `newsletter_audit_log` exists in production. Covered by F207.
- F216: Concatenated SQL is whitelisted, hard-coded or bound: not exploitable (AUDIT §4).
- F220: Committed `vendor/` is informational and works on the host. Not a defect.
- F226: `settings` exists in production. Covered by F207.

**The rows this plan disputes** (F96, F232, F217, F180, F11, F223, Q38, F102, F109) are all still mapped. They are listed under Row corrections, not dropped.


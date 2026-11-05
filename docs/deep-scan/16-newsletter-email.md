# 16. Newsletter, email & unsubscribe

> Test method as in `01-reader.md`. Outbound SMTP is unreachable from the test box. That matches your stated "no outbound calls" constraint, so every `sendEmail()` here behaved the way it will on a host that blocks outbound connections.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `newsletter.php` | 89 | Footer subscribe handler (POST then redirect) | Y |
| `unsubscribe.php` | 180 | Unsubscribe by token; request an unsubscribe link by email | Y |
| `admin/manage_newsletter.php` | 535 | Newsletter hub: send, schedule, segments, templates, import, resend failed, bulk subscriber actions | Y |
| `admin/newsletter_tabs/send.php` | 155 | Compose/send tab partial | Y |
| `admin/newsletter_tabs/queue.php` | 56 | Queue tab partial | Y |
| `admin/newsletter_tabs/archive.php` | 50 | Archive tab partial | Y |
| `admin/newsletter_tabs/segments.php` | 66 | Segments tab partial | Y |
| `admin/newsletter_tabs/import.php` | 23 | CSV import tab partial | Y |
| `admin/newsletter_tabs/audit.php` | 34 | Audit-log tab partial | Y |
| `admin/newsletter_tabs/subscribers.php` | — | **Referenced by `manage_newsletter.php`, doesn't exist** | Y |
| `admin/newsletter_preview.php` | 22 | Renders an archived newsletter | Y |
| `admin/export_subscribers.php` | 24 | CSV export | Y |
| `admin/process_queue.php` | 73 | Drains `email_queue` (designed for cron) | Y |
| `includes/mail_helper.php` | 33 | `sendEmail()`: Gmail SMTP, `die()` on failure | Y |
| `includes/admin_mail_helper.php` | 27 | `sendAdminEmail()`: never called | Y |
| Every `sendEmail()` call site | 60 sites / 36 files | See the inventory at the end | Y |

## User journeys

### Journey 1: Visitor subscribes from the footer
1. Every page's footer has an email box (`includes/footer.php:66`).
2. Env A: **[fatal] [confirmed]** *no such column: unsubscribe_token* (`newsletter.php:32`).
3. Env B: the subscriber **is saved** (row with a 64-char token), then the welcome email (`:63`) fails and the visitor sees the **SMTP error dump** instead of being sent back to the page they were on **[wrong] [confirmed]**.
4. **[quality]** The footer form has no name field and no success area. Even when it works, the success message is stored in the session and only shown on pages that read `$_SESSION['newsletter_success']` **[inferred]**.

### Journey 2: Logged-in member subscribes from the footer
1. Same footer form. **[silent] [confirmed]** `newsletter.php:9-16` redirects any logged-in user to `library.php` **before** looking at the email. Nothing is saved and nothing is said. **Members can't subscribe.**

### Journey 3: Visitor subscribes from the homepage form
1. The homepage form (`index.php:117-160`) has a CSRF token. The homepage itself fatals in Env A on `testimonials` (domain 20).
2. **[wrong] [inferred]** If the guest page cache in `index.php:9-19` ever works (it needs a `cache/` folder that doesn't exist), cached HTML would carry another visitor's CSRF token and every guest subscription would fail with "Invalid request".

### Journey 4: Subscriber unsubscribes
1. **Via the token link** in a welcome or newsletter email: `unsubscribe.php?token=…` marks the row inactive (**confirmed**: `is_active` 1 → 0). Then `:33` emails the admin, so the subscriber sees the **SMTP dump** instead of "You've been unsubscribed" **[wrong] [confirmed]**. Env A fatals on the missing column (`:13`).
2. **Via the link in blog broadcasts** (`newsletter.php?unsubscribe=1&token=…`, from `admin/editor.php:131`): **[silent] [confirmed]** `newsletter.php` has no unsubscribe handler. It redirects to the homepage and the subscriber stays subscribed. That's a legal problem for marketing email in most jurisdictions.
3. **Via "email me an unsubscribe link"** (`unsubscribe.php?email=…`): needs SMTP, so it shows the dump **[confirmed]**.

### Journey 5: Admin sends a newsletter now
1. `admin/manage_newsletter.php?tab=send`: subject, content (TinyMCE), recipients (all / segment / selected / single) → Send.
2. A queue row is created as `processing`. The first `sendEmail()` fails → **SMTP dump** **[confirmed]**.
3. **[wrong] [confirmed]** The queue row stays `processing` forever. "Resend failed" and `process_queue.php` only look at `pending`/`failed`, so it can never be retried.
4. **[wrong] [inferred, code-verified]** With working SMTP: only the **first 10 recipients** are sent in the request (`:136`). Then the whole send is marked `sent` and archived with the full recipient count (`:155-160`). Recipients 11+ never get it, and the archive says they did.

### Journey 6: Admin schedules a newsletter
1. Picks a date/time in `schedule` → the row is queued as `pending` with `scheduled_at` **[confirmed]**.
2. **[silent] [inferred]** Nothing runs at that time; there's no cron. It only goes out if the admin opens `admin/process_queue.php` by hand. That page then calls `sendEmail()` → **SMTP dump** **[confirmed]**.
3. **[wrong] [confirmed]** An unparseable date is stored as `1970-01-01 00:00:00`, shown as "scheduled for January 1, 1970", instead of being rejected. `date()` never returns `false`, so the check at `:108` never fires.

### Journey 7: Admin manages subscribers, segments, import and export
1. **Subscribers tab:** **[fatal] [confirmed]** `require_once(newsletter_tabs/subscribers.php)`: the file doesn't exist. The admin can't see the subscriber list, and the bulk actions that live there (activate, deactivate, delete, **assign to segment**) are unreachable.
2. **Segments:** creating a segment works (inferred from the handler). **Assigning subscribers needs the missing Subscribers tab**, so every segment stays empty and "send to segment" sends to nobody **[wrong] [inferred, code-verified]**.
3. **CSV import:** 3-row test file → `imp1@example.com` added, the invalid address and the existing subscriber skipped. "skipped" reported **[confirmed]**. Works.
4. **CSV export:** proper `text/csv` download with Email, Name, Status, Subscribed At, Source **[confirmed]**. Works. Every row's source shows "manual", including people who signed up on the site.
5. **Archive / preview:** `newsletter_preview.php?id=…` → "Archive not found." when nothing has been archived **[confirmed]**. Archives are only written after a "successful" send (Journey 5), so in practice this stays empty.
6. **Tab partials** (`admin/newsletter_tabs/*.php`) can be requested directly by anyone (HTTP 200, 0.7–9 KB of admin form markup) **[confirmed]**. They hold no subscriber data and their forms post nowhere useful. Low risk, but untidy.

### Journey 8: Any member action that sends email (site-wide)
1. `includes/mail_helper.php` connects to `smtp.gmail.com:587` with `GMAIL_USERNAME`/`GMAIL_APP_PASSWORD`, sets the From address to whatever the caller passes (usually `angelwrites@zohomail.com`), and on **any** exception **calls `die()`** with a red box containing the error message, PHPMailer's file path and line number (`:25-33`).
2. The default PHPMailer timeout is 300 s (`vendor/phpmailer/phpmailer/src/SMTP.php:180`). If the host silently drops outbound packets instead of refusing them, each request hangs until the PHP time limit, then fails.
3. Effect: **60 call sites in 36 files**. 42 notify the admin, 13 email the member or visitor, 5 email subscribers.
   - Almost all run **after** the database write, so the action succeeds and the user is shown a crash.
   - 10 are inside JSON/AJAX endpoints, where the dump also breaks the page's JavaScript.
   - The full inventory is at the end of this file.
4. `includes/admin_mail_helper.php` (`sendAdminEmail()`, `return false` on failure) is the safer design, but **nothing calls it**.

## Quality problems independent of the bugs above
- **There's no working delivery path at all** under your constraints. Every newsletter feature is a UI around a sender that can't send.
- **The newsletter screen is large** (send/subscribers/segments/queue/archive/audit/import/templates) **for a site whose sends cap at 10 recipients** and can't run on schedule.
- **Unsubscribe is the most important email flow legally, and it's broken in two of three paths.**
- **The From address doesn't match the sending account** (Zoho address via Gmail SMTP). Gmail either rewrites it or the mail lands in spam.
- **Subscribers are never asked to confirm** (no double opt-in), so anyone can sign anyone up.

## Root causes
- **[code]**
  - `die()` in `sendEmail()`.
  - Email inline in 60 user actions.
  - Member redirect before subscribing.
  - Missing Subscribers tab file.
  - 10-recipient cap marked as complete.
  - Stuck `processing` rows.
  - `date()` validation that can't fail.
  - Broadcast unsubscribe link to an unhandled URL.
  - Open redirect via the `redirect` field.
  - Unused safer helper.
- **[schema]** `newsletter.unsubscribe_token/updated_at`, `email_queue`, `email_attachments`, `newsletter_stats`, `newsletter_archive`, `newsletter_audit_log`, `newsletter_templates`, `subscriber_segments`, `subscriber_segment_assignments` aren't created by the repo.
- **[ux]** No double opt-in, no member subscribe path, no visible confirmation.
- **[infra]**
  - **No outbound SMTP** (stated constraint).
  - No cron for scheduled sends or queue draining.
  - Execution-time limits for bulk sends.
  - Gmail's own daily sending limit (≈500/day for a personal account).

## What a professional version looks like
- **J1/J2:** Footer subscribe works for guests and members alike, then shows "You're subscribed ✓" in place (no page change). If confirmation emails are possible, it says "Check your inbox to confirm".
- **J4:** Every email carries one unsubscribe link that works in one click and shows "You've been unsubscribed" with a "Resubscribe" button. The admin learns about it from the subscriber list, not an email.
- **J5/J6, if email can work at all on this host:** a send creates a batch. The admin page shows "120 recipients · 20 sent · Send next 20" (or sends one batch per admin page view). Progress and failures are stored per recipient. Archive counts only what was actually delivered. Scheduling is removed unless something can run it.
- **J5/J6, if it can't:** the honest feature is **export subscribers to CSV and send from an external service** (e.g. a free tier of a newsletter tool), and the in-site send tools are removed.
- **J7:** A subscriber list with search, status and segment chips; segments assigned from the list.
- **J8:** `sendEmail()` returns `false` and logs on failure; it never prints. Admin notifications go to an on-site admin inbox instead of email.

## Dependencies before this can be fixed
1. **Plan-level question for you:** does your InfinityFree plan allow outbound SMTP on port 587/465 at all? Everything in this domain (and 7 auth steps in domain 10) depends on the answer. If not, email must come from an external service the visitor's browser talks to, or be dropped.
2. Make `sendEmail()` non-fatal **before** anything else. That single change stops the crash-after-success pattern in about 15 other journeys.
3. Confirm the newsletter tables and columns in production `data/site.db`. **Add manually** where missing (SQLite file, not phpMyAdmin; see 01).
4. Decide whether in-site newsletter sending stays (batches) or becomes export-only.

## Blast radius
Every domain with a form: 10 (auth), 11 (profile), 12 (poems), 13 (blog), 14 (Q&A), 15 (comments), 17 (contact), 18 (sessions), 3/8 (payments), 2 (Bible notes), 1 (reader reports), 9 (reviews, book upload), 20 (homepage forms). Group "newsletter" content (domain 4) also points at a newsletter-issues table that doesn't exist.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 guest subscribe | S | Non-fatal email; redirect back with a message. |
| J2 member subscribe | S | Remove the early redirect. |
| J3 homepage form | S | Don't cache pages containing tokens. |
| J4 unsubscribe | S | Non-fatal email; handle the broadcast link (or fix the link). |
| J5/J6 send/schedule | M–L | Batching with per-recipient status, or replace with export-only (S). |
| J7 subscribers/segments | M | Write the missing tab; assign segments from it. |
| J8 `sendEmail()` | S | One function; biggest single improvement in the codebase. |

## Regression check against AUDIT.md
- `includes/mail_helper.php:27` `die()` on failure, 60 call sites in 36 files: **Yes**, count unchanged.
- `newsletter.php:32/41` missing columns [schema]: **Yes** (Env A).
- `newsletter.php:9-16` member redirect: **Yes.**
- `newsletter.php:78/89` open redirect: **Yes**, confirmed `Location: https://angelwrites.gt.tc@evil.example/`.
- `admin/manage_newsletter.php:136` 10-recipient cap marked sent: **Yes** (code; not reachable past SMTP in test).
- `admin/newsletter_tabs/*` no own auth check: **Yes** (reachable; markup only).
- AUDIT.md didn't report the missing `newsletter_tabs/subscribers.php`, stuck `processing` rows, the 1970 scheduling, or that segments can't be assigned. New findings.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `newsletter.php` | COVERED | Journeys 1, 2, 4 (step 2) |
| `unsubscribe.php` | COVERED | Journey 4 |
| `admin/manage_newsletter.php` | COVERED | Journeys 5, 6, 7 |
| `admin/newsletter_tabs/send.php` | COVERED | Journeys 5, 6 |
| `admin/newsletter_tabs/queue.php` | COVERED | Journey 5 step 3; Journey 7 step 6 |
| `admin/newsletter_tabs/archive.php` | COVERED | Journey 7 step 5 |
| `admin/newsletter_tabs/segments.php` | COVERED | Journey 7 step 2 |
| `admin/newsletter_tabs/import.php` | COVERED | Journey 7 step 3 |
| `admin/newsletter_tabs/audit.php` | COVERED | Journey 7 step 6 |
| `admin/newsletter_tabs/subscribers.php` (missing) | COVERED | Journey 7 step 1 |
| `admin/newsletter_preview.php` | COVERED | Journey 7 step 5 |
| `admin/export_subscribers.php` | COVERED | Journey 7 step 4 |
| `admin/process_queue.php` | COVERED | Journey 6 step 2 |
| `includes/mail_helper.php` | COVERED | Journey 8 |
| `includes/admin_mail_helper.php` | COVERED | Journey 8 step 4 |
| All `sendEmail()` call sites | COVERED | Inventory below |

## Appendix: every `sendEmail()` call site
Recipient is inferred from the first argument (`$admin_email` → admin). "Context" is whether the file answers in JSON (where the dump also breaks the page's JavaScript).

| # | Call site | Recipient | Context |
|---|---|---|---|
| 1 | `about.php:43` | admin | page |
| 2 | `admin/editor.php:121` | admin | page |
| 3 | `admin/editor.php:141` | subscriber | page |
| 4 | `admin/manage_books.php:144` | admin | page |
| 5 | `admin/manage_newsletter.php:145` | subscriber | page |
| 6 | `admin/manage_newsletter.php:343` | subscriber | page |
| 7 | `admin/manage_users.php:35` | admin | page |
| 8 | `admin/manage_users.php:66` | admin | page |
| 9 | `admin/poem_editor.php:109` | admin | page |
| 10 | `admin/process_book.php:989` | member/visitor | page |
| 11 | `admin/process_queue.php:48` | subscriber | page |
| 12 | `admin/reflection_editor.php:117` | admin | page |
| 13 | `bible_reader_ajax.php:78` | admin | JSON/AJAX endpoint |
| 14 | `blog_post.php:73` | admin | page |
| 15 | `blog_post.php:108` | admin | page |
| 16 | `blog_post.php:140` | admin | page |
| 17 | `book.php:109` | admin | page |
| 18 | `book_session.php:106` | admin | page |
| 19 | `book_session.php:121` | member/visitor | page |
| 20 | `community.php:108` | admin | page |
| 21 | `community.php:146` | admin | page |
| 22 | `community.php:156` | member/visitor | page |
| 23 | `community_delete.php:44` | admin | page |
| 24 | `community_edit.php:55` | admin | page |
| 25 | `community_report.php:58` | admin | JSON/AJAX endpoint |
| 26 | `contact.php:50` | admin | page |
| 27 | `contact.php:55` | member/visitor | page |
| 28 | `forgot_password.php:38` | member/visitor | page |
| 29 | `forgot_password.php:47` | admin | page |
| 30 | `index.php:144` | admin | page |
| 31 | `index.php:155` | admin | page |
| 32 | `index.php:187` | admin | page |
| 33 | `login.php:139` | admin | page |
| 34 | `newsletter.php:49` | admin | page |
| 35 | `newsletter.php:63` | subscriber | page |
| 36 | `newsletter.php:69` | admin | page |
| 37 | `process_payment.php:77` | admin | JSON/AJAX endpoint |
| 38 | `profile.php:117` | admin | page |
| 39 | `reader/cron/reader_reminders.php:65` | member/visitor | JSON/AJAX endpoint |
| 40 | `reader/progress_tracking.php:323` | admin | page |
| 41 | `reader/reader_ajax.php:272` | admin | JSON/AJAX endpoint |
| 42 | `reader/reader_ajax.php:298` | admin | JSON/AJAX endpoint |
| 43 | `reader/reader_ajax.php:702` | admin | JSON/AJAX endpoint |
| 44 | `reader/reader_ajax.php:738` | admin | JSON/AJAX endpoint |
| 45 | `reader/reader_ajax.php:863` | admin | JSON/AJAX endpoint |
| 46 | `reader/reader_circles.php:290` | member/visitor | JSON/AJAX endpoint |
| 47 | `register.php:149` | member/visitor | page |
| 48 | `register.php:162` | admin | page |
| 49 | `resend_verification.php:49` | member/visitor | page |
| 50 | `reset_password.php:81` | member/visitor | page |
| 51 | `reset_password.php:139` | admin | page |
| 52 | `session_cancel.php:47` | admin | page |
| 53 | `session_edit.php:89` | admin | page |
| 54 | `unsubscribe.php:33` | admin | page |
| 55 | `unsubscribe.php:36` | admin | page |
| 56 | `unsubscribe.php:72` | member/visitor | page |
| 57 | `unsubscribe.php:75` | member/visitor | page |
| 58 | `verify.php:75` | admin | page |
| 59 | `verify.php:124` | member/visitor | page |
| 60 | `worker.php:25` | admin | page |

Total: 60 call sites in 36 files. Recipients: admin 42, subscriber 5, member/visitor 13. Contexts: page 50, JSON/AJAX endpoint 10.

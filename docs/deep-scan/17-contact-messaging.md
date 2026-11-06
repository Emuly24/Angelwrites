# 17. Contact & admin messaging

> Test method as in `01-reader.md`. `contact_messages` is a repo-defined table, so the contact flow behaves the same in Env A and Env B.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `contact.php` | 324 | Contact page: name, email, subject, message, arithmetic "captcha"; saves and emails admin and sender | Y |
| `about.php` (contact form, `:24-60`, `:191`) | 412 | Second contact form on the About page (name, email, message) | Y (page itself in 21) |
| `admin/manage_messages.php` | 384 | Admin inbox: list, search, mark read, delete, bulk actions | Y |

## User journeys

### Journey 1: Visitor sends a message from the Contact page
1. Fills name, email, subject, message, answers "What is 3 + 7?".
2. Invalid email: *"Please enter a valid email address"* **[confirmed]**. Good.
3. Valid: the message **is saved**, then `contact.php:50` emails the admin and `:55` emails a copy to the sender → **SMTP error dump** **[wrong] [confirmed, both environments]**. The visitor thinks the message failed and sends it again.
4. **[wrong] [confirmed]** The captcha is client-trusted: the expected answer travels in a hidden field (`captcha_expected`), and `:25-27` only checks that the two posted numbers match. Posting with **both fields omitted** passes (0 = 0). My test messages were accepted that way. It stops nothing automated.
5. Sending a copy to the address typed in the form lets anyone send email from your account to any address (once SMTP works) **[inferred]**.

### Journey 2: Visitor sends a message from the About page
1. Second form (`about.php:191`) with name, email and message, no subject and no captcha.
2. Saved (`subject` NULL), then `about.php:43` email → **SMTP dump** **[wrong] [confirmed]**. Env A also fatals earlier on the About page's own queries (domain 21).

### Journey 3: Admin reads and answers messages
1. `admin/manage_messages.php` lists messages with search, read/unread, bulk mark-read/delete **[confirmed]**. **The inbox itself works.**
2. **[missing] [confirmed by code]** There's no Reply button and no `mailto:` link. To answer, the admin copies the address into their own mail client.
3. Mark-read and delete are GET links (`?mark_read=`, `?delete=`), with no confirmation for single delete and no CSRF **[inferred, code-verified]**.
4. No unread count in the admin header or dashboard (the dashboard shows a total message count) **[inferred]**.

## Quality problems independent of the bugs above
- **Two contact forms with different fields** and different validation (Contact has subject and captcha; About has neither).
- **Crash after success** on both forms, the most common failure pattern on the site.
- **The anti-spam measure is decorative** (Journey 1 step 4). On a public form this means spam fills `contact_messages`, which is one more table growing without bound.
- Layout: both pages show no overflow at 768/375 px (crawl) **[confirmed]**.

## Root causes
- **[code]** Inline email with `die()`; captcha answer sent to the client; GET state changes; duplicated form logic in `about.php`.
- **[ux]** No reply path for the admin, no unread badge, two divergent forms.
- **[infra]** SMTP; no rate limit on the public form, on a 50k-hits/day plan.

## What a professional version looks like
- **J1/J2:** One contact form component, used on both pages. Submit shows "Thanks, Angella will reply to *you@example.com* within a few days" in place. Spam control is server-side (a session-stored answer, or a hidden honeypot field plus a per-IP limit). No copy is sent to the visitor's typed address.
- **J3:** Inbox with an unread badge in the admin nav. Each message has "Reply by email" (`mailto:` prefilled with subject "Re: …") and a "Mark as answered" toggle. Deletes are POST with confirmation.

## Dependencies before this can be fixed
1. Non-fatal `sendEmail()` (domain 16). This alone fixes both forms' visible failure.
2. Decide whether visitors get a copy of their message; if email is unavailable, drop it.
No database change needed: `contact_messages` already exists in the repo schema.

## Blast radius
- **Domain 16 (email):** both forms' failure.
- **Domain 21 (About page):** hosts the second form.
- **Domain 19 (admin dashboard):** message counts.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 contact | S | Non-fatal email; server-side captcha/honeypot. |
| J2 about form | S | Reuse J1's handler. |
| J3 admin inbox | S | `mailto:` reply link, unread badge, POST deletes. |

## Regression check against AUDIT.md
- `contact.php:50/55` `sendEmail()` → `die()`: **Yes**, both environments.
- `about.php:43` `sendEmail()` → `die()`: **Yes.**
- AUDIT.md didn't report the bypassable captcha. New.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `contact.php` | COVERED | Journey 1 |
| `about.php` (contact form) | COVERED | Journey 2 |
| `admin/manage_messages.php` | COVERED | Journey 3 |

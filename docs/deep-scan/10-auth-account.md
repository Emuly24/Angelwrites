# 10. Authentication & account lifecycle

> Test method as in `01-reader.md`, with real browser form submissions. Verification and reset codes were read from the test database, as a user would from their inbox.
>
> **One deliberate Env B change for this domain:** the repo's `users.name` column is `NOT NULL` (`includes/db.php:44`), and registration fails on it (Journey 1). To see what happens *after* that point, Env B's `users.name` was then made nullable, as it would be in production if the table was ever rebuilt by hand. Steps marked **(B-nullable)** only apply if your production `users.name` allows NULL.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `register.php` | 574 | 13-field signup, creates unverified user, emails 6-digit code + admin alert | Y |
| `login.php` | 399 | Login by username or email, IP rate limit, remember-me token, failed-login admin alert | Y |
| `logout.php` | 40 | Clears session and cookies | Y |
| `verify.php` | 411 | Enter 6-digit code (AJAX), resend code, auto-login | Y |
| `resend_verification.php` | 114 | Separate "resend code" page | Y |
| `forgot_password.php` | 115 | Request reset code | Y |
| `reset_password.php` | 415 | Also requests a code (duplicate of forgot), then code + new password | Y |
| `reset_admin.php` | 22 | **Public** admin password reset to a hard-coded value | Y |
| `social_login.php` | 63 | Google/Facebook via Hybridauth | Y |
| `update_schema.php` | 86 | One-time admin migration script, still deployed | Y |
| `includes/auth.php` | 73 | Session start + `isLoggedIn`/`isAdmin`/redirect helpers | Y |

## User journeys

### Journey 1: New visitor creates an account
1. `register.php` shows 13 fields, all required except "other referral":
   - first name, last name, username, email, password and confirm
   - gender, date of birth, country (195 options), contact number, "how did you hear about us"
   - a terms checkbox

   On a 375 px phone the form is **1,154 px tall**. No overflow **[confirmed]**.
2. Env A: **[fatal] [confirmed]** *no such column: username* (`register.php:123`).
3. Env B as the code expects: **[fatal] [confirmed]** *NOT NULL constraint failed: users.name*.
   - `register.php:130` inserts `first_name, last_name, username…` but never `name`. The repo's own table definition makes `name` mandatory.
   - SQLite can't relax `NOT NULL` with `ALTER TABLE`, so **this fails on any database whose `users` table came from `includes/db.php`**. That is almost certainly what you've seen.
4. **(B-nullable)** The account row is inserted (unverified, `name` empty). Then `register.php:149` emails the code, SMTP fails, and `includes/mail_helper.php:27` prints the **"🔥 SMTP Error" dump** with server file paths. **[fatal] [confirmed]**
5. **(B-nullable)** The visitor tries again: *"This email is already registered. You already have an account. Please go to login."* **[confirmed]** But they can't log in (Journey 3 step 2) and never received a code.

### Journey 2: New member verifies their email
1. **(B-nullable)** `verify.php?email=…` → enter the 6-digit code (from the database here; from email in real life, which never arrives when SMTP fails).
2. The code is checked by AJAX. The account **is verified** (`is_verified = 1` confirmed) and the member **is logged in** (dashboard reachable afterwards) **[confirmed]**.
3. **[silent] [confirmed]** The admin-notification email (`verify.php:75`) fails, so the AJAX response is the SMTP HTML dump. The page's JSON parse throws (*Unexpected token '<'*) and **nothing on screen changes**: no success message, no redirect. The member doesn't know it worked.
4. The limiter (`verify.php:24-32`) counts wrong codes per IP in `/tmp` and **never expires**; the message says "wait 15 minutes" but only a successful verification clears it **[inferred, code-verified]**.

### Journey 3: Member logs in
1. Env A: **[fatal] [confirmed]** *no such column: username* (`login.php:57`).
2. Unverified account: *"Please verify your email address before logging in. Resend verification email"* **[confirmed]**. Good message.
3. **(B-nullable)** Verified account, by username or email → redirect to the dashboard **[confirmed]**.
4. **[quality] [confirmed]** The greeting reads **"Welcome back, Deprecated: htmlspecialchars(): Passing null to parameter…"**.
   - `$_SESSION['name']` is set from `users.name`, which registration never fills.
   - On PHP 8.1+ with errors displayed, that notice is printed into the greeting. With errors hidden it's "Welcome back, !".
   - **Every member who registered through the site gets this.**
5. "Remember me" stores a token and sets a cookie (`login.php:81-109`). **[wrong] [inferred, code-verified]** No code ever reads `remember_token`. When the host's session expires (~24 h) the member is logged out regardless. The cookie is also set without the `secure` flag (`:108`).

### Journey 4: Visitor mistypes their password
1. Attempts 1–2: *"Invalid username/email or password."* **[confirmed]**
2. **Attempt 3 onward: the SMTP error dump [fatal] [confirmed].** `login.php:135-139` emails the admin about "suspicious activity" on the 3rd failure, which `die()`s. The page now shows PHPMailer's file path and line number to an anonymous visitor.
3. After 5 attempts in 15 minutes the IP is blocked (`:44`). On shared hosting the counter file lives in the shared system temp directory and can vanish at any time **[inferred]**.

### Journey 5: Member forgets their password
1. `login.php:202` "Forgot password?" → `forgot_password.php` → email.
2. Unknown email: *"If an account with that email exists, a reset code has been sent."* **[confirmed]** Correctly non-revealing.
3. Known email: the reset code **is stored**, then `forgot_password.php:38` email → **SMTP dump [fatal] [confirmed]**. The rate-limit file is only written on success, so retrying is unthrottled.
   - The two responses differ (polite message vs. crash), which **reveals which emails have accounts**. So does registration's "already registered" message.
4. `forgot_password.php:77` "Go to Reset Password" → `reset_password.php`, which **starts again with its own "request a code" form** (`request_reset`, `:44-88`), duplicating step 1 **[confirmed: form fields `request_reset,email`]**.
5. Code plus new password (`reset_password`, `:92-140`): a wrong code says "Invalid reset code" (rate limited); the right code changes the password **[confirmed: new password verifies]**.
   - Then `:139` emails the admin → **SMTP dump [wrong] [confirmed]**. The member sees a crash after a successful reset.

### Journey 6: Visitor uses "Login with Google / Facebook"
1. A **Google** button on both the login page (`login.php:214`) and the signup page (`register.php:309`) links to `social_login.php?provider=Google`. (Opened with no provider, the script prints two unstyled links on a blank page, `:61-62` **[confirmed]**.)
2. `?provider=Google` → *"Social login error: Your application id is required in order to connect to Google"* (no keys in the test env) **[confirmed]**.
3. **[fatal] [inferred]** With keys configured, the OAuth code-for-token exchange is a server-side HTTPS call to Google/Facebook. Your plan forbids outbound calls, so it can't complete.
   - Even if it did, it sets only `$_SESSION['user_id']` (`:42`, `:51`), not `role` or `name`. A social login can't be recognised as admin, and gets the same blank-name greeting.

### Journey 7: Member logs out
1. `logout.php` clears the session, deletes cookies, redirects home **[confirmed]**.
2. The `remember_tokens` row is left in the database (never deleted, never read) **[inferred]**.

### Journey 8: Session expiry (host constraint)
1. PHP sessions use the host's default store and lifetime (`includes/auth.php:7-11` creates `tmp/sessions/` but the `session_save_path()` call is commented out).
2. After about 24 h the member is silently logged out mid-task. A form submitted after expiry (a review, a note) redirects to login and the typed text is lost **[inferred]**.
3. `redirectIfNotLoggedIn()` (`includes/auth.php:36-41`) doesn't pass a `redirect=` parameter, so after logging in again the member lands on the dashboard, not where they were **[inferred, code-verified]**.

### Journey 9: Anyone resets the admin password
1. `GET /reset_admin.php` → *"Password updated for admin@angelawrites.com to 'angel@2026'"* **[confirmed]**. No authentication. It's deployed by the FTP workflow on every push.
2. Combined with the seeded admin in `includes/db.php:217-224` (recreated whenever `users` is empty), there are two well-known admin credentials in the codebase.

### Journey 10: Admin runs `update_schema.php`
1. Admin-only (`:13-15`). Adds `profile_pic, phone, address, bio` and 4 tables (`user_tags`, `connections`, `invites`, `notifications`).
2. **[quality]** Still deployed after its own "Delete this file" instruction (`:86`). It's harmless when re-run (idempotent), but it uses `session_start()` without `includes/auth.php`, so it only works if a session already exists.

## Quality problems independent of the bugs above
- **Signup asks for far more than needed.** Gender, date of birth, phone and country are all *required* before someone can read a free poem. That's a conversion and privacy cost.
- **Two "resend code" pages** (`verify.php` resend and `resend_verification.php`) and **two "request reset" pages** (`forgot_password.php` and `reset_password.php` step 1).
- **Error pages leak internals:** SMTP dumps include absolute server paths and library line numbers.
- **The login page carries leftovers from the blog template:** a floating dark-mode button and a "reading progress bar" (`login.php:152-157`).
- **No password-strength feedback, no show-password toggle**, and the minimum is 8 characters.
- **Responsive:** register and login have no horizontal overflow at 768/375 px **[confirmed]**.

## Root causes
- **[code]**
  - `name` never set at registration.
  - Email sent synchronously in 7 auth paths with `die()` on failure.
  - Remember-me never read.
  - Social login sets no role or name.
  - `redirectIfNotLoggedIn()` loses the destination.
  - Duplicate flows.
  - Public `reset_admin.php`.
- **[schema]** `users` has 15+ columns the repo never creates (`username, is_verified, verification_code…`). `name` is `NOT NULL` with no default. `remember_tokens` is created on the fly.
- **[ux]** Excessive required fields, no success feedback on verification, crash after successful reset, account enumeration through differing responses.
- **[infra]**
  - Outbound SMTP and OAuth calls.
  - ~24 h sessions with no persistent login.
  - Rate-limit files in the shared temp dir.

## What a professional version looks like
- **J1 Register:** name, email, password (optional extras collected later on the profile page). On submit: "Check your inbox for a 6-digit code" screen. If email can't be sent, the account is still created and the screen offers "Resend code" with a clear "We couldn't send the email. Try again in a minute." Never a crash.
- **J2 Verify:** entering the code shows "✓ Email verified. Welcome, Ruth" and redirects to the dashboard within 2 s. Admin notifications are logged or queued, never inline.
- **J3 Login:** greeting uses the member's first name (falls back to username). "Remember me" keeps the member signed in for 30 days by reading the token when the session is missing.
- **J4 Wrong password:** the same polite message every time, then a clear lockout message with the time remaining. Admin alerts never affect the page.
- **J5 Reset:** one flow (email → code + new password on one screen) ending in "Password changed. Sign in." The same message whether or not the email exists.
- **J6 Social:** the Google buttons on login and signup are removed while outbound calls are impossible, so visitors aren't offered a path that can't work.
- **J8 Expiry:** any protected page redirects to `login.php?redirect=<that page>` and returns there after login. Long forms warn before the session expires.
- **J9:** `reset_admin.php` gone; seed credentials removed.

## Dependencies before this can be fixed
1. **Immediately:** delete `reset_admin.php` from the server and change the admin password. Do this before anything else.
2. Inspect the production `users` table. If `name` is `NOT NULL`, registration can't work until either the code fills `name` (e.g. "First Last") or the table is rebuilt. **Rebuilding means a manual SQLite step** on `data/site.db` (create new table → copy → rename), not phpMyAdmin.
3. Email strategy (domain 16). 7 of the auth steps depend on it.
4. Decide whether social login stays (needs outbound calls, so not on this plan).

## Blast radius
- **Every logged-in domain:** login/registration gate all member features. The blank-name greeting shows in the header, dashboard and index.
- **Domain 16 (email):** verification, reset, admin alerts.
- **Domain 3/4 (downloads, groups):** lost post-login redirect.
- **Domain 11 (profile):** first/last name vs `name` split; profile is where optional fields should move.
- **Domain 20 (infra):** sessions, temp-dir rate limits, deployed one-off scripts.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 register | S (fill `name`) / M (slimmer form + resilient email) | |
| J2 verify | S | Non-fatal email; show success. |
| J3 login/greeting/remember-me | M | Read the token on missing session; fix the greeting source. |
| J4 wrong password | S | Remove the inline admin email. |
| J5 reset | S–M | Merge duplicate pages; non-fatal email; uniform responses. |
| J6 social | S | Remove (or M+ on a different host). |
| J7 logout | S | Delete the token row. |
| J8 expiry/return | S | Pass `redirect=` and accept relative paths. |
| J9 reset_admin | S | Delete file, rotate password. |
| J10 update_schema | S | Remove from deploy. |

## Regression check against AUDIT.md
- `register.php:123/130` missing columns [schema]: **Yes** (Env A).
- `register.php:149` SMTP `die()` after insert: **Yes** (B-nullable).
- `login.php:57` missing columns [schema]: **Yes** (Env A).
- `login.php:135-139` admin email on 3rd failure → SMTP dump: **Yes.**
- Remember-me never read: **Yes.**
- `social_login.php` sets only `user_id`; needs outbound OAuth: **Yes.**
- `reset_admin.php` public password reset: **Yes**, confirmed again.
- `verify.php` selects `name`, which register never sets: **Different.** The visible effect is the "Deprecated: htmlspecialchars()" text in the greeting, and the `NOT NULL` constraint on `name` blocks registration outright. AUDIT.md missed that constraint.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `register.php` | COVERED | Journey 1 |
| `login.php` | COVERED | Journeys 3, 4 |
| `logout.php` | COVERED | Journey 7 |
| `verify.php` | COVERED | Journey 2 |
| `resend_verification.php` | COVERED | Journey 1 step 5; Quality (duplicate flow) |
| `forgot_password.php` | COVERED | Journey 5 steps 1–4 |
| `reset_password.php` | COVERED | Journey 5 steps 4–5 |
| `reset_admin.php` | COVERED | Journey 9 |
| `social_login.php` | COVERED | Journey 6 |
| `update_schema.php` | COVERED | Journey 10 |
| `includes/auth.php` | COVERED | Journey 8 |

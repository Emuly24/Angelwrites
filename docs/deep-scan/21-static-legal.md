# 21. Static and legal pages

> Test method as in `01-reader.md`. Note: Env A's `settings` table exists at this point because the earlier crawl opened `admin/settings.php`, which creates it. On a truly fresh database the About/Privacy/Terms pages fatal on `settings` (AUDIT.md, confirmed then).

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `404.php` | 80 | "Page not found" page with a search box | Y |
| `about.php` | 412 | About the author; reads `settings.about_content`; member reputation strip; contact form (form covered in 17) | Y |
| `accessibility.php` | 321 | Accessibility statement | Y |
| `privacy.php` | 268 | Privacy policy; reads `settings.privacy_content`, falls back to built-in text | Y |
| `terms.php` | 268 | Terms of service; reads `settings.terms_content`, falls back to built-in text | Y |

## User journeys

### Journey 1: Visitor follows a broken link
1. A non-existent URL returns the **server's default 404**, not `404.php` **[confirmed locally; inferred for Apache]**. `.htaccess` has no `ErrorDocument 404 /404.php`, so the custom page (with its search box) is never used.
2. Opening `404.php` directly shows the page with **HTTP 200**, not 404 **[confirmed]**. Search engines would index it as a normal page if it were ever linked.

### Journey 2: Visitor reads the About page
1. Guest: renders **[confirmed]** (Env A after `settings` exists; Env B).
2. Logged-in member, Env A: **[fatal] [confirmed]** *no such table: user_reputations* (`about.php:55`). The About page shows the *visitor's own* reputation, achievements and streak (`:55-65`), which is unrelated to the page's purpose, and that query breaks the page for every member.
3. Content: `about_content` is read from `settings`, but no admin screen can edit it (domain 19 J3). The page always shows its built-in text **[inferred, code-verified]**.
4. Contact form on this page: domain 17 J2 (saved, then SMTP dump).

### Journey 3: Visitor reads Privacy Policy / Terms
1. Both render with built-in text (the `*_content` settings keys can't be set from admin) **[confirmed]**.
2. **[wrong] [confirmed] "Last updated" is always today.** `privacy.php:59` and `terms.php:59` print `date('F j, Y')`, and `accessibility.php:118` does the same. Every visit claims the policy changed today, which defeats the point of the date and is legally meaningless.
3. **[wrong] [inferred, code-verified] The privacy text doesn't match what the site does.** It names "Zoho SMTP" for email (`privacy.php:20`), but mail goes through **Gmail** SMTP (`includes/mail_helper.php:12`). It doesn't mention:
   - **visitor IP addresses** stored for every poem and book view (`view_logs`)
   - **date of birth, gender, phone number and country**, required at registration (domain 10)
   - **camera photos and voice recordings** in comments and profiles (domains 11, 13)
   - **Google sign-in** (domain 10)
   - Google Fonts and other CDNs loaded on every page
   - that **notes marked "private" in the Bible reader are readable by other members** (domain 2)
4. There's no account deletion or data-export route for members, although the policy text mentions deletion once **[inferred]**.

### Journey 4: Visitor reads the Accessibility statement
1. Claims conformance with **WCAG 2.1 AA**, keyboard navigation and screen-reader support (JAWS, NVDA, VoiceOver) **[confirmed text]**.
2. **[wrong] [inferred]** Measured elsewhere in this scan:
   - The reader has **0 `aria-label`s** on 20 icon-only controls (01).
   - Phones get 15–40 sub-32 px tap targets per page.
   - The mobile menu opens off-screen (20).
   - The cookie banner covers controls (02, 20).
   - Errors and feedback use `alert()` and crash pages.

   A "skip to main content" link exists (`includes/header.php:258`), but the AA claim isn't supportable as the site stands.

## Quality problems independent of the bugs above
- **All five pages render without horizontal overflow at 375/768 px** **[confirmed]**; layout is fine.
- **Legal pages are generic templates** that don't describe this site's actual data handling.
- **About page mixes personal gamification into an author biography.**

## Root causes
- **[code]** Dynamic "last updated" dates; no `ErrorDocument`; `404.php` sends 200; About page runs member-stat queries; `*_content` keys with no editor.
- **[content]** Privacy/accessibility text doesn't reflect actual processors, data collected or the current accessibility state.
- **[schema]** `settings` (only created by an admin page), `user_reputations`, `achievements`, `reading_streaks` aren't created by the repo.

## What a professional version looks like
- **J1:** `.htaccess` sends all unknown URLs to `404.php`, which returns status 404, keeps the site header, and offers search plus links to Books, Poems and Blog.
- **J2:** About page shows only author content, editable from admin. No member queries.
- **J3:** Privacy and Terms have a fixed "Last updated: 12 March 2026" changed only when the text changes. The privacy text lists exactly what's collected (including IPs, signup fields, media uploads), the processors actually used (host, mail provider, fonts/CDNs), retention, and how to request deletion, with a working route.
- **J4:** The accessibility statement describes the real state and known issues, with a contact for help, until an audit backs a conformance claim.

## Dependencies before this can be fixed
1. Decide the actual email provider (domain 16) and the data you'll keep collecting at signup (domain 10). The privacy text depends on both.
2. Settings editing for page content (domain 19 J3) if these pages should be editable.
3. No database change is strictly needed if the About page's member queries are removed.

## Blast radius
- **Domain 10 (auth):** signup fields drive the privacy text.
- **Domain 16 (email):** processor named in the policy.
- **Domain 17 (contact):** About page hosts a contact form.
- **Domain 19 (settings):** content keys.
- **Domains 1, 2, 20:** accessibility claims vs measured gaps.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 404 | S | One `.htaccess` line plus `http_response_code(404)`. |
| J2 About | S | Remove member queries; optional settings field. |
| J3 Privacy/Terms | S (dates) / M (accurate text; a writing task, not code) | |
| J4 Accessibility | S | Rewrite the statement honestly. |

## Regression check against AUDIT.md
- `about.php:8`, `privacy.php:8`, `terms.php:8` fatal on missing `settings` (fresh DB): **Yes** on a fresh database; **No** once an admin has opened Settings (as in this run).
- `about.php:43` contact form `sendEmail()` → `die()`: **Yes** (domain 17).
- AUDIT.md didn't report the member-only About fatal (`user_reputations`), the always-today dates, the unused `404.php`, or the inaccurate privacy text. New.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `404.php` | COVERED | Journey 1 |
| `about.php` | COVERED | Journey 2 (contact form in 17) |
| `accessibility.php` | COVERED | Journey 4; Journey 3 step 2 (date) |
| `privacy.php` | COVERED | Journey 3 |
| `terms.php` | COVERED | Journey 3 |

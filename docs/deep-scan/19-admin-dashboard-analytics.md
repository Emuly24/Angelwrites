# 19. Admin dashboard, analytics & JSON endpoints

> Test method as in `01-reader.md`. Logged in as the admin (user 1) unless stated; the "guest" checks used no session at all.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `admin/dashboard.php` | 488 | Admin home: counts, charts, "deep dive" modals; polls stats and charts every 60 s | Y |
| `ajax_admin.php` | 122 | JSON for the dashboard: stats, charts, view-log and reading-log details. **No auth check** | Y |
| `admin/settings.php` | 461 | Site name, description, admin email, logo | Y |
| `admin/export_subscribers.php` | 24 | CSV export (journey in 16) | Y |
| `reader/admin/reader_analytics.php` | 398 | Admin reader analytics | Y |
| `reader/reader_analytics.php` | 436 | Second admin reader analytics page with date ranges | Y |

## User journeys

### Journey 1: Admin opens the dashboard
1. `admin/dashboard.php` renders without PHP errors in both environments **[confirmed]**. Non-admins are redirected to the homepage **[confirmed]**.
2. Counts for users, books, poems, sessions, posts and videos come from `ajax_admin.php?action=stats` **[confirmed]**. Env A: that endpoint fatals on the missing `videos` table, so the stat tiles stay empty.
3. Most activity figures are structurally zero: active readers today/week/month/year and reading hours come from `reading_sessions`, which only the dead reader writes (domain 1) **[wrong] [inferred, code-verified]**.
4. **Polling [infra] [inferred, code-verified]:** `setInterval(refreshStats, 60000)` (`:293`) and `setInterval(updateCharts, 60000)` (`:398`, two chart requests). That's about 3 requests per minute, **≈4,300 hits/day for one admin tab left open**, with no pause when the tab is hidden. Combined with a member dashboard tab (11), one person can burn ~15% of the daily allowance.
5. Charts depend on unpinned `cdn.jsdelivr.net/npm/chart.js` (same risk as 11).

### Journey 2: Anyone reads the admin data
1. **[wrong] [confirmed]** With **no session**, `ajax_admin.php` answers every action:
   - `stats`: user, book and poem counts and total views.
   - `active_chart`, `views_chart`.
   - `get_view_details`: the last 50 views, with **visitor IP addresses**, viewer names and timestamps.
   - `get_reading_details`: reader **names and email addresses** with reading sessions. Empty in the test only because no reading sessions exist.
2. This is a personal-data leak to anyone who knows or guesses the URL.

### Journey 3: Admin changes site settings
1. `admin/settings.php` form: site name, description, admin email, logo (upload or camera).
2. Env B: *"updated successfully!"* **[confirmed]**. Env A: values saved, then **[fatal] [confirmed]** *no such table: newsletter_audit_log* (`:81`, an audit write the repo never creates the table for).
3. **[wrong] [confirmed]** **The settings change nothing on the site.**
   - After renaming the site, the public pages still show the old name. `SITE_NAME` comes from `.env` (`includes/config.php:4`), and the header logo is hard-coded `assets/images/logo.png` (`includes/header.php:264`).
   - "Admin email" isn't used either: 107 places hard-code the Zoho address (AUDIT.md §3).
   - The only `settings` readers are `about.php`, `privacy.php` and `terms.php`. They read `about_content`, `privacy_content` and `terms_content`, **keys this settings page has no fields for**. Those three pages can only show their built-in fallback text (domain 21).

### Journey 4: Admin exports subscribers
Works: a proper CSV download (see 16 J7 step 4) **[confirmed]**.

### Journey 5: Admin views reader analytics
1. `reader/admin/reader_analytics.php` renders: "Total Reading Time 0 · Total Reading Sessions 0" **[confirmed]**. Env A fatals on `reading_sessions` **[confirmed]**. Always zero for the reason in J1 step 3.
2. `reader/reader_analytics.php` (the *second* analytics page): **[fatal] [confirmed]** missing include (`:10`). Behind that, every date filter except "all" builds invalid SQL (`FROM reading_sessions AND start_time >= …`, `:50`, `:54`) **[inferred, code-verified]**. The member dashboard also links members to this admin page (01 J5).

## Quality problems independent of the bugs above
- **Two reader-analytics pages and one dashboard, all reporting on data that's never written.** The admin can't learn anything useful about reading.
- **What's real** (poem/book views, user count) is buried among zeros.
- **No admin view of the things that need attention:** pending sessions, unanswered contact messages, new comments, prayer requests and "payments" are spread across separate screens or don't exist (08, 13, 15).
- **Settings page gives false confidence** (J3).
- **Admin pages render without overflow at 768/375 px**, except pages that print errors (crawl) **[confirmed]**.

## Root causes
- **[code]**
  - No auth on `ajax_admin.php`.
  - Settings written but never read.
  - Content keys read but never writable.
  - Duplicate analytics page with a broken include and SQL.
  - Polling without visibility checks.
  - Unpinned chart library.
- **[schema]** `videos`, `reading_sessions`, `view_logs`, `newsletter_audit_log` aren't created by the repo; `settings` only by `admin/settings.php` itself.
- **[ux]** No "needs attention" summary; misleading settings.
- **[infra]** Polling hit cost; full-table `COUNT`/`SUM` scans on every poll.

## What a professional version looks like
- **J1:** One admin home that loads once (no polling) and shows a **"Needs attention"** list: 3 session requests, 2 unread messages, 5 new comments, 1 prayer request, each linking to its screen. Below it, real numbers only (views this week, new members, top poems).
- **J2:** Every admin JSON endpoint checks `isAdmin()` and returns 403 otherwise.
- **J3:** Settings fields that actually drive the site (site name, logo, admin email, About/Privacy/Terms text), read in one place by the header, footer, mailer and those pages. Saving shows the change immediately.
- **J5:** One analytics page, or none, until reading data exists.

## Dependencies before this can be fixed
1. **Immediately:** add an admin check to `ajax_admin.php` (no other dependency).
2. Decide which settings are real, and where `SITE_NAME`/logo/admin email should come from (database vs `.env`).
3. Reader data (domain 1) before reading analytics can mean anything.
4. If kept, `newsletter_audit_log` must exist in production. **Create manually** (SQLite `data/site.db`, not phpMyAdmin; see 01), or remove the audit write.

## Blast radius
- **Domain 1:** source of reading data; members linked to admin analytics.
- **Domain 11:** member dashboard has the same polling pattern.
- **Domain 16:** export and audit log.
- **Domain 20/21:** header/footer/logo and About/Privacy/Terms content would read settings.
- **Domains 8, 13, 15, 17, 18:** items that belong in a "needs attention" list.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 dashboard | M | Remove polling; add a needs-attention list. |
| J2 auth leak | S | One check at the top of the file. |
| J3 settings | M | Wire settings into header/footer/mailer/legal pages. |
| J4 export | — | Works. |
| J5 analytics | S (delete duplicate) / M (meaningful) | |

## Regression check against AUDIT.md
- `ajax_admin.php` no auth, leaks emails and IPs: **Yes**, confirmed as a guest.
- `admin/dashboard.php:293/398` 60 s polling: **Yes.**
- `reader/reader_analytics.php:10` include and `:50/:54` SQL: **Yes.**
- AUDIT.md didn't report that settings are never read, or the `newsletter_audit_log` fatal on save. New.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `admin/dashboard.php` | COVERED | Journey 1 |
| `ajax_admin.php` | COVERED | Journeys 1, 2 |
| `admin/settings.php` | COVERED | Journey 3 |
| `admin/export_subscribers.php` | COVERED | Journey 4 (detail in 16) |
| `reader/admin/reader_analytics.php` | COVERED | Journey 5 |
| `reader/reader_analytics.php` | COVERED | Journey 5 |

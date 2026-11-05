# AngelWrites — Codebase Audit

**Date:** 2026-11-05 · **Commit audited:** `10b50f1` · **Scope:** every tracked file except `vendor/` (118 PHP files, `assets/css/style.css`, `assets/js/main.js`, `sw.js`, config files)

**No application files were modified.** The only file written is this report.

---

## 0. Read this first: three findings that change how to read everything else

### 0.1 The site uses SQLite, not MySQL

`includes/db.php:31` opens `new PDO('sqlite:' . __DIR__ . '/../data/site.db')`. Nothing in the repo uses `mysqli` or `mysql:`. The live database is the file `data/site.db` on the server. The FTP deploy excludes it (`.github/workflows/deploy.yml:23`), and `data/.htaccess` blocks HTTP access to it.

What this means for your constraint list:
- **"50k rows/table" and "remote DB access blocked"** are limits on InfinityFree's MySQL service, so they don't apply to this code as written. The SQLite file is bound by the account's disk, inode and file-size limits instead. It is also bound by SQLite's single-writer lock (see §6.7).
- **54 SQLite-only constructs in 22 files** (`INSERT OR IGNORE`, `RANDOM()`, `date('now', …)`, `sqlite_master`, `PRAGMA`, `strftime`) would break in a move to MySQL. One MySQL-only call already breaks on SQLite: `NOW()` at `worker.php:15`.

### 0.2 `/storage/exports/schema.sql` does not exist

That path doesn't exist, and there is no `.sql` file anywhere on this machine. So I couldn't compare the code against your live schema. Instead I built the schema the repo itself defines and ran the site against it:

- `includes/db.php` creates 13 tables. `admin/process_book.php`, `admin/settings.php`, `login.php`, `update_schema.php` and `video_watch.php` create 10 more at runtime.
- The code queries **86 distinct tables. 64 of them are not created anywhere in the repo**, marked `*` in §1. Examples: `reading_groups`, `group_members`, `highlights`, `reading_progress`, `user_reputations`, `testimonials`, `videos`, `reflection_comments`, `jobs`, `view_logs`.
- The `users` table in `db.php:42-52` has only `id, name, email, password, role, avatar, bio, created_at, updated_at`. The code also reads and writes `username, first_name, last_name, is_verified, verification_code, verification_code_expiry, reset_code, reset_code_expiry, referral_code, gender, country, contact_number, date_of_birth, referral_source, last_login, profile_pic, display_name`.

Your live `site.db` was probably changed by hand at some point, so some of these tables and columns may exist in production. Because of that, every failure in this audit carries one of two labels:
- **[code]**: fails whatever the schema is (missing file, undefined function, wrong include path, logic bug). These are certain.
- **[schema]**: fails against the schema this repo defines. Production may or may not hit it. Send me the real schema (download `data/site.db` over FTP and run `sqlite3 site.db .schema`) and I'll resolve each one.

### 0.3 How findings were verified

All 118 PHP files pass `php -l` (PHP 8.3). I exported the commit to a throwaway directory outside the repo and served it with `php -S`. Then I requested every page as a guest and as a logged-in reader, and POSTed to each feature's write path. Wherever this report says **(confirmed)**, I watched that failure happen. SMTP to Gmail was unreachable from the test box, which is a fair stand-in for a host that blocks outbound connections.

---

## 1. File-by-file inventory

"Mixes" means the file holds HTML output, request handling or business logic (`$_POST`/`$_GET`/`$_SESSION`, redirects, sending email), and SQL in the same file. **75 of 118 files mix all three, or HTML with SQL.** Most pages run their SQL at the top, interleave more queries with the HTML lower down (e.g. `community.php:519`), and carry their own `<style>` block.

There is no data-access layer, apart from a partial one in `includes/reading_groups.php`. There is no router and no templating. Helper functions such as `generate_csrf_token`, `rate_limit` and `get_image_url` are copy-pasted into several pages behind `function_exists` guards.

| Mixing category | Files |
|---|---|
| HTML + logic + SQL | 70 |
| HTML + SQL | 5 |
| Logic + SQL, no HTML (endpoints / libraries) | 18 |
| HTML + logic, no SQL | 4 |
| Presentation only | 11 |
| SQL only / logic only | 10 |

| # | File | Lines | What it does | Tables queried directly (`*` = not created anywhere in the repo) | Mixes presentation / logic / SQL? |
|---|---|---|---|---|---|
| 1 | `404.php` | 81 | Custom not-found page. | — | Presentation only |
| 2 | `about.php` | 413 | About-the-author page; reads site settings and author stats, also handles a contact-style form that emails admin. | achievements*, contact_messages, reading_streaks*, settings, user_reputations* | **Yes** — HTML + logic + SQL |
| 3 | `accessibility.php` | 322 | Static accessibility statement. | — | Presentation only |
| 4 | `admin/comments.php` | 568 | Admin moderation of poem reviews and book paragraph comments; admin replies. | book_comments, books, poems, reactions*, reviews, users | **Yes** — HTML + logic + SQL |
| 5 | `admin/dashboard.php` | 489 | Admin home: counts, charts, polls stats every 60 s. | blog_posts, books, contact_messages, poems, reading_sessions*, sessions, users, videos* | **Yes** — HTML + logic + SQL |
| 6 | `admin/editor.php` | 763 | Blog/reflection editor; can broadcast a post to every subscriber synchronously. | blog_posts, newsletter | **Yes** — HTML + logic + SQL |
| 7 | `admin/export_subscribers.php` | 25 | Streams the newsletter table as CSV. | newsletter | SQL only |
| 8 | `admin/manage_blog.php` | 923 | Blog post CRUD, bulk publish/draft/delete, live cover photo. | blog_posts | **Yes** — HTML + logic + SQL |
| 9 | `admin/manage_books.php` | 876 | Book CRUD with cover and book-file upload; emails admin. | books | **Yes** — HTML + logic + SQL |
| 10 | `admin/manage_community.php` | 178 | Moderate community questions, answers, reports. | answers, questions, reports*, users | **Yes** — HTML + logic + SQL |
| 11 | `admin/manage_groups.php` | 573 | Admin overview/delete of reading groups. | books, group_discussions*, group_members*, group_notes*, group_schedules*, newsletters*, poems, reading_groups*, users | **Yes** — HTML + logic + SQL |
| 12 | `admin/manage_groups_ajax.php` | 230 | AJAX for group actions (header comment is copy-pasted from group_ajax.php). Broken relative includes. | group_members*, group_notes*, group_schedules*, reading_groups* | Logic + SQL (no HTML) |
| 13 | `admin/manage_messages.php` | 385 | Inbox for contact-form messages; bulk read/delete. | contact_messages | **Yes** — HTML + logic + SQL |
| 14 | `admin/manage_newsletter.php` | 536 | Newsletter hub: compose, queue, send (first 10 only), segments, templates, archive, audit log; includes the tab partials. | email_attachments*, email_queue*, newsletter, newsletter_archive*, newsletter_audit_log*, newsletter_stats*, newsletter_templates*, subscriber_segment_assignments*, subscriber_segments* | **Yes** — HTML + logic + SQL |
| 15 | `admin/manage_poems.php` | 1090 | Poem CRUD, bulk status changes, image/audio upload. | poem_status, poems, reviews | **Yes** — HTML + logic + SQL |
| 16 | `admin/manage_questions.php` | 406 | Community question moderation, bulk actions. | answers, questions, users | **Yes** — HTML + logic + SQL |
| 17 | `admin/manage_reflections.php` | 207 | List/delete reflection-type blog posts. | blog_posts | **Yes** — HTML + logic + SQL |
| 18 | `admin/manage_sessions.php` | 400 | 1-on-1 session bookings: confirm/cancel/delete. | sessions, users | **Yes** — HTML + logic + SQL |
| 19 | `admin/manage_users.php` | 453 | User list, promote/demote, delete, emails admin. | users | **Yes** — HTML + logic + SQL |
| 20 | `admin/manage_videos.php` | 1046 | Video CRUD and upload. | videos* | **Yes** — HTML + logic + SQL |
| 21 | `admin/newsletter_preview.php` | 23 | Renders an archived newsletter. | newsletter_archive* | **Yes** — HTML + logic + SQL |
| 22 | `admin/newsletter_tabs/archive.php` | 51 | Partial: archive tab markup (expects variables from parent). | — | Presentation only |
| 23 | `admin/newsletter_tabs/audit.php` | 35 | Partial: audit-log tab markup. | — | Presentation only |
| 24 | `admin/newsletter_tabs/import.php` | 24 | Partial: CSV import form. | — | Presentation only |
| 25 | `admin/newsletter_tabs/queue.php` | 57 | Partial: email queue tab. | — | Presentation only |
| 26 | `admin/newsletter_tabs/segments.php` | 67 | Partial: segments tab; runs its own query. | subscriber_segment_assignments* | **Yes** — HTML + SQL |
| 27 | `admin/newsletter_tabs/send.php` | 156 | Partial: compose/send form + JS test-send. | — | Presentation only |
| 28 | `admin/poem_editor.php` | 679 | Rich editor for creating/updating a poem; emails admin. | poems | **Yes** — HTML + logic + SQL |
| 29 | `admin/preview_poem.php` | 529 | Admin preview of a poem with review/voice-review handling. Broken relative includes. | poem_reads*, poems, reviews, users | **Yes** — HTML + logic + SQL |
| 30 | `admin/process_book.php` | 2792 | 2,800-line book pipeline: PDF/EPUB/DOCX extraction, formatting, backups, collaborators, comments, history, exports, email. | activity_log*, book_backups, book_collaborators, book_comments, book_content*, book_content_history*, book_processing_queue*, books, formatting_presets, reading_progress* | **Yes** — HTML + logic + SQL |
| 31 | `admin/process_queue.php` | 74 | Drains email_queue (designed for CLI/cron). | email_attachments*, email_queue*, newsletter, newsletter_archive*, newsletter_stats* | **Yes** — HTML + logic + SQL |
| 32 | `admin/reflection_editor.php` | 729 | Editor for reflections (blog_posts subtype). | blog_posts | **Yes** — HTML + logic + SQL |
| 33 | `admin/settings.php` | 462 | Site settings key/value store; creates settings table on load. | newsletter_audit_log*, settings | **Yes** — HTML + logic + SQL |
| 34 | `ajax_admin.php` | 123 | JSON stats/charts/view-log/reading-log endpoints for admin dashboard. **No auth check.** | blog_posts, books, poems, reading_sessions*, reviews, sessions, users, videos*, view_logs* | Logic + SQL (no HTML) |
| 35 | `ajax_get_reactions.php` | 56 | JSON reaction counts for a target. | reactions* | Logic + SQL (no HTML) |
| 36 | `ajax_get_users.php` | 54 | JSON user-name autocomplete (logged-in only). | users | Logic + SQL (no HTML) |
| 37 | `ajax_toggle_reaction.php` | 70 | Toggle a like/love reaction. | reactions* | Logic + SQL (no HTML) |
| 38 | `ajax_user.php` | 225 | JSON stats/charts for the member dashboard. | blog_reads*, books, poem_reads*, poems, questions, reading_sessions*, reading_status, sessions, users, video_watches*, view_logs* | Logic + SQL (no HTML) |
| 39 | `bible_reader.php` | 1817 | 1,800-line Bible reader UI (reads SQLite Bible files via JS/AJAX). | — | HTML + logic (no SQL) |
| 40 | `bible_reader_ajax.php` | 328 | AJAX for Bible notes, bookmarks, reactions, positions, sessions, challenges. | bible_bookmarks*, bible_monthly_challenges*, bible_notes*, bible_reactions*, bible_reading_history*, bible_reading_positions*, bible_sessions*, users | **Yes** — HTML + logic + SQL |
| 41 | `blog.php` | 627 | Blog listing with search/category/sort. | blog_posts | **Yes** — HTML + logic + SQL |
| 42 | `blog_post.php` | 798 | Single blog post: comments (photo/voice), prayer requests, admin reply, reactions. | blog_posts, blog_reads*, comment_reactions*, prayer_requests*, reading_progress*, reflection_comments*, users | **Yes** — HTML + logic + SQL |
| 43 | `book.php` | 603 | Book detail page; reviews, review reactions, reputation sidebar. | achievements*, book_content*, books, reading_streaks*, review_reactions*, reviews, user_reputations*, users, view_logs* | **Yes** — HTML + logic + SQL |
| 44 | `book_session.php` | 496 | Book a 1-on-1 session; emails admin and user. | sessions, users | **Yes** — HTML + logic + SQL |
| 45 | `books.php` | 551 | Book catalogue with filters. | books | **Yes** — HTML + logic + SQL |
| 46 | `community.php` | 852 | Community Q&A: list/search/tag, ask, answer, accept, upvote, reputation sidebar. | answers, question_tags*, questions, tags*, user_reputations*, users | **Yes** — HTML + logic + SQL |
| 47 | `community_answer_delete.php` | 76 | Delete own answer. | answer_edit_history*, answers | **Yes** — HTML + logic + SQL |
| 48 | `community_answer_edit.php` | 83 | Edit own answer, keeps edit history. | answer_edit_history*, answers | **Yes** — HTML + logic + SQL |
| 49 | `community_delete.php` | 113 | Delete own question. | answers, questions | **Yes** — HTML + logic + SQL |
| 50 | `community_edit.php` | 131 | Edit own question. | answers, questions | **Yes** — HTML + logic + SQL |
| 51 | `community_report.php` | 61 | Report a question/answer; emails admin. | answers, questions, reports* | **Yes** — HTML + logic + SQL |
| 52 | `contact.php` | 325 | Contact form; stores message and emails admin + sender. | contact_messages | **Yes** — HTML + logic + SQL |
| 53 | `create_group.php` | 248 | Form to create a reading group (via includes/reading_groups.php). | — | HTML + logic (no SQL) |
| 54 | `dashboard.php` | 464 | Member dashboard: reading stats, streaks, reputation, polling charts. | blog_posts, blog_reads*, books, notifications, poem_reads*, poems, questions, reading_sessions*, reading_status, reading_streaks*, sessions, user_reputations*, users, video_watches*, videos* | **Yes** — HTML + logic + SQL |
| 55 | `download.php` | 94 | Gate for book file downloads (free or purchased). | books, purchases* | Logic + SQL (no HTML) |
| 56 | `forgot_password.php` | 116 | Request a 6-digit reset code by email. | users | **Yes** — HTML + logic + SQL |
| 57 | `generate_og_image.php` | 125 | GD-generated 1200×630 social preview image. | — | Logic only |
| 58 | `group.php` | 920 | Single reading-group page: content, notes, discussions, schedule. | book_content* | **Yes** — HTML + logic + SQL |
| 59 | `group_ajax.php` | 125 | AJAX for group membership, notes, discussions, progress. | group_members*, group_notes*, group_reading_progress*, group_schedules* | Logic + SQL (no HTML) |
| 60 | `groups.php` | 331 | My reading groups + join by invite code. | — | HTML + logic (no SQL) |
| 61 | `includes/admin_mail_helper.php` | 28 | sendAdminEmail() via Gmail SMTP (unused by any caller). | — | Logic only |
| 62 | `includes/auth.php` | 74 | Session start + isLoggedIn/isAdmin/redirect helpers. | — | Logic only |
| 63 | `includes/bible_lookup.php` | 74 | getBibleText() over the Bible SQLite files; also acts as a JSON endpoint when given GET params. | bible | Logic + SQL (no HTML) |
| 64 | `includes/config.php` | 10 | Constants from .env; SITE_URL hardcoded. | — | Logic only |
| 65 | `includes/db.php` | 265 | Opens SQLite data/site.db; runs 13 CREATE TABLE + 11 CREATE INDEX on every request; seeds default accounts. | answers, blog_posts, books, contact_messages, newsletter, poem_status, poems, questions, reading_status, reviews, sessions, users | SQL only |
| 66 | `includes/footer.php` | 408 | Footer, newsletter mini-form, cookie banner, JS includes. | — | Presentation only |
| 67 | `includes/header.php` | 521 | <head>, nav, notification count query. | notifications | **Yes** — HTML + logic + SQL |
| 68 | `includes/load_env.php` | 32 | .env parser. | — | Logic only |
| 69 | `includes/mail_helper.php` | 34 | sendEmail() via Gmail SMTP; die()s with error details on failure. | — | HTML + logic (no SQL) |
| 70 | `includes/reading_groups.php` | 425 | Reading-group data-access functions (create/join/list/notes/activity); defines time_ago(). | books, group_activity_log*, group_discussion_replies*, group_discussions*, group_members*, group_notes*, group_reactions*, group_reading_progress*, group_schedules*, newsletters*, poems, reading_groups*, users | Logic + SQL (no HTML) |
| 71 | `index.php` | 1230 | Homepage: featured content, stats, testimonials, newsletter + testimonial forms, full-page cache, service-worker registration. | blog_posts, books, newsletter, poems, prayer_requests*, reading_status, reviews, testimonials*, users | **Yes** — HTML + logic + SQL |
| 72 | `library.php` | 693 | Member library / reading status. | books, reading_status | **Yes** — HTML + logic + SQL |
| 73 | `login.php` | 400 | Login, file-based rate limit, remember-me token creation, failed-login admin alert. | remember_tokens, users | **Yes** — HTML + logic + SQL |
| 74 | `logout.php` | 41 | Destroys session and cookies. | — | Logic only |
| 75 | `newsletter.php` | 90 | Footer newsletter subscribe handler (POST then redirect). | newsletter | Logic + SQL (no HTML) |
| 76 | `notification_read.php` | 19 | Marks a notification read. | notifications | Logic + SQL (no HTML) |
| 77 | `notifications.php` | 374 | Notification centre. | notifications | **Yes** — HTML + logic + SQL |
| 78 | `poem_view.php` | 1117 | Single poem: view logging, threaded/private comments, reactions, OG meta, enqueue jobs. | jobs*, poem_reads*, poems, reactions*, reviews, users, view_logs* | **Yes** — HTML + logic + SQL |
| 79 | `poetry.php` | 353 | Poem listing. | poems | **Yes** — HTML + logic + SQL |
| 80 | `privacy.php` | 269 | Privacy policy (reads settings). | settings | **Yes** — HTML + SQL |
| 81 | `process_payment.php` | 94 | Records a mobile-money 'payment' as completed (no gateway). | payments* | **Yes** — HTML + logic + SQL |
| 82 | `profile.php` | 964 | Member profile editing, avatar crop/upload, stats. | poem_reads*, reading_status, reading_streaks*, user_reputations*, users, video_watches* | **Yes** — HTML + logic + SQL |
| 83 | `reader/admin/reader_analytics.php` | 399 | Admin reader analytics dashboard. | achievements*, books, reading_progress*, reading_sessions*, users | **Yes** — HTML + logic + SQL |
| 84 | `reader/book_data.php` | 114 | Loads a book's content/pages/chapters/progress for the reader; returns an array. | book_content*, books, reading_progress*, users | **Yes** — HTML + logic + SQL |
| 85 | `reader/cron/reader_reminders.php` | 80 | Cron-only reminder emails for inactive readers. | books, reader_admin_notifications*, reading_progress*, reading_sessions*, users | **Yes** — HTML + logic + SQL |
| 86 | `reader/includes/auth_check.php` | 34 | Reader auth/guest check (not included by reader.php). | users | Logic + SQL (no HTML) |
| 87 | `reader/progress_tracking.php` | 325 | Progress/achievement/streak functions + admin notification email. Broken include path. | achievements*, books, reader_admin_notifications*, reading_challenges*, reading_circles*, reading_progress*, reading_streaks*, users | **Yes** — HTML + logic + SQL |
| 88 | `reader/reader.php` | 1559 | 1,560-line e-reader: page flip, highlights, bookmarks, notes, TTS, share, challenges. | bookmarks*, group_members*, highlights*, reading_groups*, reading_status, reading_streaks*, user_settings* | **Yes** — HTML + logic + SQL |
| 89 | `reader/reader_ajax.php` | 867 | AJAX backend for the reader: position, bookmarks, highlights, comments, notes, circles, goals, reports. Broken include. | achievements*, book_comments, book_error_reports*, bookmarks*, books, group_members*, group_notes*, group_reactions*, highlights*, prayer_requests*, reader_admin_notifications*, reader_questions*, reading_challenges*, reading_circles*, reading_goals*, reading_progress*, reading_sessions*, reading_status, reading_streaks*, user_settings*, users | **Yes** — HTML + logic + SQL |
| 90 | `reader/reader_analytics.php` | 437 | Second admin analytics page (date filters). Broken include. | books, reading_progress*, reading_sessions*, users | **Yes** — HTML + logic + SQL |
| 91 | `reader/reader_audio.php` | 590 | Audio mode UI (browser speechSynthesis). | — | Presentation only |
| 92 | `reader/reader_challenges.php` | 419 | Monthly challenges partial (included by reader.php). | achievements*, reading_challenges* | **Yes** — HTML + logic + SQL |
| 93 | `reader/reader_circles.php` | 330 | Reading-circle API + invite emails. | books, group_notes*, reading_circle_invites*, reading_circles*, reading_groups*, users | **Yes** — HTML + logic + SQL |
| 94 | `reader/reader_export.php` | 158 | Exports a user's reader data (header says READER_ANALYTICS). | achievements*, bookmarks*, books, group_members*, group_notes*, group_reactions*, highlights*, reading_challenges*, reading_progress*, reading_sessions*, reading_status, reading_streaks*, users | Logic + SQL (no HTML) |
| 95 | `reader/reader_functions.php` | 334 | Reader helpers (formatDuration, time_ago, chapters, levels). | reading_sessions*, user_reputations* | **Yes** — HTML + SQL |
| 96 | `reader/reader_gamification.php` | 518 | XP/levels/achievements functions. | achievements*, book_comments, bookmarks*, group_notes*, highlights*, reading_challenges*, reading_progress*, reading_sessions*, reading_streaks*, user_reputations* | **Yes** — HTML + SQL |
| 97 | `reader/reader_notes.php` | 550 | Group notes panel partial/page. | group_members*, group_notes*, group_reactions*, reading_groups*, users | **Yes** — HTML + logic + SQL |
| 98 | `reader/reader_renderer.php` | 254 | Content-to-HTML renderer partial. | — | Presentation only |
| 99 | `reader/reader_share.php` | 244 | Share-dialog JS partial. | — | Logic only |
| 100 | `reader/reader_tts.php` | 544 | Text-to-speech partial (browser speechSynthesis). | — | Presentation only |
| 101 | `reflections.php` | 572 | Reflections listing (queries a 'reflections' table). | reflections* | **Yes** — HTML + logic + SQL |
| 102 | `register.php` | 575 | Signup form, creates user, emails 6-digit code + admin alert. | users | **Yes** — HTML + logic + SQL |
| 103 | `resend_verification.php` | 115 | Re-sends verification code. | users | **Yes** — HTML + logic + SQL |
| 104 | `reset_admin.php` | 23 | **Public script that resets the admin password to a hardcoded value and prints it.** | users | SQL only |
| 105 | `reset_password.php` | 416 | Enter reset code + new password. | users | **Yes** — HTML + logic + SQL |
| 106 | `search_results.php` | 486 | Site-wide search across books/poems/posts/videos/reflections. | — | **Yes** — HTML + logic + SQL |
| 107 | `serve_og.php` | 32 | Serves OG image for /img/{id} rewrite. Uses $db without including db.php. | poems | Logic + SQL (no HTML) |
| 108 | `session_cancel.php` | 161 | Member cancels a session booking. | sessions | **Yes** — HTML + logic + SQL |
| 109 | `session_edit.php` | 232 | Member edits a session booking. | sessions | **Yes** — HTML + logic + SQL |
| 110 | `social_login.php` | 64 | Google/Facebook login via Hybridauth. | users | Logic + SQL (no HTML) |
| 111 | `terms.php` | 269 | Terms of service (reads settings). | settings | **Yes** — HTML + SQL |
| 112 | `unsubscribe.php` | 181 | Unsubscribe by token or request link by email. | newsletter, newsletter_audit_log* | **Yes** — HTML + logic + SQL |
| 113 | `update_schema.php` | 87 | One-time migration (admin-only) adding columns/tables. | connections, invites, notifications, user_tags, users | **Yes** — HTML + logic + SQL |
| 114 | `verify.php` | 412 | Enter 6-digit verification code; resend; auto-login. | users | **Yes** — HTML + logic + SQL |
| 115 | `video_watch.php` | 370 | Single video with comments/reactions; ALTERs videos table at runtime. | users, video_comments*, video_reactions, videos* | **Yes** — HTML + logic + SQL |
| 116 | `videos.php` | 525 | Video listing. | videos* | **Yes** — HTML + logic + SQL |
| 117 | `videos_reactions.php` | 75 | Video reaction endpoint. | video_reactions, videos* | Logic + SQL (no HTML) |
| 118 | `worker.php` | 39 | Infinite-loop job worker for the jobs table (CLI daemon). Web-reachable. | jobs*, users | Logic + SQL (no HTML) |

---

## 2. Feature status

| Feature | Status | Where it fails (first failure, then the next ones you would hit) |
|---|---|---|
| **User registration** | **Broken** | **[schema]** `register.php:123` `SELECT id FROM users WHERE username = ?` → *no such column: username* (confirmed). The INSERT at `:130` uses 9 more columns that `db.php` doesn't define, and leaves the `NOT NULL` column `name` empty. **[code]** If the columns do exist: the user row is inserted (`:131`), then `sendEmail()` at `:149` calls Gmail SMTP. On any failure `includes/mail_helper.php:27` calls `die()` and prints the SMTP error, PHPMailer's file path and line number (confirmed). The user is then stuck. They exist but are unverified, so login refuses them (`login.php:64`). Re-registering fails with "email already registered" (`register.php:120`). Resend (`resend_verification.php:49`) and verify-resend (`verify.php:124`) need the same SMTP. **[code]** The rate-limit file (`:165`) is only written on success, so it never throttles failed attempts. |
| **User login** | **Broken** | **[schema]** `login.php:57` selects `username, is_verified` → *no such column: username* (confirmed). **[code]** After 3 failed attempts from one IP, `login.php:139` emails the admin. If SMTP fails, the login page is replaced by the SMTP error dump. **[code]** Remember-me writes a token (`login.php:100-108`) that nothing ever reads, so users are logged out whenever the PHP session expires (~24 h on your host). **[code]** `login.php:63` waives verification only for usernames `admin` and `Test User`. The admin that `db.php` seeds has no username and no `is_verified`. `social_login.php` needs outbound OAuth calls (`:36`), sets only `user_id` and not `role`/`name` (`:42`, `:51`), and `die()`s with the raw exception (`:58`). |
| **Commenting** | **Broken** (book reviews: partially working) | Four separate comment systems: **Poem comments**: **[schema]** for logged-in users `poem_view.php:116` (`poem_reads`) fatals before the form renders (confirmed). The INSERT at `:155` needs `reviews.parent_id/is_private/target_user_id`, and `:168` needs a `jobs` table. Those jobs are never processed (see §6.2). **[code] privacy bug:** `poem_view.php:180` sets `$admin_id = 1` and `:192` adds `OR ? = 1`, which is always true, so **every "private" comment is shown to every visitor**. **Book reviews** (`book.php:94`): the only write path that works on the repo schema. The review **is saved** (confirmed), then `book.php:109` `sendEmail()` → `die()`, so the user sees the SMTP error page instead of the redirect (confirmed). The saved review then **never appears**: the list query at `book.php:63-72` sub-selects the undefined `review_reactions` table, and the `catch` at `:83` silently returns an empty list. **Blog comments**: **[schema]** logged-in page view fatals at `blog_post.php:31` (`blog_reads`, confirmed), then at `:187` (`blog_posts.book_id` doesn't exist). **[code]** Photo and voice uploads are written to `'../assets/uploads/...'` (`:43`, `:85`), which is *outside* the web root, but stored as `assets/uploads/...` (`:47`, `:90`), so they 404. `sendEmail()` at `:73`/`:108` → `die()`. No CSRF token. **Video comments**: **[schema]** `videos` table missing, so `video_watch.php` fatals on load (confirmed). |
| **Joining the community** | **Broken** | The homepage "Join the Community — It's Free" button (`index.php:648`) goes to `register.php`, which has the registration failures above, and **[schema]** the homepage itself fatals at `index.php:83` (`testimonials`, confirmed). **Community Q&A** (`community.php`): **[code]** posting an answer inserts the row, then fatals at `community.php:137` *Call to undefined function awardReputation()* (confirmed). That function is not defined anywhere in the repo. Accepting an answer (`:58`) and upvoting (`:182`) die the same way. Asking a question saves, then `:108` `sendEmail()` → `die()` (confirmed). **[schema]** Any logged-in page view fatals at `:519` (`user_reputations`, confirmed). **Reading groups** (`groups.php:24` → `includes/reading_groups.php:36`): **[schema]** `groups.php` fatals on load at `includes/reading_groups.php:63` (`reading_groups`, confirmed). Even with those tables present, the same query LEFT JOINs a table `newsletters` (`:74`) and the column `poems.author` (`:66`). Nothing in the codebase creates or writes either one. **[code]** `admin/manage_groups_ajax.php:7` uses relative includes that resolve against `admin/`, so it fatals on every request (confirmed). |
| **Subscribing (newsletter)** | **Broken** | **[schema]** `newsletter.php:32` selects `unsubscribe_token` and `:41` writes `updated_at`. Neither is in `db.php:166-173`, so it fatals (confirmed). `unsubscribe.php:13` fails the same way (confirmed), and `:40` writes the undefined `newsletter_audit_log`. **[code]** `newsletter.php:9-16` redirects any logged-in user to `library.php` **before** processing the POST. The footer form (`includes/footer.php:66`) appears on every page, so for members it silently does nothing. **[code]** After the insert, `:63` and `:69` `sendEmail()` → `die()`, so the subscriber sees the SMTP dump and is never redirected back. **[code]** `:78`/`:89` builds `Location: SITE_URL . $_POST['redirect']`. A value like `@evil.example` becomes `https://angelwrites.gt.tc@evil.example`, which is an open redirect. **[code]** The homepage form (`index.php:117-160`) sits behind a full-page cache for guests (`:9-19`) that would serve another visitor's CSRF token, so validation (`:118`) would fail. It is currently inert only because the `cache/` directory doesn't exist (`:1225` warns). **[code]** Delivery: `admin/manage_newsletter.php:136` sends to the first 10 recipients, then marks the whole send `sent` (`:155`) and archives it with the full recipient count, so the rest are dropped silently. Blog broadcasts (`admin/editor.php:131`) put an unsubscribe link to `newsletter.php?unsubscribe=1&token=…`, a URL that `newsletter.php` doesn't handle. |
| **Book highlighting** | **Broken** | **[code]** `reader/reader_ajax.php:11` requires `../includes/reader_functions.php`, a file that doesn't exist (it lives at `reader/reader_functions.php`). **Every** reader AJAX call fatals, including `add_highlight` (`:145`), `get_highlights` (`:187`), save position and bookmarks (confirmed). **[code]** `reader/reader.php:500` includes `reader_challenges.php`, which includes `includes/reading_groups.php`. That file declares `time_ago()` (`:415`), which `reader/reader_functions.php:56` already declared, causing a fatal *Cannot redeclare time_ago()* (confirmed via `reader/reader_notes.php`, which hits the same pair). Everything after line 500 is never sent, **including the whole reader script**: highlight rendering `:613`, highlight saving `:1122`, the tooltip handlers. **[schema]** Before either of those, the reader fatals at `reader/book_data.php:22` (`u.username`, confirmed), and `highlights` (`reader.php:74`) is not created anywhere. |

---

## 3. Hardcoded credentials, connection strings and keys

**Good news first.** No third-party API keys, SMTP passwords or OAuth secrets are hardcoded. `GMAIL_USERNAME`, `GMAIL_APP_PASSWORD`, `GMAIL_ADMIN_APP_PASSWORD`, `GOOGLE_CLIENT_ID/SECRET` and `FACEBOOK_APP_ID/SECRET` all come from `.env` via `getenv()`. `.env` is gitignored and blocked by `.htaccess`. FTP credentials live in GitHub Actions secrets.

| Severity | Location | What is hardcoded |
|---|---|---|
| **Critical** | `reset_admin.php:5-6` | Admin email `admin@angelawrites.com` and plaintext password `angel@2026`. The script has **no authentication**. Anyone who opens `/reset_admin.php` resets the admin password to that value, and the page **prints it** (`:17`, `:21`) (confirmed on the test copy). If it creates the account, it creates it with role `admin` (`:19`). It is deployed by the FTP workflow. |
| **High** | `includes/db.php:217-234` | Seed accounts `admin@angelawrites.com` / `ChangeMe123!` (role admin) and `reader@example.com` / `Reader123!`. They are recreated automatically whenever `users` is empty, so wiping users brings back a known admin login. |
| **High** | `reader/cron/reader_reminders.php:13` | Cron "secret" `angelwrites_reader_cron`, passed in the query string (`?key=…`). |
| Medium | `poem_view.php:152`, `:180` | Admin user ID `1` (private comments are addressed to user 1, and admin visibility is keyed on 1). |
| Medium | `login.php:63` | Usernames `admin` and `Test User` skip email verification. |
| Low (connection string) | `includes/db.php:21`, `:31`; `includes/config.php:8` | DSN `sqlite:` + `__DIR__/../data/site.db`, defined twice (`DB_PATH` in config is never used). |
| Low (connection string) | `includes/bible_lookup.php:15`, `:22` | DSN `sqlite:` + `assets/bible/bible_*.db`. |
| Low (connection) | `includes/mail_helper.php:12-17`, `includes/admin_mail_helper.php:12-17` | SMTP host `smtp.gmail.com`, port 587, STARTTLS. Callers pass the From address `angelwrites@zohomail.com`, which Gmail will rewrite unless it is a verified alias. `register.php:145`'s comment says "via Zoho SMTP", but the code uses Gmail. |
| Low (config) | `includes/config.php:9` | `SITE_URL = 'https://angelwrites.gt.tc'`. `includes/db.php:16` has a fallback `https://yourdomain.com`. |
| Low (config) | 40 files, 107 occurrences | Admin notification address `angelwrites@zohomail.com`. `ADMIN_EMAIL` exists in `config.php:7` but is never used. `UPLOAD_PATH` and `BIBLE_PATH` aren't used either. |

---

## 4. SQL built by string concatenation

The codebase **mostly uses prepared statements with bound parameters correctly.** I found **no place where request input reaches SQL text unescaped.** These are every place where SQL text is assembled from variables:

| Location | What is concatenated | Injection risk | Note |
|---|---|---|---|
| `admin/manage_groups.php:23-27` | `"DELETE … WHERE id = $group_id"` ×5 via `exec()` | Low: `(int)` cast at `:22` | Should be bound params. Also no CSRF and no transaction; JSON is echoed after the header HTML was already sent (`:15`). |
| `reader/reader_analytics.php:26-34`, used at `:50`, `:54`, `:79` | `"AND start_time >= '$start_date'"` | None: server-generated date | **Functional bug:** `:50` and `:54` produce `SELECT … FROM reading_sessions AND start_time >= …`, with no `WHERE`. That is a syntax error for every range filter except "all". |
| `search_results.php:31-37`, `:90-103` | Column names, table names and WHERE fragments from a hardcoded config array; values bound | None | **Functional bug:** `buildSearchWhere()` emits one `?` per column but pushes *every* search term per column. With a 2+ word query the parameter count no longer matches the placeholders, so the query errors. |
| `video_watch.php:78-79` | `ORDER BY c.created_at $order` | None: whitelisted to `ASC`/`DESC` | — |
| `update_schema.php:27` | `ALTER TABLE users ADD COLUMN $col TEXT` | None: hardcoded list | — |
| `admin/comments.php:123-124`, `:173-174` | Count query derived from the select via `str_replace`/`preg_replace` | None | Fragile: if the select changes, the count query silently breaks. |
| `IN ($placeholders)` in `admin/manage_blog.php:33-43`, `manage_messages.php:43-47`, `manage_poems.php:52-72`, `manage_questions.php:66-83`, `manage_sessions.php:48-58`, `manage_users.php:83-96` | `implode(',', array_fill(…, '?'))` | None | Correct pattern. |
| Conditional `$sql .= " AND x = ?"` builders (`blog.php`, `books.php`, `poetry.php`, `library.php`, `videos.php`, `reflections.php`, `community.php`, admin list pages, `includes/reading_groups.php:136-155`, `reader/reader_ajax.php:197-203`) | Fixed SQL fragments; values bound; `ORDER BY` chosen by `switch` | None | `blog.php:76`, `videos.php:63` and `reflections.php:61` sort by a `comment_count` column that doesn't exist. `blog.php` fatals on load for that reason (confirmed: `blog.php:92`). |
| **Not SQL, but the same class of bug:** `admin/process_book.php:172` | `exec("$pdftotext_path … '$file_path' '$txt_path'")` | Shell injection if a stored file path ever contains `'` | The upload naming in `manage_books.php:101` strips unsafe characters, so the risk is low today. `exec` is almost certainly disabled on your host anyway; the code falls back to smalot/pdfparser. |

Related security issues found along the way (not SQL injection, but you should know about them):
- **`ajax_admin.php` has no auth check.** `?action=get_reading_details` returns the **names and email addresses** of readers. `?action=get_view_details` returns **visitor IP addresses**. `?action=stats` and the chart actions return site analytics.
- `admin/newsletter_tabs/*.php` have no auth check of their own. They are partials included by `manage_newsletter.php`, but they can be requested directly (they mostly render markup).
- `admin/manage_groups_ajax.php` checks only `redirectIfNotLoggedIn()`, not admin. It is currently dead because of its include bug.
- `worker.php` (an infinite loop) is web-reachable. Every hit ties up a PHP worker until the host kills it.
- `process_payment.php:58-63` records a payment as `completed` using the **client-supplied amount**. There is no gateway. Separately, `download.php` checks a different table (`purchases`, `:46-50`) from the one payments are written to (`payments`). So paid books can't be downloaded even after "paying", and the payment record proves nothing.
- `display_errors` is turned on in production at `book.php:8-9`, `reader/reader.php:8-10` and `admin/process_book.php:6-8`. Together with `mail_helper.php:27`, this leaks server paths.
- `assets/bible/*.db` (8 files, 41 MB in total, including NIV/ESV/NASB/NKJV/AMP) can be downloaded directly. That costs bandwidth, and those translations are under copyright licences.
- The `allow_url_fopen` file in the repo root contains `<?php phpinfo(); ?>`. It has no `.php` extension, so it is served as text, but it is leftover debug junk that gets deployed.

---

## 5. CSS audit

### Summary

| Metric | `style.css` (1,576 lines) | Inline in PHP | Distinct across both |
|---|---|---|---|
| Hex colours | **33 distinct** / 68 uses (16 outside the token blocks) | **121 distinct** / 1,188 uses | **135** |
| `px` font-sizes | **1 distinct** (`14px`, line 1389) | **1 distinct** (`16px` ×2, `admin/process_book.php`) | **2** |
| Font-sizes of any unit (context) | 21 distinct | 46 distinct | — |
| Margin values | **19 distinct** / 53 uses | **72 distinct** / 1,097 uses | **74** |
| Padding values | **38 distinct** / 49 uses | **113 distinct** / 1,050 uses | **117** |

What the numbers show:
- **Nearly all styling lives outside the stylesheet.** 70 of 118 PHP files carry their own `<style>` block (71 blocks), and there are 616 `style="…"` attributes. Inline CSS outnumbers `style.css` by about 17× on colour uses and about 20× on margin/padding uses.
- **The design tokens exist but are bypassed.** `style.css:15-81` defines `--rose`, `--text`, `--bg` and so on, with dark-mode overrides. **38 PHP files redefine `:root`** inline, and token colours such as `#f7f3ed` (49×), `#2c1e1e` (43×) and `#dba1a2` (28×) are pasted as literals. So dark mode can't reach those elements.
- **Several unrelated palettes are mixed in.** Bootstrap colours (`#dc3545`, `#28a745`, `#c82333`, `#218838`), Flat UI colours (`#e74c3c`, `#27ae60`, `#3498db`, `#f1c40f`, `#c0392b`) and greys (`#333`, `#444`, `#666`, `#999`, `#aaa`, `#ddd`, `#e0e0e0`, `#f9f9f9`, `#fdfdfd`) sit alongside the brand rose/vanilla palette. `#fff` and `#ffffff` are both used heavily.
- **Font sizes are already in `rem`**, so px font-sizes aren't a problem. Spacing is the opposite: almost entirely px, with no scale. `style.css` alone has 38 distinct padding values. The values cluster around 4/6/8/10/12/16/20/24/32/40 px, so a spacing scale could replace most of them.

Every value is listed below. For `style.css` each value comes with its line numbers. For inline CSS each value lists up to 6 of the files that use it, plus a count of the rest.

### A. `assets/css/style.css`

**Hex colours — 33 distinct, 68 occurrences.** 18 appear only inside the `:root` / dark-theme token blocks (lines 15–81, which is acceptable for design tokens); 15 are hardcoded in rules outside those blocks.

| Value | Count | Lines | Where |
|---|---|---|---|
| `#2c1e1e` | 8 | 22, 33, 45, 51, 56, 68, 73, 80 | token block |
| `#3d2e2e` | 5 | 23, 44, 57, 74, 79 | token block |
| `#1a1212` | 4 | 46, 50, 65, 77 | token block |
| `#b8a8a8` | 4 | 49, 59, 67, 76 | token block |
| `#f1c40f` | 4 | 1182, 1227, 1230, 1288 | **hardcoded in rule** |
| `#ffffff` | 3 | 21, 26, 31 | token block |
| `#ddd` | 3 | 1185, 1221, 1291 | **hardcoded in rule** |
| `#dba1a2` | 2 | 16, 41 | token block |
| `#c08a8b` | 2 | 17, 42 | token block |
| `#e8c0c0` | 2 | 18, 43 | token block |
| `#f7f3ed` | 2 | 20, 25 | token block |
| `#f0e8e8` | 2 | 47, 78 | token block |
| `#e8dddd` | 2 | 48, 66 | token block |
| `#4a3a3a` | 2 | 53, 70 | token block |
| `#0a0707` | 2 | 58, 75 | token block |
| `#e74c3c` | 2 | 235, 236 | **hardcoded in rule** |
| `#8b5a5a` | 2 | 759, 763 | **hardcoded in rule** |
| `#2ecc71` | 2 | 941, 976 | **hardcoded in rule** |
| `#efd8d6` | 1 | 19 | token block |
| `#6b5a5a` | 1 | 24 | token block |
| `#e5d5d5` | 1 | 28 | token block |
| `#f0ebe5` | 1 | 32 | token block |
| `#d4c8c8` | 1 | 34 | token block |
| `#2c1a1a` | 1 | 657 | **hardcoded in rule** |
| `#4d2e2e` | 1 | 667 | **hardcoded in rule** |
| `#7a4a4a` | 1 | 672 | **hardcoded in rule** |
| `#4a2e2e` | 1 | 676 | **hardcoded in rule** |
| `#b47a7a` | 1 | 748 | **hardcoded in rule** |
| `#9a5e5e` | 1 | 752 | **hardcoded in rule** |
| `#3a1e1e` | 1 | 760 | **hardcoded in rule** |
| `#3a2e2e` | 1 | 769 | **hardcoded in rule** |
| `#1e1414` | 1 | 774 | **hardcoded in rule** |
| `#e67e22` | 1 | 945 | **hardcoded in rule** |

**px font-sizes — 1 distinct.**

| Value | Count | Lines |
|---|---|---|
| `14px` | 1 | 1389 |

For context, style.css uses 21 distinct font-size values in total, mostly rem: `0.9rem`, `0.95rem`, `0.85rem`, `1rem`, `1.2rem`, `1.4rem`, `0.8rem`, `0.75rem`, `1.1rem`, `clamp(2.4rem, 5vw, 3.6rem)`, `1.15rem`, `4rem`, `3rem`, `1.6rem`, `3.5rem`, `clamp(1.8rem, 3vw, 2.6rem)`, `1.05rem`, `6rem`, `clamp(1.8rem, 3vw, 2.4rem)`, `14px`, `1.5rem`.

**margin values — 19 distinct, 53 occurrences.**

| Value | Count | Lines |
|---|---|---|
| `12px` | 11 | 403, 550, 630, 659, 671, 827, 1013, 1024, 1203, 1209, 1257 |
| `16px` | 7 | 366, 426, 557, 583, 1168, 1175, 1312 |
| `4px` | 7 | 563, 589, 620, 1271, 1277, 1318, 1348 |
| `0 auto` | 6 | 125, 701, 1079, 1301, 1307, 1416 |
| `0` | 3 | 9, 176, 1401 |
| `8px` | 3 | 394, 710, 1249 |
| `6px` | 3 | 411, 1007, 1285 |
| `24px` | 2 | 680, 1200 |
| `auto` | 1 | 374 |
| `32px` | 1 | 382 |
| `0 auto 24px` | 1 | 720 |
| `40px` | 1 | 840 |
| `2px` | 1 | 954 |
| `8px 0 12px` | 1 | 963 |
| `12px 0 24px` | 1 | 1045 |
| `16px 0 8px` | 1 | 1087 |
| `40px auto 0` | 1 | 1164 |
| `16px 0` | 1 | 1359 |
| `20px` | 1 | 1425 |

**padding values — 38 distinct, 49 occurrences.**

| Value | Count | Lines |
|---|---|---|
| `0` | 3 | 10, 177, 408 |
| `20px` | 3 | 473, 846, 1199 |
| `8px 14px` | 2 | 184, 458 |
| `6px 16px` | 2 | 200, 229 |
| `32px` | 2 | 523, 1302 |
| `6px 10px` | 2 | 568, 594 |
| `12px` | 2 | 610, 1418 |
| `24px` | 2 | 814, 996 |
| `40px 0` | 2 | 982, 1144 |
| `0 20px` | 1 | 126 |
| `6px 18px` | 1 | 215 |
| `0 4px` | 1 | 244 |
| `6px` | 1 | 261 |
| `4px` | 1 | 283 |
| `80px 30px 30px` | 1 | 334 |
| `12px 16px` | 1 | 346 |
| `48px 0 20px` | 1 | 373 |
| `8px 12px` | 1 | 445 |
| `80px 0 60px` | 1 | 642 |
| `4px 16px` | 1 | 669 |
| `12px 28px` | 1 | 736 |
| `8px 18px` | 1 | 781 |
| `4px 12px` | 1 | 934 |
| `16px` | 1 | 950 |
| `12px 18px` | 1 | 1094 |
| `0 12px` | 1 | 1141 |
| `10px 20px` | 1 | 1156 |
| `10px` | 1 | 1235 |
| `16px 20px` | 1 | 1255 |
| `8px 16px` | 1 | 1328 |
| `0.8rem 1.2rem` | 1 | 1495 |
| `0 0.3rem` | 1 | 1513 |
| `0.5rem 0` | 1 | 1523 |
| `0.5rem 1.2rem` | 1 | 1535 |
| `2rem` | 1 | 1550 |
| `3rem` | 1 | 1554 |
| `0.6rem 1.2rem` | 1 | 1560 |
| `0.5rem` | 1 | 1566 |

### B. Inline CSS inside PHP files (`<style>` blocks and `style=""` attributes)

**Hex colours — 121 distinct, 1188 occurrences.**

| Value | Count | Files |
|---|---|---|
| `#ffffff` | 68 | `about.php`, `accessibility.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php` … (+31 more) |
| `#fff` | 57 | `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_community.php`, `admin/manage_newsletter.php` … (+8 more) |
| `#e74c3c` | 52 | `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_newsletter.php`, `admin/manage_poems.php`, `admin/manage_questions.php`, `admin/manage_reflections.php` … (+25 more) |
| `#f7f3ed` | 49 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php` … (+19 more) |
| `#2c1e1e` | 43 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php` … (+19 more) |
| `#999` | 33 | `admin/dashboard.php`, `admin/manage_groups.php`, `admin/manage_poems.php`, `admin/newsletter_preview.php`, `admin/process_book.php`, `community.php` … (+2 more) |
| `#2a2a2a` | 32 | `admin/manage_blog.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php` … (+10 more) |
| `#1a1a1a` | 31 | `admin/manage_blog.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php` … (+10 more) |
| `#666` | 30 | `admin/dashboard.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php` … (+11 more) |
| `#333` | 29 | `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php` … (+14 more) |
| `#dba1a2` | 28 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php` … (+20 more) |
| `#efd8d6` | 27 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php` … (+18 more) |
| `#e5d5d5` | 27 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php` … (+19 more) |
| `#c08a8b` | 26 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php` … (+20 more) |
| `#3d2e2e` | 25 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php` … (+17 more) |
| `#dc3545` | 25 | `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/process_book.php` … (+5 more) |
| `#6b5a5a` | 23 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php` … (+17 more) |
| `#28a745` | 23 | `admin/dashboard.php`, `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php`, `admin/settings.php`, `bible_reader.php` … (+5 more) |
| `#e8c0c0` | 22 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php` … (+16 more) |
| `#f1c40f` | 21 | `admin/comments.php`, `admin/manage_blog.php`, `admin/manage_community.php`, `admin/manage_newsletter.php`, `admin/manage_reflections.php`, `admin/manage_sessions.php` … (+7 more) |
| `#c0392b` | 20 | `accessibility.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php` … (+13 more) |
| `#000` | 16 | `accessibility.php`, `admin/manage_blog.php`, `admin/manage_poems.php`, `admin/manage_videos.php` |
| `#444` | 16 | `admin/manage_blog.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php` … (+10 more) |
| `#aaa` | 16 | `admin/manage_blog.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php` … (+10 more) |
| `#ddd` | 15 | `admin/comments.php`, `admin/preview_poem.php`, `admin/process_book.php`, `book.php`, `poem_view.php`, `profile.php` … (+2 more) |
| `#27ae60` | 15 | `admin/manage_blog.php`, `admin/manage_newsletter.php`, `admin/manage_reflections.php`, `admin/manage_videos.php`, `book.php`, `books.php` … (+6 more) |
| `#fdf5e6` | 15 | `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php`, `blog.php` … (+7 more) |
| `#e0e0e0` | 15 | `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php`, `blog.php` … (+8 more) |
| `#3498db` | 14 | `admin/editor.php`, `admin/manage_newsletter.php`, `admin/manage_sessions.php`, `admin/manage_users.php`, `book_session.php`, `library.php` … (+6 more) |
| `#c82333` | 14 | `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/process_book.php`, `library.php` … (+1 more) |
| `#218838` | 14 | `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php`, `admin/settings.php`, `bible_reader.php`, `library.php` … (+1 more) |
| `#fdfdfd` | 14 | `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `bible_reader.php`, `blog.php` … (+8 more) |
| `#f9f9f9` | 13 | `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `bible_reader.php`, `blog.php`, `blog_post.php` … (+7 more) |
| `#a93226` | 12 | `admin/manage_questions.php`, `admin/manage_sessions.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `blog.php`, `blog_post.php` … (+6 more) |
| `#1a1212` | 11 | `about.php`, `accessibility.php`, `admin/dashboard.php`, `bible_reader.php`, `contact.php`, `dashboard.php` … (+5 more) |
| `#e8dddd` | 11 | `about.php`, `accessibility.php`, `admin/manage_blog.php`, `bible_reader.php`, `contact.php`, `poem_view.php` … (+5 more) |
| `#4a3a3a` | 11 | `about.php`, `accessibility.php`, `admin/dashboard.php`, `bible_reader.php`, `contact.php`, `dashboard.php` … (+5 more) |
| `#d4edda` | 11 | `about.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/process_book.php` … (+4 more) |
| `#2ecc71` | 11 | `admin/manage_community.php`, `admin/manage_messages.php`, `admin/manage_newsletter.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `book_session.php` … (+3 more) |
| `#f8d7da` | 10 | `about.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/process_book.php` … (+4 more) |
| `#721c24` | 10 | `about.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/process_book.php` … (+4 more) |
| `#f5c6cb` | 10 | `about.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/process_book.php` … (+4 more) |
| `#155724` | 10 | `about.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/process_book.php` … (+4 more) |
| `#c3e6cb` | 9 | `about.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/process_book.php`, `admin/settings.php` … (+3 more) |
| `#a08a8a` | 8 | `about.php`, `accessibility.php`, `bible_reader.php`, `contact.php`, `privacy.php`, `profile.php` … (+2 more) |
| `#eee` | 8 | `admin/dashboard.php`, `admin/process_book.php`, `dashboard.php` |
| `#ffc107` | 8 | `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php`, `admin/settings.php` |
| `#e0a800` | 8 | `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php`, `admin/settings.php` |
| `#ccc` | 6 | `admin/dashboard.php`, `dashboard.php` |
| `#17a2b8` | 6 | `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php` |
| `#138496` | 6 | `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php` |
| `#991b1b` | 6 | `includes/mail_helper.php`, `login.php`, `resend_verification.php`, `reset_password.php`, `verify.php` |
| `#dc2626` | 5 | `admin/editor.php`, `admin/manage_newsletter.php`, `admin/manage_videos.php`, `admin/reflection_editor.php`, `create_group.php` |
| `#fee2e2` | 5 | `includes/mail_helper.php`, `login.php`, `resend_verification.php`, `reset_password.php`, `verify.php` |
| `#e0d0d0` | 4 | `admin/dashboard.php`, `dashboard.php` |
| `#212529` | 4 | `admin/manage_books.php`, `admin/manage_poems.php`, `admin/process_book.php`, `admin/settings.php` |
| `#1877f2` | 4 | `admin/preview_poem.php`, `blog_post.php`, `book.php`, `poem_view.php` |
| `#1da1f2` | 4 | `admin/preview_poem.php`, `blog_post.php`, `book.php`, `poem_view.php` |
| `#25d366` | 4 | `admin/preview_poem.php`, `blog_post.php`, `book.php`, `poem_view.php` |
| `#f87171` | 4 | `login.php`, `resend_verification.php`, `reset_password.php`, `verify.php` |
| `#d1fae5` | 4 | `login.php`, `resend_verification.php`, `reset_password.php`, `verify.php` |
| `#065f46` | 4 | `login.php`, `resend_verification.php`, `reset_password.php`, `verify.php` |
| `#34d399` | 4 | `login.php`, `resend_verification.php`, `reset_password.php`, `verify.php` |
| `#888` | 3 | `admin/dashboard.php`, `dashboard.php` |
| `#1e1414` | 3 | `admin/editor.php`, `admin/poem_editor.php`, `admin/reflection_editor.php` |
| `#ff3b30` | 3 | `admin/manage_blog.php`, `admin/manage_poems.php`, `admin/manage_videos.php` |
| `#95a5a6` | 3 | `admin/manage_community.php`, `admin/manage_newsletter.php` |
| `#e3f2fd` | 3 | `admin/manage_groups.php`, `group.php`, `groups.php` |
| `#0d47a1` | 3 | `admin/manage_groups.php`, `group.php`, `groups.php` |
| `#f3e5f5` | 3 | `admin/manage_groups.php`, `group.php`, `groups.php` |
| `#4a148c` | 3 | `admin/manage_groups.php`, `group.php`, `groups.php` |
| `#e8f5e9` | 3 | `admin/manage_groups.php`, `group.php`, `groups.php` |
| `#1b5e20` | 3 | `admin/manage_groups.php`, `group.php`, `groups.php` |
| `#e67e22` | 3 | `admin/manage_questions.php`, `profile.php` |
| `#f8f9fa` | 3 | `admin/process_book.php` |
| `#6c757d` | 3 | `bible_reader.php`, `group.php` |
| `#000000` | 2 | `accessibility.php` |
| `#ffd700` | 2 | `admin/comments.php`, `poem_view.php` |
| `#f0dad9` | 2 | `admin/dashboard.php`, `dashboard.php` |
| `#3a2a2a` | 2 | `admin/dashboard.php`, `dashboard.php` |
| `#f1f3f5` | 2 | `admin/process_book.php`, `groups.php` |
| `#f5f0e1` | 2 | `bible_reader.php` |
| `#faf6ed` | 2 | `bible_reader.php` |
| `#d4c5a9` | 2 | `bible_reader.php` |
| `#f4e8d1` | 2 | `bible_reader.php` |
| `#db4437` | 2 | `login.php`, `register.php` |
| `#c23321` | 2 | `login.php`, `register.php` |
| `#f39c12` | 2 | `profile.php` |
| `#fdf5ec` | 2 | `reader/reader.php` |
| `#fff9c4` | 2 | `reader/reader.php` |
| `#c8e6c9` | 2 | `reader/reader.php` |
| `#bbdefb` | 2 | `reader/reader.php` |
| `#f8bbd0` | 2 | `reader/reader.php` |
| `#e8d5f5` | 2 | `reader/reader.php` |
| `#922b21` | 1 | `accessibility.php` |
| `#f5f0e8` | 1 | `accessibility.php` |
| `#faf3ef` | 1 | `accessibility.php` |
| `#222222` | 1 | `accessibility.php` |
| `#333333` | 1 | `accessibility.php` |
| `#fcfcfc` | 1 | `accessibility.php` |
| `#2980b9` | 1 | `admin/editor.php` |
| `#fff3cd` | 1 | `admin/manage_poems.php` |
| `#856404` | 1 | `admin/manage_poems.php` |
| `#fff3b0` | 1 | `bible_reader.php` |
| `#ffeb3b` | 1 | `bible_reader.php` |
| `#a5d6a7` | 1 | `bible_reader.php` |
| `#90caf9` | 1 | `bible_reader.php` |
| `#f48fb1` | 1 | `bible_reader.php` |
| `#3d2b1f` | 1 | `bible_reader.php` |
| `#6b4f3a` | 1 | `bible_reader.php` |
| `#4a3728` | 1 | `bible_reader.php` |
| `#7a5a3a` | 1 | `bible_reader.php` |
| `#f5e0df` | 1 | `books.php` |
| `#495057` | 1 | `groups.php` |
| `#fffdf9` | 1 | `reader/reader.php` |
| `#fbf3e9` | 1 | `reader/reader.php` |
| `#4a3d36` | 1 | `reader/reader.php` |
| `#d9c9b8` | 1 | `reader/reader.php` |
| `#3b82f6` | 1 | `videos.php` |
| `#ef4444` | 1 | `videos.php` |
| `#8b5cf6` | 1 | `videos.php` |

**px font-sizes — 1 distinct, 2 occurrences.**

| Value | Count | Files |
|---|---|---|
| `16px` | 2 | `admin/process_book.php` |

**margin values — 72 distinct, 1097 occurrences.**

| Value | Count | Files |
|---|---|---|
| `0` | 138 | `404.php`, `about.php`, `accessibility.php`, `admin/dashboard.php`, `admin/editor.php`, `admin/manage_blog.php` … (+45 more) |
| `8px` | 138 | `404.php`, `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/editor.php` … (+44 more) |
| `16px` | 118 | `404.php`, `about.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/editor.php`, `admin/manage_blog.php` … (+47 more) |
| `12px` | 110 | `404.php`, `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/editor.php` … (+32 more) |
| `4px` | 101 | `about.php`, `accessibility.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_books.php`, `admin/manage_groups.php` … (+39 more) |
| `24px` | 89 | `404.php`, `admin/comments.php`, `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_groups.php` … (+41 more) |
| `6px` | 51 | `about.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/editor.php`, `admin/manage_books.php`, `admin/manage_newsletter.php` … (+25 more) |
| `20px` | 43 | `admin/comments.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php` … (+24 more) |
| `32px` | 40 | `404.php`, `about.php`, `accessibility.php`, `admin/poem_editor.php`, `admin/preview_poem.php`, `blog.php` … (+21 more) |
| `0 auto` | 39 | `404.php`, `about.php`, `admin/dashboard.php`, `admin/editor.php`, `admin/manage_books.php`, `admin/manage_newsletter.php` … (+21 more) |
| `10px` | 30 | `admin/dashboard.php`, `admin/editor.php`, `admin/manage_poems.php`, `admin/manage_videos.php`, `admin/poem_editor.php`, `admin/preview_poem.php` … (+8 more) |
| `28px` | 18 | `accessibility.php`, `admin/dashboard.php`, `create_group.php`, `dashboard.php`, `group.php`, `groups.php` … (+1 more) |
| `0 0 4px` | 15 | `book.php`, `books.php`, `forgot_password.php`, `index.php`, `library.php`, `login.php` … (+6 more) |
| `12px 0` | 12 | `admin/preview_poem.php`, `admin/process_book.php`, `books.php`, `poem_view.php`, `profile.php`, `reader/reader.php` |
| `2px` | 11 | `admin/manage_books.php`, `admin/manage_groups.php`, `blog.php`, `groups.php`, `index.php`, `poetry.php` … (+4 more) |
| `16px 0` | 9 | `accessibility.php`, `bible_reader.php`, `blog_post.php`, `community.php`, `poem_view.php`, `reader/reader_challenges.php` |
| `8px 0` | 8 | `admin/comments.php`, `community.php`, `group.php`, `reader/reader.php`, `reader/reader_challenges.php`, `reader/reader_renderer.php` … (+1 more) |
| `0 auto 32px` | 7 | `admin/preview_poem.php`, `index.php`, `poem_view.php` |
| `8px 0 12px` | 6 | `404.php`, `accessibility.php`, `library.php`, `privacy.php`, `reader/reader_notes.php`, `terms.php` |
| `0 0 4px 0` | 6 | `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/settings.php`, `poetry.php` |
| `48px auto 0` | 6 | `admin/preview_poem.php`, `blog_post.php`, `poem_view.php`, `privacy.php`, `terms.php` |
| `auto` | 5 | `about.php`, `includes/header.php`, `poetry.php`, `search_results.php` |
| `20px 0` | 5 | `about.php`, `index.php`, `login.php`, `register.php` |
| `4px 0` | 5 | `admin/dashboard.php`, `dashboard.php`, `includes/header.php`, `reader/reader.php`, `reader/reader_challenges.php` |
| `0 0 8px` | 5 | `admin/process_book.php`, `bible_reader.php`, `group.php`, `library.php`, `reader/reader_challenges.php` |
| `48px` | 4 | `about.php`, `library.php` |
| `0 0 12px 0` | 4 | `admin/dashboard.php`, `dashboard.php`, `poem_view.php`, `video_watch.php` |
| `40px` | 4 | `admin/dashboard.php`, `dashboard.php`, `privacy.php`, `terms.php` |
| `6px 0` | 4 | `admin/preview_poem.php`, `poem_view.php`, `reader/reader.php`, `reader/reader_renderer.php` |
| `16px auto` | 3 | `admin/preview_poem.php`, `index.php`, `poem_view.php` |
| `2px 0 6px` | 3 | `admin/preview_poem.php`, `blog_post.php`, `poem_view.php` |
| `0 0 6px` | 3 | `group.php`, `index.php` |
| `15px` | 3 | `register.php`, `verify.php` |
| `24px 0 12px` | 2 | `about.php`, `blog_post.php` |
| `0 0 16px 0` | 2 | `admin/dashboard.php`, `dashboard.php` |
| `0 auto 24px` | 2 | `admin/preview_poem.php`, `poem_view.php` |
| `32px auto 0` | 2 | `admin/preview_poem.php`, `poem_view.php` |
| `8px 16px` | 2 | `bible_reader.php`, `reader/reader.php` |
| `0 0 2px` | 2 | `contact.php`, `library.php` |
| `0 0 12px` | 2 | `group.php`, `index.php` |
| `14px` | 2 | `group.php` |
| `0 0 10px` | 2 | `groups.php` |
| `40px auto` | 2 | `includes/mail_helper.php`, `reader/reader_notes.php` |
| `8px auto 0` | 2 | `index.php` |
| `4px 0 12px` | 2 | `poetry.php`, `reader/reader_challenges.php` |
| `var(--sidebar-width)` | 2 | `reader/reader.php` |
| `0 auto 40px auto` | 2 | `reader/reader.php` |
| `25px` | 2 | `register.php`, `verify.php` |
| `12px auto 0` | 1 | `about.php` |
| `0 6px` | 1 | `about.php` |
| `0 auto 12px` | 1 | `about.php` |
| `0 auto 48px` | 1 | `about.php` |
| `24px 0` | 1 | `blog_post.php` |
| `40px auto 0` | 1 | `blog_post.php` |
| `0 auto 8px` | 1 | `blog_post.php` |
| `4px 0 0` | 1 | `book_session.php` |
| `16px auto 0` | 1 | `contact.php` |
| `0 auto 10px` | 1 | `group.php` |
| `0 4px` | 1 | `includes/header.php` |
| `20px 0 24px` | 1 | `index.php` |
| `0 auto 18px` | 1 | `index.php` |
| `2px 0 0 0` | 1 | `library.php` |
| `8px 0 16px` | 1 | `login.php` |
| `2px 0 4px` | 1 | `notifications.php` |
| `24px 0 32px` | 1 | `poem_view.php` |
| `px` | 1 | `poem_view.php` |
| `0 auto 16px` | 1 | `profile.php` |
| `1.2rem` | 1 | `reader/reader.php` |
| `8px 0 4px` | 1 | `reader/reader_challenges.php` |
| `20px auto` | 1 | `reader/reader_notes.php` |
| `8px 0 0` | 1 | `search_results.php` |
| `4px 0 8px` | 1 | `video_watch.php` |

**padding values — 113 distinct, 1050 occurrences.**

| Value | Count | Files |
|---|---|---|
| `20px` | 65 | `about.php`, `accessibility.php`, `admin/dashboard.php`, `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_newsletter.php` … (+36 more) |
| `12px` | 55 | `admin/comments.php`, `admin/dashboard.php`, `admin/manage_books.php`, `admin/manage_videos.php`, `admin/newsletter_tabs/import.php`, `admin/newsletter_tabs/send.php` … (+28 more) |
| `8px 12px` | 48 | `admin/comments.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_newsletter.php`, `admin/manage_poems.php`, `admin/manage_questions.php` … (+15 more) |
| `0` | 45 | `about.php`, `accessibility.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/manage_videos.php` … (+22 more) |
| `16px` | 45 | `admin/comments.php`, `admin/dashboard.php`, `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_groups.php` … (+27 more) |
| `24px` | 41 | `404.php`, `accessibility.php`, `admin/comments.php`, `admin/editor.php`, `admin/manage_books.php`, `admin/manage_groups.php` … (+23 more) |
| `32px 0 60px` | 40 | `admin/comments.php`, `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php` … (+34 more) |
| `12px 16px` | 37 | `about.php`, `admin/editor.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_poems.php` … (+20 more) |
| `8px 20px` | 32 | `accessibility.php`, `admin/comments.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php` … (+19 more) |
| `8px` | 26 | `admin/comments.php`, `admin/dashboard.php`, `admin/manage_videos.php`, `admin/newsletter_tabs/import.php`, `admin/process_book.php`, `bible_reader.php` … (+8 more) |
| `4px 12px` | 25 | `about.php`, `admin/comments.php`, `admin/dashboard.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_community.php` … (+16 more) |
| `4px 8px` | 25 | `admin/manage_blog.php`, `admin/manage_questions.php`, `admin/manage_reflections.php`, `admin/manage_sessions.php`, `admin/manage_videos.php`, `admin/process_book.php` … (+9 more) |
| `14px 20px` | 24 | `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_newsletter.php`, `admin/manage_poems.php`, `admin/manage_reflections.php` … (+8 more) |
| `8px 16px` | 23 | `accessibility.php`, `admin/comments.php`, `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_poems.php`, `admin/manage_questions.php` … (+11 more) |
| `10px` | 23 | `admin/dashboard.php`, `admin/manage_books.php`, `admin/preview_poem.php`, `admin/process_book.php`, `admin/settings.php`, `blog.php` … (+10 more) |
| `6px 14px` | 22 | `admin/comments.php`, `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_groups.php`, `admin/manage_messages.php` … (+16 more) |
| `32px` | 21 | `about.php`, `admin/dashboard.php`, `admin/manage_poems.php`, `admin/preview_poem.php`, `blog_post.php`, `book_session.php` … (+15 more) |
| `2px 10px` | 20 | `admin/comments.php`, `admin/dashboard.php`, `admin/manage_questions.php`, `admin/manage_users.php`, `admin/preview_poem.php`, `blog.php` … (+8 more) |
| `10px 16px` | 19 | `404.php`, `about.php`, `admin/manage_newsletter.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `blog_post.php` … (+7 more) |
| `12px 28px` | 18 | `about.php`, `accessibility.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php` … (+12 more) |
| `16px 20px` | 17 | `admin/manage_poems.php`, `admin/preview_poem.php`, `bible_reader.php`, `blog_post.php`, `book_session.php`, `community.php` … (+7 more) |
| `2px 12px` | 16 | `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_newsletter.php`, `admin/manage_users.php`, `blog.php`, `blog_post.php` … (+7 more) |
| `10px 14px` | 16 | `admin/manage_newsletter.php`, `admin/poem_editor.php`, `book_session.php`, `books.php`, `forgot_password.php`, `group.php` … (+6 more) |
| `10px 24px` | 15 | `404.php`, `accessibility.php`, `admin/editor.php`, `admin/poem_editor.php`, `admin/reflection_editor.php`, `admin/settings.php` … (+5 more) |
| `6px 16px` | 14 | `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_poems.php`, `admin/manage_videos.php`, `admin/settings.php`, `bible_reader.php` … (+8 more) |
| `40px 0` | 13 | `admin/manage_blog.php`, `admin/manage_newsletter.php`, `admin/manage_questions.php`, `admin/manage_reflections.php`, `admin/manage_sessions.php`, `admin/manage_videos.php` … (+7 more) |
| `4px 10px` | 12 | `admin/manage_blog.php`, `admin/manage_groups.php`, `admin/manage_questions.php`, `admin/manage_reflections.php`, `admin/manage_sessions.php`, `admin/manage_videos.php` … (+6 more) |
| `6px 10px` | 11 | `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/manage_videos.php`, `bible_reader.php` … (+1 more) |
| `4px 0` | 11 | `admin/manage_books.php`, `admin/process_book.php`, `dashboard.php`, `includes/header.php`, `poem_view.php`, `reader/reader.php` … (+1 more) |
| `30px` | 10 | `admin/editor.php`, `admin/manage_blog.php`, `admin/manage_poems.php`, `admin/manage_videos.php`, `admin/poem_editor.php`, `admin/reflection_editor.php` |
| `10px 20px` | 10 | `admin/manage_blog.php`, `admin/manage_groups.php`, `admin/manage_newsletter.php`, `admin/manage_videos.php`, `admin/process_book.php`, `blog_post.php` … (+4 more) |
| `6px 12px` | 10 | `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/manage_videos.php` … (+4 more) |
| `20px 24px` | 10 | `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `admin/preview_poem.php`, `admin/settings.php` … (+3 more) |
| `40px 20px` | 10 | `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_poems.php`, `admin/manage_users.php`, `blog.php`, `community.php` … (+4 more) |
| `0 4px` | 10 | `bible_reader.php`, `includes/header.php`, `index.php`, `reader/reader.php` |
| `12px 20px` | 9 | `bible_reader.php`, `book.php`, `includes/footer.php`, `library.php`, `poem_view.php`, `reader/reader.php` … (+2 more) |
| `40px 0 80px` | 8 | `about.php`, `accessibility.php`, `book.php`, `contact.php`, `library.php`, `privacy.php` … (+2 more) |
| `2px 8px` | 8 | `admin/comments.php`, `blog.php`, `dashboard.php`, `group.php`, `poem_view.php`, `reader/reader_notes.php` … (+2 more) |
| `8px 0` | 8 | `blog.php`, `community_delete.php`, `includes/header.php`, `reader/reader.php`, `reflections.php`, `session_cancel.php` |
| `14px` | 7 | `admin/dashboard.php`, `books.php`, `dashboard.php`, `register.php`, `session_edit.php`, `unsubscribe.php` … (+1 more) |
| `10px 12px` | 7 | `admin/dashboard.php`, `admin/manage_groups.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `reader/reader_analytics.php`, `video_watch.php` |
| `4px` | 7 | `admin/manage_blog.php`, `admin/manage_books.php`, `admin/manage_poems.php`, `admin/manage_videos.php`, `login.php`, `reader/reader.php` |
| `0 20px` | 7 | `admin/manage_blog.php`, `admin/manage_poems.php`, `admin/manage_videos.php`, `index.php`, `reader/reader.php` |
| `60px 20px` | 7 | `admin/manage_groups.php`, `books.php`, `groups.php`, `notifications.php`, `poetry.php`, `search_results.php` … (+1 more) |
| `40px` | 7 | `admin/manage_poems.php`, `book.php`, `index.php`, `login.php`, `reader/reader.php`, `register.php` … (+1 more) |
| `24px 32px` | 6 | `about.php`, `admin/manage_books.php`, `admin/manage_messages.php`, `admin/manage_users.php`, `admin/settings.php`, `index.php` |
| `2px 6px` | 6 | `admin/dashboard.php`, `admin/manage_groups.php`, `dashboard.php`, `includes/header.php`, `reader/reader_audio.php` |
| `6px 0` | 5 | `admin/dashboard.php`, `blog.php`, `book_session.php`, `dashboard.php`, `includes/header.php` |
| `8px 10px` | 5 | `admin/manage_blog.php`, `admin/manage_groups.php`, `admin/manage_questions.php`, `admin/manage_sessions.php`, `group.php` |
| `0 8px` | 5 | `bible_reader.php`, `group.php`, `reader/reader.php`, `reader/reader_notes.php` |
| `14px 32px` | 5 | `book_session.php`, `community_answer_delete.php`, `community_delete.php`, `create_group.php`, `session_cancel.php` |
| `4px 14px` | 5 | `books.php`, `community.php`, `library.php`, `poetry.php` |
| `10px 28px` | 4 | `about.php`, `accessibility.php`, `blog_post.php`, `book_session.php` |
| `28px 32px` | 4 | `accessibility.php`, `admin/dashboard.php`, `dashboard.php`, `poetry.php` |
| `0 12px` | 4 | `admin/manage_blog.php`, `admin/manage_poems.php`, `admin/manage_videos.php`, `index.php` |
| `16px 24px` | 4 | `admin/manage_poems.php`, `admin/process_book.php`, `create_group.php`, `reader/reader_gamification.php` |
| `12px 0` | 4 | `admin/manage_videos.php`, `index.php`, `reader/reader_challenges.php`, `video_watch.php` |
| `28px` | 4 | `admin/process_book.php`, `bible_reader.php`, `group.php`, `groups.php` |
| `60px 0` | 4 | `index.php` |
| `20px 0` | 3 | `about.php`, `contact.php`, `reader/reader_challenges.php` |
| `6px 8px` | 3 | `admin/manage_groups.php`, `reader/reader.php` |
| `2px 0` | 3 | `admin/newsletter_tabs/send.php`, `reader/reader.php`, `search_results.php` |
| `4px 16px` | 3 | `book.php`, `index.php`, `search_results.php` |
| `24px 20px` | 2 | `accessibility.php`, `index.php` |
| `16px 8px` | 2 | `admin/dashboard.php`, `dashboard.php` |
| `12px 14px` | 2 | `admin/manage_groups.php` |
| `14px 18px` | 2 | `group.php` |
| `16px 18px` | 2 | `groups.php` |
| `36px 32px` | 2 | `privacy.php`, `terms.php` |
| `30px 40px` | 2 | `reader/reader.php` |
| `6px` | 2 | `reader/reader.php` |
| `30px 20px` | 2 | `register.php`, `verify.php` |
| `60px 0 80px` | 1 | `404.php` |
| `28px 20px` | 1 | `about.php` |
| `36px` | 1 | `about.php` |
| `48px` | 1 | `about.php` |
| `40px 30px` | 1 | `admin/manage_poems.php` |
| `14px 28px` | 1 | `admin/manage_videos.php` |
| `0 6px` | 1 | `bible_reader.php` |
| `24px 12px` | 1 | `bible_reader.php` |
| `8px 24px` | 1 | `bible_reader.php` |
| `16px 32px` | 1 | `book.php` |
| `40px 0 60px` | 1 | `books.php` |
| `10px 14px 10px 40px` | 1 | `books.php` |
| `20px 24px 24px` | 1 | `books.php` |
| `14px 16px 16px` | 1 | `books.php` |
| `8px 14px` | 1 | `group.php` |
| `0 10px` | 1 | `group.php` |
| `12px 18px` | 1 | `groups.php` |
| `0.8rem 1.2rem` | 1 | `includes/footer.php` |
| `0 0.3rem` | 1 | `includes/footer.php` |
| `0.5rem 0` | 1 | `includes/footer.php` |
| `0.5rem 1.2rem` | 1 | `includes/footer.php` |
| `2rem` | 1 | `includes/footer.php` |
| `3rem` | 1 | `includes/footer.php` |
| `0.6rem 1.2rem` | 1 | `includes/footer.php` |
| `0.5rem` | 1 | `includes/footer.php` |
| `1rem` | 1 | `includes/footer.php` |
| `80px 24px 24px` | 1 | `includes/header.php` |
| `8px 18px` | 1 | `library.php` |
| `10px 12px 10px 36px` | 1 | `library.php` |
| `48px 32px` | 1 | `library.php` |
| `2px` | 1 | `poem_view.php` |
| `12px 8px` | 1 | `profile.php` |
| `44px` | 1 | `profile.php` |
| `20px 20px 120px 20px` | 1 | `reader/reader.php` |
| `6px 36px 6px 16px` | 1 | `reader/reader.php` |
| `30px 28px 28px` | 1 | `reader/reader.php` |
| `2px 10px 2px 6px` | 1 | `reader/reader.php` |
| `0 5px` | 1 | `reader/reader.php` |
| `0 2px` | 1 | `reader/reader_tts.php` |
| `24px 0` | 1 | `video_watch.php` |
| `4px 6px` | 1 | `videos.php` |

For context, inline CSS uses 46 distinct font-size values in total: `0.9rem`, `0.85rem`, `0.95rem`, `0.8rem`, `0.75rem`, `1rem`, `0.7rem`, `1.2rem`, `1.05rem`, `1.1rem`, `1.4rem`, `2rem`, `1.8rem`, `2.5rem`, `1.5rem`, `1.3rem`, `2.4rem`, `3rem`, `1.6rem`, `2.2rem`, `1.15rem`, `4rem`, `2.8rem`, `0.6rem`, `5rem`, `0.7rem!important`, `clamp(2rem, 4vw, 3.2rem)`, `16px`, `0.65rem`, `3.5rem`, `1rem!important`, ``, `clamp(1.8rem, 3.5vw, 2.8rem)`, `1rem !important`, `1.6rem !important`, `1.5rem !important`, `2.6rem`, `.95rem`, `1.5rem!important`, `0.95rem!important`, `1.2rem!important`, `0.75rem!important`, `0.65rem!important`, `0.8rem!important`, `95%!important`, `90%!important`.

---

## 6. Code that will break under the InfinityFree constraints

### 6.1 "No outbound API calls"
| Code | Effect |
|---|---|
| `includes/mail_helper.php:11-23` (Gmail SMTP, port 587), used by **60 `sendEmail()` calls in 36 files** | Your constraint list rules out outbound connections, so every email fails. Worse, the `catch` at `:25-33` calls **`die()`** and prints the error with file and line. A failed email therefore doesn't just go unsent; it **kills the request after the database write**. This is behind the failures in register, verify, forgot/reset password, newsletter, contact, comments, community, book sessions, reports, profile and payments. PHPMailer's default `Timeout` is 300 s (`vendor/phpmailer/phpmailer/src/SMTP.php:180`), so a connection that hangs instead of being refused will hit the host's execution limit first. *(If your plan does allow outbound SMTP on 587, sending would work, but the `die()` makes any temporary SMTP problem fatal.)* |
| `includes/admin_mail_helper.php` | Same SMTP design. Never called by anything. |
| `social_login.php:31-37` (Hybridauth → Google/Facebook token endpoints) | OAuth needs server-side HTTPS calls, so it can't work. |
| `admin/process_book.php:989` | Calls `sendEmail()` with 8 arguments to email an attachment. `sendEmail()` accepts 6, so the attachment is silently dropped. |

Everything else that reaches out runs in the visitor's browser: Font Awesome, Google Fonts, unpkg `page-flip` (`reader/reader.php:495`), `speechSynthesis`, WhatsApp share links. Browser-side calls are not affected by the server restriction.

### 6.2 "No cron, no background jobs"
| Code | Effect |
|---|---|
| `worker.php` | A `while (true)` daemon with `sleep(1)`. It can't run under your constraints. Its SQL also uses MySQL-only `NOW()` (`:15`), which fails on SQLite. It is web-reachable (§4). |
| `poem_view.php:168` → `jobs` table | Comment jobs are queued and never processed, so the private-comment email to admin never goes out. |
| `admin/process_queue.php` | Built to drain `email_queue` on a cron. Scheduled newsletters (`scheduled_at`) are never sent unless an admin opens this page by hand. |
| `reader/cron/reader_reminders.php` | A daily cron script. It also fails on every request because of its relative includes (`:7-10` resolve against `reader/cron/`; confirmed). |
| `admin/manage_newsletter.php:136-153`, `:350`; `admin/editor.php:139-145` | Bulk email in the request with `usleep(500000)` per recipient. `editor.php` loops over **every** active subscriber synchronously, so with 100 subscribers the request takes at least 50 s plus SMTP time and will hit the execution limit partway through. `manage_newsletter.php` caps at 10 and then wrongly marks the whole send as sent (§2). |
| `admin/process_book.php` (PDF/EPUB/DOCX parsing with smalot/pdfparser and ZipArchive inside the request) | Large books will hit the CPU, memory and time limits. `book_processing_queue` suggests an async design that was never built. |

### 6.3 "Sessions expire ~24 h"
| Code | Effect |
|---|---|
| `login.php:81-109` | Remember-me tokens are stored and set as a cookie (without `secure`, `:108`), but **no code reads them**. Members are logged out when the session expires. A new `remember_tokens` row is added on every remember-me login and never cleaned up. |
| `includes/auth.php:7-11` | Creates `tmp/sessions/` inside the web root on every request, but `session_save_path()` is commented out, so the folder sits there unused. |
| Login/verify/register/forgot rate limits (`login.php:30`, `verify.php:25`, `register.php:23`, `forgot_password.php:13`, `poem_view.php:49`, `ajax_*`) | Stored as files in `sys_get_temp_dir()`. On shared hosting that directory may be shared with other tenants and cleaned out at any time, so these limits are best-effort only. The limiter in `verify.php` never resets after 15 minutes, despite its message; it resets only on a successful verification. |

### 6.4 "50k hits/day"
InfinityFree counts every request as a hit, static files included.
| Code | Effect |
|---|---|
| Every page | At least 6 same-origin requests per page view: the page, `style.css`, `main.js`, `logo.png` (**2.1 MB**), `manifest.json`, and `favicon.ico`, which **doesn't exist** and so returns a 404 that still counts. `index.php` adds `sw.js` and `hero-logo.png` (1.3 MB). Realistically that is **≈6–8k page views/day** before the cap. |
| `dashboard.php:378`, `:399`; `admin/dashboard.php:293`, `:398` | Two `setInterval(…, 60000)` polls. One dashboard tab left open uses **~2,880 hits/day**. |
| `index.php:244` + `sw.js` | The service worker caches `/` cache-first with a fixed `CACHE_NAME` and no update path, so returning visitors may see a stale homepage forever. That saves hits, but it is a correctness bug. |
| `includes/db.php:205-255` | Runs 13 `CREATE TABLE IF NOT EXISTS`, 11 `CREATE INDEX IF NOT EXISTS` and a `COUNT(*)` on **every request**. That isn't a hit cost, but it is CPU and lock overhead on every page. |

### 6.5 "50k rows/table"
These tables grow on every request with no pruning: `view_logs` (an INSERT on every poem/book view, guests and bots included: `poem_view.php:101`, `book.php:37`), `poem_reads`, `blog_reads`, `reading_sessions`, `group_activity_log`, `newsletter_stats`, `jobs` (never drained), `remember_tokens`, `reader_admin_notifications`, `notifications`. At 1–2k views a day, `view_logs` alone would pass 50k rows within 1–2 months. *As noted in §0.1, that row cap is a MySQL-service limit. On the current SQLite design the same growth is bounded by disk and inode quota, and by the cost of the `COUNT`/`SUM` scans that `ajax_admin.php` and the dashboards run over these tables.*

### 6.6 "No Composer on server"
Not a problem today: `vendor/` (2.2 MB) is committed and deployed by FTP. Two notes: `includes/mail_helper.php` is included on 52 pages, so the Composer autoloader loads on nearly every request even when no mail is sent. And `vendor/composer/platform_check.php` requires PHP ≥ 7.2, so check the PHP version your InfinityFree account runs.

### 6.7 "Remote DB access blocked" (and what SQLite means on this host)
- You can't inspect or back up the live database except by downloading `data/site.db` over FTP. The repo holds no migrations, so the live schema has drifted from the code and nothing records it (§0.2).
- SQLite allows one writer at a time. Several plain page views write: `UPDATE poems SET view_count…` (`poem_view.php:96`), `books` (`book.php:32`), `blog_posts` (`blog_post.php:25`), plus the `view_logs` inserts. No `busy_timeout` or WAL mode is set anywhere, so two simultaneous visitors can trigger **"database is locked"**. With `ERRMODE_EXCEPTION` and no `catch` on most of those statements, that is a fatal error page.
- Confirm with InfinityFree that SQLite file locking is supported on their storage. Shared hosts often keep files on network filesystems, where SQLite locking is unreliable.

### 6.8 Path and include bugs that break regardless of host
| Location | Problem |
|---|---|
| `reader/reader_ajax.php:11`, `reader/reader_analytics.php:10` | Require `includes/reader_functions.php`, which doesn't exist (confirmed). |
| `reader/progress_tracking.php:7-10` | Uses `/../../includes/`, one level too far (confirmed). |
| `admin/manage_groups_ajax.php:7-10`, `admin/preview_poem.php:2-4` | Bare relative paths `includes/…` resolve against `admin/` (confirmed). |
| `reader/cron/reader_reminders.php:7-10` | `../includes/` resolves against `reader/cron/` (confirmed). |
| `reader/reader.php:500` + `includes/reading_groups.php:415` + `reader/reader_functions.php:56` | `time_ago()` is declared twice, so any page that loads both files fatals (confirmed). |
| `community.php:58`, `:137`, `:182`; `book.php:99` (guarded) | `awardReputation()` is never defined (confirmed). |
| `serve_og.php:16` | Uses `$db` without including `db.php` (confirmed). The fallback at `:31` and `generate_og_image.php:21` point to `assets/images/angelwrites-logo.png`, which doesn't exist. So every `/img/{id}` social-preview image is broken. |
| `blog_post.php:43`, `:85` | Uploads go to `../assets/uploads/…`, which is outside `htdocs`. |
| `index.php:9`, `:1225` | The page cache writes to `cache/`, which doesn't exist, so it warns on every guest visit. |
| `about.php:8`, `privacy.php:8`, `terms.php:8` | Query `settings` before anything creates it. The table is only created when an admin opens `admin/settings.php`. On a fresh DB these three public pages fatal (confirmed). |
| `blog.php:92`, `reflections.php:17`, `videos.php:19`, `index.php:83`, `profile.php:23`, `notifications.php:50` | **[schema]** Public or member pages that fatal against the repo-defined schema (confirmed). |

---

## 7. Suggested order of work (for discussion, nothing done yet)

1. **Today, security:** delete `reset_admin.php` from the server and the repo, and change the admin password (assume it is known). Add an admin check to `ajax_admin.php`. Remove `worker.php` and the `allow_url_fopen` file from the deploy. Turn off `display_errors`.
2. **Get the real schema** (`sqlite3 site.db .schema`) into the repo as a migration file, so every **[schema]** item above can be resolved.
3. **Make email non-fatal:** replace the `die()` in `mail_helper.php` with `return false` plus logging, and set a short `Timeout`. Then decide how email can work on this host at all.
4. **Fix the [code] blockers** for the five features: the reader include path, the duplicate `time_ago()`, `awardReputation()`, the newsletter redirect for logged-in users, the private-comment visibility bug, the review list query, and blog upload paths.
5. Decide whether to stay on SQLite or move to InfinityFree MySQL. That choice determines which of the §6.5/§6.7 limits apply, and whether the 54 SQLite-only statements need rewriting.
6. Then the structural work: a data-access layer, consolidating CSS onto the existing tokens, and pulling HTML out of the logic files.

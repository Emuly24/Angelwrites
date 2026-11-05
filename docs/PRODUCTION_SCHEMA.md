# Production database check

Source: the `site.db` you uploaded (10 MB, SQLite 3.53, WAL mode, `PRAGMA integrity_check` = ok). I worked on a private copy only. This file contains schema facts and row counts, **no personal data**. Method: the repo was served against a copy of this file (**Env P**), and every page and main action was replayed as a guest, a verified reader (an existing account) and the admin.

This resolves the **[schema]** items in `docs/deep-scan/` and AUDIT.md. Where Env P contradicts a domain file, Env P wins.

## 1. The headline: 19 tables point at an empty `users_old`

At some point `users` was rebuilt by renaming it to `users_old` and creating a new `users`. Modern SQLite rewrites every foreign key that referenced `users` to reference `users_old` when a table is renamed. `users_old` now has **0 rows**, and `includes/db.php:37` turns on `PRAGMA foreign_keys = ON` for every request. Result: **any insert into these 19 tables fails for every user**:

`activity_logs, answers, blog_reads, book_highlights, connections, invites, notifications, poem_reads, poem_status, prayer_requests, questions, reading_progress, reading_status, reflection_comments, reflection_reads, reviews, sessions, user_tags, video_watches`

`PRAGMA foreign_key_check` already lists 3 existing rows (`reading_progress` ×2, `poem_reads` ×1) orphaned by this. The other 33 user-linked tables correctly reference `users`.

**What it breaks in production (confirmed in Env P):**

| Action | Result in production |
|---|---|
| Ask a question / answer one (`community.php:82` / `:130`) | **Fatal**: FOREIGN KEY constraint failed (before the `awardReputation()` bug is even reached) |
| Comment on a poem (`poem_view.php:156`) | **Fatal**: FOREIGN KEY |
| Review a book (`book.php:95`) | **Silent**: the error is caught, the page reloads, nothing is saved |
| Open any blog post as a member (`blog_post.php:32`, `blog_reads`) | **Fatal**: FOREIGN KEY |
| Change a book's status in My Library (`library.php:51`) | **Fatal**: FOREIGN KEY |
| Book a session | **Fatal** (also missing `session_type`, below) |
| Save reading progress, notifications, prayer requests, blog comments, video watches | Would fail on FOREIGN KEY once their other bugs are fixed |

This one schema problem is now **the biggest single cause of broken journeys in production**, ahead of email, because it hits actions that don't involve email at all.

## 2. Tables and columns the code uses that production lacks

**Missing tables (4):** `comment_reactions`, `purchases`, `review_reactions`, `video_comments`.

**Missing columns:**

| Table | Missing columns | Effect (confirmed unless marked) |
|---|---|---|
| `sessions` | `session_type` | Every booking fatals (`book_session.php:85`) |
| `reflections` | `tags`, `slug`, `featured`, `comment_count`, `likes` | `reflections.php:17` fatals for everyone |
| `blog_posts` | `likes`, `loves`, `prays` | Search fatals whenever a blog post matches (`search_results.php:107`) |
| `book_comments` | `page_num`, `parent_id`, `is_admin_reply` | Reader paragraph comments and admin replies fail (inferred) |
| `reflection_comments` | `photo_path`, `voice_path`, `is_admin_reply` | Blog photo/voice comments and admin replies fail (inferred) |
| `prayer_requests` | `book_id`, `request_text` | Reader prayer requests fail (inferred) |
| `videos` | `views`, `likes`, `dislikes` | "Most viewed" sort fails; fake `rand()` like counts would show (inferred) |
| `newsletter` | `updated_at`, `source` | Re-subscribing a lapsed subscriber fatals (`newsletter.php:41`) |
| `bookmarks` | `offset` | Reader data export fails (inferred) |
| `email_queue` | `segment_id` | Segment sends fail (inferred) |
| `reading_progress` | `bucket`, `completion_rate`, `drop_offs` | Admin analytics queries fail (inferred) |
| `reading_sessions` | `day` | Analytics chart query fails (inferred) |
| `reading_challenges` / `reading_goals` / `reading_circles` | `completed` / `completed_at` / `created_at` | Reader challenge, goal and circle actions fail (inferred) |
| `users` | `is_admin` | One query path (inferred, minor) |

**NOT NULL traps** (columns production requires that the code doesn't supply):
- `reading_groups.book_id`: the code inserts `content_type`/`content_id`, not `book_id`. **Creating a group fatals** (confirmed: `NOT NULL constraint failed: reading_groups.book_id`).
- `book_comments.comment_text` *and* `book_comments.comment` are both required; every insert in the code supplies only `comment`.

**Present in production, never used by the code (14):** `activity_logs, batch_jobs, blog_categories, book_content_drafts, book_highlights, book_processing_log, connections, downloads, invites, media_files, rate_limits, reflection_reads, user_tags, users_old`.

## 3. What the data says about real usage

| Fact | Meaning |
|---|---|
| `users`: 11 (1 admin, 10 readers); **5 of the 10 readers are unverified** | Half of real signups never completed verification, consistent with the verification email never arriving (domain 10). The admin account is also `is_verified = 0`; it can log in only because its username is on the hard-coded exemption list (`login.php:63`). |
| `users.name` is nullable (3 NULLs) | The registration `NOT NULL` fatal (domain 10 J1) **doesn't apply in production**: registration saves the account and then shows the SMTP dump. |
| `highlights`: 5, `reading_sessions`: 87, `reading_progress`: 2, `bible_sessions`: 14 | The reader and Bible reader **did work at some point**. The current reader code fails before any script loads. |
| `remember_tokens`: 13 | Remember-me tokens are written and never read (domain 10). |
| `books`: 1 (free, processed); `payments`: 0; `purchases`: table missing | No paid book has ever existed, so the purchase bugs (domains 3/8) haven't hurt anyone yet. Paid downloads would fatal on the missing `purchases` table rather than redirect. |
| `poems`: 13, `poem_status`: 2 | 11 poems have no status row, so the draft/publish toggles do nothing for them (domain 12). |
| `reviews`: 0, `questions`: 0, `answers`: 0, `reflection_comments`: 0, `contact_messages`: 0, `sessions`: 0, `blog_posts`: 0, `videos`: 0 | No comment, review, Q&A, contact message, booking, blog post or video has ever been saved. That matches every one of those flows being broken in production. |
| `newsletter`: 3, `email_queue`: 5 | A few subscribers; queued sends exist that nothing will ever process. |
| `settings`: `site_name`, `site_description`, `admin_email` | Written by the settings page, read by nothing (domain 19). No `about_content`/`privacy_content`/`terms_content`. |
| `user_reputations`: 4 | Rows exist, so the reputation sidebars render in production (the Env A fatals on that table don't apply). |
| Journal mode **WAL** | Better concurrency than the default; the "database is locked" rate seen in testing (domain 20 J6) is likely lower in production but not eliminated (DDL still runs on every request). |

## 4. Domain-by-domain correction (Env P vs. the domain files)

| Domain | What changes in production |
|---|---|
| 01 Reader | **Worse for guests:** a visible fatal at the top of the reader (`reader/reader_functions.php:256`: `getReaderLevel(0)` inserts a reputation row for user 0, rejected by the FK). Members: same hidden fatal at `:384`. `reading_progress` → `users_old`, so position saving fails even after the code fixes. **New:** `reader/admin/reader_analytics.php` fatals on undefined `formatDuration()` once real reading sessions exist (87 in production). |
| 02 Bible | All Bible tables exist and reference `users` correctly. Note saving works (then the SMTP issue); the private-note leak is code, so it still applies. |
| 03 / 08 Downloads & payments | `purchases` doesn't exist, so a paid download would **fatal** instead of redirecting. Zero payments and no paid books so far. |
| 04 Groups | **Create fails** on `reading_groups.book_id NOT NULL` even via a direct POST. The UI endpoint is missing too. |
| 05 Search | Single-word search works unless a blog post or reflection matches; then it fatals (`likes`). Multi-word still always fatals. |
| 06 Video | `video_comments` doesn't exist, so comments fatal. Watch page `time_ago()` fatal unchanged. No videos yet. |
| 07 Reflections | `reflections.php` **fatals for everyone** (no `tags` column). |
| 09 Books | `book.php` renders. **Reviews silently aren't saved** (FK), and the review list is hidden (no `review_reactions`). |
| 10 Auth | Registration saves the user, then the SMTP dump (no `NOT NULL` fatal). Half of real readers are unverified. |
| 11 Member area | Pages render. **Library status changes fatal** (FK). Notifications can't be inserted (FK), on top of nothing creating them. |
| 12 Poetry | **Poem comments fatal** (FK). Poem-read tracking silently fails (caught). |
| 13 Blog | Listing works. **Every blog post page fatals**: guests on `comment_reactions`, members on the `blog_reads` FK. |
| 14 Community | **Ask and answer both fatal on the FK** before reaching the `awardReputation()` bug. |
| 15 Comments | Every system except Bible notes and group notes fails to save in production. |
| 16 Newsletter | Subscribing saves, then the SMTP dump. Re-subscribing fatals on `updated_at`. |
| 17 Contact | Saves, then the SMTP dump (0 messages stored in production suggests no visitor has ever succeeded, or they were deleted). |
| 18 Sessions | **Booking fatals** (`session_type` missing, and the `sessions` FK → `users_old`). |
| 19 Admin | Reader analytics fatal (`formatDuration`). `ajax_admin.php` leak unchanged. |
| 20 Infra | WAL mode helps concurrency. 14 unused tables and `users_old` add clutter. |
| 21 Static | About/Privacy/Terms render (settings and reputation tables exist). The "last updated" and privacy-text issues are unchanged. |

## 5. Repairing the database (for your decision; nothing has been run)

1. **The `users_old` foreign keys** are the highest-value fix and need a careful manual step. SQLite can't `ALTER` a foreign key, so each of the 19 tables has to be recreated with `REFERENCES users(id)` and its rows copied back. Do it with `PRAGMA foreign_keys=OFF` and `PRAGMA legacy_alter_table=ON`, so that renaming doesn't rewrite references again. Then drop `users_old` and run `PRAGMA foreign_key_check`.
2. **Add the missing columns and tables** in §2, or change the code to stop using them. Each domain file says which way makes sense.
3. **Relax or satisfy the NOT NULL traps** (`reading_groups.book_id`, `book_comments.comment_text`).
4. **Back up first.** Download `data/site.db` *and* any `site.db-wal` / `site.db-shm` files beside it, because WAL mode keeps recent writes in the `-wal` file. Make changes on a copy, re-upload while the site is idle, and keep the original.

If you'd like, I can write that repair script and prove it on the copy of your database. It would be checked against every journey in this scan, but not run against production and not committed until you've reviewed it.

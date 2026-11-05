# Coverage reconciliation

**One row per finding** from `docs/AUDIT.md` and the 21 files in `docs/deep-scan/` (plus `docs/PRODUCTION_SCHEMA.md`, which the rollup adopted), each mapped to its status.

Branches read for this file (code inspected, not PR text):

| Branch | Head | PR | State |
|---|---|---|---|
| `fix/core-fatals` | `256887f` | #1 | open, not merged |
| `db/repair-artifacts` | `38a7abb` | #2 | open, not merged (repair not applied to the live DB) |
| `deploy/exclude-sensitive` | `5145257` | #3 | open, not merged |
| `fix/security` | — | — | not written yet (7 items, Step 4) |

## How statuses are applied
- **FIXED:** a complete fix exists on the named branch, verified by reading that branch's code. The *Where* column names the file and line of the fix. None of these branches is merged yet, so FIXED here means "fixed in code, waiting to merge".
- **IN-PROGRESS:** the fix exists on a branch but needs a further step to take effect. In practice this is the database repair: `db/repair/repair.sql` is written and tested, but not yet applied to the live `data/site.db`.
- **PLANNED:** one of the seven `fix/security` items (Step 4), or a manual step in Step 3. **No batch letters (A–O) were supplied in this session**, so no row cites a batch letter. Everything not on a branch and not in those seven items is OPEN.
- **OPEN:** no branch, no planned item.
- **WONTFIX:** not a bug, with the reason. That's either corrected by the deep scan, safe as written, or a "repo-schema only" fatal that doesn't occur on the production database (`PRODUCTION_SCHEMA.md`). The underlying problem, that the repo can't build a working fresh install, is tracked as one OPEN row in domain 20.

**Corrections applied from the deep scan:**
- **Private poem comments don't leak on SQLite:** WONTFIX, with the latent clause kept as a separate OPEN row.
- **`video_watch.php` doesn't create tables at runtime:** WONTFIX.

**Deduplication:** where AUDIT.md and a deep-scan file report the same defect, it appears once. AUDIT-only findings (credentials, SQL construction, CSS) are tagged "(AUDIT §n)". Crash-after-email findings are split: the crash is FIXED by `fix/core-fatals`, while any underlying problem (e.g. a fake payment, or undelivered email) has its own row.

**Severity:** Critical = data or money leak, or account takeover. High = a core journey blocked or a security hole. Medium = a feature broken or wrong data. Low = minor.

## Functional and security findings

| # | Domain | File:line | One-line description | Severity | Status | Where |
|---|---|---|---|---|---|---|
| F1 | 01 Reader | `reader/reader.php:384` | Undefined `$tocEntries` → TypeError fatal for every visitor on PHP 8; error printed inside hidden TOC drawer, no script reaches the browser, all 32 controls dead | High | **FIXED** | fix/core-fatals (PR #1) — reader/reader.php:25 defines `$tocEntries = buildTOC(...)` |
| F2 | 01 Reader | `reader/reader_ajax.php:11` | Requires non-existent `../includes/reader_functions.php` → every reader AJAX action fatals (save position, bookmarks, highlights, notes, reports) | High | **FIXED** | fix/core-fatals — reader/reader_ajax.php:11 → `__DIR__ . '/reader_functions.php'` |
| F3 | 01 Reader | `reader/reader.php:500 + includes/reading_groups.php:415` | `time_ago()` declared twice → "Cannot redeclare" fatal wherever both load (reader.php after line 500, reader_notes.php) | High | **FIXED** | fix/core-fatals — `function_exists` guards at reader/reader_functions.php:58 and includes/reading_groups.php:415 |
| F4 | 01 Reader | `reader/reader.php:662` | `new StPageFlip(...)` but the library exposes `window.St.PageFlip` → flip mode can never start | Medium | **FIXED** | fix/core-fatals — reader/reader.php:667 `new St.PageFlip(` |
| F5 | 01 Reader | `reader/reader.php:91,495` | Unpinned page-flip CSS/JS URLs 404 on jsdelivr/unpkg (no `dist/css`, no `.min.js` in 2.0.7) | Medium | **FIXED** | fix/core-fatals — reader/reader.php:499 pinned `page-flip@2.0.7/dist/js/page-flip.browser.js`; CSS link removed |
| F6 | 01 Reader | `reader/reader.php (markup)` | `#highlight-tooltip` element missing from markup → text selection can never show the highlight toolbar (found while fixing) | High | **FIXED** | fix/core-fatals — reader/reader.php:494 adds `<div id="highlight-tooltip">` |
| F7 | 01 Reader | `reader/reader.php:584,1537` | Default flip mode turns pages on drag, so text can't be selected/highlighted | Medium | **FIXED** | fix/core-fatals — reader/reader.php:585 and :1538 default to scroll; flip still selectable in settings |
| F8 | 01 Reader | `reader/reader_functions.php:256` | Guest reader: `getReaderLevel(0)` inserts a reputation row for user 0 → FK fatal at top of reader in production | High | **FIXED** | fix/core-fatals — reader/reader_functions.php:254 returns defaults for guests without writing |
| F9 | 01 Reader | `reader/reader_ajax.php:162` | XP for highlights/finishing never awarded: `updateUserStats()` lives in reader_gamification.php, which reader_ajax.php never includes | Low | **OPEN** | — |
| F10 | 01 Reader | `dashboard.php (member modules)` | Dashboard "Reader Tools" link 8 raw partials/endpoints: Open Reader (no id → redirect), Export (raw JSON 400), Circles (raw JSON), My Analytics (admin-only page), Challenges/TTS (raw fragments), Gamification (blank) | Medium | **OPEN** | — (My Notes fatal fixed by the time_ago guard; the links themselves remain wrong) |
| F11 | 01 Reader | `reader/reader_circles.php:281` | Circle invite emails link to non-existent `reader/join_circle.php` → every invite 404s | Medium | **OPEN** | — |
| F12 | 01 Reader | `reader/reader_export.php` | Export served as raw JSON `text/html`, no download headers; reader `export_highlights` unreachable before fix | Low | **OPEN** | — |
| F13 | 01 Reader | `reader/cron/reader_reminders.php:7` | Relative `../includes/` resolves against reader/cron/ → fatal when run; also needs cron the host doesn't have | Low | **OPEN** | — |
| F14 | 01 Reader | `reader/reader_analytics.php:10` | Second analytics page requires non-existent include → fatal | Medium | **FIXED** | fix/core-fatals — reader/reader_analytics.php:10 → `__DIR__ . '/reader_functions.php'` |
| F15 | 01 Reader | `reader/reader_analytics.php:50,54` | Date filters build `FROM reading_sessions AND start_time…` (no WHERE) → SQL error for 7/30/90-day ranges | Medium | **FIXED** | fix/core-fatals — reader/reader_analytics.php:50,54 `WHERE 1=1 $date_condition` |
| F16 | 01 Reader | `reader/progress_tracking.php:7` | Wrong `/../../includes/` path → fatal (file is otherwise orphaned) | Low | **FIXED** | fix/core-fatals — reader/progress_tracking.php:7-10 corrected paths |
| F17 | 01 Reader | `reader/reader.php:46` | Group lookup uses `reading_groups.book_id`; groups are stored as content_type/content_id → reader never finds the member's group, group notes never appear | Medium | **OPEN** | — |
| F18 | 01 Reader | `reader/book_data.php (access)` | Paid books fully readable by anyone (guests included): book_data.php has no price/purchase check and renders every page | Critical | **OPEN** | — (not in the 7 fix/security items; see §3) |
| F19 | 01 Reader | `reader/reader.php (JS)` | `get_challenge_history` sent by the challenge widget has no handler in reader_ajax.php | Low | **OPEN** | — |
| F20 | 01 Reader | `reader/book_data.php:52` | Page split regex stops at the first nested `</div>` → a page edited to contain a div is silently truncated (latent) | Low | **OPEN** | — |
| F21 | 01 Reader | `reader/book_data.php:22` | `u.username`/`display_name`/`books.author_id` missing (Env A only) | Low | **WONTFIX** | Production schema has these columns (PRODUCTION_SCHEMA.md); tracked by the repo-schema row in domain 20 |
| F22 | 02 Bible reader | `bible_reader_ajax.php:22-28` | Private Bible notes returned to every logged-in user (`get_notes` has no `is_private` filter) — confirmed leak | Critical | **PLANNED** | fix/security item 4 |
| F23 | 02 Bible reader | `bible_reader.php:647` | Writes to missing `#notesVerseRef` → TypeError on every chapter load; "Go to" box never updates | Low | **OPEN** | — |
| F24 | 02 Bible reader | `bible_reader.php:215 + includes/bible_lookup.php:14` | YLT offered but no DB → silently serves KJV labelled YLT | Medium | **OPEN** | — |
| F25 | 02 Bible reader | `assets/bible/bible_ASV.db` | ASV has only 20 books; Proverbs stops at ch. 28; menu offers all 66, shows raw "[ASV Isaiah 1 not found]" | Medium | **OPEN** | — |
| F26 | 02 Bible reader | `bible_reader_ajax.php:78` | Public note saved, then SMTP dump replaces JSON → panel says "No notes", user re-posts duplicates | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 returns false instead of die() |
| F27 | 02 Bible reader | `bible_reader.php (~1450)` | Verse highlight keyed on the dropdown verse, not the selected text; localStorage only (one device) | Medium | **OPEN** | — |
| F28 | 02 Bible reader | `bible_reader.php (JS)` | JS sends `action=add_highlight`; bible_reader_ajax.php has no handler | Low | **OPEN** | — |
| F29 | 02 Bible reader | `bible_reader.php` | Shared verse links ignore book/chapter/verse (no `$_GET`/`location.search` read) → always opens default passage | Medium | **OPEN** | — |
| F30 | 02 Bible reader | `assets/bible/*.db` | 41 MB of Bible DBs (incl. copyrighted NIV/ESV/NASB/NKJV/AMP) directly downloadable over HTTP | High | **OPEN** | — (licensing + bandwidth; see §3) |
| F31 | 02 Bible reader | `bible_reader_ajax.php (sessions)` | `start_session`/`end_session` written on every visit (2 hits + 2 SQLite writes); Bible tables absent in repo schema | Low | **WONTFIX** | Tables exist in production; hit cost tracked in quality table |
| F32 | 03 Downloads & gate | `book.php:224-226` | Detail page links the raw book file for unprocessed books — guests download paid PDFs with one click | Critical | **PLANNED** | fix/security item 3 |
| F33 | 03 Downloads & gate | `admin/manage_books.php:99-103` | Uploaded book files stored in public `assets/uploads/books/` with predictable names → direct URL download, no gate | Critical | **OPEN** | — (not covered by item 3, which gates links only; see §3) |
| F34 | 03 Downloads & gate | `download.php:46-50 + process_payment.php:58-63` | Payments written to `payments`, gate reads `purchases` → every paid download refused forever | High | **OPEN** | — (purchases table added by repair; reconciliation not planned) |
| F35 | 03 Downloads & gate | `process_payment.php:23` | Payment amount taken from the browser | High | **OPEN** | — |
| F36 | 03 Downloads & gate | `process_payment.php:77` | "Payment failed" shown while DB says completed (SMTP die) | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 (the fake-payment itself stays OPEN, domain 08) |
| F37 | 03 Downloads & gate | `books.php (?error=not_purchased)` | Refused download redirects to `books.php?error=not_purchased`, which never shows the message | Low | **OPEN** | — |
| F38 | 03 Downloads & gate | `login.php:117 + download.php:19` | Post-login redirect only honours absolute SITE_URL; relative `redirect=` from download.php ignored → user lands on dashboard | Medium | **OPEN** | — |
| F39 | 03 Downloads & gate | `admin/manage_books.php:101` | Book upload accepts any file extension into a public folder | High | **OPEN** | — (host-abuse class; see §3) |
| F40 | 03 Downloads & gate | `download.php:26,35,62` | Errors are bare `die()` white pages without site layout | Low | **OPEN** | — |
| F41 | 04 Reading groups | `create_group.php:225` | Fetches non-existent `ajax/get_content_items.php` → content dropdown "Loading…" forever; no member can create a group | High | **OPEN** | — |
| F42 | 04 Reading groups | `includes/reading_groups.php:20` | Production `reading_groups.book_id NOT NULL` → creating a group fatals | High | **IN-PROGRESS** | db/repair-artifacts (PR #2) — repair.sql relaxes the constraint; not yet applied to the live DB |
| F43 | 04 Reading groups | `group_ajax.php` | No reply action/UI: discussions show "0 replies" but can never be replied to (`addDiscussionReply()` unused) | Medium | **OPEN** | — |
| F44 | 04 Reading groups | `group_ajax.php` | Creator actions `update_group`/`change_member_role`/`remove_member` return "Invalid action" (handlers only in broken admin copy) | Medium | **OPEN** | — |
| F45 | 04 Reading groups | `group_ajax.php:26-60` | Non-members can post discussions/notes into any group, private ones included — confirmed | High | **OPEN** | — (access control; not in the 7 items; see §3) |
| F46 | 04 Reading groups | `admin/manage_groups_ajax.php:7-10` | Bare relative includes resolve against admin/ → fatal on every request; also only checks login, not admin (AUDIT §4) | Medium | **OPEN** | — |
| F47 | 04 Reading groups | `admin/manage_groups.php:15-30` | Delete handled after HTML header output → JSON appended to a 21 KB page; admin JS fails though delete happened | Medium | **OPEN** | — |
| F48 | 04 Reading groups | `admin/manage_groups.php:79 + includes/reading_groups.php:66,74,96` | Joins non-existent `newsletters` table and `poems.author` | Medium | **OPEN** | — |
| F49 | 04 Reading groups | `groups.php:165` | `closeJoinModalAfterSubmit` undefined (JS error on join) | Low | **OPEN** | — |
| F50 | 04 Reading groups | `group_ajax.php:42` | Undefined `$_POST['book_id']` warning printed before JSON when display_errors on | Low | **OPEN** | — |
| F51 | 04 Reading groups | `groups.php` | No group discovery: no directory, nothing on the book page; code-only joining | Medium | **OPEN** | — |
| F52 | 04 Reading groups | `includes/reading_groups.php:63,86` | `reading_groups` missing (Env A only) | Low | **WONTFIX** | Production has the table; see domain 20 repo-schema row |
| F53 | 05 Search | `search_results.php:28-38` | Multi-word queries: one `?` per column but every term bound per column → "column index out of range" for any 2+ word search | High | **PLANNED** | fix/security item 5 |
| F54 | 05 Search | `search_results.php:218` | Reflection results link to non-existent `reflection.php` → 404 | Medium | **PLANNED** | fix/security item 5 |
| F55 | 05 Search | `search_results.php:65,73` | Selects `likes/loves/prays` (+reflections `comment_count/likes`) that production lacks → fatal whenever a blog post/reflection matches | High | **IN-PROGRESS** | db/repair-artifacts (PR #2) adds the columns; not yet applied |
| F56 | 05 Search | `includes/header.php:305 + search_results.php:13` | Header search icon links without a query → redirected to homepage; search is effectively hidden | Medium | **OPEN** | — |
| F57 | 05 Search | `search_results.php:209` | Book results link straight into the reader instead of book.php | Low | **OPEN** | — |
| F58 | 05 Search | `search_results.php:105-112` | Pagination applies LIMIT 12 per type then merges (up to 60/page; wrong page count) | Low | **OPEN** | — |
| F59 | 05 Search | `search_results.php (sort)` | "Relevance" isn't implemented; "Most commented" sorts on a column nothing writes | Low | **OPEN** | — |
| F60 | 06 Video | `video_watch.php:129` | Calls `time_ago()` without loading it → fatal for every viewer under the player | High | **FIXED** | fix/core-fatals — video_watch.php:5 requires reader/reader_functions.php |
| F61 | 06 Video | `video_watch.php:43` | MySQL `ON DUPLICATE KEY UPDATE` on SQLite → like/dislike fatals | Medium | **OPEN** | — |
| F62 | 06 Video | `video_watch.php:28-29` | Like/dislike counts fall back to `rand(100,500)`/`rand(10,50)` — fabricated numbers | Medium | **OPEN** | — |
| F63 | 06 Video | `videos.php:63` | "Most commented" sort → fatal (`videos.comment_count` doesn't exist) | Medium | **OPEN** | — |
| F64 | 06 Video | `videos.php (views)` | "Most viewed" meaningless: `videos.views` never incremented (column also missing in production) | Low | **OPEN** | — (repair adds the column; nothing writes it) |
| F65 | 06 Video | `video_watch.php:61` | Video comments: `video_comments` table missing in production → posting fatals | Medium | **IN-PROGRESS** | db/repair-artifacts (PR #2) creates the table; not yet applied |
| F66 | 06 Video | `video_watch.php vs videos_reactions.php:22` | Two reaction vocabularies on one table (like/dislike vs like/love/pray) | Low | **OPEN** | — |
| F67 | 06 Video | `video_watch.php:111-114` | Only `watch?v=` YouTube links embed; youtu.be/shorts/&t= fail | Low | **OPEN** | — |
| F68 | 06 Video | `admin/manage_videos.php:78-83` | Video upload: no extension/size check into a public folder | High | **OPEN** | — (host-abuse class; see §3) |
| F69 | 06 Video | `video_watch.php:16-24` | AUDIT said the page creates `video_reactions`/ALTERs `videos` at runtime | — | **WONTFIX** | Corrected by the deep scan: those statements are inside a comment and never run |
| F70 | 07 Reflections | `reflections.php:17` | Production `reflections` lacks `tags` (and slug/featured/comment_count/likes) → page fatals for everyone | High | **IN-PROGRESS** | db/repair-artifacts (PR #2) adds the columns; not yet applied |
| F71 | 07 Reflections | `admin/reflection_editor.php:108 vs reflections.php` | Admin writes reflections to `blog_posts`; public page reads `reflections` → published reflections never appear there | High | **OPEN** | — |
| F72 | 07 Reflections | `reflections.php (4 links)` | Every reflection link points to non-existent `reflection.php` → 404 | High | **OPEN** | — (fix/security item 5 fixes the search link only) |
| F73 | 07 Reflections | `includes/db.php:183 + admin/manage_reflections.php:24` | Default blog category "Christian Reflections" makes every blog post a "reflection" in admin | Medium | **OPEN** | — |
| F74 | 07 Reflections | `reflections.php (nav)` | Reflections page not in public navigation (only dashboards link it) | Low | **OPEN** | — |
| F75 | 08 Payments | `process_payment.php:58-63` | "Payment" recorded as `completed` with no provider contacted — simulated payment shown as real | Critical | **OPEN** | — |
| F76 | 08 Payments | `process_payment.php:60` | Phone number entered by the payer is never stored | High | **OPEN** | — |
| F77 | 08 Payments | `dashboard.php/profile.php/library.php` | No "My purchases"/receipt for members | Medium | **OPEN** | — |
| F78 | 08 Payments | `admin/*` | No admin screen to see or confirm payments | High | **OPEN** | — |
| F79 | 08 Payments | `books.php:121-122` | Sale shows the normal price in a "sale" colour (no sale price field) | Low | **OPEN** | — |
| F80 | 08 Payments | `process_payment.php:41` | Price 0 + not free → "Invalid amount", book unobtainable | Low | **OPEN** | — |
| F81 | 08 Payments | `download.php:46` | `purchases` table missing in production → paid download fatals instead of redirecting | Medium | **IN-PROGRESS** | db/repair-artifacts (PR #2) creates the table; not yet applied |
| F82 | 09 Books | `book.php:109` | Review saved, then SMTP dump instead of the page | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F83 | 09 Books | `book.php:95` | Production: review insert fails on FK to `users_old`; error caught → review silently not saved | High | **IN-PROGRESS** | db/repair-artifacts (PR #2) repoints the FK; not yet applied |
| F84 | 09 Books | `book.php:63-72` | Review list hidden (sub-select on missing `review_reactions`, caught → empty) | High | **IN-PROGRESS** | db/repair-artifacts (PR #2) creates `review_reactions`; not yet applied |
| F85 | 09 Books | `book.php:99` | Reputation for reviews never awarded (function didn't exist; guarded) | Low | **FIXED** | fix/core-fatals — includes/reputation.php defines awardReputation(); book.php:6 includes it |
| F86 | 09 Books | `books.php` | Every card shows "Read", including unprocessed books → empty reader "1 / 0" | Medium | **OPEN** | — |
| F87 | 09 Books | `book.php:155` | Streak shows blank: `fetchColumn() ?? 0` doesn't catch `false` | Low | **OPEN** | — |
| F88 | 09 Books | `book.php:8-9` | `display_errors` forced on; notices corrupt JSON responses and leak paths | Medium | **OPEN** | — (information leak class; see §3) |
| F89 | 09 Books | `admin/process_book.php (~1100)` | "Add to processing queue" says success; nothing ever processes the queue | Medium | **OPEN** | — |
| F90 | 09 Books | `admin/process_book.php:607` | Per-book reader CSS saved but never read by the reader | Low | **OPEN** | — |
| F91 | 09 Books | `admin/process_book.php:989` | sendEmail() called with 8 args (attachment) — signature takes 6, attachment silently dropped | Low | **OPEN** | — |
| F92 | 09 Books | `admin/process_book.php:172` | `exec()` with interpolated file path (shell injection if a path ever contains `'`; exec usually disabled) | Low | **OPEN** | — (see §3) |
| F93 | 09 Books | `book.php (reviews)` | One member can review the same book repeatedly | Low | **OPEN** | — |
| F94 | 10 Auth & account | `reset_admin.php:5-6` | Public script resets admin password to a hard-coded value and prints it; production admin password currently equals that value | Critical | **PLANNED** | Server deletion + password change: your Step 3 (manual); repo deletion: fix/security item 1; no re-upload: deploy/exclude-sensitive (PR #3, FIXED — .github/workflows/deploy.yml exclude `reset_admin.php`) |
| F95 | 10 Auth & account | `register.php:149 + includes/mail_helper.php:27` | Registration: account saved, then SMTP dump; user stuck (can't re-register, can't log in, no code) | High | **FIXED** | fix/core-fatals — register.php:151-152 succeeds regardless; success screen register.php:327-333 points to "Request a new code" |
| F96 | 10 Auth & account | `includes/mail_helper.php (delivery)` | Verification/reset codes can't be delivered while outbound SMTP is blocked → half of real signups unverified | High | **OPEN** | — (needs a plan-level email decision; not code) |
| F97 | 10 Auth & account | `verify.php:75` | Verified + logged in, but SMTP dump breaks the AJAX JSON → no feedback | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F98 | 10 Auth & account | `login.php:135-139` | 3rd failed login → admin email → SMTP dump with server paths for anonymous visitors | High | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 (verified: 3 wrong passwords show the normal message) |
| F99 | 10 Auth & account | `forgot_password.php:38 / reset_password.php:81,139` | Reset code stored / password changed, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F100 | 10 Auth & account | `login.php + dashboard greeting` | "Welcome back, Deprecated: htmlspecialchars()…" — self-registered users have NULL `name` | Medium | **OPEN** | — |
| F101 | 10 Auth & account | `login.php:81-109` | Remember-me token written, never read → logged out at ~24 h session expiry; cookie lacks `secure` | Medium | **OPEN** | — |
| F102 | 10 Auth & account | `social_login.php:36-58` | Google/Facebook login needs outbound OAuth (blocked); sets only user_id (no role/name); Google buttons shown on login/register | Medium | **OPEN** | — |
| F103 | 10 Auth & account | `includes/auth.php:36-41` | redirectIfNotLoggedIn() drops the destination → users return to dashboard after login | Medium | **OPEN** | — |
| F104 | 10 Auth & account | `verify.php:24-32` | Wrong-code limiter never expires (message says 15 minutes) | Low | **OPEN** | — |
| F105 | 10 Auth & account | `forgot_password.php vs register.php` | Account enumeration via different responses (polite message vs crash/"already registered") | Low | **OPEN** | — (crash half fixed by mail_helper; enumeration via register remains) |
| F106 | 10 Auth & account | `logout.php` | Remember token row left in DB on logout | Low | **OPEN** | — |
| F107 | 10 Auth & account | `update_schema.php` | One-off migration still deployed | Low | **PLANNED** | fix/security item 1 (repo); deploy/exclude-sensitive excludes it (FIXED, PR #3) |
| F108 | 10 Auth & account | `includes/db.php:217-234` | Seed admin `admin@angelawrites.com`/`ChangeMe123!` and test reader recreated whenever `users` is empty (AUDIT §3) | High | **OPEN** | — (credential class; see §3) |
| F109 | 10 Auth & account | `login.php:63` | Usernames `admin` and `Test User` bypass email verification (AUDIT §3) | Medium | **OPEN** | — (see §3) |
| F110 | 10 Auth & account | `register.php:123,130 / login.php:57` | Missing users columns; `users.name NOT NULL` (Env A only) | Low | **WONTFIX** | Production `users` has the columns and `name` is nullable (PRODUCTION_SCHEMA.md) |
| F111 | 11 Profile/dashboard/library | `library.php:47-50 + reader_ajax.php:616` | Library can never fill: no "add to library" anywhere; only the (previously dead) reader writes status | Medium | **OPEN** | — |
| F112 | 11 Profile/dashboard/library | `reader_ajax.php:607 vs library.php:19,40 / dashboard.php:59 / ajax_user.php:58` | Status vocabulary mismatch (`currently_reading` vs `currently reading`) | Medium | **OPEN** | — |
| F113 | 11 Profile/dashboard/library | `library.php:51` | Production: library status change fatals (FK to `users_old`) | High | **IN-PROGRESS** | db/repair-artifacts (PR #2); not yet applied |
| F114 | 11 Profile/dashboard/library | `notifications.php / includes/header.php` | Nothing ever creates a notification; bell and page are UI only | Medium | **OPEN** | — |
| F115 | 11 Profile/dashboard/library | `profile.php:100-117` | Email change saved immediately, stays verified, no confirmation → account-takeover path | High | **OPEN** | — (auth class; see §3) |
| F116 | 11 Profile/dashboard/library | `profile.php:117` | Profile save → SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F117 | 11 Profile/dashboard/library | `dashboard.php (tiles)` | Videos watched / minutes / streak / level tiles can never change (no writers or dead writers) | Low | **OPEN** | — |
| F118 | 11 Profile/dashboard/library | `dashboard.php (streak)` | "🔥 Day Streak" shows no number | Low | **OPEN** | — |
| F119 | 11 Profile/dashboard/library | `profile.php:53-66` | Avatar base64 written as .jpg without image check; old avatars never deleted | Low | **OPEN** | — |
| F120 | 11 Profile/dashboard/library | `profile.php:23 / notifications.php:50` | Missing `poem_reads`/`notifications` (Env A only) | Low | **WONTFIX** | Present in production |
| F121 | 12 Poetry | `poem_view.php:156` | Production: poem comment insert fatals (FK to `users_old`) | High | **IN-PROGRESS** | db/repair-artifacts (PR #2); not yet applied |
| F122 | 12 Poetry | `poetry.php / poem_view.php / index.php / search` | Drafts are public: no public page reads `poem_status` | Medium | **OPEN** | — |
| F123 | 12 Poetry | `admin/poem_editor.php:100` | Editor never creates a `poem_status` row → draft/publish toggles update 0 rows (11 of 13 production poems have none) | Medium | **OPEN** | — |
| F124 | 12 Poetry | `admin/poem_editor.php:109` | Poem saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F125 | 12 Poetry | `poem_view.php:168` | Comment jobs queued with NULL status and never processed → admin never told about private messages | Medium | **OPEN** | — |
| F126 | 12 Poetry | `ajax_get_users.php:48` | @-mentions skip every self-registered member (NULL name); mentions notify no one | Low | **OPEN** | — |
| F127 | 12 Poetry | `admin/preview_poem.php:2-4` | Bare relative includes → fatal (last fatal in the 207-page sweep) | Low | **PLANNED** | fix/security item 7 |
| F128 | 12 Poetry | `poem_view.php:192` | AUDIT: private comments leak via `OR ? = 1` | Medium | **WONTFIX** | Corrected by the deep scan: does not reproduce on SQLite (string '1' ≠ integer 1); latent clause tracked in the next row |
| F129 | 12 Poetry | `poem_view.php:152,180,192` | Latent always-true clause and hard-coded admin id `1`; would leak on MySQL or a binding change | Low | **OPEN** | — |
| F130 | 12 Poetry | `admin/manage_poems.php:50-56` | Bulk delete leaves reactions/poem_reads/view_logs/jobs orphans | Low | **OPEN** | — |
| F131 | 12 Poetry | `poem_view.php:116` | `poem_reads` missing (Env A only) | Low | **WONTFIX** | Present in production |
| F132 | 13 Blog | `blog_post.php:171` | Production: every blog post page fatals for guests (`comment_reactions` table missing) | High | **IN-PROGRESS** | db/repair-artifacts (PR #2) creates the table; not yet applied |
| F133 | 13 Blog | `blog_post.php:32` | Production: every blog post page fatals for members (`blog_reads` FK to `users_old`) | High | **IN-PROGRESS** | db/repair-artifacts (PR #2); not yet applied |
| F134 | 13 Blog | `blog_post.php:73,108,140` | Comment / voice comment / prayer request saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F135 | 13 Blog | `blog_post.php:43,85` | Photo/voice uploads written to `../assets/uploads/…` (outside web root) but stored as in-site paths → 404 | Medium | **OPEN** | — |
| F136 | 13 Blog | `blog_post.php (4 POST handlers)` | No CSRF token on comment/voice/prayer/admin-reply | Medium | **OPEN** | — (see §3) |
| F137 | 13 Blog | `blog_post.php:43,85` | Uploads not type-checked | Medium | **OPEN** | — (host-abuse class; see §3) |
| F138 | 13 Blog | `admin/*` | No admin screen lists prayer requests | Medium | **OPEN** | — |
| F139 | 13 Blog | `blog_post.php:171-175` | `comment_reactions` never written → counts always 0, no endpoint | Low | **OPEN** | — |
| F140 | 13 Blog | `admin/editor.php:118-146` | Broadcast: admin email first (now non-fatal), then all subscribers synchronously with 0.5 s sleeps → exceeds execution limit | Medium | **OPEN** | — (crash part fixed by mail_helper; synchronous loop remains) |
| F141 | 13 Blog | `admin/editor.php:131` | Broadcast unsubscribe link `newsletter.php?unsubscribe=1` is not handled | High | **OPEN** | — |
| F142 | 13 Blog | `blog.php:76` | "Most commented" sorts on a column that's always 0 | Low | **OPEN** | — |
| F143 | 13 Blog | `blog_post.php:187` | Reading-progress query on non-existent `blog_posts.book_id` | Medium | **OPEN** | — (fatals for members in Env A; production lacks the column too — not added by the repair) |
| F144 | 13 Blog | `blog.php:92` | `comment_count` missing (Env A only) | Low | **WONTFIX** | Present in production |
| F145 | 14 Community Q&A | `community.php:58,137,182` | `awardReputation()` undefined → answer/accept/upvote fatal after saving | High | **FIXED** | fix/core-fatals — includes/reputation.php (new); community.php:6 includes it |
| F146 | 14 Community Q&A | `community.php:82,130` | Production: ask/answer insert fatals (FK to `users_old`) | High | **IN-PROGRESS** | db/repair-artifacts (PR #2); not yet applied |
| F147 | 14 Community Q&A | `community.php:108` | Question saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F148 | 14 Community Q&A | `community.php:175` | Upvotes not tracked per user → unlimited upvotes incl. own | Medium | **OPEN** | — |
| F149 | 14 Community Q&A | `community.php:349-350` | No link to community_edit.php / community_delete.php for questions | Low | **OPEN** | — |
| F150 | 14 Community Q&A | `community_delete.php / community_answer_delete.php` | Deletes performed by GET `?confirm=` with no CSRF — confirmed | High | **OPEN** | — (CSRF class; see §3) |
| F151 | 14 Community Q&A | `community_report.php:58` | Report saved, then SMTP dump breaks the modal's JSON | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F152 | 14 Community Q&A | `community_report.php:12-14` | Unguarded input → PHP warnings printed before JSON | Low | **OPEN** | — |
| F153 | 14 Community Q&A | `community_edit.php:55 / community_delete.php:44` | Edit/delete → SMTP dump | Low | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F154 | 14 Community Q&A | `community.php:519 / admin/manage_community.php` | `user_reputations`/`reports` missing (Env A only) | Low | **WONTFIX** | Present in production |
| F155 | 15 Comments & reactions | `admin/comments.php:67` | Admin delete of a poem comment uses MySQL `NOW()` → fatal on SQLite | Medium | **OPEN** | — |
| F156 | 15 Comments & reactions | `admin/comments.php:54-60` | "Toggle private" is a GET link with no CSRF; one request made a private message public — confirmed | High | **OPEN** | — (CSRF class; see §3) |
| F157 | 15 Comments & reactions | `admin/comments.php:103` | Book reviews appear in no admin screen (filter `target_type='poem'`) | Medium | **OPEN** | — |
| F158 | 15 Comments & reactions | `admin/*` | Blog and video comments have no moderation anywhere | Medium | **OPEN** | — |
| F159 | 15 Comments & reactions | `reader/reader_ajax.php:667 + admin/comments.php:45` | `book_comments` inserts use `page_num`/`parent_id`/`is_admin_reply` and omit required `comment_text` | Medium | **IN-PROGRESS** | db/repair-artifacts (PR #2) adds columns and relaxes NOT NULL; not yet applied |
| F160 | 15 Comments & reactions | `admin/comments.php:297,418` | Undefined `$books_list`/`$poems_list` warnings on both tabs | Low | **OPEN** | — |
| F161 | 15 Comments & reactions | `ajax_toggle_reaction.php:43-50` | Accepts any `target_type` and reaction name (no allow-list) | Low | **OPEN** | — (input validation; see §3) |
| F162 | 16 Newsletter & email | `includes/mail_helper.php:25-33` | `sendEmail()` die()s with error/file/line on any SMTP failure (60 call sites, 36 files); 300 s default timeout | High | **FIXED** | fix/core-fatals — includes/mail_helper.php:25 (10 s timeout), :33-35 (log + return false) |
| F163 | 16 Newsletter & email | `newsletter.php:9-16` | Logged-in members are redirected before subscribing — members can't subscribe | Medium | **OPEN** | — |
| F164 | 16 Newsletter & email | `newsletter.php:63,69 / unsubscribe.php:33,72` | Subscribe/unsubscribe saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F165 | 16 Newsletter & email | `newsletter.php:78,89` | Open redirect via POST `redirect` (`https://angelwrites.gt.tc@evil.example/`) — confirmed | Medium | **OPEN** | — (see §3) |
| F166 | 16 Newsletter & email | `newsletter.php:41` | Re-subscribing a lapsed subscriber fatals (production lacks `updated_at`) | Medium | **IN-PROGRESS** | db/repair-artifacts (PR #2) adds the column; not yet applied |
| F167 | 16 Newsletter & email | `admin/export_subscribers.php:14` | Production lacks `newsletter.source` → subscriber export fatals | Medium | **IN-PROGRESS** | db/repair-artifacts (PR #2) adds the column; not yet applied |
| F168 | 16 Newsletter & email | `admin/manage_newsletter.php (tab)` | Subscribers tab requires non-existent `newsletter_tabs/subscribers.php` → fatal; bulk actions and segment assignment unreachable | High | **OPEN** | — |
| F169 | 16 Newsletter & email | `admin/manage_newsletter.php:136-160` | "Send now" sends to the first 10 recipients, marks all sent and archives full count | High | **OPEN** | — |
| F170 | 16 Newsletter & email | `admin/manage_newsletter.php (queue)` | Failed send leaves the queue row `processing` forever; never retried | Medium | **OPEN** | — |
| F171 | 16 Newsletter & email | `admin/manage_newsletter.php:106-108` | Unparseable schedule stored as 1970 (date() never returns false) | Low | **OPEN** | — |
| F172 | 16 Newsletter & email | `admin/process_queue.php` | Scheduled sends need cron; never run | Medium | **OPEN** | — |
| F173 | 16 Newsletter & email | `admin/manage_newsletter.php (segments)` | Segments can never be populated (assignment lives on the missing tab) | Medium | **OPEN** | — |
| F174 | 16 Newsletter & email | `index.php:9-19` | Guest page cache would serve another visitor's CSRF token if `cache/` existed (latent) | Low | **OPEN** | — |
| F175 | 16 Newsletter & email | `admin/newsletter_tabs/*.php` | Tab partials reachable directly without an admin check (markup only) | Low | **OPEN** | — (see §3) |
| F176 | 16 Newsletter & email | `newsletter.php` | No double opt-in; anyone can subscribe anyone | Low | **OPEN** | — |
| F177 | 16 Newsletter & email | `newsletter.php:32` | `unsubscribe_token` missing (Env A only) | Low | **WONTFIX** | Present in production |
| F178 | 17 Contact | `contact.php:50,55 / about.php:43` | Message saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F179 | 17 Contact | `contact.php:25-27` | Captcha client-trusted: expected answer in a hidden field; omitting both passes — confirmed | Medium | **OPEN** | — (abuse class; see §3) |
| F180 | 17 Contact | `contact.php:55` | Copy of the message emailed to whatever address the visitor typed (relay once SMTP works) | Medium | **OPEN** | — (abuse class; see §3) |
| F181 | 17 Contact | `admin/manage_messages.php` | No reply action / mailto link | Low | **OPEN** | — |
| F182 | 17 Contact | `admin/manage_messages.php` | Mark-read/delete by GET without CSRF | Low | **OPEN** | — (CSRF class; see §3) |
| F183 | 18 Session booking | `book_session.php:85` | Production lacks `sessions.session_type` (and FK to `users_old`) → every booking fatals | High | **IN-PROGRESS** | db/repair-artifacts (PR #2); not yet applied |
| F184 | 18 Session booking | `book_session.php:106,121 / session_edit.php:89 / session_cancel.php:47` | Booking/edit/cancel saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F185 | 18 Session booking | `book_session.php:15,121` | Member confirmation addressed to an undefined `$user['email']` (query selected name only) | Low | **FIXED** | fix/core-fatals — book_session.php:15 selects `name, email` |
| F186 | 18 Session booking | `book_session.php:64,86` | Timezone collected, never stored | Medium | **OPEN** | — (repair adds the column; code doesn't write it) |
| F187 | 18 Session booking | `session_cancel.php` | Cancel by GET `?confirm=1`, no CSRF — confirmed | Medium | **OPEN** | — (CSRF class; see §3) |
| F188 | 18 Session booking | `admin/manage_sessions.php` | Confirm/cancel doesn't notify the member | Medium | **OPEN** | — |
| F189 | 18 Session booking | `admin/manage_sessions.php` | Delete by GET | Low | **OPEN** | — (CSRF class; see §3) |
| F190 | 19 Admin & analytics | `ajax_admin.php` | No auth: guests read stats, visitor IPs and reader names/emails — confirmed | Critical | **PLANNED** | fix/security item 2 |
| F191 | 19 Admin & analytics | `admin/settings.php` | Settings saved but never read (site name/logo/admin email) | Medium | **OPEN** | — |
| F192 | 19 Admin & analytics | `admin/settings.php:81` | Save writes `newsletter_audit_log` (absent on repo schema) → fatal after saving (Env A) | Low | **WONTFIX** | Table exists in production |
| F193 | 19 Admin & analytics | `reader/admin/reader_analytics.php:256` | `formatDuration()` undefined once real reading sessions exist → fatal (production) | Medium | **FIXED** | fix/core-fatals — reader/admin/reader_analytics.php:11 requires reader/reader_functions.php |
| F194 | 19 Admin & analytics | `admin/dashboard.php / ajax_admin.php` | Reading-activity figures structurally zero (writer was dead) | Low | **OPEN** | — |
| F195 | 20 Layout/assets/infra | `.github/workflows/deploy.yml` | Deploy published docs/, one-off scripts (reset_admin, worker, update_schema, allow_url_fopen), composer files | Critical | **FIXED** | deploy/exclude-sensitive (PR #3) — .github/workflows/deploy.yml:22-39 exclude list (simulated: 0 sensitive files uploaded) |
| F196 | 20 Layout/assets/infra | `worker.php` | Web-reachable `while(true)` loop; MySQL `NOW()` | High | **PLANNED** | Server deletion: your Step 3; repo deletion: fix/security item 1; no re-upload: PR #3 (FIXED) |
| F197 | 20 Layout/assets/infra | `allow_url_fopen` | Stray `<?php phpinfo(); ?>` file deployed | Low | **PLANNED** | Server deletion: Step 3; repo deletion: fix/security item 1 |
| F198 | 20 Layout/assets/infra | `includes/header.php` | Mobile menu opens ~30 px on-screen at 375 px; button clipped | High | **PLANNED** | fix/security item 6 |
| F199 | 20 Layout/assets/infra | `assets/js/main.js:170` | Populates a non-existent Bible modal → JS error on every page; active-nav code never runs | Medium | **OPEN** | — |
| F200 | 20 Layout/assets/infra | `serve_og.php:16,31` | Uses `$db` without including db.php; fallback logo file missing → every poem share image broken | Medium | **OPEN** | — |
| F201 | 20 Layout/assets/infra | `generate_og_image.php:13-26` | Reads any image path reachable by PHP (`src=../…` outside the site) | Low | **OPEN** | — (path reach; see §3) |
| F202 | 20 Layout/assets/infra | `index.php:83` | Testimonials submitted but no admin screen can approve them | Medium | **OPEN** | — |
| F203 | 20 Layout/assets/infra | `index.php:187` | Testimonial saved, then SMTP dump | Medium | **FIXED** | fix/core-fatals — includes/mail_helper.php:33-35 |
| F204 | 20 Layout/assets/infra | `sw.js` | Cache-first service worker with fixed name → returning visitors get stale homepage/CSS/JS forever | Medium | **OPEN** | — |
| F205 | 20 Layout/assets/infra | `includes/db.php:205-255` | DDL + seed check on every request; with per-view writes → "database is locked" on 4 of 60 concurrent views — confirmed | High | **OPEN** | — |
| F206 | 20 Layout/assets/infra | `data/.htaccess` | Never uploaded by the deploy (data/** excluded since day one) → /data/site.db may be downloadable | Critical | **OPEN** | — (verify with check 3 on PR #3; manual upload if needed) |
| F207 | 20 Layout/assets/infra | `includes/db.php (definitions)` | Repo schema can't build a working fresh install (code needs 86 tables, repo creates 23; definitions conflict with inserts) | High | **OPEN** | — (production is handled by the repair; the repo itself is not) |
| F208 | 20 Layout/assets/infra | `includes/db.php:31 + 54 statements` | SQLite-only SQL throughout; MySQL-only SQL in 3 places (NOW, ON DUPLICATE KEY) | Low | **OPEN** | — (MySQL instances listed in domains 06/15/20) |
| F209 | 20 Layout/assets/infra | `reader_reminders / process_queue / worker / book_processing_queue` | Features that need cron/background workers the host doesn't provide | Medium | **OPEN** | — (see each domain's row) |
| F210 | 20 Layout/assets/infra | `index.php:9,1225` | Page cache writes to a non-existent `cache/` folder → warning on every guest visit | Low | **OPEN** | — |
| F211 | 20 Layout/assets/infra | `book.php:8-9 / reader/reader.php:8-10 / admin/process_book.php:6-8` | `display_errors` forced on in production → server paths leak | Medium | **OPEN** | — (information leak; see §3) |
| F212 | 20 Layout/assets/infra | `reader/cron/reader_reminders.php:13` | Hard-coded cron key `angelwrites_reader_cron` in the URL (AUDIT §3) | Low | **OPEN** | — |
| F213 | 20 Layout/assets/infra | `includes/config.php / 40 files` | SITE_URL hard-coded; admin address hard-coded 107× while `ADMIN_EMAIL` is unused (AUDIT §3) | Low | **OPEN** | — |
| F214 | 20 Layout/assets/infra | `admin/manage_groups.php:23-27` | SQL built by string concatenation (int-cast, not exploitable) (AUDIT §4) | Low | **OPEN** | — |
| F215 | 20 Layout/assets/infra | `admin/comments.php:123-124,173-174` | Count query derived from select via str_replace (fragile) (AUDIT §4) | Low | **OPEN** | — |
| F216 | 20 Layout/assets/infra | `video_watch.php:79 / update_schema.php:27 / IN ($placeholders)` | Concatenated SQL that is whitelisted or hard-coded (AUDIT §4) | — | **WONTFIX** | Not exploitable: values whitelisted, hard-coded or bound (AUDIT §4) |
| F217 | 20 Layout/assets/infra | `includes/mail_helper.php (From)` | From address is a Zoho address sent via Gmail SMTP (rewritten or spam-flagged) | Low | **OPEN** | — |
| F218 | 20 Layout/assets/infra | `admin/process_book.php (extract)` | PDF/EPUB/DOCX extraction runs inside the request (smalot/pdfparser, ZipArchive) → large books hit host time/memory limits (AUDIT §6.2) | Medium | **OPEN** | — |
| F219 | 20 Layout/assets/infra | `includes/db.php:21,31 + includes/config.php:8` | SQLite DSN defined twice; `DB_PATH` constant unused (AUDIT §3) | Low | **OPEN** | — |
| F220 | 20 Layout/assets/infra | `vendor/ + vendor/composer/platform_check.php` | Committed Composer dependencies; requires PHP ≥ 7.2 (AUDIT §6.6) | — | **WONTFIX** | Informational: works without Composer on the server; no defect |
| F221 | 21 Static & legal | `about.php:55-65` | About page runs the visitor's reputation/achievement/streak queries (member fatal on repo schema; unrelated to the page) | Low | **OPEN** | — (no fatal in production; queries still pointless) |
| F222 | 21 Static & legal | `privacy.php:59 / terms.php:59 / accessibility.php:118` | "Last updated" is always today's date | Medium | **OPEN** | — |
| F223 | 21 Static & legal | `privacy.php:20` | Privacy text inaccurate: names Zoho (Gmail used); omits IP logging, DOB/gender/phone, camera/voice uploads, Google sign-in, CDNs | High | **OPEN** | — |
| F224 | 21 Static & legal | `accessibility.php` | Claims WCAG 2.1 AA conformance that the site doesn't meet | Medium | **OPEN** | — |
| F225 | 21 Static & legal | `.htaccess + 404.php` | No `ErrorDocument 404`; 404.php returns HTTP 200 | Low | **OPEN** | — |
| F226 | 21 Static & legal | `about.php:8 / privacy.php:8 / terms.php:8` | Fatal on missing `settings` (fresh DB only) | Low | **WONTFIX** | Table exists in production |
| F227 | P Production DB | `19 tables (FK → users_old)` | Foreign keys point at empty `users_old`; every insert into questions, answers, reviews, sessions, reading_status/progress, blog_reads, notifications, prayer_requests, reflection_comments… fails | Critical | **IN-PROGRESS** | db/repair-artifacts (PR #2) — db/repair/repair.sql Part 1 (tested); not yet applied |
| F228 | P Production DB | `4 tables` | Missing tables `comment_reactions`, `purchases`, `review_reactions`, `video_comments` | High | **IN-PROGRESS** | db/repair-artifacts — repair.sql Part 3; not yet applied |
| F229 | P Production DB | `~16 tables, 30 columns` | Missing columns used by the code (sessions.session_type, reflections.*, blog_posts likes/loves/prays, newsletter.updated_at/source, …) | High | **IN-PROGRESS** | db/repair-artifacts — repair.sql Part 2; not yet applied |
| F230 | P Production DB | `reading_groups.book_id, book_comments` | NOT NULL constraints the code can't satisfy | High | **IN-PROGRESS** | db/repair-artifacts — repair.sql Part 1 relaxations; not yet applied |
| F231 | P Production DB | `reading_progress ×2, poem_reads ×1` | Orphaned FK rows (referencing `users_old`) | Low | **IN-PROGRESS** | db/repair-artifacts — valid after repoint (users exist); not yet applied |
| F232 | P Production DB | `users (5 of 10 readers)` | Half of real signups never verified (consequence of email delivery) | High | **OPEN** | — (see domain 10 delivery row) |

### 1. Totals

| Status | Functional | Quality | Total |
|---|---|---|---|
| FIXED | 35 | 0 | 35 |
| IN-PROGRESS | 21 | 0 | 21 |
| PLANNED | 11 | 0 | 11 |
| OPEN | 150 | 55 | 205 |
| WONTFIX | 15 | 0 | 15 |
| **All** | **232** | **55** | **287** |

Per domain (functional + quality):

| Domain | FIXED | IN-PROGRESS | PLANNED | OPEN | WONTFIX | Total |
|---|---|---|---|---|---|---|
| 01 Reader | 11 | 0 | 0 | 17 | 1 | 29 |
| 02 Bible reader | 1 | 0 | 1 | 12 | 1 | 15 |
| 03 Downloads & gate | 1 | 0 | 1 | 9 | 0 | 11 |
| 04 Reading groups | 0 | 1 | 0 | 12 | 1 | 14 |
| 05 Search | 0 | 1 | 2 | 5 | 0 | 8 |
| 06 Video | 1 | 1 | 0 | 8 | 1 | 11 |
| 07 Reflections | 0 | 1 | 0 | 5 | 0 | 6 |
| 08 Payments | 0 | 1 | 0 | 7 | 0 | 8 |
| 09 Books | 2 | 2 | 0 | 10 | 0 | 14 |
| 10 Auth & account | 4 | 0 | 2 | 14 | 1 | 21 |
| 11 Profile/dashboard/library | 1 | 1 | 0 | 11 | 1 | 14 |
| 12 Poetry | 1 | 1 | 1 | 8 | 2 | 13 |
| 13 Blog | 1 | 2 | 0 | 10 | 1 | 14 |
| 14 Community Q&A | 4 | 1 | 0 | 5 | 1 | 11 |
| 15 Comments & reactions | 0 | 1 | 0 | 7 | 0 | 8 |
| 16 Newsletter & email | 2 | 2 | 0 | 13 | 1 | 18 |
| 17 Contact | 1 | 0 | 0 | 5 | 0 | 6 |
| 18 Session booking | 2 | 1 | 0 | 5 | 0 | 8 |
| 19 Admin & analytics | 1 | 0 | 1 | 4 | 1 | 7 |
| 20 Layout/assets/infra | 2 | 0 | 3 | 30 | 2 | 37 |
| 21 Static & legal | 0 | 0 | 0 | 7 | 1 | 8 |
| P Production DB | 0 | 5 | 0 | 1 | 0 | 6 |

**287 of 287 findings accounted for** (232 functional/security + 55 quality). Closed or on a branch (FIXED + IN-PROGRESS): 56 functional. OPEN: 150 functional + 55 quality.

### 2. Gap list (every OPEN functional/security row, by domain)

**01 Reader** (9)
- F9 · Low · `reader/reader_ajax.php:162`: XP for highlights/finishing never awarded: `updateUserStats()` lives in reader_gamification.php, which reader_ajax.php never includes
- F10 · Medium · `dashboard.php (member modules)`: Dashboard "Reader Tools" link 8 raw partials/endpoints: Open Reader (no id → redirect), Export (raw JSON 400), Circles (raw JSON), My Analytics (admin-only page), Challenges/TTS (raw fragments), Gamification (blank)
- F11 · Medium · `reader/reader_circles.php:281`: Circle invite emails link to non-existent `reader/join_circle.php` → every invite 404s
- F12 · Low · `reader/reader_export.php`: Export served as raw JSON `text/html`, no download headers; reader `export_highlights` unreachable before fix
- F13 · Low · `reader/cron/reader_reminders.php:7`: Relative `../includes/` resolves against reader/cron/ → fatal when run; also needs cron the host doesn't have
- F17 · Medium · `reader/reader.php:46`: Group lookup uses `reading_groups.book_id`; groups are stored as content_type/content_id → reader never finds the member's group, group notes never appear
- F18 · Critical · `reader/book_data.php (access)`: Paid books fully readable by anyone (guests included): book_data.php has no price/purchase check and renders every page
- F19 · Low · `reader/reader.php (JS)`: `get_challenge_history` sent by the challenge widget has no handler in reader_ajax.php
- F20 · Low · `reader/book_data.php:52`: Page split regex stops at the first nested `</div>` → a page edited to contain a div is silently truncated (latent)

**02 Bible reader** (7)
- F23 · Low · `bible_reader.php:647`: Writes to missing `#notesVerseRef` → TypeError on every chapter load; "Go to" box never updates
- F24 · Medium · `bible_reader.php:215 + includes/bible_lookup.php:14`: YLT offered but no DB → silently serves KJV labelled YLT
- F25 · Medium · `assets/bible/bible_ASV.db`: ASV has only 20 books; Proverbs stops at ch. 28; menu offers all 66, shows raw "[ASV Isaiah 1 not found]"
- F27 · Medium · `bible_reader.php (~1450)`: Verse highlight keyed on the dropdown verse, not the selected text; localStorage only (one device)
- F28 · Low · `bible_reader.php (JS)`: JS sends `action=add_highlight`; bible_reader_ajax.php has no handler
- F29 · Medium · `bible_reader.php`: Shared verse links ignore book/chapter/verse (no `$_GET`/`location.search` read) → always opens default passage
- F30 · High · `assets/bible/*.db`: 41 MB of Bible DBs (incl. copyrighted NIV/ESV/NASB/NKJV/AMP) directly downloadable over HTTP

**03 Downloads & gate** (7)
- F33 · Critical · `admin/manage_books.php:99-103`: Uploaded book files stored in public `assets/uploads/books/` with predictable names → direct URL download, no gate
- F34 · High · `download.php:46-50 + process_payment.php:58-63`: Payments written to `payments`, gate reads `purchases` → every paid download refused forever
- F35 · High · `process_payment.php:23`: Payment amount taken from the browser
- F37 · Low · `books.php (?error=not_purchased)`: Refused download redirects to `books.php?error=not_purchased`, which never shows the message
- F38 · Medium · `login.php:117 + download.php:19`: Post-login redirect only honours absolute SITE_URL; relative `redirect=` from download.php ignored → user lands on dashboard
- F39 · High · `admin/manage_books.php:101`: Book upload accepts any file extension into a public folder
- F40 · Low · `download.php:26,35,62`: Errors are bare `die()` white pages without site layout

**04 Reading groups** (10)
- F41 · High · `create_group.php:225`: Fetches non-existent `ajax/get_content_items.php` → content dropdown "Loading…" forever; no member can create a group
- F43 · Medium · `group_ajax.php`: No reply action/UI: discussions show "0 replies" but can never be replied to (`addDiscussionReply()` unused)
- F44 · Medium · `group_ajax.php`: Creator actions `update_group`/`change_member_role`/`remove_member` return "Invalid action" (handlers only in broken admin copy)
- F45 · High · `group_ajax.php:26-60`: Non-members can post discussions/notes into any group, private ones included — confirmed
- F46 · Medium · `admin/manage_groups_ajax.php:7-10`: Bare relative includes resolve against admin/ → fatal on every request; also only checks login, not admin (AUDIT §4)
- F47 · Medium · `admin/manage_groups.php:15-30`: Delete handled after HTML header output → JSON appended to a 21 KB page; admin JS fails though delete happened
- F48 · Medium · `admin/manage_groups.php:79 + includes/reading_groups.php:66,74,96`: Joins non-existent `newsletters` table and `poems.author`
- F49 · Low · `groups.php:165`: `closeJoinModalAfterSubmit` undefined (JS error on join)
- F50 · Low · `group_ajax.php:42`: Undefined `$_POST['book_id']` warning printed before JSON when display_errors on
- F51 · Medium · `groups.php`: No group discovery: no directory, nothing on the book page; code-only joining

**05 Search** (4)
- F56 · Medium · `includes/header.php:305 + search_results.php:13`: Header search icon links without a query → redirected to homepage; search is effectively hidden
- F57 · Low · `search_results.php:209`: Book results link straight into the reader instead of book.php
- F58 · Low · `search_results.php:105-112`: Pagination applies LIMIT 12 per type then merges (up to 60/page; wrong page count)
- F59 · Low · `search_results.php (sort)`: "Relevance" isn't implemented; "Most commented" sorts on a column nothing writes

**06 Video** (7)
- F61 · Medium · `video_watch.php:43`: MySQL `ON DUPLICATE KEY UPDATE` on SQLite → like/dislike fatals
- F62 · Medium · `video_watch.php:28-29`: Like/dislike counts fall back to `rand(100,500)`/`rand(10,50)` — fabricated numbers
- F63 · Medium · `videos.php:63`: "Most commented" sort → fatal (`videos.comment_count` doesn't exist)
- F64 · Low · `videos.php (views)`: "Most viewed" meaningless: `videos.views` never incremented (column also missing in production)
- F66 · Low · `video_watch.php vs videos_reactions.php:22`: Two reaction vocabularies on one table (like/dislike vs like/love/pray)
- F67 · Low · `video_watch.php:111-114`: Only `watch?v=` YouTube links embed; youtu.be/shorts/&t= fail
- F68 · High · `admin/manage_videos.php:78-83`: Video upload: no extension/size check into a public folder

**07 Reflections** (4)
- F71 · High · `admin/reflection_editor.php:108 vs reflections.php`: Admin writes reflections to `blog_posts`; public page reads `reflections` → published reflections never appear there
- F72 · High · `reflections.php (4 links)`: Every reflection link points to non-existent `reflection.php` → 404
- F73 · Medium · `includes/db.php:183 + admin/manage_reflections.php:24`: Default blog category "Christian Reflections" makes every blog post a "reflection" in admin
- F74 · Low · `reflections.php (nav)`: Reflections page not in public navigation (only dashboards link it)

**08 Payments** (6)
- F75 · Critical · `process_payment.php:58-63`: "Payment" recorded as `completed` with no provider contacted — simulated payment shown as real
- F76 · High · `process_payment.php:60`: Phone number entered by the payer is never stored
- F77 · Medium · `dashboard.php/profile.php/library.php`: No "My purchases"/receipt for members
- F78 · High · `admin/*`: No admin screen to see or confirm payments
- F79 · Low · `books.php:121-122`: Sale shows the normal price in a "sale" colour (no sale price field)
- F80 · Low · `process_payment.php:41`: Price 0 + not free → "Invalid amount", book unobtainable

**09 Books** (8)
- F86 · Medium · `books.php`: Every card shows "Read", including unprocessed books → empty reader "1 / 0"
- F87 · Low · `book.php:155`: Streak shows blank: `fetchColumn() ?? 0` doesn't catch `false`
- F88 · Medium · `book.php:8-9`: `display_errors` forced on; notices corrupt JSON responses and leak paths
- F89 · Medium · `admin/process_book.php (~1100)`: "Add to processing queue" says success; nothing ever processes the queue
- F90 · Low · `admin/process_book.php:607`: Per-book reader CSS saved but never read by the reader
- F91 · Low · `admin/process_book.php:989`: sendEmail() called with 8 args (attachment) — signature takes 6, attachment silently dropped
- F92 · Low · `admin/process_book.php:172`: `exec()` with interpolated file path (shell injection if a path ever contains `'`; exec usually disabled)
- F93 · Low · `book.php (reviews)`: One member can review the same book repeatedly

**10 Auth & account** (10)
- F96 · High · `includes/mail_helper.php (delivery)`: Verification/reset codes can't be delivered while outbound SMTP is blocked → half of real signups unverified
- F100 · Medium · `login.php + dashboard greeting`: "Welcome back, Deprecated: htmlspecialchars()…" — self-registered users have NULL `name`
- F101 · Medium · `login.php:81-109`: Remember-me token written, never read → logged out at ~24 h session expiry; cookie lacks `secure`
- F102 · Medium · `social_login.php:36-58`: Google/Facebook login needs outbound OAuth (blocked); sets only user_id (no role/name); Google buttons shown on login/register
- F103 · Medium · `includes/auth.php:36-41`: redirectIfNotLoggedIn() drops the destination → users return to dashboard after login
- F104 · Low · `verify.php:24-32`: Wrong-code limiter never expires (message says 15 minutes)
- F105 · Low · `forgot_password.php vs register.php`: Account enumeration via different responses (polite message vs crash/"already registered")
- F106 · Low · `logout.php`: Remember token row left in DB on logout
- F108 · High · `includes/db.php:217-234`: Seed admin `admin@angelawrites.com`/`ChangeMe123!` and test reader recreated whenever `users` is empty (AUDIT §3)
- F109 · Medium · `login.php:63`: Usernames `admin` and `Test User` bypass email verification (AUDIT §3)

**11 Profile/dashboard/library** (7)
- F111 · Medium · `library.php:47-50 + reader_ajax.php:616`: Library can never fill: no "add to library" anywhere; only the (previously dead) reader writes status
- F112 · Medium · `reader_ajax.php:607 vs library.php:19,40 / dashboard.php:59 / ajax_user.php:58`: Status vocabulary mismatch (`currently_reading` vs `currently reading`)
- F114 · Medium · `notifications.php / includes/header.php`: Nothing ever creates a notification; bell and page are UI only
- F115 · High · `profile.php:100-117`: Email change saved immediately, stays verified, no confirmation → account-takeover path
- F117 · Low · `dashboard.php (tiles)`: Videos watched / minutes / streak / level tiles can never change (no writers or dead writers)
- F118 · Low · `dashboard.php (streak)`: "🔥 Day Streak" shows no number
- F119 · Low · `profile.php:53-66`: Avatar base64 written as .jpg without image check; old avatars never deleted

**12 Poetry** (6)
- F122 · Medium · `poetry.php / poem_view.php / index.php / search`: Drafts are public: no public page reads `poem_status`
- F123 · Medium · `admin/poem_editor.php:100`: Editor never creates a `poem_status` row → draft/publish toggles update 0 rows (11 of 13 production poems have none)
- F125 · Medium · `poem_view.php:168`: Comment jobs queued with NULL status and never processed → admin never told about private messages
- F126 · Low · `ajax_get_users.php:48`: @-mentions skip every self-registered member (NULL name); mentions notify no one
- F129 · Low · `poem_view.php:152,180,192`: Latent always-true clause and hard-coded admin id `1`; would leak on MySQL or a binding change
- F130 · Low · `admin/manage_poems.php:50-56`: Bulk delete leaves reactions/poem_reads/view_logs/jobs orphans

**13 Blog** (9)
- F135 · Medium · `blog_post.php:43,85`: Photo/voice uploads written to `../assets/uploads/…` (outside web root) but stored as in-site paths → 404
- F136 · Medium · `blog_post.php (4 POST handlers)`: No CSRF token on comment/voice/prayer/admin-reply
- F137 · Medium · `blog_post.php:43,85`: Uploads not type-checked
- F138 · Medium · `admin/*`: No admin screen lists prayer requests
- F139 · Low · `blog_post.php:171-175`: `comment_reactions` never written → counts always 0, no endpoint
- F140 · Medium · `admin/editor.php:118-146`: Broadcast: admin email first (now non-fatal), then all subscribers synchronously with 0.5 s sleeps → exceeds execution limit
- F141 · High · `admin/editor.php:131`: Broadcast unsubscribe link `newsletter.php?unsubscribe=1` is not handled
- F142 · Low · `blog.php:76`: "Most commented" sorts on a column that's always 0
- F143 · Medium · `blog_post.php:187`: Reading-progress query on non-existent `blog_posts.book_id`

**14 Community Q&A** (4)
- F148 · Medium · `community.php:175`: Upvotes not tracked per user → unlimited upvotes incl. own
- F149 · Low · `community.php:349-350`: No link to community_edit.php / community_delete.php for questions
- F150 · High · `community_delete.php / community_answer_delete.php`: Deletes performed by GET `?confirm=` with no CSRF — confirmed
- F152 · Low · `community_report.php:12-14`: Unguarded input → PHP warnings printed before JSON

**15 Comments & reactions** (6)
- F155 · Medium · `admin/comments.php:67`: Admin delete of a poem comment uses MySQL `NOW()` → fatal on SQLite
- F156 · High · `admin/comments.php:54-60`: "Toggle private" is a GET link with no CSRF; one request made a private message public — confirmed
- F157 · Medium · `admin/comments.php:103`: Book reviews appear in no admin screen (filter `target_type='poem'`)
- F158 · Medium · `admin/*`: Blog and video comments have no moderation anywhere
- F160 · Low · `admin/comments.php:297,418`: Undefined `$books_list`/`$poems_list` warnings on both tabs
- F161 · Low · `ajax_toggle_reaction.php:43-50`: Accepts any `target_type` and reaction name (no allow-list)

**16 Newsletter & email** (11)
- F163 · Medium · `newsletter.php:9-16`: Logged-in members are redirected before subscribing — members can't subscribe
- F165 · Medium · `newsletter.php:78,89`: Open redirect via POST `redirect` (`https://angelwrites.gt.tc@evil.example/`) — confirmed
- F168 · High · `admin/manage_newsletter.php (tab)`: Subscribers tab requires non-existent `newsletter_tabs/subscribers.php` → fatal; bulk actions and segment assignment unreachable
- F169 · High · `admin/manage_newsletter.php:136-160`: "Send now" sends to the first 10 recipients, marks all sent and archives full count
- F170 · Medium · `admin/manage_newsletter.php (queue)`: Failed send leaves the queue row `processing` forever; never retried
- F171 · Low · `admin/manage_newsletter.php:106-108`: Unparseable schedule stored as 1970 (date() never returns false)
- F172 · Medium · `admin/process_queue.php`: Scheduled sends need cron; never run
- F173 · Medium · `admin/manage_newsletter.php (segments)`: Segments can never be populated (assignment lives on the missing tab)
- F174 · Low · `index.php:9-19`: Guest page cache would serve another visitor's CSRF token if `cache/` existed (latent)
- F175 · Low · `admin/newsletter_tabs/*.php`: Tab partials reachable directly without an admin check (markup only)
- F176 · Low · `newsletter.php`: No double opt-in; anyone can subscribe anyone

**17 Contact** (4)
- F179 · Medium · `contact.php:25-27`: Captcha client-trusted: expected answer in a hidden field; omitting both passes — confirmed
- F180 · Medium · `contact.php:55`: Copy of the message emailed to whatever address the visitor typed (relay once SMTP works)
- F181 · Low · `admin/manage_messages.php`: No reply action / mailto link
- F182 · Low · `admin/manage_messages.php`: Mark-read/delete by GET without CSRF

**18 Session booking** (4)
- F186 · Medium · `book_session.php:64,86`: Timezone collected, never stored
- F187 · Medium · `session_cancel.php`: Cancel by GET `?confirm=1`, no CSRF — confirmed
- F188 · Medium · `admin/manage_sessions.php`: Confirm/cancel doesn't notify the member
- F189 · Low · `admin/manage_sessions.php`: Delete by GET

**19 Admin & analytics** (2)
- F191 · Medium · `admin/settings.php`: Settings saved but never read (site name/logo/admin email)
- F194 · Low · `admin/dashboard.php / ajax_admin.php`: Reading-activity figures structurally zero (writer was dead)

**20 Layout/assets/infra** (19)
- F199 · Medium · `assets/js/main.js:170`: Populates a non-existent Bible modal → JS error on every page; active-nav code never runs
- F200 · Medium · `serve_og.php:16,31`: Uses `$db` without including db.php; fallback logo file missing → every poem share image broken
- F201 · Low · `generate_og_image.php:13-26`: Reads any image path reachable by PHP (`src=../…` outside the site)
- F202 · Medium · `index.php:83`: Testimonials submitted but no admin screen can approve them
- F204 · Medium · `sw.js`: Cache-first service worker with fixed name → returning visitors get stale homepage/CSS/JS forever
- F205 · High · `includes/db.php:205-255`: DDL + seed check on every request; with per-view writes → "database is locked" on 4 of 60 concurrent views — confirmed
- F206 · Critical · `data/.htaccess`: Never uploaded by the deploy (data/** excluded since day one) → /data/site.db may be downloadable
- F207 · High · `includes/db.php (definitions)`: Repo schema can't build a working fresh install (code needs 86 tables, repo creates 23; definitions conflict with inserts)
- F208 · Low · `includes/db.php:31 + 54 statements`: SQLite-only SQL throughout; MySQL-only SQL in 3 places (NOW, ON DUPLICATE KEY)
- F209 · Medium · `reader_reminders / process_queue / worker / book_processing_queue`: Features that need cron/background workers the host doesn't provide
- F210 · Low · `index.php:9,1225`: Page cache writes to a non-existent `cache/` folder → warning on every guest visit
- F211 · Medium · `book.php:8-9 / reader/reader.php:8-10 / admin/process_book.php:6-8`: `display_errors` forced on in production → server paths leak
- F212 · Low · `reader/cron/reader_reminders.php:13`: Hard-coded cron key `angelwrites_reader_cron` in the URL (AUDIT §3)
- F213 · Low · `includes/config.php / 40 files`: SITE_URL hard-coded; admin address hard-coded 107× while `ADMIN_EMAIL` is unused (AUDIT §3)
- F214 · Low · `admin/manage_groups.php:23-27`: SQL built by string concatenation (int-cast, not exploitable) (AUDIT §4)
- F215 · Low · `admin/comments.php:123-124,173-174`: Count query derived from select via str_replace (fragile) (AUDIT §4)
- F217 · Low · `includes/mail_helper.php (From)`: From address is a Zoho address sent via Gmail SMTP (rewritten or spam-flagged)
- F218 · Medium · `admin/process_book.php (extract)`: PDF/EPUB/DOCX extraction runs inside the request (smalot/pdfparser, ZipArchive) → large books hit host time/memory limits (AUDIT §6.2)
- F219 · Low · `includes/db.php:21,31 + includes/config.php:8`: SQLite DSN defined twice; `DB_PATH` constant unused (AUDIT §3)

**21 Static & legal** (5)
- F221 · Low · `about.php:55-65`: About page runs the visitor's reputation/achievement/streak queries (member fatal on repo schema; unrelated to the page)
- F222 · Medium · `privacy.php:59 / terms.php:59 / accessibility.php:118`: "Last updated" is always today's date
- F223 · High · `privacy.php:20`: Privacy text inaccurate: names Zoho (Gmail used); omits IP logging, DOB/gender/phone, camera/voice uploads, Google sign-in, CDNs
- F224 · Medium · `accessibility.php`: Claims WCAG 2.1 AA conformance that the site doesn't meet
- F225 · Low · `.htaccess + 404.php`: No `ErrorDocument 404`; 404.php returns HTTP 200

**P Production DB** (1)
- F232 · High · `users (5 of 10 readers)`: Half of real signups never verified (consequence of email delivery)

Quality rows (55 OPEN) are listed in section 4, not repeated here.

### 3. What `fix/security` must contain to close its domains

**The seven planned items are not complete.** They cover:
- deleting the one-off scripts (item 1)
- `ajax_admin.php` auth (2)
- the `book.php` raw-file link (3)
- private Bible notes (4)
- search and its reflection link (5)
- the mobile menu (6)
- `preview_poem.php` (7)

Reading both audits for the same classes (auth, leakage, injection, guest access, host abuse), these are missing:

**Must add: same severity as the seven (Critical/High)**

| # | Addition | Rows | Why it belongs in this batch |
|---|---|---|---|
| S1 | **Gate the reader for paid books:** `reader/book_data.php` renders every page with no purchase check, so a guest reads a paid book in full. Item 3 gates only `book.php`'s links. | F18 | Same money and data leak as item 3, by a different door. |
| S2 | **Stop serving book files straight from `assets/uploads/books/`:** add a deny rule for that folder (or move the files) so files are reachable only through `download.php`. Gating links (item 3) doesn't stop someone who has or guesses the URL. | F33 | Item 3's own acceptance test ("if they guess the URL, a refusal") can't pass without it. |
| S3 | **`data/.htaccess` must exist on the server.** The deploy has never uploaded it (`data/**` excluded since day one). If `/data/site.db` downloads, the entire database leaks, including password hashes and emails. | F206 | Biggest possible leak. A one-line file, but it needs either a manual upload or a deploy change (the exclude currently blocks it). |
| S4 | **Membership check on group writes:** non-members can post into any group, including private ones (`group_ajax.php`). | F45 | Access control (guest/non-member access). |
| S5 | **Email change must be confirmed:** `profile.php` switches the account email instantly with no re-verification. With any password reset, that's an account takeover. | F115 | Auth. |
| S6 | **Remove the seed admin credentials** that `includes/db.php` recreates whenever `users` is empty (`admin@angelawrites.com` / `ChangeMe123!`), and the hard-coded verification bypass for usernames `admin` / `Test User` in `login.php:63`. | F108, F109 | Same class as `reset_admin.php` (item 1): known credentials in code. |
| S7 | **Turn off forced `display_errors`** in `book.php:8-9`, `reader/reader.php:8-10`, `admin/process_book.php:6-8` (server paths leak to visitors). | F88, F211 | Information leakage. |
| S8 | **Upload type and size checks:** book files (`admin/manage_books.php:101`), videos (`admin/manage_videos.php:78-83`), blog photo/voice comments (`blog_post.php:43,85`), all writing into public folders. | F39, F68, F137 | Host abuse (a stored `.php` in a public folder is code execution; also storage/bandwidth abuse). Admin-only for books/videos, but member-facing for blog uploads. |

**Should add: Medium/Low in the same classes**

| # | Addition | Rows |
|---|---|---|
| S9 | **State changes by GET without CSRF:** Q&A deletes (`community_delete.php`, `community_answer_delete.php`), session cancel (`session_cancel.php`), admin "toggle private" (`admin/comments.php:54-60`, which made a private message public in testing), admin message mark-read/delete, admin session delete; plus the four blog POST handlers with no token. | F150, F187, F156, F182, F189, F136 |
| S10 | **Open redirect** in `newsletter.php:78,89` (`https://angelwrites.gt.tc@evil.example/` confirmed). | F165 |
| S11 | **Contact form abuse:** the captcha is client-trusted (omitting both fields passes), and a copy is sent to whatever address is typed. Once email works, that's a spam relay. | F179, F180 |
| S12 | **Bible databases downloadable** (`assets/bible/*.db`, including copyrighted translations). A deny rule; the PHP reads them from disk. | F30 |
| S13 | **Low-risk hardening:** `admin/newsletter_tabs/*.php` direct access, `generate_og_image.php` path reach, `ajax_toggle_reaction.php` allow-list, `exec()` path quoting in `admin/process_book.php:172`, cron key in a URL (`reader_reminders.php:13`). | F175, F201, F161, F92, F212 |

**Implementation note for item 3:** `fix/security` merges *before* the database repair, so the `purchases` table won't exist yet on the live site. The purchase check must treat a missing table as "not purchased" rather than crash `book.php`.

With S1–S8 added, `fix/security` closes every Critical/High row in the auth, leakage, guest-access and host-abuse classes. S9–S13 close the rest of those classes.

### 4. Quality findings ("works but ugly"), separated

Status is limited to PLANNED or OPEN. No batch letters were supplied, so none is PLANNED; all are OPEN until a quality batch is authorised.

| # | Domain | File:line | One-line description | Severity | Status | Where |
|---|---|---|---|---|---|---|
| Q1 | 01 Reader | `reader/reader.php` | Phone width: 20-icon sidebar permanently on screen; title and status dropdown clipped | Medium | **OPEN** | — |
| Q2 | 01 Reader | `reader/reader.php` | Icon-only controls: 0 aria-labels; tooltips useless on touch | Medium | **OPEN** | — |
| Q3 | 01 Reader | `reader/reader.php:292` | Reader is standalone; back arrow is a JS button, not a link | Low | **OPEN** | — |
| Q4 | 01 Reader | `reader/reader.php:825` | Synchronous XHR in loadBookmarkStatus() freezes the page | Low | **OPEN** | — |
| Q5 | 01 Reader | `reader/reader.php` | Many small AJAX calls per reading session (hit cost) | Low | **OPEN** | — |
| Q6 | 01 Reader | `reader/reader.php` | No loading/empty/error states (unprocessed book shows "1 / 0") | Medium | **OPEN** | — |
| Q7 | 01 Reader | `reader/reader.php` | Guests not told progress/highlights need an account | Low | **OPEN** | — |
| Q8 | 01 Reader | `reader/reader_audio.php, reader_renderer.php, reader/includes/auth_check.php, reader/assets/sw.js` | Dead code; two TTS implementations | Low | **OPEN** | — |
| Q9 | 02 Bible reader | `bible_reader.php` | alert()/prompt() used for all feedback; raw bracketed errors | Low | **OPEN** | — |
| Q10 | 02 Bible reader | `assets/bible/bible_WEB.db` | 330 verses show raw `{footnote}` text | Low | **OPEN** | — |
| Q11 | 02 Bible reader | `includes/footer.php (cookie bar)` | Cookie banner covers controls (e.g. Bible "Post Note") until dismissed | Medium | **OPEN** | — |
| Q12 | 02 Bible reader | `bible_reader.php` | Guest actions show technical alert, no sign-in link | Low | **OPEN** | — |
| Q13 | 02 Bible reader | `bible_reader.php` | Theme keys split across 6 localStorage entries; can disagree with site theme | Low | **OPEN** | — |
| Q14 | 03 Downloads & gate | `books.php / book.php` | No "Owned" state, no buy button on detail page, guests not told sign-in unlocks downloads | Medium | **OPEN** | — |
| Q15 | 03 Downloads & gate | `admin/manage_books.php` | No upload size check or host-limit message | Low | **OPEN** | — |
| Q16 | 04 Reading groups | `groups.php / group.php` | Weak empty state; alert() feedback; invite code can't be regenerated; 400+ lines inline CSS | Low | **OPEN** | — |
| Q17 | 04 Reading groups | `includes/reading_groups.php` | `group_activity_log` grows without pruning | Low | **OPEN** | — |
| Q18 | 05 Search | `search_results.php` | 38 sub-32 px tap targets on phone; no match highlighting; snippet quality; 10 full LIKE scans per search | Low | **OPEN** | — |
| Q19 | 06 Video | `video player` | Plyr loaded after the (now fixed) fatal; no view counting UI | Low | **OPEN** | — |
| Q20 | 07 Reflections | `admin/reflection_editor.php` | Recorded media uploaded in the same post as text (upload limit loses the text) | Low | **OPEN** | — |
| Q21 | 08 Payments | `books.php` | Price shown with tambala decimals; text-only provider buttons; mock flow presented as real | Low | **OPEN** | — |
| Q22 | 09 Books | `book.php` | Phone: cover fills first screen, actions below the fold; "(1 reviews)" pluralisation | Low | **OPEN** | — |
| Q23 | 09 Books | `admin/process_book.php` | 2,800-line single page, 40+ actions, display_errors into JSON | Low | **OPEN** | — |
| Q24 | 10 Auth & account | `register.php` | 13 required fields (gender, DOB, phone…) before reading free content | Medium | **OPEN** | — |
| Q25 | 10 Auth & account | `verify.php / resend_verification.php / reset_password.php` | Duplicate resend and request-reset flows | Low | **OPEN** | — |
| Q26 | 10 Auth & account | `login.php:152-157` | Leftover dark-mode button and reading progress bar on the login page | Low | **OPEN** | — |
| Q27 | 10 Auth & account | `register.php / profile.php` | No password-strength feedback or show-password toggle | Low | **OPEN** | — |
| Q28 | 11 Profile/dashboard/library | `dashboard.php:378,399` | Polling every 60 s ×2 → ~2,880 hits/day per open tab, no pause when hidden | Medium | **OPEN** | — |
| Q29 | 11 Profile/dashboard/library | `dashboard.php / ajax_user.php` | Chart.js loaded unpinned from jsdelivr | Low | **OPEN** | — |
| Q30 | 11 Profile/dashboard/library | `dashboard.php` | Gamification shown prominently while inert | Low | **OPEN** | — |
| Q31 | 11 Profile/dashboard/library | `profile.php / register.php` | first/last name at signup vs single name in profile | Low | **OPEN** | — |
| Q32 | 12 Poetry | `poem_view.php` | No `$pageTitle`; OG tags emitted in `<body>`; 35 sub-32 px tap targets | Medium | **OPEN** | — |
| Q33 | 12 Poetry | `poem_view.php / ajax_get_reactions.php` | Reaction vocabulary differs from every other content type | Low | **OPEN** | — |
| Q34 | 13 Blog | `blog.php / blog_post.php` | Category naming confusion ("Christian Reflections" is title, default category and type); 40 small tap targets | Low | **OPEN** | — |
| Q35 | 14 Community Q&A | `admin/manage_community.php / manage_questions.php` | Two admin screens for one content type; no answer list | Low | **OPEN** | — |
| Q36 | 15 Comments & reactions | `all comment systems` | Seven comment systems / six reaction systems with different forms, feedback and vocabularies | Medium | **OPEN** | — |
| Q37 | 16 Newsletter & email | `newsletter.php / footer` | No name field or inline success on footer subscribe | Low | **OPEN** | — |
| Q38 | 16 Newsletter & email | `admin/manage_newsletter.php` | Large newsletter UI for a sender that can't send; From/Gmail mismatch | Low | **OPEN** | — |
| Q39 | 17 Contact | `contact.php / about.php` | Two contact forms with different fields and validation | Low | **OPEN** | — |
| Q40 | 18 Session booking | `book_session.php` | No availability rules; no meeting link/place on confirmation; no calendar view | Low | **OPEN** | — |
| Q41 | 19 Admin & analytics | `admin/dashboard.php:293,398` | Admin polling ~3 req/min (~4,300 hits/day per open tab) | Medium | **OPEN** | — |
| Q42 | 19 Admin & analytics | `admin/dashboard.php` | No "needs attention" summary; real numbers buried among zeros; two analytics pages | Low | **OPEN** | — |
| Q43 | 20 Layout/assets/infra | `assets/images/logo.png, hero-logo.png` | 2.1 MB logo (1536×1024) + 1.3 MB hero → 2.1–3.4 MB first page view | High | **OPEN** | — |
| Q44 | 20 Layout/assets/infra | `includes/header.php:57` | favicon.ico missing → 404 hit on every page | Low | **OPEN** | — |
| Q45 | 20 Layout/assets/infra | `manifest.json` | No icons | Low | **OPEN** | — |
| Q46 | 20 Layout/assets/infra | `assets/css/style.css + 70 PHP files` | Inline CSS bypasses tokens: 135 hex colours, 74 margin / 117 padding values, 38 pages redefine `:root` (dark mode not followed) (AUDIT §5) | Medium | **OPEN** | — |
| Q47 | 20 Layout/assets/infra | `includes/admin_mail_helper.php` | Safer mail helper exists but is never called (dead code) | Low | **OPEN** | — |
| Q48 | 20 Layout/assets/infra | `includes/auth.php:7-11` | Creates unused `tmp/sessions/` on every request | Low | **OPEN** | — |
| Q49 | 20 Layout/assets/infra | `login.php:30 etc.` | Rate-limit files in the shared system temp dir (best-effort only) | Low | **OPEN** | — |
| Q50 | 20 Layout/assets/infra | `view_logs, poem_reads, blog_reads, group_activity_log, jobs, remember_tokens…` | Append-only tables never pruned | Low | **OPEN** | — |
| Q51 | 20 Layout/assets/infra | `includes/db.php` | 14 unused production tables + clutter (activity_logs, media_files, …) | Low | **OPEN** | — |
| Q52 | 20 Layout/assets/infra | `pages (15–40 per page)` | Sub-32 px tap targets on phone across the site | Low | **OPEN** | — |
| Q53 | 20 Layout/assets/infra | `external CDNs` | TinyMCE in two versions; Chart.js unpinned; several third-party dependencies | Low | **OPEN** | — |
| Q54 | 21 Static & legal | `privacy.php / terms.php` | Generic legal templates | Low | **OPEN** | — |
| Q55 | 21 Static & legal | `about.php` | Personal gamification mixed into the author biography | Low | **OPEN** | — |

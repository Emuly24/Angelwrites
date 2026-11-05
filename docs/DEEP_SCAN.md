# AngelWrites — Deep Scan Rollup

Per-domain detail: `docs/deep-scan/01-…21-*.md`. Code-level inventory: `docs/AUDIT.md`. Method: the repo served with PHP 8.3 against two SQLite databases. **Env A** is exactly the schema the repo creates. **Env B** adds every table and column the code expects, plus seed data. Real Chromium walked the journeys at 1280/768/375 px.

## 1. Overall state

The site looks finished and is mostly responsive, but almost every interactive journey breaks, usually *after* doing its work, so users see a crash page for actions that actually succeeded. Three things dominate:
- **Email:** `sendEmail()` kills the request on any SMTP failure, and your plan forbids outbound calls.
- **Schema drift:** the code uses 86 tables, the repo creates 23, and even core inserts (`users.name`, `sessions.session_type`) conflict with the repo's own definitions.
- **A cluster of small code fatals:** reader, Q&A answers, video page, search, admin comment delete.

On the repo-defined schema, the homepage, blog, video, reflections, groups, registration, login, session booking and newsletter signup are all down (47 of 207 page/role combinations fatal). With the schema the code expects, pages render, but the core actions still fail:
- register, answer a question, open a video, highlight in the reader, search with two words, pay, subscribe as a member, delete a comment as admin.

What works end to end:
- reading the Bible
- browsing books/poems/blog
- commenting on poems
- free downloads
- the contact inbox and session admin
- password change
- CSV import/export

There are also real exposures:
- a public admin-password reset
- an unauthenticated admin JSON endpoint leaking emails and IPs
- "private" Bible notes readable by other members
- paid books downloadable or readable for free
- a payment flow that records money that was never taken

## 2. Cross-domain themes

| # | Systemic cause | Domains touched | Count |
|---|---|---|---|
| 1 | **`sendEmail()` calls `die()` on SMTP failure** (60 call sites, 36 files). Action saved, user shown a crash with server paths; inside AJAX it also breaks the page's JS. | 1, 2, 3, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 20, 21 | **17** |
| 2 | **Schema drift:** code needs 86 tables; the repo creates 23; repo definitions conflict with inserts (`users.name NOT NULL`, `sessions.session_type`, `book_comments.page_num`). Production fixes must be manual on `data/site.db`. | Every domain except 17 | **20** |
| 3 | **Reader include path** (`reader_ajax.php:11` → non-existent `includes/reader_functions.php`) kills every reader AJAX call; plus `reader.php:384` undefined `$tocEntries` fatal **before** any script reaches the browser. Downstream: no reading data anywhere. | 1, 11, 15, 19 | **4** |
| 4 | **`time_ago()` problems:** declared twice (`reader/reader_functions.php:56`, `includes/reading_groups.php:415`), fatal wherever both load; missing where needed (`video_watch.php:129`). In `reader.php` the duplicate (`:500`) is now masked by the earlier `:384` fatal. | 1, 4, 6 | **3** |
| 5 | **`awardReputation()` undefined:** answer, accept and upvote all fatal; reputation/points shown everywhere but never awarded. | 14, 9, 11 (+21 shows it) | **3–4** |
| 6 | **SQLite contention:** DDL on every request plus writes on every view. Confirmed *database is locked* on 4 of 60 concurrent views. | 1, 2, 9, 12, 13, 20 | **6** |
| 7 | **MySQL-only SQL on SQLite:** `ON DUPLICATE KEY` (video reactions), `NOW()` (admin comment delete, worker). | 6, 15, 20 | **3** |
| 8 | **Inline CSS bypassing tokens** (AUDIT §5; 38 pages redefine `:root`). Visible effects: dark mode not followed, inconsistent widgets. | All page domains; defects observed in 2, 4, 20 | **19 / 3 observed** |
| 9 | **Polling burns hits:** member dashboard ≈2,880 hits/day per open tab, admin dashboard ≈4,300; per-action AJAX in reader and Bible. | 11, 19, 1, 2 | **4** |
| 10 | **`purchases`/`payments` mismatch plus ungated content:** paid downloads impossible for payers, free for everyone else (detail-page raw link, public uploads folder, reader). | 3, 8, 1, 9 | **4** |
| 11 | **GET state changes / no CSRF:** Q&A deletes, session cancel, admin privacy toggle, message delete. | 4, 13, 14, 15, 17, 18 | **6** |
| 12 | **Features shown but never fed:** notifications, reputation, video watches, comment reactions, testimonial approval, settings. | 9, 11, 13, 14, 19, 20 | **6** |
| 13 | **Links or requests to files that don't exist:** `ajax/get_content_items.php`, `reflection.php`, `join_circle.php`, `newsletter_tabs/subscribers.php`, `includes/reader_functions.php`, `favicon.ico`, page-flip `.min.js`/CSS on the CDN. | 1, 4, 5, 7, 16, 20 | **6** |
| 14 | **No background execution** (cron/worker) for queues: comment jobs, scheduled newsletters, reminders, book processing queue. | 1, 9, 12, 16 | **4** |

## 3. Severity

"Blocked" = the domain's main journey can't complete for the people it's meant for. Users blocked is relative; actual numbers depend on your traffic.

| # | Domain | State | Who is blocked | Deps | Effort |
|---|---|---|---|---|---|
| 01 | Reader | **Blocked** | Every reader (text readable only if schema present; no controls, no saving, no highlights) | 5 | M–L |
| 02 | Bible reader | **Half-working** | Nobody for reading; members get broken note feedback; private notes exposed | 4 | S–M |
| 03 | Downloads / gate | **Half-working** | Every buyer of a paid book (free downloads work) | 5 | L |
| 04 | Reading groups | **Blocked** | All members (can't create; Env A can't open) | 4 | M |
| 05 | Search | **Blocked** | All visitors (multi-word always; single word unless undeclared columns exist) | 3 | S–M |
| 06 | Video | **Half-working** | All viewers of any video page (listing works) | 3 | S–M |
| 07 | Reflections | **Blocked** | All visitors (no readable reflection page) | 4 | S–M |
| 08 | Payments | **Blocked** | Every buyer; admin can't see or verify payments | 4 | L |
| 09 | Books catalogue/detail | **Half-working** | Reviewers (crash); paid content leaks | 4 | M |
| 10 | Auth & account | **Blocked** | Every new user (registration fatal on the repo's `users` table; no code emails) | 4 | M |
| 11 | Profile / dashboard / library | **Poor quality** | Nobody hard-blocked in Env B (Env A profile fatal); library can never fill | 4 | M |
| 12 | Poetry | **Works / poor quality** | Nobody for reading and commenting; admin save crashes; drafts public | 4 | S–M |
| 13 | Blog | **Blocked** (repo schema) / half-working | All visitors if `comment_count` absent; every commenter sees a crash | 4 | M |
| 14 | Community Q&A | **Blocked** | Every member who answers, accepts or upvotes; members can't view in Env A | 3 | S–M |
| 15 | Comments & reactions | **Half-working** | Varies by system; admins can't delete poem comments | 4 | M (L if unified) |
| 16 | Newsletter & email | **Blocked** | All members (can't subscribe), all subscribers (unsubscribe broken), admin (can't send) | 4 | M |
| 17 | Contact | **Half-working** | Nobody (messages saved) but every sender sees a crash | 2 | S |
| 18 | Session booking | **Blocked** (repo schema) / half-working | Every booker if `session_type` absent; otherwise crash after booking | 3 | S–M |
| 19 | Admin dashboard/analytics | **Poor quality** + data leak | Admin gets mostly zeros; anyone can read emails and IPs | 4 | M |
| 20 | Layout / assets / infra | **Poor quality** | Phone members can't use the menu; 2–3 MB first loads; locking under bursts | 4 | M |
| 21 | Static & legal | **Works / poor quality** | Nobody (members fatal on About in Env A); legal text inaccurate | 3 | S |

## 4. Suggested fix order

**Phase 0 — Security, today (no dependencies):**
1. Delete `reset_admin.php` from the server and the repo; change the admin password.
2. Add an `isAdmin()` check to `ajax_admin.php`.
3. Add `docs/**`, `reset_admin.php`, `worker.php`, `update_schema.php`, `allow_url_fopen` to the deploy exclude list, **and delete them from the server by FTP**. Don't merge this branch into `main` before doing this.
4. Filter private Bible notes server-side; add a membership check to group writes.
5. Stop telling buyers "Payment successful" (hide or disable the pay button) and remove the raw-file download link on `book.php`.

**Phase 1 — Stop crash-after-success, and know the schema:**
1. Make `sendEmail()` return `false` and log instead of `die()`. This one function removes the visible failure from 17 domains.
2. Download `data/site.db` and send me its schema. Then produce one consolidated list of manual table and column changes (domains 1–19 each list theirs).
3. Registration: populate `users.name` (or rebuild the table manually).

**Phase 2 — Small code fatals (mostly S each):**
1. Reader: `$tocEntries`, include path, the duplicate `time_ago()`, the `StPageFlip` global, a pinned or self-hosted page-flip.
2. `awardReputation()`: define it or remove it. Search parameter fix. `video_watch.php` `time_ago()` and its upsert. `admin/comments.php` `NOW()`.
3. Missing files and links: group content list, reflection link target, newsletter Subscribers tab, circle join page.
4. Mobile menu; `main.js:170`; `serve_og.php`.

**Phase 3 — Decisions, then model fixes (need your answers below):**
1. Payment/ownership model: manual confirmation fits the host. One table; download and reader gating.
2. Reflections model; reading-status vocabulary; reaction vocabulary; a comment moderation view; notification producers (or remove the bell).
3. Newsletter: batched in-site sending vs export-only; working unsubscribe everywhere.

**Phase 4 — Host fit and polish:**
1. Remove dashboard polling; resize the logo and hero; version or remove the service worker; move DDL out of page requests and set a busy timeout.
2. Accurate privacy, terms and accessibility text with fixed dates; a real 404; empty, loading and error states where noted in each domain.

## 5. Open questions only you can answer

1. **Does your InfinityFree plan allow outbound SMTP** (to `smtp.gmail.com:587`/`465`)? If not, email has to be dropped or moved to a service you trigger outside the server. This decides domains 10 and 16 and the admin notifications everywhere.
2. **Is SQLite acceptable on InfinityFree's storage?** Locking errors already appear under bursts. Or do you want to move to their MySQL? That brings the 50k rows/table and no-remote-access limits into play and means rewriting 54 SQLite-only statements.
3. **Can we start a fresh database, or must existing data be kept** (members, reviews, subscribers, "payments")? Can you download `data/site.db` over FTP so the real schema can replace guesswork?
4. **Which PHP version is your account on, and are errors displayed?** PHP 8 turns the reader's undefined variable into a fatal; displayed errors leak server paths.
5. **Payments:** is a manual "send mobile money, admin confirms" flow acceptable? Are the "completed" rows already in `payments` genuine?
6. **Licensing:** are you licensed to serve NIV, ESV, NASB, NKJV and AMP as downloadable files and on-site text?
7. **Scope:** which features do you want to keep?
   - Reader extras: flip mode, TTS, circles, challenges, gamification.
   - Reading groups, video uploads vs YouTube-only, social login, photo/voice comments, in-site newsletter sending.
8. **What's your plan's upload size limit?** It affects books, videos, photos and voice notes.
9. **Do you have real members and subscribers today**, and roughly how many? That turns "who is blocked" into numbers and decides how careful the data migrations must be.

## 6. Coverage statement

- **Tracked files when the scan began:** 441 (118 PHP outside `vendor/`, 281 `vendor/` files, 8 Bible DBs, 3 images, CSS/JS/SW/manifest, `.htaccess` ×2, the workflow, composer files, `.gitignore`, `allow_url_fopen`, and 19 `docs/` files).
- **Accounted for in the 21 coverage matrices:** 441 of 441.
  - `vendor/**` (281) is one NOT APPLICABLE row in domain 20 (third-party code; its user-visible effects are covered in 09, 10 and 16).
  - `docs/**` is one COVERED row in domain 20 (deploy exposure).
  - Every other file is named individually with the heading where it's covered.
- **Files written during the scan:** `docs/deep-scan/19-*`, `20-*`, `21-*` and `docs/DEEP_SCAN.md`. These fall under the `docs/**` row. Total now 445, all accounted for.
- **Files not accounted for:** none.
- **Referenced but missing files**, also recorded: `admin/newsletter_tabs/subscribers.php`, `includes/reader_functions.php`, `ajax/get_content_items.php`, `reflection.php`, `reader/join_circle.php`, `favicon.ico`, `assets/images/angelwrites-logo.png`, `assets/bible/bible_YLT.db`.

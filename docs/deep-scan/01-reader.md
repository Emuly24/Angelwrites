# 01. Book reading & the reader/ folder

> **How this was tested.** The repo was served locally with `php -S` (PHP 8.3) against two SQLite databases:
> - **Env A**: exactly the schema the repo creates (`includes/db.php` plus runtime `CREATE TABLE`s).
> - **Env B**: Env A plus every table and column the code's SQL refers to, inferred automatically, seeded with a 6-page, 3-chapter processed book, users, groups and so on. Env B lets a journey get past "no such table" so the next real problem shows.
>
> A real Chromium browser was routed to the local server, so `https://angelwrites.gt.tc/...` URLs, cookies and redirects behave as in production. The CDNs used by the reader were simulated with what they actually serve today; see R-3.
>
> `[confirmed]` = reproduced this way. `[inferred]` = read from code, not reproduced. A step that only fails in Env A is a schema problem that may or may not exist in production.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `reader/reader.php` | 1558 | The e-reader page: toolbar, sidebar, scroll/flip view, all reader JS | Y |
| `reader/book_data.php` | 113 | Loads book, processed pages, chapters, user progress; returns an array to reader.php | Y |
| `reader/reader_ajax.php` | 866 | Every reader AJAX action (positions, bookmarks, highlights, notes, comments, reports, prayer) | Y |
| `reader/reader_renderer.php` | 253 | Page-to-HTML renderer functions | Y |
| `reader/reader_functions.php` | 333 | Helpers: chapters, TOC, levels, `time_ago()` | Y |
| `reader/reader_gamification.php` | 517 | XP/levels/achievements, `updateUserStats()` | Y |
| `reader/reader_notes.php` | 549 | Group-notes panel / standalone page | Y |
| `reader/reader_challenges.php` | 418 | Monthly challenge widget (included by reader.php) | Y |
| `reader/reader_circles.php` | 329 | Reading-circle JSON API + invite emails | Y |
| `reader/reader_audio.php` | 589 | "Audio mode" (browser speech) | Y |
| `reader/reader_tts.php` | 543 | Text-to-speech controls (included by reader.php) | Y |
| `reader/reader_share.php` | 243 | Share-dialog JS (included by reader.php) | Y |
| `reader/reader_export.php` | 157 | JSON export of a user's reader data | Y |
| `reader/progress_tracking.php` | 324 | Progress/achievement functions | Y |
| `reader/includes/auth_check.php` | 33 | Reader auth/guest check | Y |
| `reader/admin/reader_analytics.php` | 398 | Admin reader analytics | Y (journey here; the dashboard view is in domain 19) |
| `reader/reader_analytics.php` | 436 | Second admin analytics page | Y (also domain 19) |
| `reader/cron/reader_reminders.php` | 79 | Daily "you left off" email cron | Y |
| `reader/assets/sw.js` | 41 | "Offline reading" service worker | Y |

## User journeys

### Journey 1: A logged-in member opens a book to read it
1. Member clicks **Read** on a book. `reader/reader.php?id=1` loads.
   - Env A: **[fatal] [confirmed]** `reader/book_data.php:22` *no such column: u.username*, so a white error page.
2. Env B: the server renders the top bar, the 20-icon sidebar, the cover and all 6 pages of text in a vertical scroll. The page counter reads `1 / 6`.
3. Rendering then dies at `reader.php:384`: *count(): Argument #1 must be of type Countable|array, null given*. `$tocEntries` is used at `:384` and `:386` but never assigned anywhere in the repo.
   - **[silent] [confirmed]** The error text is printed **inside the hidden table-of-contents drawer**, so the member doesn't see an error. The page looks loaded.
   - Nothing after line 384 is sent, including every `<script>` (0 scripts reach the browser, confirmed).
   - Also lost: the notes panel, settings panel, share modal, error-report modal, TTS controls and challenge widget.
   - On PHP 7.x this line is only a warning. On PHP 8 it is fatal for every visitor, logged in or not.
4. Member scrolls. They **can read the text**. This is the only part of the reader that works.
5. Member taps any of the 32 buttons (back arrow, search, bookmark, TOC, notes, settings, fullscreen, comments, report, prayer, stats, highlight, audio, export, reset, history, challenges, share). **[silent] [confirmed]** Nothing happens; all handlers live in the script that never arrived.
   - The **back arrow** (`reader.php:292`) is a `<button>` wired in JS, not a link, so the only way out is the browser's Back button.
6. Member closes the tab. Their position is not saved: the save code never loaded, and `reader_ajax.php` would fatal anyway (Journey 3).

*Journey stops here; everything else in the reader depends on the missing script.*

### Journey 2: A guest (not logged in) opens a book
1. Same as Journey 1. The reader deliberately allows guests (`book_data.php` doesn't require login; `reader/includes/auth_check.php` has its redirect commented out and is never included anyway).
2. **[silent] [confirmed]** Same hidden fatal at `reader.php:384`. The guest sees text plus dead controls.
3. **[quality] [inferred]** If it did work, a guest would get no message that progress, highlights and bookmarks need an account. The UI shows them all.

### Journey 3: Member selects text and highlights it (the reported failure)
1. Can't start in the browser: the selection tooltip (`reader.php:1020-1050`) and highlight saving (`:1122`) are in the script cut off by the fatal at `:384`.
2. Calling the endpoint directly, as the browser would: `POST /reader/reader_ajax.php action=add_highlight` → **[fatal] [confirmed]** `reader_ajax.php:11` requires `../includes/reader_functions.php`, which doesn't exist (the file is `reader/reader_functions.php`). The response is an HTML error page, not JSON.
   - Same for **every** reader action: `save_position`, `add_bookmark`, `get_highlights`, `add_reader_note`, `report_typo`, …
3. Further layers, which apply only after 1 and 2 are fixed:
   - **[fatal] [confirmed]** `reader.php:500` includes `reader_challenges.php` → `includes/reading_groups.php`, which redeclares `time_ago()`. It is already declared in `reader/reader_functions.php:56` (reproduced through `reader_notes.php`, which loads the same pair).
   - **[fatal] [confirmed]** Flip mode would fail even with the library loaded. `reader.php:662` calls `new StPageFlip(...)`, but the library defines `window.St.PageFlip` (checked in the `page-flip@2.0.7` bundle).
   - **[silent] [inferred]** XP for highlighting never happens. `reader_ajax.php:162` calls `updateUserStats()` only `if (function_exists(...))`, and `reader_gamification.php`, which defines it, is not included by `reader_ajax.php` or `reader.php`.

*Stop: the highlight journey can't get past step 1 in a browser.*

### Journey 4: Member wants to switch to "page-flip" mode
1. Blocked by Journey 1 step 3 (no script).
2. Independently: `reader.php:91` loads `https://cdn.jsdelivr.net/npm/page-flip/dist/css/page-flip.browser.min.css`, and `:495` loads `https://unpkg.com/page-flip/dist/js/page-flip.browser.min.js`.
   - **[fatal-for-feature] [confirmed against the package]** The unversioned URLs resolve to `page-flip@2.0.7`, whose package has **no `dist/css/` and no `.min.js`**; only `dist/js/page-flip.browser.js` exists. Both requests 404 in production today, so flip mode can't work.
   - The versions aren't pinned, so the next library release could change behaviour without any change in this repo.

### Journey 5: Member clicks the reader shortcuts on their dashboard ("member modules")
`dashboard.php` (around line 310) links to 8 reader files directly. What each link shows:

| Link label | Target | What the member sees | Tag |
|---|---|---|---|
| Open Reader | `reader/reader.php` (no `id`) | Silent redirect to `books.php` | [wrong] [confirmed] |
| Export Data | `reader/reader_export.php` | Raw text `{"error":"Missing book_id."}`, HTTP 400 | [wrong] [confirmed] |
| Reading Circles | `reader/reader_circles.php` | Raw text `{"success":false,"error":"Invalid action."}` | [wrong] [confirmed] |
| My Analytics | `reader/reader_analytics.php` | PHP fatal (missing include, line 10). This is also an **admin-only** page (`:12`), so even when fixed it would bounce members to the homepage. | [fatal] [confirmed] |
| Challenges | `reader/reader_challenges.php` | Unstyled HTML fragment (a partial, not a page) | [quality] [confirmed] |
| My Notes | `reader/reader_notes.php` | PHP fatal *Cannot redeclare time_ago()* | [fatal] [confirmed] |
| Text to Speech | `reader/reader_tts.php` | Raw CSS/JS printed as text | [quality] [confirmed] |
| Gamification | `reader/reader_gamification.php` | Blank white page | [silent] [confirmed] |

### Journey 6: Member invites a friend to a reading circle
1. There is no UI for this inside the reader (the script never loads), and `reader_circles.php` is a JSON API with no page.
2. **[wrong] [inferred]** The invite email links to `SITE_URL/reader/join_circle.php?token=…` (`reader_circles.php:281`). **`join_circle.php` doesn't exist**, so every invite link is a 404.
3. The invite email goes through `sendEmail()` (`reader_circles.php:290`), so an SMTP failure prints the SMTP dump inside a JSON response.

### Journey 7: Member exports their highlights or data
1. In-reader **Export Highlights** button: blocked by the missing script.
2. `reader_export.php?book_id=1` directly: Env A **[fatal]** (`bookmarks` missing). Env B returns a JSON dump, served as `text/html` with no download headers. The result is raw JSON in the browser, not a file. **[quality] [confirmed]**
3. `reader_ajax.php action=export_highlights` (a `.txt` download, `:215-234`) is unreachable because of the line-11 fatal.

### Journey 8: Inactive reader receives a "you left off" reminder
1. Needs a daily cron. There is no cron on this host. **[silent] [infra]**
2. Opening it by hand with the key in the URL: **[fatal] [confirmed]** `reader/cron/reader_reminders.php:7`. The `../includes/` path resolves against `reader/cron/`.

### Journey 9: Admin opens reader analytics
1. `reader/admin/reader_analytics.php`: Env B renders without a PHP error **[confirmed]**. Env A fatals on the missing `reading_sessions` tables **[inferred from §schema]**. Quality review of this page is in domain 19.
2. `reader/reader_analytics.php` (the second analytics page): **[fatal] [confirmed]** line 10, missing include. Its date filters build invalid SQL (`… FROM reading_sessions AND start_time >= …`, `:50`/`:54`), already in AUDIT.md.

## Quality problems independent of the bugs above
- **Phone width (375 px) [confirmed by screenshot].**
  - The 20-icon sidebar stays permanently on screen and takes ~40 px.
  - The title truncates to "Walkin…" and the reading-status dropdown is clipped to "Not S…".
  - There's no horizontal overflow (0 px), but the layout is a cramped desktop layout, not a mobile one.
  - Tablet (768 px) shows the same layout with more room.
- **Discoverability.**
  - 20 sidebar icons, none with an `aria-label` (0 in the markup), 17 with a `title` tooltip.
  - Tooltips don't exist on touch devices, so on a phone the icons carry no labels.
  - 4 buttons have neither text nor a title.
- **The reader is a separate, standalone page** (its own `<html>` at `reader.php:82`, no site header or footer). Once JS works, the back arrow is the only way back to the site.
- **Silent-failure design.** The page prints PHP errors into the HTML (`display_errors` is forced on at `reader.php:8`) but often inside hidden panels. Users see a "working" reader that does nothing. There is no visible error state anywhere in the reader.
- **Synchronous XHR.** `loadBookmarkStatus()` (`reader.php:825`) uses `xhr.open('POST', …, false)`, which blocks the whole page on every call. Browsers warn about this and it freezes the reader on slow connections.
- **Hit cost.** Every bookmark check, page save and session start/end is its own request to `reader_ajax.php`. A 30-page reading session would cost dozens of hits (inferred from the number of call sites; can't be measured until the script loads).
- **No loading, empty or error states.** E.g. a book with no processed content shows `1 / 0` and an empty frame, with no "This book isn't ready yet" message.
- **Fragile page parsing.** `book_data.php:52` splits pages with `/<div class="page-content" …>(.*?)<\/div>/`. The first nested `</div>` in a page truncates that page silently. Today's extractor doesn't emit nested divs (`admin/process_book.php:1026-1033`), but the manual formatting tools in `process_book.php` (`:743`, `:791`, `:945`) rewrite `content_html` and could.
- **Wrong group lookup.** `reader.php:46` looks up the member's group with `reading_groups.book_id`. Groups are stored as `content_type`/`content_id` (`includes/reading_groups.php:20`), so the reader never finds a member's group and group notes never appear. **[wrong] [inferred]**
- **Dead code.** `reader_audio.php`, `reader_renderer.php`, `progress_tracking.php` and `reader/includes/auth_check.php` are not included by any file. `reader/assets/sw.js` ("offline reading") is never registered, and it would cache paths that don't exist (`/reader/reader/reader.php`, `/assets/js/reader.js`). Two different TTS implementations exist (`reader_tts.php` is used; `reader_audio.php` is orphaned).
- **Request handling to fix.** `get_challenge_history` is sent by the challenges widget but has no handler in `reader_ajax.php`. The handler set has drifted from what the JS sends.

## Root causes
- **[code]**
  - Undefined `$tocEntries` (`reader.php:384`).
  - Wrong include path (`reader_ajax.php:11`, `reader_analytics.php:10`, `progress_tracking.php:7`, `reader_reminders.php:7`).
  - `time_ago()` declared twice.
  - `StPageFlip` vs `St.PageFlip`.
  - Missing `join_circle.php`.
  - Gamification not included.
  - No handler for `get_challenge_history`.
  - Group lookup on a non-existent column.
  - Dashboard links point at partials and APIs.
- **[schema]** `users.username/display_name`, `books.author_id`, `highlights`, `bookmarks`, `reading_progress`, `reading_streaks`, `user_settings`, `reading_sessions`, `user_reputations`, `reading_groups.book_id`: none of them are created by the repo.
- **[ux]** No error, empty or loading states. Icon-only controls with no labels on touch. No mobile layout. Back navigation depends on JS. Guests aren't told features need an account.
- **[infra]**
  - Unpinned third-party CDN assets that 404 today.
  - The cron-based reminder has no cron.
  - Many small AJAX calls burn hits.
  - Reader writes (`view_count` on every open, `book_data.php:37`) contend for the SQLite write lock.
  - Report/prayer/question actions send email inline (`reader_ajax.php:272`, `:298`, `:702`, `:738`, `:863`), so an SMTP failure dumps HTML into a JSON response.

## What a professional version looks like
- **J1/J2 Open a book.**
  - Server renders the first page fast, with a visible chapter title.
  - If the book has no processed content, show a clear card: "This book is being prepared. Check back soon." with a link back to the book page.
  - The back control is a real link to the book's page (works without JS).
  - Server errors never render inside hidden panels. The page either loads fully or shows one friendly error with a "Back to library" link, and the error is logged server-side.
  - Guests get a slim banner: "Sign in to save your place and highlights."
- **J3 Highlight.**
  - Select text and a small floating toolbar appears with 5 colours plus "Note".
  - The highlight shows immediately (optimistic) and is saved in the background with a single JSON request.
  - On failure the highlight is visibly un-applied and a toast says "Couldn't save highlight. Try again."
  - Highlights reload on next open and appear in a "My highlights" list for that book.
  - The endpoint always returns JSON with a status code, never HTML.
- **J4 Flip mode.** Library pinned to an exact version and either self-hosted under `assets/` or loaded from a pinned CDN URL, constructed with the library's real global. If it fails to load, the toggle is hidden and scroll mode stays.
- **J5 Dashboard modules.** Every tile goes to a real page: "Continue reading" (opens the last book at the saved page), "My highlights & notes", "My reading stats". Endpoints and partials are never linked.
- **J6 Circles.** Invite link goes to a page that exists, shows the circle name and inviter, and asks the friend to sign in or join.
- **J7 Export.** Download a `.txt`/`.csv`/`.json` file with `Content-Disposition: attachment` and a sensible filename.
- **J8 Reminders.** With no cron, either drop the feature or do it opportunistically: e.g. when a member logs in, show an on-site "You left off in *X*, page 12" card instead of emailing.
- **Saving position.** Save at most once every N seconds while reading, plus on page hide (`sendBeacon`), to keep hits low.

## Dependencies before this can be fixed
1. Know the real production schema (download `data/site.db`, run `.schema`). If the reader tables (`highlights`, `bookmarks`, `reading_progress`, `reading_sessions`, `user_settings`, `reading_streaks`) and the `users.username/display_name` and `books.author_id` columns don't exist, **you'll have to create them manually**. Note: this database is SQLite, not MySQL, so phpMyAdmin can't reach it. The changes have to be made to the `site.db` file, e.g. downloaded via FTP, edited with a SQLite tool, re-uploaded, or applied by a one-off admin-only PHP script.
2. Fix the `reader.php:384` fatal and the `reader_ajax.php:11` include. Until both are fixed, nothing else in this domain can be tested in a browser.
3. Resolve the duplicate `time_ago()` (shared with domain 4, reading groups).
4. Decide on the email strategy (domain 16) before re-enabling the report/prayer/question actions.
5. Decide whether flip mode, circles, challenges, TTS and gamification are kept. Each is a large surface that is currently 100% non-functional.

## Blast radius
- **Domain 4 (reading groups):** shares `includes/reading_groups.php`; the `time_ago()` collision is between the two domains. Group notes inside the reader depend on the group lookup.
- **Domain 9 (book detail):** book.php's Read button lands here. `admin/process_book.php` produces the `content_html` this domain parses.
- **Domain 11 (dashboard/library):** dashboard links into 8 reader files. Library "continue reading" depends on `reading_progress`.
- **Domain 15 (comments):** paragraph/book comments are written by `reader_ajax.php` (`add_book_comment`).
- **Domain 16 (email):** reader report/prayer/question/circle actions send mail.
- **Domain 19 (analytics):** both analytics pages read `reading_sessions`, which this domain writes.
- **Domain 3/8 (downloads/payments):** the reader doesn't check purchase status at all. A paid book can be read in full by anyone with its `id`, guests included (`book_data.php` has no access check). **[wrong] [inferred]**

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1/J2 open & read | S | Two one-line fatals (undefined variable, include path) plus a JS-free back link; the rendering already works. |
| J3 highlight | M | After the fatals: the duplicate function, verifying the selection→save→reload loop end to end, plus error feedback. |
| J4 flip mode | S | Pin or self-host the library and use the correct global; or remove the mode. |
| J5 dashboard modules | M | Needs real destination pages, or removing the tiles; touches domain 11. |
| J6 circles | M | Missing page plus invite flow plus email dependency. |
| J7 export | S | Headers and filename. |
| J8 reminders | M | Needs a non-cron design. |
| J9 admin analytics | S | Include path; the SQL fix is in domain 19. |
| Access control for paid books | M | Needs the purchase model from domain 3/8 settled first. |

## Regression check against AUDIT.md
- `reader_ajax.php:11` missing include: **Yes.**
- `reader.php:500` duplicate `time_ago()`: **Different.** It still reproduces (via `reader_notes.php`), but in reader.php it is now masked by an earlier fatal at `:384` (undefined `$tocEntries`) that AUDIT.md missed.
- `book_data.php:22` `u.username` [schema]: **Yes** (Env A).
- `highlights` table never created: **Yes.**
- `reader_analytics.php:10` missing include: **Yes.**
- `progress_tracking.php:7` wrong path: **Yes.**
- `reader_reminders.php:7` relative include: **Yes.**
- `display_errors` forced on in `reader.php:8`: **Yes** (it is how the hidden error gets into the page).

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `reader/reader.php` | COVERED | Journeys 1–4; Quality |
| `reader/book_data.php` | COVERED | Journey 1; Quality (page parsing) |
| `reader/reader_ajax.php` | COVERED | Journey 3; Journey 7; Quality (handler drift) |
| `reader/reader_renderer.php` | COVERED | Quality (dead code: not included anywhere) |
| `reader/reader_functions.php` | COVERED | Journey 3 (`time_ago()` collision); Journey 5 |
| `reader/reader_gamification.php` | COVERED | Journey 3 step 3; Journey 5 |
| `reader/reader_notes.php` | COVERED | Journey 3 step 3; Journey 5 |
| `reader/reader_challenges.php` | COVERED | Journey 3 step 3; Journey 5; Quality (`get_challenge_history`) |
| `reader/reader_circles.php` | COVERED | Journey 5; Journey 6 |
| `reader/reader_audio.php` | COVERED | Quality (dead code, duplicate TTS) |
| `reader/reader_tts.php` | COVERED | Journey 1 step 3 (cut off); Journey 5 |
| `reader/reader_share.php` | COVERED | Journey 1 step 3 (cut off by the fatal; markup only, no server logic) |
| `reader/reader_export.php` | COVERED | Journey 5; Journey 7 |
| `reader/progress_tracking.php` | COVERED | Quality (dead code); Regression |
| `reader/includes/auth_check.php` | COVERED | Journey 2 (never included); Quality |
| `reader/admin/reader_analytics.php` | COVERED | Journey 9 |
| `reader/reader_analytics.php` | COVERED | Journey 5; Journey 9 |
| `reader/cron/reader_reminders.php` | COVERED | Journey 8 |
| `reader/assets/sw.js` | COVERED | Quality (dead code: never registered, wrong paths) |

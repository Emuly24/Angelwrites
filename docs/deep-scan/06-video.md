# 06. Video

> Test method as in `01-reader.md`. Env B: one video (YouTube URL, thumbnail) after the seed was corrected to the real column names `video_url`, `video_file`, `thumbnail`.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `videos.php` | 524 | Video listing: search, type filter, sort, like/love/pray reactions | Y |
| `video_watch.php` | 369 | Single video: player (file or YouTube/Vimeo embed), like/dislike, comments | Y |
| `videos_reactions.php` | 74 | JSON endpoint for listing reactions (like/love/pray) | Y |
| `admin/manage_videos.php` | 1045 | Admin CRUD: upload file or paste URL, thumbnail (incl. camera capture) | Y |

## User journeys

### Journey 1: Visitor browses videos
1. `videos.php`: Env A **[fatal] [confirmed]** *no such table: videos* (`videos.php:19`).
2. Env B: renders cards with search, type filter and sort **[confirmed]**. No horizontal overflow at 768/375 px (crawl) **[confirmed]**.
3. Sort **"Most commented"**: **[fatal] [confirmed]** *no such column: comment_count*. Nothing creates or maintains a comment count on `videos`.
4. Sort **"Most viewed"**: runs, but `videos.views` is never incremented anywhere (no `UPDATE videos SET views` in the repo), so it is always 0 and the order is meaningless. **[wrong] [inferred, code-verified]**

### Journey 2: Visitor reacts to a video from the listing
1. Taps like / love / pray on a card. `videos_reactions.php` gets `video_id` and `reaction`, returning `{"success":true,"likes":1,…}` **[confirmed]**. **Works** (Env B).
2. Guests get `{"success":false,"error":"Please login to react."}`. The card shows it as a toast/alert, with no sign-in link **[inferred]**.

### Journey 3: Visitor opens a video to watch it
1. `video_watch.php?id=1` renders the header and the player markup. A YouTube `watch?v=` URL is rewritten to `/embed/` (`:111-114`).
2. **[fatal] [confirmed]** Right under the player, `video_watch.php:129` calls `time_ago()`, which isn't defined in any file this page includes (it lives in `reader/reader_functions.php` and `includes/reading_groups.php`).
   - Rendering stops there for **every visitor, guest or member, regardless of schema**.
   - Title, description, like buttons, comments and the Plyr player script (`:247`) never arrive.
   - The browser may still play the bare iframe or `<video>` above the error. The page is otherwise a PHP error dump.
3. **[wrong] [inferred]** If the page got further, it would show **made-up like counts**. `video_watch.php:28-29` uses `$video['likes'] ?? rand(100, 500)` and `rand(10, 50)` for dislikes, so every refresh shows different invented numbers.
   - The header comment (`:16-24`) describes a `CREATE TABLE video_reactions` and an `ALTER TABLE videos ADD likes/dislikes`, but **they're inside a comment block and never run**. Nothing ever creates those columns.

*Stop: the watch journey can't get past step 2.*

### Journey 4: Member likes or dislikes on the watch page
1. Blocked by Journey 3 step 2 in the browser.
2. Calling the endpoint directly (`POST video_watch.php action=react`): **[fatal] [confirmed]** *near "DUPLICATE": syntax error*. `video_watch.php:43` uses MySQL's `INSERT … ON DUPLICATE KEY UPDATE`, which SQLite doesn't support. Schema-independent.
3. The watch page's like/dislike model (`video_reactions.reaction_type in like/dislike`) differs from the listing's like/love/pray model on the same table (`videos_reactions.php:22`). A "dislike" stored here would be counted nowhere on the listing.

### Journey 5: Member comments on a video
1. Blocked in the browser by Journey 3 step 2, since the comment form is below the fatal.
2. Posting directly: the comment **is saved** (`video_comments` row created) and the page 302-redirects back to `video_watch.php`, which fatals again. **[wrong] [confirmed]** Comments go in and can never be read.
3. No CSRF token on the form; no moderation or delete path for video comments anywhere (`admin/comments.php` doesn't cover `video_comments`, domain 15) **[inferred]**.

### Journey 6: Admin adds a video
1. `admin/manage_videos.php` renders in Env B **[confirmed]**. Env A **[fatal] [confirmed]** *no such table: videos*.
2. **URL path:** paste a YouTube link.
   - **[wrong] [inferred]** Only `watch?v=` links are converted. `youtu.be/…`, `youtube.com/shorts/…` and links with `&t=`/`&list=` either fail to embed or embed incorrectly.
   - Vimeo is mentioned in a comment but there's no conversion for it.
3. **File path:** upload a video file to `assets/uploads/videos/` (`:78-83`).
   - **[quality] [inferred]** There's no extension or size check and no progress indicator.
   - InfinityFree's per-file upload limit (about 10 MB) rejects almost any real video, and the admin only sees a generic failure.
   - Hosting video files also burns bandwidth and hits on a free plan.
4. Thumbnail: file upload or a live camera capture. Both are saved into the same public folder.

## Quality problems independent of the bugs above
- **Two reaction systems on one table with different vocabularies** (like/love/pray vs like/dislike) and invented fallback numbers. Counts can't be trusted anywhere.
- **No view counting**, so "Most viewed" is decorative.
- **Player depends on `cdn.plyr.io`** (`:93`, `:247`), pinned to 3.7.8. That's fine, but it's one more third-party dependency and it is loaded after the fatal, so it never runs.
- **Listing is responsive** (no overflow at 768/375) **[confirmed]**. The watch page can't be judged because it fatals.
- **No empty state** check was possible beyond the seed. With zero videos, `videos.php` shows its "No videos found" panel (code present) **[inferred]**.

## Root causes
- **[code]**
  - `time_ago()` not available on the page.
  - MySQL-only `ON DUPLICATE KEY UPDATE`.
  - `rand()` fallbacks.
  - Schema statements left inside a comment.
  - Two reaction vocabularies.
  - No view increment.
  - Narrow YouTube URL handling.
- **[schema]** `videos`, `video_comments`, `video_reactions` aren't created by the repo (AUDIT.md thought `video_watch.php` created `video_reactions`; it doesn't). `videos.comment_count/likes/dislikes/views` are never created.
- **[ux]** No sign-in prompts, no upload limits shown, no comment moderation.
- **[infra]** Video files on free hosting (upload limit, bandwidth, hits).

## What a professional version looks like
- **J1:** Listing sorts only on data that exists (newest/oldest, plus most viewed once views are counted). Each card shows real counts.
- **J2/J4:** One reaction model for videos across listing and watch page. Counts come from the reactions table, never `rand()`. Guests get "Sign in to react."
- **J3:** Watch page renders title, date ("3 days ago"), description, player, reactions and comments with no PHP errors. The player loads responsively (16:9 box) at all widths.
- **J5:** Comment appears immediately after posting, with author name and time. Admin can hide or delete video comments from the same moderation screen as other comments.
- **J6:** Admin pastes any YouTube/Vimeo URL form and sees a live preview of the embed before saving. File upload is either removed (videos hosted on YouTube) or limited, with the size limit printed next to the field.

## Dependencies before this can be fixed
1. Confirm `videos`, `video_comments`, `video_reactions` exist in production `data/site.db`. Add `views` (and decide on like counts) **manually** if needed (SQLite, not phpMyAdmin; see 01).
2. Decide: self-hosted video files, or YouTube-only? On this plan, YouTube-only is the realistic option.
3. Decide the single reaction vocabulary (shared with domain 15).

## Blast radius
- **Domain 15 (comments/reactions):** video comments and reactions are two of the systems compared there.
- **Domain 1/4:** `time_ago()` lives in their files; the fix for the collision there should make a shared helper this page can use.
- **Domain 5 (search):** video results link here; search selects `videos.thumbnail`.
- **Domain 19 (admin dashboard):** `ajax_admin.php` counts `videos`.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 browse/sort | S | Remove the impossible sort; add a view increment. |
| J2 listing reactions | — | Works; add a guest prompt (S). |
| J3 watch page | S | Make `time_ago()` available; drop `rand()`. |
| J4 watch reactions | S | SQLite-compatible upsert, unified with J2. |
| J5 comments | S (display) / M (moderation) | Display comes free with J3; moderation needs admin UI. |
| J6 admin add | S–M | URL parsing and preview is S; a file-upload policy is a decision. |

## Regression check against AUDIT.md
- `videos` table missing [schema]: **Yes** (Env A, `videos.php:19`).
- "`video_watch.php:18` creates `video_reactions`; `:26` ALTERs `videos`": **Different.** Both statements are inside a `/* … */` comment and never execute. AUDIT.md misread them as runtime schema changes.
- `video_watch.php:78-79` whitelisted `ORDER BY $order`: **Yes**, safe (and unreachable behind the line-129 fatal).
- `videos.php:63` sort on non-existent `comment_count`: **Yes**, confirmed fatal.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `videos.php` | COVERED | Journeys 1, 2 |
| `video_watch.php` | COVERED | Journeys 3, 4, 5 |
| `videos_reactions.php` | COVERED | Journey 2; Journey 4 step 3 |
| `admin/manage_videos.php` | COVERED | Journey 6 |

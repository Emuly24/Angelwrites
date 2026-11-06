# 13. Blog & blog posts

> Test method as in `01-reader.md`. Env B seed: one published post "Finding Peace". During the test the admin published "Broadcast Test" with "send to subscribers" ticked.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `blog.php` | 626 | Blog listing: categories, search, sort, pagination | Y |
| `blog_post.php` | 797 | Single post: body, related posts, comments (text/photo/voice), prayer request, admin reply, comment reactions | Y |
| `admin/editor.php` | 762 | Admin create/edit blog post; optional broadcast to all subscribers | Y |
| `admin/manage_blog.php` | 922 | Admin list, bulk publish/draft/delete, live cover photo | Y |

## User journeys

### Journey 1: Visitor opens the Blog
1. Env A: **[fatal] [confirmed]** *no such column: comment_count* (`blog.php:92`).
   - The default sort includes `comment_count` in the query, and the repo's `blog_posts` table has no such column. Nothing ever writes one (same as domain 5).
   - **The Blog page is down unless production has a column the code never maintains.**
2. With the column present (Env B): posts render, with category filter and sorts. No overflow at 768/375 px **[confirmed]**. 40 tap targets under 32 px on phone **[confirmed]**.
3. **[wrong] [inferred]** "Most commented" sorts on a column that's always 0. "Popular" sorts on `views`, which `blog_post.php:25` does increment, so that one is real.
4. **[wrong] [confirmed]** Admin "reflections" appear on the Blog (domain 7). Every post saved with the default category is labelled "Christian Reflections", which is also the Blog's page title.

### Journey 2: Visitor reads a post
1. `blog_post.php?slug=finding-peace` renders the body, related posts and comments, for guests and members **[confirmed]** (Env B).
   - Env A, logged in: **[fatal]** on `blog_reads` (`:31`) and then `blog_posts.book_id` (`:187`). The second is a query for "reading progress of the book linked to this post", but blog posts have no linked book in the repo schema **[schema]**.
2. The title is correct ("Finding Peace — Blog") **[confirmed]**.
3. Each view: `views + 1` and, for members, an `INSERT OR IGNORE INTO blog_reads` (2 SQLite writes).

### Journey 3: Member comments on a post (text)
1. Types a comment → **comment saved** (row confirmed), then `blog_post.php:73` `sendEmail()` → **SMTP dump** **[wrong] [confirmed]**.
2. Reloading shows the comment **[confirmed]**, but the member was shown a crash, so they're likely to post it again.
3. **[quality] [inferred]** No CSRF token on any of the four POST handlers (comment, voice comment, prayer, admin reply), unlike `poem_view.php`.

### Journey 4: Member attaches a photo to a comment
1. Live-photo capture → upload with the comment.
2. **[wrong] [confirmed]**
   - The file is written to `../assets/uploads/comments/` (`:43`). That is **the folder above the website root**; in the test it landed outside the site directory.
   - The database stores `assets/uploads/comments/comment_<time>.jpg` (`:47`).
   - The comment shows a broken image (`GET` → **404**).
   - On InfinityFree the folder above `htdocs` is usually not writable, so the upload may fail outright **[inferred]**.
3. No file-type check; any file is saved with a `.jpg` name **[inferred]**.
4. Voice comments (`:82-115`) use the same `../` pattern **[inferred, same code]**.

### Journey 5: Member asks for prayer on a post
1. "Request prayer" → **request saved** (`prayer_requests`), then `:140` `sendEmail()` → **SMTP dump** **[wrong] [confirmed]**.
2. There's no admin screen that lists prayer requests (grep: only `index.php` counts them, and `reader_ajax.php` writes them). The admin only learns of them from the email, which fails **[missing] [inferred, code-verified]**.

### Journey 6: Member reacts to a comment
1. Comments show like/love/pray counts from `comment_reactions` (`:171-175`).
2. **[missing] [confirmed by grep]** **Nothing in the codebase writes to `comment_reactions`**, so the counts are permanently 0, and the buttons (if any) have no endpoint.

### Journey 7: Admin publishes a post and emails subscribers
1. `admin/editor.php` → title, content, category, status published, "send newsletter" ticked → submit.
2. The post **is saved** **[confirmed]**. Then the admin-notification email (`:118-121`) → **SMTP dump**, so the subscriber broadcast (`:125-146`) **never starts** **[wrong] [confirmed]**.
3. **[wrong] [inferred]** If SMTP worked:
   - The broadcast loops over **every active subscriber in one request**, with `usleep(500000)` between sends (`:144`). About 100 subscribers would take 50 s plus SMTP time, past the host's execution limit, so it stops partway with no record of who got it.
   - Each email's unsubscribe link is `newsletter.php?unsubscribe=1&token=…` (`:131`), and `newsletter.php` has **no unsubscribe handler**. The link silently redirects (domain 16).

### Journey 8: Admin manages posts
1. `admin/manage_blog.php` renders and lists posts **[confirmed: crawl]**. Bulk publish/draft/delete use bound placeholders.
2. Deleting a post doesn't delete its `reflection_comments`, `blog_reads` or `prayer_requests` rows **[inferred]**.

## Quality problems independent of the bugs above
- **Every member interaction on a post** (comment, photo, voice, prayer) **ends in a crash page** while actually succeeding.
- **Photo and voice comments** are a heavy feature (camera, microphone, uploads) built on a broken storage path, on a host with small upload limits.
- **No moderation queue:** comments publish immediately; admin replies are the only tool. `admin/comments.php` doesn't cover blog comments (domain 15).
- **Category naming confusion:** "Christian Reflections" is the Blog title, the default category and the "reflection" type.
- **Layout is fine on phone** **[confirmed]**; tap targets are small.

## Root causes
- **[code]**
  - `comment_count` sort column that's never maintained.
  - Uploads written to `../` relative paths.
  - Email inside every member action.
  - No CSRF.
  - `comment_reactions` never written.
  - No prayer-request admin view.
  - Synchronous broadcast.
  - Unsubscribe link to an unhandled URL.
  - Reading-progress query on a non-existent link.
- **[schema]** `blog_posts.comment_count/book_id`, `blog_reads`, `reflection_comments`, `comment_reactions`, `prayer_requests` aren't created by the repo.
- **[ux]** Crash-after-success everywhere; no moderation; category confusion.
- **[infra]** SMTP; execution time for broadcasts; upload limits; writes per view.

## What a professional version looks like
- **J1:** The Blog loads with real sorts only (Newest, Popular). Categories are clearly separate from the page title.
- **J2:** Post page as today, minus the reading-progress query.
- **J3/J5:** Comment or prayer → instant inline "Posted" / "Your prayer request was received". The request appears in an admin "Prayer requests" list with a "prayed" checkbox. Email is optional and never blocks.
- **J4:** Either remove photo/voice comments, or store uploads inside the site's uploads folder with type and size checks and a visible limit.
- **J6:** One reaction model for comments site-wide, with a working endpoint, or no reaction buttons.
- **J7:** Publishing never emails inline. "Send to subscribers" creates a send job that the admin runs in small batches (e.g. a "Send next 20" button with progress), with a working unsubscribe link.

## Dependencies before this can be fixed
1. Decide whether `comment_count` exists (computed vs stored). If stored, **add it manually** and backfill; otherwise remove it from the query (SQLite `data/site.db`, not phpMyAdmin; see 01).
2. Reflections model decision (domain 7), because of the shared table and category.
3. Email strategy and batch-sending design (domain 16).
4. Upload location policy (domain 20), shared with domains 3, 6, 11.

## Blast radius
- **Domain 7 (reflections):** shared table and category.
- **Domain 5 (search):** shared `comment_count/likes/loves/prays` columns.
- **Domain 15 (comments):** blog comments and reactions compared there.
- **Domain 16 (newsletter):** broadcast and unsubscribe link.
- **Domain 20 (infra):** upload paths.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 listing | S | Drop the dead sort or compute it. |
| J2 read | S | Drop the reading-progress query. |
| J3 comment | S | Non-fatal email; add CSRF. |
| J4 photo/voice | M | Storage path, validation, limits (or remove: S). |
| J5 prayer | M | Admin list view plus non-fatal email. |
| J6 reactions | S–M | Endpoint plus model decision. |
| J7 broadcast | M | Batched sending UI plus unsubscribe handling. |
| J8 admin manage | S | Cascade deletes. |

## Regression check against AUDIT.md
- `blog.php:92` `comment_count` fatal: **Yes** (Env A).
- `blog_post.php:31` `blog_reads` and `:187` `blog_posts.book_id` [schema]: **Yes** (Env A).
- `blog_post.php:43/85` uploads outside web root: **Yes**, confirmed on disk and as a 404.
- `blog_post.php:73` and `:108` / `:140` `sendEmail()` → `die()`: **Yes.**
- `admin/editor.php:139-145` synchronous broadcast: **Yes** in code; in practice it never starts because the earlier admin email dies first.
- Broadcast unsubscribe link to `newsletter.php?unsubscribe=1`: **Yes.**

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `blog.php` | COVERED | Journey 1 |
| `blog_post.php` | COVERED | Journeys 2–6 |
| `admin/editor.php` | COVERED | Journey 7 |
| `admin/manage_blog.php` | COVERED | Journey 8 |

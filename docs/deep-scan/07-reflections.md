# 07. Reflections

> Test method as in `01-reader.md`. Env B seed: one row in `reflections` ("Quiet Waters") and one blog post ("Finding Peace", category "Christian Reflections"). During the test the admin created "Admin Reflection On Hope" through `admin/reflection_editor.php`.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `reflections.php` | 571 | Public reflections listing: search, tags, sort, featured, recent | Y |
| `admin/manage_reflections.php` | 206 | Admin list/delete of reflections | Y |
| `admin/reflection_editor.php` | 728 | Admin create/edit a reflection (rich text, photo, audio/video recording) | Y |
| `reflections` table | — | Read by `reflections.php` only; written by nothing | Y |

## User journeys

### Journey 1: Admin writes and publishes a reflection
1. `admin/reflection_editor.php` → title, body, excerpt, tags, status "published" → Save. **[confirmed]** Saves without error (Env B).
2. **Where it goes:** `INSERT INTO blog_posts (…, category, …)` with category "Christian Reflections" (`reflection_editor.php:108`). **Not** into `reflections`. **[confirmed]**
3. `admin/manage_reflections.php` lists it **[confirmed]**, because that page lists `blog_posts WHERE category = 'Christian Reflections'` (`:24`).

### Journey 2: Visitor looks for that reflection
1. **How a visitor gets to reflections:** `reflections.php` isn't in the site header or footer. The only links are on the member dashboard and admin dashboard (`dashboard.php`, `admin/dashboard.php`). A public visitor has no route to it **[confirmed via grep]**.
2. Opening `reflections.php` directly: Env A **[fatal] [confirmed]** *no such table: reflections* (`reflections.php:17`).
3. Env B: the page lists **only "Quiet Waters"** (the row inserted directly into `reflections`). **[wrong] [confirmed]** The admin's newly published reflection is **not there**.
4. The admin's reflection **appears on the Blog instead** (`blog.php` shows "Admin Reflection On Hope") **[confirmed]**.

### Journey 3: Visitor opens a reflection to read it
1. Every link on `reflections.php` (cards, featured, recent, search-as-you-type results) points to `reflection.php?id=…` (4 places).
2. **[fatal-404] [confirmed]** `reflection.php` doesn't exist.

*Stop: no reflection can be read through the reflections section.*

### Journey 4: Admin edits or deletes a reflection
1. Edit from `manage_reflections.php` opens `reflection_editor.php?id=…` and updates the `blog_posts` row. Works for admin-made reflections **[inferred]**.
2. **[wrong] [confirmed]** The list also contains **ordinary blog posts**. `blog_posts.category` defaults to "Christian Reflections" (`includes/db.php:183`), so any blog post saved without changing the category ("Finding Peace" in the test) is shown and can be deleted as a "reflection".
3. Reflections that exist in the `reflections` table (what the public page shows) can't be edited or deleted from any admin screen.

### Journey 5: Admin attaches audio or video to a reflection
1. The editor offers audio recording, video recording and a live photo (`reflection_editor.php` fields `audio_recording`, `video_recording`, `live_photo`).
2. **[quality] [inferred]** Recorded media is uploaded in the same form post. Browser recordings quickly exceed the host's per-file upload limit, which gives a generic failure and loses the text that was typed.
3. Publishing also sends an admin notification email (`reflection_editor.php:113-117`). On SMTP failure that `die()`s after the row is saved (domain 16) **[inferred]**.

## Quality problems independent of the bugs above
- **Two meanings of "reflection":** a blog category (what admin writes) and a separate table (what the public page reads). Neither links to a readable page.
- **Not in navigation** for visitors, so the section is effectively invisible even when it renders.
- **Search-as-you-type** on `reflections.php` builds links in JS (`'reflection.php?id=' + item.id`), so even the live search results 404.
- **"Most commented" / popularity sorts** rely on `comment_count`/`views` columns that nothing maintains (same as domains 5, 6, 13).
- **Layout:** no horizontal overflow at 768/375 in the crawl **[confirmed]**. It looks finished, which makes the dead links more confusing.

## Root causes
- **[code]**
  - Admin writes `blog_posts`, the public page reads `reflections`.
  - Links to a page that was never written.
  - Category default makes every blog post a reflection.
- **[schema]** `reflections` table isn't created by the repo and isn't written by any code.
- **[ux]** No navigation entry; recorded media and text submitted together.
- **[infra]** Upload limits for recorded media; email on publish.

## What a professional version looks like
- **J1/J2:** One model. A reflection is a blog post with a "Reflection" type (or a reflections table that the editor writes to). Publishing makes it appear immediately on the Reflections page, which is in the main navigation.
- **J3:** Each reflection has a readable page (the blog-post page is enough) with title, date, body, media and comments. Every link on the listing goes there.
- **J4:** The admin list shows only reflections. Editing and deleting work on the same rows the public sees.
- **J5:** Media uploads happen separately (with size limit shown and progress) and are attached after upload, so a failed upload never loses the written text.

## Dependencies before this can be fixed
1. **Decide the model:** are reflections blog posts with a category, or their own table? Everything here depends on this.
2. If production has a populated `reflections` table, decide whether to migrate those rows into `blog_posts` or vice versa. That's a manual data step in `data/site.db` (SQLite, not phpMyAdmin; see 01).
3. Change the default blog category so ordinary posts stop being treated as reflections. **Existing rows may need a manual category correction.**
4. Email strategy (domain 16).

## Blast radius
- **Domain 13 (blog):** shares `blog_posts`; admin "reflections" show on the blog; the default category collides.
- **Domain 5 (search):** search queries `reflections` and links to `reflection.php`.
- **Domain 11 (dashboard):** the member dashboard is the only public-ish entry point.
- **Domain 16 (email):** publish notification.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 admin publish | S | Point the editor at the chosen model. |
| J2 visitor finds it | S | Read the chosen model; add a nav link. |
| J3 read a reflection | S | Link to the blog-post page (or write one detail page). |
| J4 admin manage | S | Filter by the real type; fix the default category. |
| J5 media | M | Separate upload step with limits and progress. |

## Regression check against AUDIT.md
- `reflections` table missing [schema] (`reflections.php:17`): **Yes** (Env A).
- Search links to non-existent `reflection.php`: **Yes**, and the same broken target is used by every link on `reflections.php` itself.
- `admin/reflection_editor.php:117` `sendEmail()` → `die()`: not reproduced in this run (the test post didn't reach the email branch). **Still present in code.**

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `reflections.php` | COVERED | Journeys 2, 3; Quality |
| `admin/manage_reflections.php` | COVERED | Journeys 1, 4 |
| `admin/reflection_editor.php` | COVERED | Journeys 1, 5 |
| `reflections` table | COVERED | Journeys 2, 4 (written by nothing) |

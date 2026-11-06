# 12. Poetry

> Test method as in `01-reader.md`. Env B seed: poem 1 "Morning Light" with a public review and a private review (from user 3 to the admin).

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `poetry.php` | 352 | Public poem listing with search and pagination | Y |
| `poem_view.php` | 1116 | Single poem: text, audio, share/OG, threaded and private comments, reactions, view logging, job queue | Y |
| `admin/poem_editor.php` | 678 | Admin create/edit poem (rich text, image, audio) | Y |
| `admin/preview_poem.php` | 528 | Admin preview with review handling | Y |
| `admin/manage_poems.php` | 1089 | Admin list, bulk publish/draft/delete, image/audio upload | Y |
| `ajax_get_users.php` | 53 | @-mention autocomplete used by the poem comment box | Y |

## User journeys

### Journey 1: Visitor browses poems
1. `poetry.php` lists poems with search and pagination **[confirmed]** in both environments (`poems` is a repo-defined table). No overflow at 768/375 px **[confirmed]**.
2. **[wrong] [inferred, code-verified] Drafts are public.** `poetry.php`, `poem_view.php`, `index.php` and search never read `poem_status`, so a poem the admin set to "draft" still shows everywhere.

### Journey 2: Visitor reads a poem
1. `poem_view.php?id=1` renders poem, intro, comments and reaction buttons **[confirmed]** (Env B). Env A: fatal on `poem_reads` for **logged-in** users (AUDIT.md); guests pass **[confirmed via crawl: guest OK]**.
2. Every view increments `view_count`, inserts a `view_logs` row and, for members, a `poem_reads` row. That's 2–3 SQLite writes per view, bots included.
3. **[quality] [confirmed]** The page never sets `$pageTitle`, so the browser tab and search results show only the site name, never the poem's title.
4. **[quality] [inferred]** Poem-specific Open Graph tags are printed at `poem_view.php:286-290`, *after* `includes/header.php` has already emitted generic `og:` tags and closed `<head>`. Shares get duplicate, conflicting tags, and some crawlers ignore tags in `<body>`. The `og:image` points to `/img/<id>`, served by `serve_og.php`, which is broken (domain 20). So **sharing a poem shows no image**.
5. On phone: 35 tap targets under 32 px (reaction chips, share icons) **[confirmed]**.

### Journey 3: Member comments on a poem
1. Form with CSRF token, rating, comment, "private" checkbox, reply-to **[confirmed]**.
2. Submit → comment saved, 302 back, comment visible to the member **[confirmed]**. **Works** (Env B).
3. A `jobs` row is queued for "process_comment" (`:168`). **[silent] [confirmed]** The row's `status` is **NULL**, not `'pending'`, because the INSERT doesn't set it. `worker.php` only picks `status='pending'`, and there's no worker or cron anyway, so **the admin is never told about private comments**.
4. **@-mentions [wrong] [confirmed]:** typing `@` calls `ajax_get_users.php`, which lists members `WHERE name != ''`. Every self-registered member has a NULL `name` (domain 10), so **only seeded or admin-edited accounts can be mentioned**. Guests get `[]`. Mentions are meant to be handled by `worker.php` ("Notify tagged users… you can implement…", `worker.php:30-32`), which isn't implemented and never runs, so a mention notifies no one.
5. Env A: the INSERT needs `reviews.parent_id/is_private/target_user_id/deleted_at`, which the repo's table lacks (AUDIT.md) **[schema]**.

### Journey 4: Member leaves a private message on a poem
1. Ticks "private". Stored with `is_private = 1, target_user_id = 1` (hard-coded admin id, `:152`).
2. Other members and guests **don't** see it; the admin does **[confirmed]**.
   - **Correction to AUDIT.md:** the query's `OR ? = 1` (`:192`) compares PDO's string `'1'` with the integer `1`. SQLite treats those as unequal, so the "always true" branch doesn't fire and private comments **don't leak** on SQLite.
   - It is still a latent bug: on MySQL, or if the binding type changes, every private comment becomes public.
3. The admin isn't notified (Journey 3 step 3), so private messages sit unseen unless the admin happens to open that poem.

### Journey 5: Member reacts to a comment
1. `ajax_toggle_reaction.php` with `target_type=comment` → `{"active":true,"count":1}`. Toggle off works **[confirmed]**.
2. `ajax_get_reactions.php` returns six reaction types (like, love, laugh, wow, sad, …) **[confirmed]**. A different vocabulary from blog (like/love/pray), video (like/dislike or like/love/pray) and book reviews (domain 15).

### Journey 6: Admin writes a new poem
1. `admin/poem_editor.php` → title, intro, content, image, audio → Save. **[wrong] [confirmed]** The poem **is saved**, then `:109` `sendEmail()` → **SMTP dump**.
2. **[wrong] [confirmed]** No `poem_status` row is created for the new poem (`poem_status` still has only the seeded row). The draft/publish toggles in `manage_poems.php` (`:25`, `:34`, `:67`, `:72`) run `UPDATE poem_status … WHERE poem_id = ?`, which updates **zero rows** for any poem made in the editor. They report success and change nothing. (And status isn't read publicly anyway, Journey 1 step 2.)

### Journey 7: Admin previews a poem
1. `admin/preview_poem.php?id=1` → **[fatal] [confirmed]** *Failed opening required 'includes/config.php'* (relative path resolved against `admin/`).
2. Nothing links to `preview_poem.php` (grep). It's an orphan with its own copy of review, voice-review and admin-reply handling (`:24-70`) that duplicates `poem_view.php` and `admin/comments.php`.

### Journey 8: Admin bulk-manages poems
1. `admin/manage_poems.php` renders (Env A and B) **[confirmed: crawl]**. Bulk delete runs inside a transaction (`:50`), removing `poem_status`, `reviews` and `poems`.
2. **[wrong] [inferred]** Bulk delete doesn't remove `reactions`, `poem_reads`, `view_logs` or `jobs` for those poems, so orphan rows accumulate (and count toward table growth).

## Quality problems independent of the bugs above
- **Draft/publish is decorative** (Journeys 1 and 6). The admin believes poems are hidden when they're not.
- **Sharing is the point of poems** and it's the weakest part: no title tag, duplicate OG tags in the body, a broken OG image.
- **Reaction vocabulary differs from every other content type.**
- **Admin notification for private messages never fires.**
- **Listing and reading layouts are fine on phone** (no overflow) **[confirmed]**. Tap targets are small.

## Root causes
- **[code]**
  - `poem_status` ignored by public pages and not created by the editor.
  - `jobs.status` not set.
  - `$pageTitle` not set.
  - OG tags emitted after `<head>`.
  - Relative includes in `preview_poem.php`.
  - Latent `OR ? = 1`.
  - Hard-coded admin id `1`.
  - Email inside editor save.
  - Incomplete cascade on delete.
- **[schema]** `reviews` lacks `parent_id/is_private/target_user_id/deleted_at` in the repo definition. `poem_reads`, `view_logs`, `reactions`, `jobs` aren't created by the repo.
- **[ux]** Small tap targets; unseen private messages.
- **[infra]** No worker or cron for the job queue; writes on every view.

## What a professional version looks like
- **J1:** Listing and every public surface show only published poems. Drafts are visible only to the admin, with a "Draft" badge.
- **J2:** `<title>Morning Light · AngelWrites</title>`. Each poem has exactly one set of OG tags in `<head>` with a real image (a static per-poem image generated when the poem is saved, or the poem's own image).
- **J3/J4:** After posting: "Comment posted" (or "Private message sent to Angella"). Private messages appear in an admin inbox with an unread count; nothing depends on a background worker.
- **J6:** Saving a poem shows "Saved · Draft" or "Saved · Published" and never crashes. The status control in the editor is the one source of truth.
- **J7:** One preview, the public page rendered with a "Preview" banner for the admin.

## Dependencies before this can be fixed
1. Decide whether poems have a draft state. If yes, every existing poem needs a `poem_status` row, **inserted manually** (or by a one-off admin script) in `data/site.db` (SQLite, not phpMyAdmin; see 01).
2. Confirm `reviews` has the extra columns in production (or add them manually).
3. OG image approach (domain 20) before poem sharing can be fixed.
4. Email strategy (domain 16) for editor saves and private-message alerts.

## Blast radius
- **Domain 15 (comments):** poem reviews are one of the systems compared there; `admin/comments.php` moderates them.
- **Domain 20 (infra):** `serve_og.php`, `generate_og_image.php`, `/img/` rewrite, `worker.php` and the job queue.
- **Domain 5 (search):** search shows draft poems too.
- **Domain 11 (dashboard):** "Poems read" counter comes from `poem_reads`.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 drafts public | S | One status filter on public queries plus status rows for existing poems. |
| J2 title/OG | S–M | Title is S; a working OG image is M (domain 20). |
| J3 comment notify | S | Replace the job queue with a direct inbox flag. |
| J4 private | S | Remove the latent clause; admin inbox (shared with J3). |
| J5 reactions | S | Vocabulary decision (domain 15). |
| J6 editor | S | Create status row; non-fatal email. |
| J7 preview | S | Delete or fix includes. |
| J8 bulk delete | S | Delete dependent rows. |

## Regression check against AUDIT.md
- `poem_view.php:192` `OR ? = 1` makes private comments public: **No / Different.** It doesn't reproduce on SQLite (string vs integer comparison); it's a latent bug.
- `poem_view.php:168` jobs never processed: **Yes**, and worse: the `status` column is left NULL, so even a worker wouldn't pick them up.
- `admin/preview_poem.php:2` relative include fatal: **Yes.**
- `poem_view.php:116` `poem_reads` missing [schema]: **Yes** (Env A, logged in).
- `admin/poem_editor.php:109` `sendEmail()` → `die()`: **Yes** (Journey 6).

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `poetry.php` | COVERED | Journey 1 |
| `poem_view.php` | COVERED | Journeys 2, 3, 4, 5 |
| `admin/poem_editor.php` | COVERED | Journey 6 |
| `admin/preview_poem.php` | COVERED | Journey 7 |
| `admin/manage_poems.php` | COVERED | Journeys 6 (step 2), 8 |
| `ajax_get_users.php` | COVERED | Journey 3 step 4 |

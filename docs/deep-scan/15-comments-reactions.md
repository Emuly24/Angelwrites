# 15. Commenting & reactions (all systems, side by side)

> Test method as in `01-reader.md`. Each system was exercised as a member (post, see, react) and as the admin (moderate). Findings already detailed in their home domains are summarised in the comparison table and linked rather than re-walked.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `poem_view.php` | 1116 | Poem comments (stored in `reviews`, `target_type='poem'`): threaded, private, rated | Y |
| `book.php` | 602 | Book reviews (`reviews`, `target_type='book'`) + review reactions (`review_reactions`) | Y |
| `admin/comments.php` | 567 | Admin moderation: poem comments + book paragraph comments; reply, delete, toggle private | Y |
| `blog_post.php` | 797 | Blog comments (`reflection_comments`): text/photo/voice, admin reply, `comment_reactions` | Y |
| `video_watch.php` | 369 | Video comments (`video_comments`) + like/dislike (`video_reactions`) | Y |
| `ajax_get_reactions.php` | 55 | Read reaction counts (`reactions` table) | Y |
| `ajax_toggle_reaction.php` | 69 | Toggle a reaction (`reactions` table) | Y |
| `reader/reader_ajax.php` (comment actions) | 866 | `add_book_comment` / `get_book_comments` (`book_comments`) | Y |
| *(for completeness)* `admin/process_book.php` `add_comment` | — | Admin paragraph comments (`book_comments`) | Y |

## The systems compared

| | Poem comments | Book reviews | Paragraph comments | Blog comments | Video comments | *(also)* Bible notes · Q&A answers · Group notes |
|---|---|---|---|---|---|---|
| **Table** | `reviews` (poem) | `reviews` (book) | `book_comments` | `reflection_comments` | `video_comments` | `bible_notes` · `answers` · `group_notes` |
| **Member can post?** | ✅ works **[confirmed]** | ⚠ saved, then SMTP dump **[confirmed]** | ❌ reader endpoint fatals (01) | ⚠ saved, then SMTP dump **[confirmed]** | ⚠ saved, page fatals **[confirmed]** | ⚠ · ❌ fatal (14) · ✅ |
| **Member can see it after posting?** | ✅ | ❌ in Env A (list query needs `review_reactions`); ✅ Env B | ❌ | ✅ | ❌ (page fatal at `:129`) | ✅ · ✅ · ✅ |
| **Replies / threading** | ✅ (`parent_id`) | ❌ | admin reply only (broken, below) | admin reply only | ❌ | ❌ · n/a · ❌ (06) |
| **Private option** | ✅ hidden from others **[confirmed]** | ❌ | ❌ | ❌ | ❌ | ⚠ private notes **leak** (02) |
| **Rating** | ✅ (1–5) | ✅ (1–5) | ❌ | ❌ | ❌ | ❌ |
| **CSRF token** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Reactions** | `reactions`: like, love, laugh, wow, sad… via `ajax_toggle_reaction.php` ✅ **[confirmed]** | `review_reactions`: like/love/pray via `book.php` POST ✅ (Env B) | none | `comment_reactions`: like/love/pray **displayed but never written** (no INSERT anywhere) | `video_reactions`: like/dislike (watch page, MySQL syntax → fatal) **and** like/love/pray (listing, works) | `bible_reactions` · upvotes (unlimited) · `group_reactions` (no UI) |
| **Admin moderation** | `admin/comments.php` ✅ list, ✅ reply, ❌ delete fatal, ⚠ privacy toggle by GET | **none** | `admin/comments.php` list ✅, reply ❌ (columns), delete ✅ | admin reply on the post page only; no list or delete | **none** | none · `manage_community.php` (reports) · none |
| **Admin notified** | via `jobs` queue that's never processed | email → SMTP dump | email (reader path dead) | email → SMTP dump | none | email → SMTP dump · email → dump · none |

Seven comment-like systems and six reaction systems, with **four different reaction vocabularies**. No two behave the same.

## User journeys

### Journey 1: Member comments on a poem, and the admin answers
1. Member posts (with CSRF) → visible immediately **[confirmed]**.
2. Admin opens `admin/comments.php` (Poems tab). The list shows comments, but the page prints **PHP warnings** at the top: *Undefined variable $books_list* (`:418`) on the Poems tab and *$poems_list* (`:297`) on the Books tab **[quality] [confirmed]**.
3. Admin replies → saved as an admin reply and shown under the comment on the poem page **[confirmed]**. **This is the one complete comment→reply loop on the site.**
4. Admin deletes a comment → **[fatal] [confirmed, schema-independent]** *no such function: NOW* (`admin/comments.php:67`, MySQL function used on SQLite). Admins can't delete poem comments.

### Journey 2: Admin reviews private messages
1. Private messages are only shown with the "private" filter (`?filter_private=1`) **[confirmed]**. The default view hides them, and no unread count exists anywhere.
2. **[wrong] [confirmed]** The "toggle private" control is a plain GET link (`?toggle_private=<id>`, `:54-60`). Following it once made a member's private message **public** on the poem page. No confirmation, no CSRF, so a stray click or a crafted link exposes private messages.

### Journey 3: Member reviews a book
1. Detailed in 09 J3: saved, then SMTP dump; invisible in Env A.
2. **[missing] [confirmed by code]** Book reviews appear in **no** admin screen: `admin/comments.php` filters `target_type = 'poem'` (`:103`). The admin can't remove an abusive book review except by editing the database.

### Journey 4: Paragraph comments on a book ("this line has a typo")
1. From the reader: `add_book_comment` is unreachable (reader script never loads; endpoint fatals, 01).
2. **[wrong] [inferred, code-verified against the repo's own table]** Even with the reader fixed:
   - `reader_ajax.php:667` inserts `(user_id, book_id, page_num, comment)`.
   - The repo's own `book_comments` definition (`admin/process_book.php:46-54`) has **no `page_num`** and requires `paragraph_index NOT NULL`.
   - So reader comments fail on the table the repo creates.
3. Admin side: the Books tab lists them **[confirmed]**. Reply inserts `parent_id, is_admin_reply` (`admin/comments.php:45`), columns that table definition also lacks, so it fails on a repo-created table **[inferred, code-verified]**. Delete works (hard delete).

### Journey 5: Member comments on a blog post or video
1. Blog: 13 J3–J6 (saved → SMTP dump; photo path broken; reactions never written). Not in `admin/comments.php`.
2. Video: 06 J5 (saved → page fatal → never visible). No moderation anywhere.

### Journey 6: Member reacts
1. Poem comments: `ajax_toggle_reaction.php` toggles and returns `{active, count}` **[confirmed]**.
   - Rate-limited by a file in the system temp dir (`rate_limit()` copy).
   - Accepts any `target_type` string, so reactions can be attached to arbitrary types and ids **[inferred]**.
2. `ajax_get_reactions.php` returns counts for 6 types, but different pages render different subsets **[confirmed]**.
3. Book reviews, videos and blog comments each use their own table, vocabulary and endpoint (table above).

## Quality problems independent of the bugs above
- **No single "comments" experience.** Members meet a different form, layout, set of features and set of failures on each content type.
- **Moderation is partial and inconsistent:** two of seven systems are in the admin screen, and both have a broken action. There's no global "recent comments" or "needs attention" view.
- **Feedback after posting** ranges from "works" (poems) to crash page (books, blog) to invisible (video).
- **Reaction counts can't be compared or trusted** across the site.

## Root causes
- **[code]**
  - Every content type built its own comment and reaction code.
  - MySQL functions (`NOW()`, `ON DUPLICATE KEY`) on SQLite.
  - GET-based state changes.
  - Undefined template variables in `admin/comments.php`.
  - Writers and readers disagree on columns (`page_num` vs `paragraph_index`).
  - `comment_reactions` never written.
  - Email in the post path.
- **[schema]** 7 comment tables and 6 reaction tables, most not created by the repo. Where the repo does create one (`book_comments`), the code doesn't match it.
- **[ux]** Inconsistent forms and feedback; private messages hidden behind a filter.
- **[infra]** SMTP; unprocessed job queue.

## What a professional version looks like
- **Posting (all types):** the same component everywhere. Text box, optional rating where it makes sense, Post → the comment appears instantly with "Posted · just now". Errors show inline and keep the text.
- **Replies:** one level of replies on every type. The admin's replies are badged "Angella".
- **Private messages:** one "Message Angella privately" option that lands in an admin inbox with an unread badge, never mixed into public threads.
- **Reactions:** one small fixed set (e.g. ❤️ 🙏) for all content and comments, one table, one endpoint, one toggle behaviour.
- **Moderation:** one admin "Comments" screen listing every new comment from every type, newest first, with Reply / Hide / Delete. Actions are POST with a token and confirm before deleting.

## Dependencies before this can be fixed
1. Decide the comment model: one shared comments table with a `content_type`, or keep separate tables with a shared admin view. Merging tables is a **manual data migration** in `data/site.db` (SQLite, not phpMyAdmin; see 01).
2. Decide the reaction vocabulary; existing reaction rows may need mapping.
3. Email strategy (domain 16) so posting never depends on SMTP.
4. Fix MySQL-only SQL across the codebase (this domain has 2 instances; see the rollup).

## Blast radius
- **Domains 9, 12, 13, 6, 1, 2, 14, 4:** each owns one of the systems compared here.
- **Domain 11:** comment replies are the natural source of member notifications.
- **Domain 19:** the admin dashboard's comment counts read some of these tables.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 poem comment + admin | S | Fix `NOW()` and the undefined variables. |
| J2 private messages | S | POST + confirmation for the toggle; unread count. |
| J3 book reviews moderation | S | Include `target_type='book'` in the admin list. |
| J4 paragraph comments | S–M | Align columns with the table; depends on the reader (01). |
| J5 blog/video moderation | M | Add to a shared admin view. |
| J6 reactions | M | One vocabulary/table/endpoint plus migration. |
| Unified comments | L | Only if you choose to merge the systems. |

## Regression check against AUDIT.md
- `poem_view.php:192` private-comment leak: **No / Different.** Doesn't reproduce on SQLite (see 12 J4).
- `book.php:63-85` reviews hidden when `review_reactions` missing: **Yes** (Env A).
- `blog_post.php:73` `sendEmail()` → `die()`: **Yes.**
- `video_watch.php` comments: AUDIT.md didn't cover them. New: saved but never visible (page fatal at `:129`).
- AUDIT.md didn't report `admin/comments.php:67` `NOW()` or the GET privacy toggle. New findings.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `poem_view.php` | COVERED | Comparison table; Journeys 1, 2 |
| `book.php` | COVERED | Comparison table; Journey 3 |
| `admin/comments.php` | COVERED | Journeys 1, 2, 3 (step 2), 4 (step 3) |
| `blog_post.php` | COVERED | Comparison table; Journey 5 |
| `video_watch.php` | COVERED | Comparison table; Journey 5 |
| `ajax_get_reactions.php` | COVERED | Journey 6 |
| `ajax_toggle_reaction.php` | COVERED | Journey 6 |
| `reader/reader_ajax.php` (comment actions) | COVERED | Journey 4 |
| `admin/process_book.php` `add_comment` | COVERED | Journey 4 (table definition `:46-54`) |

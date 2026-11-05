# 09. Book catalogue & book detail

> Test method as in `01-reader.md`. Env B: "Walking in Grace" (id 1, free, processed into 6 pages) and "Seasons of the Heart" (id 2, MWK 5,000, **not** processed), with one seeded review. Env A has no books, so the catalogue renders empty there (no fatal).

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `books.php` | 550 | Catalogue: search, Free/Sale filter, cards with Read / Download, payment modal | Y |
| `book.php` | 602 | Book detail: cover, description, price, Read or Download, reviews and review reactions, reputation strip | Y |
| `admin/manage_books.php` | 875 | Admin book CRUD: metadata, cover (upload or camera), file upload, price/free/sale | Y |
| `admin/process_book.php` | 2791 | Admin pipeline turning an uploaded file into reader pages. **Reader-facing output only** here: extract, publish, queue, per-book reader CSS | Y (reader-facing parts) |

## User journeys

### Journey 1: Visitor browses the catalogue
1. `books.php` lists both books with cover, description ("Read More" toggle over 400 chars), price, **Read**, and, for members only, **Download** **[confirmed]**.
2. Search "grace" → 1 result. Filter "Free" → 1 result. Filter "Sale" → none (no sale books) **[confirmed]**.
3. No horizontal overflow at 768/375 px **[confirmed]**.
4. **[wrong] [confirmed]** Every card shows **Read**, including books with no processed content. "Seasons of the Heart" opens a reader showing an empty frame and "1 / 0" (domain 1). The detail page handles this case differently (Journey 2 step 3), so the two pages disagree.

### Journey 2: Visitor opens a book's detail page
1. `book.php?id=1`: cover, title, author, price badge, description, "What Readers Say" with the review, and a **Read Book** button **[confirmed]**. A non-existent id quietly redirects to the catalogue.
2. Logged-in members also see a reputation strip: "🏆 0 pts · Level 1 🔥 day streak". **[quality] [confirmed]** The streak number is blank when the member has no streak row: `fetchColumn()` returns `false` and `?? 0` doesn't catch `false` (`book.php:155`).
3. `book.php?id=2` (not processed): instead of Read, it shows **"Download (PDF)"**, linking **directly to the raw file** (`book.php:224-226`). This happens for **guests and for a paid book**, next to its MWK 5,000 price. The file is served (200 `application/pdf`). **[wrong] [confirmed]** Full analysis in 03 J4.0.
4. **No buy button** on the detail page for paid books with processed content; just "Read Book", into a reader that doesn't check payment either (domain 1).
5. Phone (375 px) **[confirmed by screenshot]**:
   - The cover image fills the entire first screen; title, price and buttons start below the fold.
   - The cookie bar covers the bottom quarter.
   - The header crowds 5 icons plus the menu button, and the menu button is partly clipped at the right edge.
6. Copy: "(1 reviews)" / "(2 reviews)" with no singular form **[confirmed]**.

### Journey 3: Member writes a review
1. Review form (rating plus comment) shows for logged-in members only. Guests see nothing, not even "Sign in to review" **[confirmed]**.
2. Submit: the review **is saved** (count 1 → 2), then `book.php:109` `sendEmail()` → `die()`, so the member sees the **SMTP error page** instead of their review **[wrong] [confirmed]**.
3. Env A: the review list query sub-selects `review_reactions` (not created by the repo). The `catch` returns an empty list, so saved reviews **never display** **[confirmed in AUDIT.md run; still in code]**.
4. A member can review the same book any number of times (no uniqueness check) **[inferred]**.

### Journey 4: Member reacts to someone's review
1. `POST book.php react_to_review` returns `{"success":true}` **[confirmed]** (Env B). Env A answers "Reaction table missing".
2. **[quality] [inferred]** `display_errors` is forced on (`book.php:8-9`), so any notice before the JSON corrupts it.

### Journey 5: Admin adds a new book and makes it readable
1. `admin/manage_books.php`: title, author, description, price/free/sale, cover (file or camera), book file. Saves; emails admin (`:144`), which is exposed to the SMTP `die()` (domain 16) **[inferred]**.
2. The new book appears in the catalogue immediately, with a **Read** button that leads to an empty reader until processed (Journey 1 step 4), and a raw-file **Download** on its detail page (Journey 2 step 3).
3. Admin opens **Process** (`admin/process_book.php?id=…`). Two paths:
   - **Extract** (synchronous): parses the PDF/EPUB/DOCX in the request, writes `book_content` with `<div class="page-content" data-page="N">` pages. That's the format the reader expects **[inferred, code-verified `:1026-1033` vs `reader/book_data.php:52`]**. Large files risk the host's time and memory limits.
   - **Add to processing queue** (`queue_book`, about `:1100`): says "✅ Book added to processing queue." **[silent] [inferred, code-verified]** Nothing ever processes `book_processing_queue`; it's only ever inserted into and status-read (`:385`). With no cron and no worker, the book stays "queued" forever.
4. **Publish version** updates `book_content.content_html` (`:515`). Works as written **[inferred]**.
5. **Reader CSS** (`save_reader_css`, `:607`) saves per-book styling. **[wrong] [inferred, code-verified]** No reader file reads `reader_css`, so the setting has no effect.
6. The manual editing tools (`merge_chapters`, `split_chapter`, `search_replace`, presets) rewrite `content_html` (`:743`, `:791`, `:945`). If they introduce any nested `<div>`, the reader's non-greedy page regex truncates those pages silently (domain 1 Quality) **[inferred]**.

## Quality problems independent of the bugs above
- **Catalogue and detail page disagree** on what a reader can do with an unprocessed book (Read vs raw Download).
- **No "owned" state, no buy button on the detail page, no "Sign in to download/review" prompts for guests.**
- **Phone layout** of `book.php`: the oversized cover pushes all actions below the fold.
- **Price display** "MWK 5,000.00" with decimals; the sale price looks identical to the regular price (no strike-through or original price).
- **`admin/process_book.php` is a 2,800-line single page with 40+ actions.** For one admin this is mostly hidden complexity, but every action posts to the same file with `display_errors` on (`:6-8`), so errors print into JSON responses.
- **Hits:** `book.php` costs 5–6 requests per view **[confirmed]**, plus a `view_logs` insert and a `view_count` update (2 SQLite writes per view, guests and bots included).

## Root causes
- **[code]**
  - Raw-file link for unprocessed books.
  - Read button regardless of processing.
  - `fetchColumn() ?? 0` streak bug.
  - Email in the review path.
  - Queue never drained.
  - `reader_css` unused.
  - Pluralisation.
- **[schema]** `review_reactions`, `book_content`, `book_content_history`, `book_processing_queue`, `reading_streaks`, `user_reputations`, `view_logs` aren't created by the repo.
- **[ux]** No guest prompts, cover-first mobile layout, no ownership/buy states.
- **[infra]**
  - Synchronous extraction against host time/memory limits.
  - No cron for the queue.
  - Writes on every view.
  - SMTP `die()`.

## What a professional version looks like
- **J1:** Each card shows what the visitor can actually do: **Read** (processed and free/owned), **Preview** (processed and paid), **Buy** (paid), **Coming soon** (unprocessed). Never a link into an empty reader.
- **J2:**
  - Detail page on phone: title, author, price and the main action above the fold, with a compact cover beside or above them.
  - No raw-file links. Every download goes through the gate.
  - The streak strip hides when there's no streak.
- **J3:** Review posts and reappears at the top of the list with "Thanks for your review". One review per member per book (edit instead of duplicate). Guests see "Sign in to write a review".
- **J5:**
  - Processing is synchronous with a clear progress/result screen, plus a size warning before extraction. Remove the queue button (or label it accurately) because nothing can process a queue on this host.
  - A book appears in the catalogue only after it is published, or appears as "Coming soon".
  - Reader CSS is applied by the reader, or the setting is removed.

## Dependencies before this can be fixed
1. Decide the purchase/ownership model (03/08), because card and detail-page actions depend on it.
2. Confirm production tables (`book_content`, `review_reactions`, `reading_streaks`, `user_reputations`, `view_logs`). **Add manually** where missing (SQLite `data/site.db`, not phpMyAdmin; see 01).
3. Email strategy (domain 16) for review notifications.
4. Reader fixes (domain 1) before "Read" can be relied on.

## Blast radius
- **Domains 3/8:** raw-file download leak, missing buy path.
- **Domain 1:** "Read" lands in the reader; `process_book.php` produces the reader's input.
- **Domain 15:** book reviews and review reactions are one of the comment systems compared there.
- **Domain 5:** search links books into the reader instead of here.
- **Domain 11:** reputation and streak data shared with the dashboard and profile.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 catalogue actions | S | Conditional buttons once ownership exists. |
| J2 detail page | S–M | Remove raw link (S); mobile layout and states (M). |
| J3 reviews | S | Non-fatal email, list query, one-per-member. |
| J4 review reactions | S | Works; just stop errors corrupting JSON. |
| J5 admin processing | M | Remove/replace the queue, clearer results, apply or drop reader CSS. |

## Regression check against AUDIT.md
- `book.php:109` `sendEmail()` → `die()`: **Yes** (Journey 3).
- `book.php:63-85` review list empty when `review_reactions` missing: **Yes** (Env A).
- `display_errors` forced on in `book.php:8-9` and `admin/process_book.php:6-8`: **Yes.**
- `admin/process_book.php:172` `exec()` for pdftotext: **Yes**, still present (falls back to smalot/pdfparser).

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `books.php` | COVERED | Journey 1 (payment modal in 03/08) |
| `book.php` | COVERED | Journeys 2, 3, 4 |
| `admin/manage_books.php` | COVERED | Journey 5 steps 1–2 (upload storage in 03) |
| `admin/process_book.php` | COVERED | Journey 5 steps 3–6 (reader-facing output only, as scoped) |

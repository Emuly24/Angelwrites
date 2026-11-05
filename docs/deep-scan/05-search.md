# 05. Site-wide search

> Test method as in `01-reader.md`. Seeded Env B content: 2 books, 1 poem, 1 published blog post ("Finding Peace"), 1 reflection, 1 video.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `search_results.php` | 485 | Search form, per-type queries, merge, sort, paginate, render | Y |
| Tables and columns it queries | — | `books(title, author, description, cover_path)`, `poems(title, intro, content, image_path)`, `blog_posts(title, content, excerpt, category, featured_image, slug, status, comment_count, likes, loves, prays)`, `reflections(title, content, excerpt, image_path, slug, comment_count, likes, loves, prays)`, `videos(title, description, thumbnail)` | Y |
| Entry points | — | `index.php:612` search form, `404.php:36` search form, `includes/header.php:305` search icon | Y |

## User journeys

### Journey 1: Visitor taps the search icon in the header
1. The magnifier icon in every page header links to `search_results.php` with no query (`includes/header.php:305`).
2. **[wrong] [confirmed]** `search_results.php:13-14` sees an empty `q` and **redirects to the homepage**. The visitor taps "search" and lands on the home page with no search box in view.
   - A search box exists only on the homepage (`index.php:612`, far down the page) and on the 404 page.

### Journey 2: Visitor searches for one word ("grace")
1. Env A: **[fatal] [confirmed]** *no such table: reflections*. Every type runs regardless of matches, and `reflections` isn't created by the repo.
2. Env B, as the code expects: **[fatal] [confirmed]** *no such column: likes*. The blog and reflection queries select `comment_count, likes, loves, prays` (`search_results.php:65`, `:73`), and **no code anywhere creates or writes those columns**.
   - The same `comment_count` column is why `blog.php` fatals (domain 13).
3. With those columns added (Env B, patched): "1 result found" **[confirmed]**. Results render as cards with type badges.

*Unless production has columns that the code never writes, every search fails at step 1 or 2.*

### Journey 3: Visitor searches for two or more words ("lord shepherd")
1. **[fatal] [confirmed] in both environments, schema-independent:** *General error: 25 column index out of range*.
   - `buildSearchWhere()` (`:28-38`) writes one `?` per column but pushes **every search term for every column**. With 2 words and 3 columns that's 6 parameters for 3 placeholders.
2. Most real searches ("prayer journal", "Walking in Grace") have more than one word, so **this is the common case**.

### Journey 4: Visitor opens a result
1. **Poem** → `poem_view.php?id=…` ✔. **Blog** → `blog_post.php?slug=…` ✔. **Video** → `video_watch.php?id=…` ✔ (that page has its own problems, domain 6).
2. **Book** → `reader/reader.php?id=…` (`:209`). **[wrong] [confirmed]** It skips the book's detail page and drops the visitor straight into the reader, which renders but has no working controls (domain 1).
3. **Reflection** → `reflection.php?id=…` (`:218`). **[fatal-404] [confirmed]** `reflection.php` doesn't exist, so the visitor gets a 404.

### Journey 5: Visitor filters by type, sorts, pages through results
1. **Type filter:** works when the query works **[confirmed]**.
2. **Sort "Relevance"** is the default. There is no relevance scoring; it's simply the merge order (books, then poems, then blog, then reflections, then videos) **[inferred, code-verified]**.
3. **Sort "Most commented"** sorts on `comment_count`, which is never written, so it's always 0 and does nothing **[wrong] [inferred]**.
4. **Pagination [wrong] [inferred, code-verified]:**
   - `LIMIT 12 OFFSET …` is applied to *each* of the 5 types separately, then the results are merged (`:105-112`).
   - Page 1 of "All" can show up to 60 results.
   - The page count is computed as total ÷ 12, so later pages show uneven batches and the last pages are often empty.

### Journey 6: Visitor searches for nothing that exists ("zzzz") or for HTML
1. "No results found. We couldn't find anything matching…" with suggestions **[confirmed]**. This empty state is one of the better ones on the site.
2. `<b>x</b>` as the query is echoed escaped, so no injection **[confirmed]**.
3. `%` matches everything, because LIKE wildcards aren't escaped. Harmless but odd **[confirmed]**.

## Quality problems independent of the bugs above
- **Responsive:** no horizontal overflow at 768/375 px **[confirmed]**. On a 375 px phone, **38 tap targets are smaller than 32 px** (filter chips, sort select, pagination, links) **[confirmed]**.
- **No search box in the header**, only an icon that doesn't work. Search is effectively hidden.
- **No match highlighting** in result snippets, and no indication *why* an item matched.
- **Snippets come from raw HTML content** (`content as description`). Blog and poem markup is stripped for display, but long posts produce uniform, uninformative excerpts.
- **Reading-time labels** are computed for every result, including videos and books, where they mean nothing.
- **Cost:** every search runs 10 queries (5 counts + 5 fetches), each a full `LIKE '%…%'` scan over text columns including whole blog bodies and poem contents. No index can help that. Cheap today; slow once content grows.

## Root causes
- **[code]**
  - Parameter/placeholder mismatch for multi-word queries.
  - Header icon links without a query.
  - Link to a non-existent `reflection.php`.
  - Book results link to the reader.
  - Per-type pagination.
  - "Relevance" isn't implemented.
- **[schema]**
  - `reflections` table missing.
  - `blog_posts`/`reflections` columns `comment_count, likes, loves, prays` are selected but never created or written.
  - `poems.image_path` and `videos.thumbnail` are referenced; the latter differs from `thumbnail_path` used elsewhere (domain 6).
- **[ux]** Hidden search entry, no highlighting, small tap targets.
- **[infra]** Full-text `LIKE` scans on SQLite on every search.

## What a professional version looks like
- **J1:** The header icon opens an inline search field (or a search page with the field focused). An empty query shows the search page with a field and suggested topics, not a redirect.
- **J2/J3:** Any number of words: each word must match at least one field (AND across words, OR across fields), with one bound parameter per placeholder. Results grouped by type ("Books (2) · Poems (5) · Posts (3)"), each group showing its top 3 with "See all". Matched words are bolded in titles and snippets.
- **J4:** Every result links to its public detail page: book → `book.php?id=`, reflection → the page that actually shows reflections. Never a 404 or the raw reader.
- **J5:** Real pagination over one combined, ordered list, or per-group "See all" pages. Sort options only for fields that hold real data.
- **J6:** Keep the current empty state, and add "Did you mean…" only if cheap.

## Dependencies before this can be fixed
1. Decide what "reflections" are. `reflections.php` reads a `reflections` table, while admin manages reflections as `blog_posts` rows (domain 7). Search can only link correctly once there's one model and one detail page.
2. Remove or create the `comment_count`/`likes`/`loves`/`prays` columns. If production really has them, nothing writes them anyway. If they need to exist, **add them manually** (SQLite `data/site.db`, not phpMyAdmin; see 01).
3. Book detail page (domain 9) must be reliable, since search will link there.

## Blast radius
- **Domain 7 (reflections):** result links and table model.
- **Domain 9 (books):** the link target should be `book.php`.
- **Domain 13 (blog):** the shared non-existent `comment_count` column.
- **Domain 6 (video):** `thumbnail` vs `thumbnail_path` column naming.
- **Domain 20 (layout):** header search icon lives in `includes/header.php`.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 header entry | S | Link to a page with a field, or add an inline field. |
| J2 single word | S | Drop the non-existent columns from the selects (or create them). |
| J3 multi-word | S | Fix parameter building. |
| J4 result links | S | Two link targets. |
| J5 sort/pagination | M | Combined ordering and pagination need restructuring of the merge. |
| J6 empty state | — | Already acceptable. |

## Regression check against AUDIT.md
- `buildSearchWhere()` parameter mismatch on multi-word queries: **Yes**, confirmed in both environments.
- `reflections` table missing [schema]: **Yes** (Env A).
- Columns selected from hardcoded config, values bound, so no injection: **Yes**, still no injection (Journey 6).

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `search_results.php` | COVERED | Journeys 1–6 |
| `books` columns queried | COVERED | Journey 2 (works), Journey 4 (link target) |
| `poems` columns queried | COVERED | Journey 4; Root causes (`image_path`) |
| `blog_posts` columns queried | COVERED | Journey 2 step 2 (`comment_count/likes/loves/prays`) |
| `reflections` table/columns | COVERED | Journey 2 step 1; Journey 4 step 3 |
| `videos` columns queried | COVERED | Journey 4; Root causes (`thumbnail`) |
| `includes/header.php:305` (entry point only) | COVERED | Journey 1 (file reviewed in full in domain 20) |
| `index.php:612`, `404.php:36` (entry points only) | COVERED | Journey 1 (`index.php` reviewed in full in domain 20, `404.php` in domain 21) |

# 02. Bible reader & bible data

> Test method as in `01-reader.md`: Env A = repo-defined schema, Env B = schema inferred from the code plus seed data, real Chromium against `php -S`. The Bible text itself lives in the 8 committed `assets/bible/*.db` files, so verse loading behaves identically in both environments.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `bible_reader.php` | 1816 | Bible reader page: version/book/chapter pickers, parallel view, notes, highlights, bookmarks, themes, sharing, challenge widget (almost all JS) | Y |
| `bible_reader_ajax.php` | 327 | JSON API: notes, reactions, bookmarks, reading sessions, positions, monthly challenge | Y |
| `includes/bible_lookup.php` | 73 | `getBibleText()` plus a public JSON endpoint over the Bible DBs | Y |
| `assets/bible/bible_AMP.db` | 6.2 MB | 31,100 verses, 66 books | Y |
| `assets/bible/bible_ASV.db` | 3.2 MB | **17,216 verses, 20 books** (incomplete) | Y |
| `assets/bible/bible_ESV.db` | 5.5 MB | 31,101 verses, 66 books | Y |
| `assets/bible/bible_KJV.db` | 5.6 MB | 31,102 verses, 66 books | Y |
| `assets/bible/bible_NASB.db` | 5.5 MB | 31,102 verses, 66 books | Y |
| `assets/bible/bible_NIV.db` | 5.4 MB | 31,100 verses, 66 books | Y |
| `assets/bible/bible_NKJV.db` | 5.5 MB | 31,102 verses, 66 books | Y |
| `assets/bible/bible_WEB.db` | 5.6 MB | 31,102 verses, 66 books, **330 verses contain raw `{footnote}` text** | Y |

Nothing else in the repo references `assets/bible/` except `includes/config.php` (`BIBLE_PATH`, never used).

## User journeys

### Journey 1: Visitor opens the Bible reader and reads a chapter
1. `bible_reader.php` loads inside the normal site header and footer. No PHP error in either environment **[confirmed]**. The first chapter shown is John 3 (KJV).
2. Verses arrive from `includes/bible_lookup.php` as JSON and render as `<p>1. text</p>` paragraphs **[confirmed]**. **This is the best-working interactive feature on the site.**
3. Every chapter load throws `Cannot set properties of null (setting 'textContent')` at `bible_reader.php:647`. The code writes to `#notesVerseRef`, an element that doesn't exist. **[quality] [confirmed]**
   - The verses still display, because the throw happens after rendering.
   - The rest of `renderVerse()` is skipped: the "Go to" box (`:648`) never updates to show the current reference.
   - Two more uncaught errors on load (`appendChild` and `classList` of null) **[confirmed]**.
4. Load costs **8 requests (hits)**: page, CSS, JS, logo, manifest, `favicon.ico` (404), lookup, challenge **[confirmed]**. Each chapter change adds about 1–2 more.

### Journey 2: Reader switches translation
1. KJV / NIV / ESV / NASB / NKJV / AMP: correct text **[confirmed]**.
2. **YLT** ("Young's Literal Translation") is offered in both version menus (`bible_reader.php:215`) but there is no `bible_YLT.db`. `includes/bible_lookup.php:14` silently falls back to KJV. **[wrong] [confirmed]** The reader sees KJV text labelled YLT.
3. **ASV**: only 20 Old Testament books exist (Genesis–Psalms/Proverbs, plus Ruth and Esther), and Proverbs stops at chapter 28 of 31. **[wrong] [confirmed]**
   - Choosing ASV while on John 3 shows the literal text `[ASV John 3 not found]`.
   - Choosing Isaiah shows `[ASV Isaiah 1 not found]`.
   - The book menu still lists all 66 books for ASV.
4. **WEB**: verse text shows translator footnotes inline, e.g. *"In the beginning God{After "God," the Hebrew has the two letters "Aleph Tav" …} created…"*, in 330 verses. **[quality] [confirmed]**
5. Parallel view uses the same lookup, so it shows the same three defects side by side **[inferred]**.

### Journey 3: Logged-in reader writes a note on a verse
1. Opens the Notes panel, clicks **Add note**, types, clicks **Post Note**.
   - **[quality] [confirmed]** Until the visitor accepts or declines cookies, the site-wide cookie bar (`includes/footer.php`, `#cookieConsent`) **covers the Post Note button**, so the click hits the banner.
2. After dismissing the banner: `POST bible_reader_ajax.php action=add_note`. The note **is saved** (row confirmed in `bible_notes`).
3. Then, for a public note, `bible_reader_ajax.php:78` calls `sendEmail()` to notify the admin. On SMTP failure the response body is the red "🔥 SMTP Error" HTML block, not JSON.
   - **[wrong] [confirmed]** The page's JSON parse throws (*Unexpected token '<'*), the panel never refreshes, and it still says **"No notes for this book."**
   - The reader assumes it failed and will likely post it again, creating duplicates.
4. Private notes skip the email, so they "work", but see Journey 4.

### Journey 4: Reader marks a note "private"
1. The note is stored with `is_private = 1`.
2. **[wrong] [confirmed] privacy leak:** `get_notes` (`bible_reader_ajax.php:22-28`) selects all notes for the book and chapter with **no `is_private` filter**.
   - Logged in as a different user, I fetched John 3 and got back user 2's private note text *"SECRET PRAYER ABOUT MY MARRIAGE"* along with their username.
   - The page only gives private notes a CSS class (`bible_reader.php:745`). Any logged-in user can read them in the browser's network tab.

### Journey 5: Reader highlights a verse
1. Picking verse 5 from the verse dropdown, then clicking **Highlight**: an `alert("Please select a verse to highlight.")`. **[quality] [confirmed]**
   - The button actually requires a mouse text-selection (`toggleHighlight()`, about `:1450`). Nothing on screen says so.
2. With a text selection, it saves `book-chapter-verse` to `localStorage` only **[confirmed: no request is made]**. Two problems:
   - **[wrong] [inferred]** The key uses the *dropdown's* verse, not the verse that was selected, so selecting verse 12 while the dropdown says 16 highlights verse 16.
   - **[wrong] [inferred]** Highlights live in one browser only and are lost on another device or after clearing data.
3. Separately the page JS sends `action=add_highlight` to the server in two places. `bible_reader_ajax.php` has **no `add_highlight` handler**, so it returns "Invalid action". **[silent] [inferred]**

### Journey 6: Guest tries to use notes, bookmarks or reactions
1. Notes and bookmark buttons are shown to guests.
2. Posting a note or bookmarking shows a browser `alert("Error: Invalid action or missing authentication.")`. **[quality] [confirmed]** That's a technical message, with no sign-in link.

### Journey 7: Reader shares a verse
1. Share buttons build `bible_reader.php?book=…&chapter=…&verse=…&share=1` (`:1323-1350`) and open Facebook, WhatsApp and similar.
2. **[wrong] [inferred, code-verified]** Neither the PHP nor the JS reads `book`, `chapter`, `verse` or `share` from the URL. There's no `$_GET` and no `location.search`/`URLSearchParams` read anywhere in `bible_reader.php`, so a shared link always opens the reader's default or saved passage, never the one shared.
3. Shares carry no per-verse Open Graph tags, so the preview is the generic site card.

### Journey 8: Reader joins the monthly challenge / reading session tracking
1. `get_monthly_challenge` returns JSON **[confirmed]** (Env B). Env A: the `bible_monthly_challenges`, `bible_sessions` and `bible_reading_positions` tables don't exist, so every tracking call errors silently in the background **[inferred]**.
2. `start_session` is called on every load and `end_session` via `sendBeacon`, so 2 hits and 2 SQLite writes per visit **[confirmed: start_session POST seen]**.

## Quality problems independent of the bugs above
- **Responsive:** no horizontal overflow at 375 px or 768 px **[confirmed]**. This is one of the better pages on mobile.
- **`alert()` / `prompt()` for all feedback** (highlight hint, copy success, errors). They block the page, look dated, and on mobile interrupt reading.
- **No loading or error state of its own.** "Loading..." text is swapped for raw bracketed error strings like `[ASV Isaiah 1 not found]`.
- **Translations offered that don't exist (YLT) or are incomplete (ASV).** The UI gives no indication.
- **Licensing exposure.** NIV, ESV, NASB, NKJV and AMP are copyrighted translations. The full databases are served as **directly downloadable files** at `/assets/bible/*.db` (nothing blocks them), about 41 MB in total. That is both a bandwidth cost and a licensing risk you should check.
- **Notes are public by default** and named by username, but the form doesn't say who will see them.
- **Theme state is split** across `theme`, `angelwrites_theme` and 4 more `bible*` `localStorage` keys, so the Bible reader's theme can disagree with the site's dark-mode setting **[confirmed: keys observed]**.

## Root causes
- **[code]**
  - Missing `#notesVerseRef`.
  - YLT in the menus with no DB, plus a silent KJV fallback.
  - No `is_private` filter in `get_notes`.
  - Highlight keyed on the dropdown verse.
  - `add_highlight` sent with no handler.
  - Email sent inside a JSON endpoint.
- **[data]** ASV DB is incomplete. WEB DB contains unstripped footnotes.
- **[schema]** All Bible user tables (`bible_notes`, `bible_reactions`, `bible_bookmarks`, `bible_reading_positions`, `bible_reading_history`, `bible_monthly_challenges`, `bible_sessions`) are not created by the repo.
- **[ux]** Text-selection-only highlighting with no hint. `alert()`-based feedback. Cookie bar overlapping a panel action. Guests offered member features.
- **[infra]**
  - SMTP failure turns a successful save into a "failed" UI.
  - Large public DB downloads count against bandwidth and hits.
  - Session start/end writes on every visit hit SQLite.

## What a professional version looks like
- **J1:** Chapter loads with a skeleton placeholder, then text. The current reference ("John 3 · KJV") is always shown and kept in the URL (`?v=KJV&b=John&c=3`), so refresh and share land on the same passage.
- **J2:** The version menu lists only translations that exist. A translation with gaps greys out missing books ("Not available in ASV") instead of offering them. Footnotes are either stripped at import or rendered as small superscript markers with tap-to-reveal.
- **J3:**
  - Note saves return JSON immediately.
  - Any admin notification is best-effort and can never change the response.
  - The new note appears at the top of the list at once, with "Saved" feedback.
  - On failure the text stays in the box with an inline error.
- **J4:** Private notes are filtered on the server (`is_private = 0 OR user_id = me`). The form shows "Visible to: everyone / only me".
- **J5:** Tap a verse number to select it. A small bar offers highlight colours, note, copy, share. Highlights are saved server-side for members (and localStorage for guests, with a "sign in to keep these" prompt), keyed on the verse actually tapped.
- **J6:** Member-only controls are either hidden for guests or show "Sign in to save notes" with a link.
- **J7:** Shared links open the exact verse, scrolled into view and briefly highlighted.

## Dependencies before this can be fixed
1. Confirm the production Bible user tables exist (same SQLite caveat as domain 1: this is `data/site.db`, not phpMyAdmin/MySQL).
2. Decide which translations you're licensed to serve. Then either complete the ASV file or remove ASV, and remove YLT or add its file.
3. Email strategy (domain 16) so note-posting can't be broken by SMTP.
4. Decide whether `assets/bible/` should be web-readable (add a deny rule like `data/.htaccess` if not; the PHP lookup reads the files from disk and doesn't need HTTP access).

## Blast radius
- **Domain 16 (email):** the public-note admin email is the cause of Journey 3's failure.
- **Domain 20 (layout/infra):** the cookie banner from `includes/footer.php` overlaps the notes panel. Dark-mode keys collide with the site theme. `favicon.ico` 404 on every load.
- **Domain 15 (comments/reactions):** Bible note reactions are a fifth reaction system (`bible_reactions`) not covered there.
- **Domain 19 (analytics):** Bible sessions are written but never shown in any admin view (`bible_sessions` isn't read anywhere except `bible_reader_ajax.php`).

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 read | S | One missing element and two null guards. |
| J2 translations | S–M | Menu change is S. Fixing the ASV/WEB data (re-import or strip footnotes) is M. |
| J3 note save | S | Make the email non-fatal or remove it; refresh list from the response. |
| J4 private notes | S | One `WHERE` clause. |
| J5 highlights | M | Needs a server endpoint and table, a tap-to-select UI, and correct keying. |
| J6 guest states | S | Conditional rendering. |
| J7 share links | S | Read URL params on load. |
| J8 tracking | S | Exists already, once the tables are confirmed. |

## Regression check against AUDIT.md
- `bible_reader_ajax.php:78` `sendEmail()` → `die()`: **Yes**, it now shows up as a broken note UI (Journey 3).
- Bible DBs downloadable (41 MB, copyrighted translations): **Yes.**
- Bible user tables not created by the repo [schema]: **Yes.**
- AUDIT.md didn't report any Bible-reader page fatal, and there still isn't one.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `bible_reader.php` | COVERED | Journeys 1–7; Quality |
| `bible_reader_ajax.php` | COVERED | Journeys 3, 4, 5 (missing handler), 6, 8 |
| `includes/bible_lookup.php` | COVERED | Journeys 1, 2 (silent KJV fallback at `:14`) |
| `assets/bible/bible_AMP.db` | COVERED | Journey 2; Quality (licensing) |
| `assets/bible/bible_ASV.db` | COVERED | Journey 2 (20 books, Proverbs truncated) |
| `assets/bible/bible_ESV.db` | COVERED | Journey 2; Quality (licensing) |
| `assets/bible/bible_KJV.db` | COVERED | Journeys 1–2 |
| `assets/bible/bible_NASB.db` | COVERED | Journey 2; Quality (licensing) |
| `assets/bible/bible_NIV.db` | COVERED | Journey 2; Quality (licensing) |
| `assets/bible/bible_NKJV.db` | COVERED | Journey 2; Quality (licensing) |
| `assets/bible/bible_WEB.db` | COVERED | Journey 2 (330 footnoted verses) |

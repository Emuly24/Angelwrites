# Bible reader rebuild: research and plan (wish S)

Status: **plan only, no feature code.** Written 13 November 2026 against `main` at `66c58af`.
Nothing here is built until the owner approves it.

How to read the confidence labels used below:

- **Checked** means I verified it in this repository or measured it.
- **Reported** means a web source says so; the source is listed in section K. I could not open
  ebible.org from this session (the host is outside the session's allowed network), so licence
  wording for texts hosted there comes from search results, not from reading the licence page.
  Every "Reported" licence must be re-read on the publisher's own page before any text is bundled.
- **Unknown** means I could not find out. I say so rather than guess.

---

## 0. Two things the owner needs to know before anything else

### 0.1 The site is distributing five copyrighted translations today

**Checked.** `assets/bible/` holds eight databases. Five of them are modern commercial
translations that are under copyright:

| File | Translation | Rights holder |
|---|---|---|
| `bible_NIV.db` | New International Version | Biblica |
| `bible_ESV.db` | English Standard Version | Crossway |
| `bible_NASB.db` | New American Standard Bible | The Lockman Foundation |
| `bible_NKJV.db` | New King James Version | Thomas Nelson (HarperCollins) |
| `bible_AMP.db` | Amplified Bible | The Lockman Foundation |

Each file contains the complete text (31,100 to 31,102 verses). Publishers' standard "gratis use"
terms allow quoting a few hundred verses with a credit line; none of them allow serving the whole
Bible from your own database without a signed licence. I found no licence, credit line or
permission record in the repository.

This is the one item in this plan I would act on regardless of the rebuild: **either produce the
licences, or remove these five files.** Recommended default: remove them in Phase B1 and keep
KJV, WEB and (once completed) ASV, which are public domain. See open question 1.

The other three are fine: KJV (public domain everywhere except a Crown patent that applies only
inside the United Kingdom), WEB (World English Bible, dedicated to the public domain), ASV (1901,
public domain).

### 0.2 Most of the listed bugs are already fixed on `main`

The brief describes several fixes as being "on a branch". They have since been merged. Details in
section A. The rebuild therefore starts from a reader that works, which lowers the risk: the
rebuild can be incremental instead of a big-bang replacement.

---

## A. Current state

**Checked.** `bible_reader.php` (1,970 lines), `bible_reader_ajax.php` (396 lines, 16 actions),
`includes/bible_lookup.php` (78 lines). Text lives in eight per-version SQLite files, each with
one table `bible(id, book, chapter, verse, text)` and an index on `(book, chapter, verse)`.
Member data lives in the main database: `bible_notes`, `bible_reactions`, `bible_bookmarks`,
`bible_reading_positions`, `bible_reading_history`, `bible_sessions`,
`bible_monthly_challenges`, plus a highlights table.

| Bug | What it was | State on `main` today |
|---|---|---|
| F22 | Private notes returned to other members | **Fixed** (`bible_reader_ajax.php`: private notes only to their author) |
| F23 | `#notesVerseRef` TypeError on every chapter load | **Fixed** (null-guarded) |
| F24 | Choosing YLT silently showed KJV | **Fixed** (unknown version now reports "not available"). YLT still has no data file. |
| F25 | ASV missing 46 books | **Still open.** `bible_ASV.db` holds 20 books, 17,216 verses (Genesis to Proverbs, not all of them). The page explains the gap but the data is incomplete. |
| F27 | Highlight keyed on the dropdown verse, not the selection | **Fixed** (verse taken from the selection) |
| F28 | `add_highlight` had no handler | **Fixed** (handler and per-verse storage added) |
| F29 | Share link ignored book/chapter/verse | **Fixed** (reader opens `?book=&chapter=&verse=`) |
| F30 | 41 MB of Bible databases downloadable over HTTP | **Mitigated, not verified live.** `assets/bible/.htaccess` denies all access. The files are still inside the web root, so the protection depends on Apache honouring that file. See section C.3. |

What the rebuild still has to fix, beyond the list above:

1. **Licensing** (section 0.1).
2. **ASV is incomplete** (F25). Replace the file with a complete public-domain ASV, or drop ASV.
3. **Gamification inside the reader.** `bible_monthly_challenges`, `get_monthly_challenge` and
   `update_challenge_progress` are exactly what wish C removes.
4. **The reader is one 1,970-line file** mixing markup, styles and script, which is why bugs like
   F23 and F27 happened. The rebuild splits it (section H).
5. **No focus mode, no editor picker.** The two product reasons for the Bible feature do not
   exist yet.
6. **Guests** can read but every personal feature silently requires a login.

---

## B. Malawian language versions

Summary first, then one entry per language.

| Language | Usable today without permission? | Best candidate | What is needed |
|---|---|---|---|
| Chichewa (Nyanja) | **Probably yes, one version** | Biblica *Mawu a Mulungu mu Chichewa Chalero* (CC BY-SA 4.0, reported) | Re-read the licence on the publisher's page; meet attribution and share-alike terms |
| Chichewa, *Buku Lopatulika* | **No** (modern editions); the 1922 text is unclear | — | Written permission from the Bible Society of Malawi |
| Chichewa, *Buku Loyera* | **No** | — | Written permission from the Bible Society of Malawi |
| Tumbuka | **No** | — | Permission from the Bible Society of Malawi |
| Yao (Chiyao) | **Possibly, New Testament only** | *Lilangano lya Sambano* (CC BY 4.0, reported, with a conflicting notice) | Confirm the licence and that the dialect is the one read in Malawi |
| Lomwe (Elhomwe) | **No** | — | Permission from the Bible Society of Malawi |
| Tonga (Chitonga, Malawi) | **No** | — | Permission from the Bible Society of Malawi |
| Sena | **Not the Malawian text** | Mozambican Sena NT is CC BY-ND (reported) | Different country's translation; ask a Sena reader before using |
| Nkhonde / Ngonde | **No** | — | "All rights reserved" (reported) |
| Lambya | **No** | — | Rights holder not confirmed |

### B.1 Chichewa (Nyanja)

Three translations matter.

**Buku Lopatulika** ("the Holy Book"). The standard Protestant Chichewa Bible and the one most
readers in Malawi mean by "the Bible".
- History (reported): first published complete in 1922, printed by the National Bible Society of
  Scotland; revised in 1966; orthography revised in 2014; current edition published by the Bible
  Society of Malawi as *Buku Lopatulika 2018* ("2018 version of 1922 version").
- Copyright holder: **Bible Society of Malawi** for the revised editions in print and in apps.
- Licence status: **all rights reserved. Permission needed.**
- Is the 1922 text public domain? **Unknown, and I would not rely on it.** A 1922 text is old
  enough that it may be out of copyright in many countries, but: (a) I could not confirm the
  translators' death dates, which is what the term is counted from; (b) Malawi's Copyright Act
  2016 gives life plus 50 years, but sources report that the Act's copyright part was not
  commenced with the rest, so which term currently applies needs a Malawian lawyer; (c) every
  digital copy I could find is the *revised* text, which carries its own newer copyright. So
  there is no clean, provably public-domain digital file to use.
- Verdict: **not usable without written permission.**

**Buku Loyera** ("the Sacred Book"). The 1998 common-language translation, with a Catholic
edition that includes the deuterocanonical books.
- Copyright holder: Bible Society of Malawi. (From my own background knowledge; the web search
  did not return a rights statement for it. Treat the holder as "to be confirmed in writing".)
- Verdict: **not usable without written permission.**

**Mawu a Mulungu mu Chichewa Chalero** ("God's Word in Contemporary Chichewa"), by Biblica.
- Status (reported): complete Bible; copyright 2002, 2016 Biblica, Inc.; published in Biblica's
  "Open" series under **Creative Commons Attribution-ShareAlike 4.0 (CC BY-SA 4.0)**. The
  reported terms: you may copy and redistribute the unmodified work as long as the Biblica
  trademark and attribution stay intact; anything you derive from it must carry the same licence.
- A conflicting notice exists: BibleGateway's page for the same translation says rights reserved
  worldwide and does not mention Creative Commons. That is normal when a publisher licenses
  different channels differently, but it means the licence must be read **on the file we would
  actually download**, and a copy of that licence page saved with the import record.
- What CC BY-SA would require of AngelWrites: show the credit line and licence next to the text;
  do not alter the text; and when a verse is quoted inside a poem or blog post, show the credit
  with the quotation. Share-alike applies to adaptations of the Bible text itself, not to the
  rest of a post that merely quotes it, but this is the point I would have a lawyer confirm
  before Angella's own books quote it at length.
- Verdict: **the one Malawian-language Bible that looks usable now.** Recommended as the first
  local-language version, after the licence is re-read and recorded.

### B.2 Tumbuka (Chitumbuka)

- Translations (reported): a first Tumbuka Bible in the 1950s, revised 1989; a new
  common-language Bible, *Mazgu gha Chiuta, Mukang'anamuliro Kaphya*, launched by the Bible
  Society of Malawi in 2018, with a Catholic edition.
- Copyright holder (reported): Bible Society of Malawi ("Copyright © Bible Society of Malawi,
  2014" on the listing found).
- Open-licensed version: none found.
- Verdict: **not usable without permission.**

### B.3 Yao (Chiyao)

- Translations (reported): a full Yao Bible by the Bible Society of Malawi was reported as being
  printed after a 15-year project; and a New Testament, *Lilangano lya Sambano*, © 2018 The Word
  for the World International.
- Licence (reported): the New Testament is listed on ebible.org under **Creative Commons
  Attribution 4.0**, "may be shared and redistributed provided that you include the copyright and
  source information". A mirror of the same text says "for other uses please contact the
  respective copyright owners". The two notices conflict.
- Two further cautions: it is the **New Testament only**, and Yao is spoken in Malawi, Mozambique
  and Tanzania with real differences, so a Yao speaker should confirm it reads naturally for
  Malawian readers.
- Verdict: **possibly usable (NT only) after the licence is confirmed in writing or re-read at
  source.** The Bible Society's full Yao Bible needs permission.

### B.4 Lomwe (Elhomwe, the Malawian variety)

- Translation (reported): *The Bible in Elhomwe*, "© The Bible Society of Malawi, 2014"; a full
  Bible has since been launched.
- Open-licensed version: none found.
- Verdict: **not usable without permission.**

### B.5 Tonga (Chitonga of Malawi)

- Translation (reported): a Tonga Bible, 1986 edition, listed on YouVersion; the Bible Society of
  Malawi names Tonga among the languages that have a full Bible.
- Copyright notice: not found. In the absence of a notice the safe assumption is that the Bible
  Society of Malawi holds the rights.
- Caution for whoever imports data later: Malawian Tonga (ISO code `tog`) is a different language
  from Zambian Tonga (`toi`) and from the Tongan of the Pacific. Data sets are easy to mix up.
- Verdict: **not usable without permission.**

### B.6 Others found

- **Sena (Chisena).** The Bible Society of Malawi lists Sena among languages with a Bible. The
  openly licensed Sena text found is the *Mozambican* Sena New Testament (© 1983 Wycliffe,
  CC BY-ND 4.0, reported). It is a different translation for a different country. **Do not treat
  it as the Malawian Sena Bible.** Usable only if a Malawian Sena reader says it serves.
- **Nkhonde / Ngonde.** A Nyakyusa-Ngonde New Testament (Wycliffe, 2019) is reported as "All
  rights reserved". **Not usable.**
- **Lambya (Chilambya).** New Testament *Ulufingo Ulupya* launched 2016, full Bible since.
  Rights holder not confirmed. **Not usable without permission.**

### B.7 The permission workflow

1. The owner (not a developer) writes to the Bible Society of Malawi
   (biblesociety-malawi.org), asking for a non-commercial digital licence to display
   *Buku Lopatulika* first, then *Buku Loyera*, Tumbuka, Yao, Lomwe and Tonga, on
   angelwrites.gt.tc. The letter should state: non-commercial ministry site; read-only display
   with full credit; no downloads, no API; approximate audience; that texts are stored outside
   the public web folder.
2. Ask for the text in a standard format (USFM or USX) so the import is exact.
3. Expect questions about a fee, a reporting duty, and a requirement to display their copyright
   line. Bible Societies commonly license through the Digital Bible Library; a small site may be
   pointed there.
4. Until a signed permission exists, **nothing from the Bible Society is downloaded or bundled.**
   The reader ships with public-domain English plus, if the licence check passes, Biblica's open
   Chichewa.
5. Each granted permission is stored as a file in `db/licences/` (excluded from deploy) and
   summarised in the `versions` registry (section C.2) so the credit line shows automatically.

A draft of the request letter is a small, free next step. See open question 2.

---

## C. Storage budget

### C.1 What the host allows

I cannot log in to the hosting account, so these are **reported**, not checked. The owner can
confirm all of them in two minutes in the InfinityFree control panel ("Account limits"):

| Limit | Reported value | Where it bites |
|---|---|---|
| Disk space | 5 GB (staff say "5000 MB") | Not the tight one |
| Inodes (files + folders) | 30,019 | Not tight today: the repository deploys about 460 files |
| Largest single file | 10 MB (the code base already assumes this; I could not confirm it from the host's documentation) | **This is the tight one** |
| Hits per day | 50,000 | Drives the "no polling" and static-file rules |

**Checked:** the repository is 47 MB, of which `assets/bible/` is 42 MB in eight files of 3.2 to
6.2 MB each. Every existing file is under 10 MB.

### C.2 One file per version, or one file for all?

| | Per-version files (today) | One file with a `versions` table |
|---|---|---|
| Fits the 10 MB file limit | Yes, each is 3 to 6 MB | **No.** Three versions already exceed it |
| Add or remove a version | Upload or delete one file | Rewrite a large file over FTP |
| A failed FTP upload | Damages one version | Damages the whole Bible |
| Deploy time | Only changed files re-upload | Any change re-uploads everything |
| Locking | Text files are opened read-only; member writes go to `site.db` | Same, if kept read-only |
| Parallel view (two versions) | Open two files, or `ATTACH` | One query |
| Search across versions | One query per file | One query |

**Recommendation: keep one SQLite file per version.** The 10 MB limit decides it; the other rows
agree. Add a small **registry**, a PHP array or a `bible_versions` table in `site.db`, holding
for each version: code, name, language, file name, rights holder, licence, credit line, whether
it has Old and New Testaments, whether it is enabled. `includes/bible_lookup.php` reads the
registry instead of its hard-coded map, and the credit line is shown wherever a verse is shown.

Size per new version: a complete Bible in this format is about 5.5 MB. Adding a full-text search
index (SQLite FTS5) roughly doubles it, which would break the 10 MB limit, so **search does not
use FTS5 inside the text file** (see section F, "Search").

### C.3 Where the files live (F30)

Today: `assets/bible/*.db`, inside the web root, protected by a deny-all `.htaccess`.

The better location is outside the web root. On InfinityFree the account's FTP root has `htdocs/`
as the only web-served folder, so a sibling folder such as `/bible-data/` cannot be requested by
URL at all, whatever the web server's configuration.

Constraints: the deploy action uploads into `htdocs/`. Moving the files out means either a second
deploy step that targets the sibling folder, or a one-time manual FTP upload (the texts change
almost never). `open_basedir` on the host may also restrict PHP to `htdocs/`; that must be tested
before relying on it.

**Recommendation, in two steps:**

1. **Now (Phase B1):** keep the deny rule, and prove it works: request
   `https://angelwrites.gt.tc/assets/bible/bible_KJV.db` and confirm a 403. Add that request to
   the release checklist, so a lost or ignored `.htaccess` is noticed at once.
2. **Phase B2:** test whether PHP can read a folder beside `htdocs/`. If it can, move the files
   there, read them by absolute path from one config constant, and upload them by hand once.
   If `open_basedir` forbids it, stay with the deny rule and record that decision.

Either way, the files are **read by PHP and never linked**, and `data/` stays as it is for
member data.

### C.4 A cap on versions

**Recommendation: at most 8 enabled versions, about 45 MB.** Proposed line-up, in order:

1. KJV (English, public domain) — keep
2. WEB (English, public domain) — keep
3. ASV (English, public domain) — replace the incomplete file with a complete one, or drop
4. Biblica open Chichewa — add once the licence is re-read
5. Buku Lopatulika — add when permission arrives
6. to 8. Reserved for Tumbuka, Yao, Lomwe or Tonga as permissions arrive

Removing the five unlicensed English files frees 28 MB, so the total stays about where it is
today even with four Malawian versions added.

---

## D. Embed-in-editor (the writing workflow)

Angella is writing a poem, blog post or reflection. She wants a verse in it without leaving the
editor.

### D.1 The picker

- A button in the editor toolbar, "Add a verse" (book icon).
- It opens a panel **over** the editor: a side sheet on wide screens, a full-height sheet on
  phones. The page does not navigate and the draft is untouched.
- Two ways in:
  - **By reference.** Type `John 3:16`, `Jn 3 16-18`, `Masalimo 23` (book names are matched in
    the selected version's language and in English).
  - **By words.** Type `still waters`; results list verse, reference and a highlighted snippet.
- Choose a version (the registry decides what is offered; the last one used is remembered).
- Tap one verse, or tap a first and a last verse for a range inside one chapter. A range across
  chapters is done as two inserts in v1.
- A live preview shows exactly what will be inserted.
- "Insert" places it at the cursor and closes the panel. Escape or the close button cancels.
  Focus returns to the editor at the insertion point.
- A cap protects licensed texts: at most 30 verses per insert, and a counter of verses quoted
  from each licensed version in this document, with a gentle notice at the publisher's limit.

### D.2 Staying independent of the editor (the hard requirement)

The picker must not lock the editor decision (wish N), so it is **not written as an editor
plugin.** It is a stand-alone script, `assets/js/aw-verse-picker.js`, with one function:

```js
AWVersePicker.open({ version: 'KJV' }).then(function (result) {
    // result is null if cancelled, otherwise:
    // { html, text, reference, version, book, chapter, verseStart, verseEnd }
});
```

It knows nothing about any editor. Each editor gets a **small adapter** whose only job is to add
the toolbar button and insert `result.html` at the cursor:

| Editor | Adapter (about 15 lines each) |
|---|---|
| TinyMCE (today) | `editor.ui.registry.addButton(...)`, then `editor.insertContent(html)` |
| Quill | toolbar handler, then `quill.clipboard.dangerouslyPasteHTML(index, html)` plus a registered blot so the block survives |
| Lexical | a command that builds a quote node from the structured `result` fields |
| Plain `<textarea>` (comments, community posts) | inserts `result.text` at the cursor: `"…" (John 3:16, KJV)` |

Because the adapter can use either `result.html` or the structured fields, an editor that will
not accept raw HTML (Lexical) is still served. Changing editors means rewriting one adapter.

The data comes from one endpoint, `bible_api.php` (read-only, GET, cacheable):
`?action=passage&version=&book=&chapter=&from=&to=`, `?action=search&version=&q=`,
`?action=books&version=`. The same endpoint feeds the reader and focus mode, so there is one
code path for Bible text on the server.

### D.3 What gets inserted

```html
<blockquote class="aw-verse" data-version="KJV" data-ref="John 3:16">
  <p class="aw-verse-text">For God so loved the world, …</p>
  <footer class="aw-verse-ref"><cite>John 3:16</cite> <span class="aw-verse-version">KJV</span></footer>
</blockquote>
```

- For a range, each verse is its own `<span class="aw-verse-line"><sup>16</sup> …</span>` inside
  the paragraph, so verse numbers can be shown or hidden by CSS.
- For a licensed version, the registry's credit line is added inside the footer
  (`<span class="aw-verse-credit">…</span>`).
- CSS lives in the design system (`aw-design.css`): rose left rule, Playfair italic text, small
  caps reference. The same classes are styled in the email template with inline styles, so a
  verse in a newsletter looks right in Gmail.

**Safety.** The HTML is built **on the server** from database text passed through
`htmlspecialchars()`; the client never assembles it from strings. `data-*` values are validated
against the registry and the canon list. And because a draft can be edited by hand, the
server-side sanitiser that wish N requires (HTMLPurifier or equivalent) has an allow-list entry
for exactly this shape: `blockquote.aw-verse`, `p`, `footer`, `cite`, `span`, `sup`, the two
`data-` attributes, and nothing that can carry script. An inserted verse cannot carry a script,
and a tampered one is cleaned on save.

---

## E. Focus mode (personal quiet time)

The most distraction-free page on the site. Open to everyone, signed in or not.

**Route:** `bible.php?mode=focus&v=KJV&b=Psalms&c=23` (and a short link, `/quiet`, if the host's
rewrite rules allow it). The ordinary reader gets a "Quiet mode" button that switches without
losing the place.

**What is on the page**
- The passage, and nothing else until you ask.
- One thin bar that fades after three seconds of reading and returns on tap or key press:
  reference (opens the book/chapter chooser), version, text settings, close.
- No site header, footer, sidebars, floating buttons, newsletter prompts, join bars, notification
  badge or toasts. The cookie notice, if consent is still needed, is a single line at the very
  bottom that cannot cover a control and disappears once answered.
- No analytics pings while reading; the only requests are the passage itself and, for members, a
  position save when the chapter changes.

**Reading comfort**
- Text size (6 steps), line height (3), margins (3), serif or sans, and optional verse numbers.
- Themes: light (fantasy), sepia (vanilla), dark. Brand colours only.
- Settings are remembered: in the account for members (`reader_preferences`, which already
  exists), in `localStorage` for guests.

**Moving around**
- Keyboard: left/right for previous/next chapter, `g` to jump to a reference, `+`/`-` for size,
  `t` for theme, `Esc` to leave. Arrow keys never trap focus.
- Touch: swipe left/right for chapters; tap the edges as an alternative. Swipe is disabled while
  text is selected.
- A "jump to verse" field accepts `23:4` or `John 3:16`.

**Working with a verse**
- Tap a verse (or select text) to open a small menu: Highlight (four brand tints), Note, Copy,
  Share. For a guest, Highlight and Note explain in one line that signing in keeps them, and
  offer to continue without. No wall.
- Share produces a URL that reopens that passage in that version, and uses the device's share
  sheet where available.

**Remembering the place**
- Members: saved to `bible_reading_positions` on chapter change (one small request).
- Guests: saved on the device.
- "Continue where you left off" appears once, at the top, on the next visit.

**Accessibility and motion**
- Real headings and landmarks; each verse is addressable; the fading bar never hides from a
  keyboard or screen-reader user (it stays in the tab order and reappears on focus).
- With `prefers-reduced-motion`, nothing fades or slides; the bar is simply present.
- Contrast at WCAG 2.2 AA in all three themes; targets at least 44 px.

**Works offline for what was read.** The current chapter and the next are cached by the service
worker, so a dropped connection does not blank the page. (The service worker needs the careful
treatment described in PROGRAM_PLAN.md; if that work is not done yet, this point waits.)

---

## F. Rebuilt feature set

| Feature | Decision | Why |
|---|---|---|
| Version selection | **Rebuild** | Driven by the registry; only licensed, complete versions appear; each shows its credit line |
| Book / chapter / verse navigation | **Rebuild** | One chooser component shared by reader, focus mode and picker; book names per language |
| Parallel view (two versions) | **Keep**, rebuild lightly | Useful for English beside Chichewa; side by side on wide screens, stacked on phones |
| Search within a version | **Rebuild** | Reference parser first; then word search with `LIKE` on one version, capped at 50 results, with a minimum of 3 letters. No FTS5 (doubles file size past the 10 MB limit). Revisit if the host limit changes |
| Notes | **Keep** | F22 fix carried over and covered by a test: private notes go only to their author |
| Public notes with reactions | **Keep, simplified** | Reactions stay, but no counts-as-score and no ranking (wish C) |
| Bookmarks | **Keep** | Move into the universal save table when wish F lands, so a saved verse appears in the Library |
| Highlights | **Keep** | F27/F28 fixes carried over; four brand tints instead of arbitrary colours |
| Reading position | **Keep** | Members in the account, guests on the device |
| Share | **Rebuild** | One URL format that reopens the passage (F29), share sheet where available, copy otherwise |
| Insert into editor | **New** | Product reason 1 (section D) |
| Focus mode | **New** | Product reason 2 (section E) |
| Cross-references | **Defer** | Feasible: the Treasury of Scripture Knowledge is public domain, about 4 to 6 MB as its own file. Phase B4, only if wanted |
| Audio (read aloud) | **Keep, as an option** | Reuse the browser's `speechSynthesis`, as elsewhere on the site. English voices are fine; **there is usually no Chichewa voice on a phone**, so the button is hidden when no voice matches the version's language. Recorded audio Bibles are a licensing question of their own and are out of scope |
| Reading plans | **Rebuild, small** | A plan is a list of passages; progress is ticked by hand. No streaks, no scheduled emails (no cron). An optional "today's reading" line on the dashboard when the member next visits |
| Monthly challenges | **Drop** | Gamification (wish C) |
| Reading sessions / time tracking | **Drop** | Collects reading time nobody uses; fewer writes |
| YLT | **Drop from the menu**, or add a real file | It has never had data (F24). Young's Literal is public domain if someone wants it later |
| Verse of the day | **Optional, new** | Chosen from a fixed list by date on the server; no cron needed. Feeds the home page and focus mode start screen |

---

## G. Dependencies

| This plan needs | From | Why | If it is late |
|---|---|---|---|
| The editor decision | Wish N | The adapter in D.2 is written for the chosen editor | Ship the TinyMCE adapter now; the picker itself does not change |
| The HTML sanitiser | Wish N | D.3 relies on an allow-list entry for `blockquote.aw-verse` | The picker can ship for admins only (trusted authors) until the sanitiser exists; it must not be offered in member-facing editors before then |
| Notifications that actually get created | F114 | Only if sharing a verse *to a member* should notify them | Share by link only; no dependency |
| The upload helper | Wish R (PR #34) | Only if audio reflections on a verse are added | Not in v1 |
| Storage budget | PROGRAM_PLAN.md, Phase 0(c) | The 8-version, 45 MB cap must be agreed there | Stay at three public-domain versions |
| Design system components | PROGRAM_PLAN.md, Phase 0(a) | Chooser, sheet, menu and theme tokens | Build them here first and donate them to the system |
| Universal save table | Wish F | Bookmarks appear in the Library | Keep `bible_bookmarks` until then |
| Service worker rework | Wish G v2 | Offline chapter cache | Online only |
| Licences | Owner | Any non-public-domain version | Public-domain English only |

Nothing in v1 (Phases B1 and B2) is blocked by anything except the owner's answers.

---

## H. Phased delivery

### Phase B1: clean foundation (about 1 week)

- Registry of versions with licence and credit; `bible_lookup.php` reads it.
- Remove the five unlicensed files (or record their licences); replace or drop the partial ASV.
- One read-only endpoint, `bible_api.php` (passage, books, search).
- Prove the deny rule: direct requests for `.db` files return 403.
- Remove monthly challenges and session tracking from the reader.

Exit criteria: only licensed, complete versions are offered; every displayed verse shows its
version; a direct request for any Bible file is refused; existing notes, highlights and bookmarks
still appear; no script errors on the reader.

iPad tests: open the reader, change version and chapter; a private note is invisible from a
second account; open `…/assets/bible/bible_KJV.db` in Safari and get "Forbidden"; a shared link
reopens the same verse.

Rollback: revert the PR. The removed files are still in git history and on the server until the
next deploy deletes them; member data is untouched because no table is altered.

### Phase B2: focus mode (about 1.5 to 2 weeks)

- `bible.php?mode=focus` as in section E, for guests and members.
- Shared chooser, verse menu, themes and text settings.
- Position and preferences saved (account or device).
- Test reading files from outside `htdocs/`; move them if the host allows.

Exit criteria: a guest can open a link, read, change size and theme, move chapter by key and by
swipe, and return later to the same place; nothing but the passage and the fading bar is on
screen; passes keyboard-only and reduced-motion checks; at most 2 requests per chapter change.

iPad tests: open as a guest in Safari, rotate the device, swipe chapters, set sepia and large
text, close the tab, reopen and continue; turn on Reduce Motion and confirm nothing animates;
select a verse and share it to Notes, then open the link.

Rollback: remove the route; the ordinary reader is unaffected.

### Phase B3: verse picker in the editor (about 1.5 weeks)

- `aw-verse-picker.js` and the TinyMCE adapter in the blog, reflection, poem and newsletter
  editors (admin only at first).
- Server-built insert HTML, design-system styling, email-safe styling.
- Plain-text adapter for comment boxes once the sanitiser question is settled.

Exit criteria: from each admin editor, a verse and a range can be found by reference and by
words, previewed and inserted without leaving the page or losing the draft; the published post
shows the styled quote with reference and version; a newsletter containing a verse renders
correctly in Gmail; hand-edited malicious markup in a verse block is neutralised on save.

iPad tests: write a reflection, insert John 3:16 by typing the reference, insert Psalm 23:1-3 by
searching "shepherd", publish and view; send a test newsletter containing a verse to Gmail on the
iPad.

Rollback: remove the toolbar button (one line per editor). Inserted quotes are plain HTML and
stay valid.

### Phase B4: local languages and extras (as permissions arrive)

- Biblica open Chichewa after the licence check; then each Bible Society version as permission is
  granted.
- Book names and reference parsing in Chichewa.
- Optional: cross-references, reading plans, verse of the day, offline chapters.

Exit criteria per version: licence on file, credit line showing, complete canon, spot-checked by
a native reader (20 verses across both Testaments), file under 10 MB.

Rollback: disable the version in the registry (one flag); delete the file.

**v1 is B1 + B2 + B3**: both product reasons, on public-domain English, in roughly 4 to 5 weeks
of focused work. Local languages follow as paperwork allows, without code changes.

---

## I. Open questions for the owner

1. **The five copyrighted English versions (NIV, ESV, NASB, NKJV, AMP).** Do you hold a licence
   for any of them?
   *Recommended default: no licence exists, so remove all five in Phase B1 and keep KJV, WEB and
   a completed ASV.*
2. **Shall I draft the permission letter to the Bible Society of Malawi** for you to send?
   *Recommended default: yes, asking for Buku Lopatulika first.*
3. **Which Chichewa Bible do your readers expect?** Buku Lopatulika needs permission and may take
   months; Biblica's contemporary Chichewa looks usable now but reads differently.
   *Recommended default: launch with Biblica's version labelled clearly, add Buku Lopatulika when
   permission arrives.*
4. **Which local languages matter most after Chichewa?**
   *Recommended default: Tumbuka, then Yao, then Lomwe, then Tonga, matching speaker numbers.*
5. **May guests use highlights and notes on their own device** without an account?
   *Recommended default: yes for highlights and reading position (stored on the device), notes
   for members only.*
6. **Should the verse picker be available to members** (in community posts and comments), or only
   to you and the admin team?
   *Recommended default: admins first; members get a plain-text version once the sanitiser from
   wish N is in place.*
7. **Keep public notes on verses?** They are a small social feature that needs moderation.
   *Recommended default: keep, but route reports through the moderation queue planned in
   PROGRAM_PLAN.md, and hide public notes in focus mode.*
8. **Catholic editions** (with the deuterocanonical books) exist for Buku Loyera and Tumbuka.
   Do you want them offered?
   *Recommended default: Protestant canon only in v1, so every version has the same 66 books and
   parallel view always lines up.*
9. **Please confirm three numbers from the InfinityFree control panel:** disk used and allowed,
   inodes used and allowed, and the largest file you can upload by FTP.
   *Recommended default: assume 5 GB, 30,019 and 10 MB until you say otherwise.*

---

## J. Effort and risks

**Effort** (one developer, focused; add a third for review, iPad testing and fixes):

| Phase | Estimate |
|---|---|
| B1 Clean foundation | 1 week |
| B2 Focus mode | 1.5 to 2 weeks |
| B3 Verse picker | 1.5 weeks |
| **v1 total** | **4 to 5 weeks** |
| B4 per licensed version | 1 to 2 days each, after the paperwork |
| B4 extras (cross-references, plans, offline) | 2 to 3 weeks if all are wanted |

**Top risks**

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Licensing, existing:** a publisher notices NIV/ESV/NASB/NKJV/AMP served in full | Medium | High (takedown notice; possible claim) | Remove in B1 unless licences are produced |
| **Licensing, Malawian:** permission is slow, costly or refused | High | Medium (no Buku Lopatulika at launch) | Launch on public-domain English plus Biblica's open Chichewa; letter sent early; plan never depends on a "yes" |
| **A "Creative Commons" label turns out narrower than reported** | Medium | Medium | Read the licence at source before import; save a dated copy; registry flag turns a version off in one step |
| **The 41 MB leak (F30) reopens** if the `.htaccess` is lost or the host stops honouring it | Low | High for licensed texts | Live 403 check on the release checklist; move files outside `htdocs/` if `open_basedir` allows |
| **Storage:** a version exceeds the 10 MB file limit | Low | Medium | No FTS index in text files; size check in the import script |
| **Editor dependency:** wish N changes the editor mid-build | Medium | Low | Picker is editor-independent; only a 15-line adapter changes |
| **Sanitiser not ready** when the picker reaches member editors | Medium | High if ignored (XSS) | Admin-only until the sanitiser ships; server builds the HTML |
| **Refactoring a 1,970-line reader** breaks notes or highlights | Medium | Medium | Incremental: new endpoint and focus mode sit beside the old reader; the old page is retired only after parity tests pass |
| **No Chichewa text-to-speech voice** on most devices | High | Low | Hide the button when no matching voice exists |
| **Text quality** of an imported version (encoding, missing verses) | Medium | Medium | Import script counts books, chapters and verses against the canon; native-reader spot check |

---

## K. Sources

Licensing and translation history (all "Reported"; re-read at source before bundling anything):

- Biblica open Chichewa, licence summary: https://ebible.org/nya/copyright.htm and
  https://ebible.org/details.php?id=nya
- The same translation with an "all rights reserved" notice:
  https://biblegateway.com/versions/Mawu-a-Mulungu-mu-Chichewa-Chalero-Word-of-God-in-Contemporary-Chichewa-CCL/
- Buku Lopatulika history and current edition: https://biblesociety-malawi.org/?p=6107 ,
  https://biblesociety-malawi.org/?p=1640 , https://www.bible.com/versions/1068 ,
  https://ajol.info/index.php/conspectus/article/view/251269/237491
- Tumbuka: https://biblesociety-malawi.org/new-tumbuka-common-language-bible-launched/ ,
  https://find.bible/en/bibles/TUMNEW ,
  https://nyasatimes.com/bible-society-malawi-launches-tumbuka-version-bible
- Yao New Testament: https://ebible.org/yao/copyright.htm ;
  Yao and Lomwe Bibles in print: https://mwnation.com/?p=116744
- Lomwe: https://bible.com/audio-bible-app-versions/3325 ,
  https://news.americanbible.org/article/malawis-elhomwe-speakers-receive-their-first-new-testament
- Sena (Mozambique) and Nyakyusa-Ngonde: https://ebible.org/study/content/texts/seh/about.html ,
  https://ebible.org/bible/details.php?id=nyy
- Lambya: https://biblesociety-malawi.org/lambya-bible-launch-brings-joy-to-chitipa/
- Bible Society of Malawi: https://biblesociety-malawi.org
- Malawi copyright term and commencement: https://malawilii.org/akn/mw/act/2016/26/eng@2017-12-31 ,
  https://www.wipo.int/wipolex/en/legislation/details/17267

Host limits (community forum, not official documentation):

- https://forum.infinityfree.com/t/how-much-disk-space-is-free-account/80589
- https://forum.infinityfree.com/t/inode-limit-on-free-hosting/49331

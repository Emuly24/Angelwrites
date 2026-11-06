# 20. Shared layout, static assets & infrastructure

> For this domain, "user journey" means what a visitor's browser actually receives, and what the deploy pipeline actually publishes. Measured with Chromium against the local server (Env B unless noted). `index.php` isn't assigned to any domain in the brief; it's covered here as the page every visitor receives first.

## Files in scope

| File | Lines / size | Role | Covered below? |
|---|---|---|---|
| `includes/header.php` | 520 | `<head>`, OG tags, nav (desktop + mobile), notification bell, inline CSS | Y |
| `includes/footer.php` | 407 | Footer, newsletter mini-form, cookie banner, scripts | Y |
| `includes/config.php` | 9 | Constants; `SITE_URL` hard-coded | Y |
| `includes/db.php` | 264 | SQLite connection; runs DDL and seeding on every request | Y |
| `includes/load_env.php` | 31 | `.env` parser | Y |
| `assets/css/style.css` | 1576 / 31 KB | Global stylesheet and colour tokens | Y |
| `assets/js/main.js` | 261 / 10 KB | Menu, theme toggle, Bible modal, active-nav | Y |
| `sw.js` | 19 | Service worker (cache-first) | Y |
| `manifest.json` | 8 | PWA manifest | Y |
| `.htaccess` | 9 | Deny `.env`, force HTTPS, `/img/<id>` rewrite | Y |
| `data/.htaccess` | 1 | Deny all for the SQLite folder | Y |
| `.github/workflows/deploy.yml` | 27 | FTP deploy on push to `main` | Y |
| `worker.php` | 38 | Infinite-loop job worker | Y |
| `allow_url_fopen` | 1 | Stray `<?php phpinfo(); ?>` file | Y |
| `reset_admin.php` | 22 | Public admin password reset | Y |
| `serve_og.php` | 31 | Social preview image for `/img/<id>` | Y |
| `generate_og_image.php` | 124 | GD-generated 1200×630 preview image | Y |
| `index.php` | 1229 | Homepage (added here; not in the brief's list) | Y |
| `assets/images/logo.png`, `hero-logo.png`, `angella.jpg` | 2.1 MB / 1.3 MB / 56 KB | Site images | Y |
| `composer.json`, `composer.lock`, `.gitignore` | 7 / 298 / 34 | Build metadata | Y |
| `vendor/**` (281 files) | 2.2 MB | Committed Composer dependencies (PHPMailer, Hybridauth, smalot/pdfparser, symfony polyfill) | Y |
| `docs/**` (AUDIT.md + this scan) | — | Documentation | Y |

## User journeys

### Journey 1: A first-time visitor loads a page
1. **Requests and weight [confirmed]:**

   | Page | Same-origin requests | Bytes |
   |---|---|---|
   | `index.php` | 5 | **3.4 MB** |
   | `books.php` | 5 | 2.1 MB |
   | `about.php` | 5 | 2.1 MB |
   | `poem_view.php` | 9 | 2.1 MB |

   About 2 MB of every first view is **`assets/images/logo.png`: a 1536×1024, 2.1 MB PNG** shown at roughly 150 px wide in the header and again in the footer. The homepage adds `hero-logo.png` (1.3 MB). On mobile data in Malawi that's the main reason the site feels slow.
2. **`favicon.ico` doesn't exist** (`includes/header.php:57` links it), so every browser makes a 404 request, which still counts as a hit **[confirmed: file absent; 404 seen in crawl console logs]**.
3. **Every page throws a JavaScript error [quality] [confirmed in 50 of the crawled pages]:** `assets/js/main.js:170` populates a "Bible modal" (`#bibleBook`, `#bibleVersion`…) that exists on no page, then throws on `appendChild` of null. Everything after it in main.js (the active-nav highlighting, `:246-260`) never runs. The menu and theme toggle are wired earlier and do work.
4. **Cookie banner** (`includes/footer.php:378`) is fixed to the bottom of every page until dismissed. It covers the bottom quarter of a phone screen and hides real controls, e.g. the Bible reader's "Post Note" button (02 J3) **[confirmed]**.
5. External assets: Font Awesome (cdnjs), Google Fonts, Chart.js (unpinned `@latest`), TinyMCE (two different versions on different admin pages), Plyr, page-flip (unpinned, 404s; domain 1). These are browser-side, so the host's outbound restriction doesn't apply, but each is a third-party dependency.

### Journey 2: A visitor uses the site on a phone
1. Most pages have **no horizontal overflow at 768 or 375 px** (crawl of 69 pages × 3 roles; the only overflows were PHP error messages) **[confirmed]**. Layout responsiveness is generally good.
2. **[wrong] [confirmed] The mobile menu is broken for logged-in members:**
   - At 375 px the header holds the logo, 4 icon buttons and the menu button, and the menu button sits at x = 367–405 px, partly **off the right edge**.
   - Tapping it opens a panel positioned at x = 346–665 px. **Only a ~30 px sliver of the menu is on screen**, with item labels cut to their first letter ("H", "E"…). It stays that way after the animation (re-checked at 2 s).
   - Phone members can't navigate by menu at all.
3. Tap targets: 15–40 controls per page are smaller than 32 px on phone (crawl).

### Journey 3: A visitor opens the homepage
1. Env A: **[fatal] [confirmed]** *no such table: testimonials* (`index.php:83`). **The homepage is down on the repo schema.** Env B renders hero, featured books, latest poems and posts, stats, testimonials, newsletter and testimonial forms **[confirmed]**.
2. "Join the Community — It's Free" → `register.php`, which fails (domain 10).
3. Logged-in member submits a testimonial → **saved with `approved = 0`**, then `index.php:187` email → **SMTP dump** **[wrong] [confirmed]**. **No admin screen can approve testimonials** (no admin file references the table), so new testimonials never appear **[missing] [confirmed by grep]**.
4. The guest page cache (`index.php:9-19`, `:1223-1226`) writes to a `cache/` folder that doesn't exist, so it never caches. If someone creates the folder, every guest gets the same cached HTML, including another visitor's CSRF token (domain 16 J3) **[inferred]**.
5. The service worker is registered here (`index.php:244`). See Journey 5.

### Journey 4: A visitor shares a page on WhatsApp or Facebook
1. `includes/header.php:60-80` emits generic `og:` tags. `poem_view.php` adds poem tags later, inside `<body>` (domain 12).
2. Poem shares point `og:image` at `/img/<id>`, rewritten by `.htaccess` to `serve_og.php`. **[fatal] [confirmed]** `serve_og.php:16` uses `$db` without including `db.php`, so it returns a PHP error page instead of an image. Its last-resort fallback `assets/images/angelwrites-logo.png` (`:31`) doesn't exist either.
3. `generate_og_image.php` does produce a 1200×630 PNG (425 KB) **[confirmed]**, but nothing links to it. It also reads **any image file the PHP process can reach**: a `src=../…` path outside the site was served **[confirmed]**. Low impact, since only image extensions are allowed.

### Journey 5: A returning visitor (service worker)
1. `sw.js` caches `/`, `style.css` and `main.js` under a fixed name `angelwrites-v1`, and answers **every** request cache-first, falling back to network (`:13-18`).
2. **[wrong] [inferred]**
   - After the first visit, `/` (the homepage HTML), the CSS and the JS are served from the browser cache **forever**. There's no versioning or update step, so CSS and JS fixes and homepage changes won't reach returning visitors until they clear site data.
   - Because cache-first applies to every request, any other cached response would also be served stale.
3. `manifest.json` declares `"display": "standalone"` with **no icons** (`"icons": []`), so "Add to home screen" shows a generic icon.

### Journey 6: Two visitors arrive at the same moment (SQLite)
1. `includes/db.php` runs on **every request**: 13 `CREATE TABLE IF NOT EXISTS`, 11 `CREATE INDEX IF NOT EXISTS`, a `COUNT(*)` on users, `PRAGMA foreign_keys`. Content pages then write (view counts, view logs, reads).
2. **[fatal] [confirmed]** 60 simultaneous requests to a poem page: **4 failed with *SQLSTATE[HY000]: General error: 5 database is locked*** (an HTTP 200 with a PHP fatal in the body). That's about 7% of concurrent views during a burst, such as a poem shared to a WhatsApp group.
3. If `users` is ever empty, `db.php` silently recreates the default admin (`admin@angelawrites.com` / `ChangeMe123!`) and a test reader (AUDIT.md §3).

### Journey 7: What the deploy pipeline publishes
1. On every push to `main`, `.github/workflows/deploy.yml` FTP-syncs the repo, excluding only `data/**`, `.git*`, `.github/**`, `.env` and `README.md`.
2. **Published today: 438 files, ~50 MB** **[confirmed by listing]**, including:
   - `reset_admin.php`: public admin password reset, **critical** (domain 10 J9).
   - `worker.php`: web-reachable `while(true)` loop. Each hit ties up a PHP process until the host kills it.
   - `update_schema.php` (one-off migration) and `allow_url_fopen` (a stray file containing `<?php phpinfo(); ?>`; with no `.php` extension it's served as text).
   - `composer.json` and `composer.lock`, readable by anyone; 281 `vendor/` files including READMEs and licences.
   - `assets/bible/*.db`: 41 MB of Bible databases, directly downloadable (domain 2).
   - **`docs/`: AUDIT.md and this deep scan, once merged into `main`.** They describe every vulnerability above. **Add `docs/**` to the exclude list before merging.**
3. `.htaccess` is listed in `.gitignore` but tracked, so it *is* deployed. Good: it protects `.env` and forces HTTPS. `data/.htaccess` (deny all) protects the SQLite file **[confirmed present]**.
4. The `data/` exclusion means the production database is never overwritten. It also means schema changes in code never reach production; every table and column change is manual (see the rollup).

## Quality problems independent of the bugs above
- **2–3.4 MB first page weight**, almost all from one oversized logo.
- **Global JS error on every page**, from leftover Bible-modal code.
- **Mobile menu unusable** for members.
- **Stale-forever service worker.**
- **PHP errors are shown to visitors** on many pages (`display_errors` is forced on in several files and the host's default display is evidently on, since the test reproductions show full paths). Errors expose server paths.
- **Inline CSS everywhere** (AUDIT.md §5). Visible consequence: pages that redefine `:root` don't follow the dark-mode toggle.

## Root causes
- **[code]**
  - Leftover Bible-modal JS.
  - `serve_og.php` missing include and wrong fallback filename.
  - Unbounded path in `generate_og_image.php`.
  - Cache-first service worker without versioning.
  - Empty manifest icons.
  - Header markup too wide for phones.
  - DDL and seeding on every request.
  - Testimonials with no approval screen.
- **[assets]** 2.1 MB logo, 1.3 MB hero; missing favicon.
- **[infra]**
  - SQLite write contention (confirmed lock errors).
  - Deploy publishes one-off, debug and doc files.
  - Hit cost of 5–9 requests per view plus polling.
- **[ux]** Cookie banner overlapping controls.

## What a professional version looks like
- **J1:** Logo exported at about 2× display size (~300×200 px, under 30 KB, plus WebP), a real favicon, one `main.js` with no errors, and a cookie banner that is a slim bar that doesn't cover controls.
- **J2:** On phones the header shows logo + menu button only; the menu slides fully into view, closes on outside tap, and holds the bell/profile/theme entries.
- **J3:** Homepage renders on any schema state without fatals. Testimonials have an approval list in admin.
- **J4:** Each poem or post has one set of OG tags with a static image generated when content is saved (no PHP on the share path).
- **J5:** Either no service worker, or one that is versioned on each deploy, network-first for HTML, and cache-first only for versioned static files.
- **J6:** No DDL on page requests (schema handled by a one-off admin migration), view counts updated without blocking, and a busy timeout set explicitly.
- **J7:** Deploy excludes `docs/`, one-off scripts, `composer.*`, vendor docs and the Bible DBs if they don't need HTTP access. Each deploy bumps the service-worker version.

## Dependencies before this can be fixed
1. **Immediately:** add `docs/**`, `reset_admin.php`, `worker.php`, `update_schema.php`, `allow_url_fopen` to the deploy exclude list, **and delete those files from the server by FTP** (excluding them stops future uploads but doesn't remove what's already there).
2. Decide whether the service worker stays. Returning visitors will need one forced update (a new `CACHE_NAME`) to escape the stale cache.
3. Decide whether the Bible DBs must be web-readable.
4. Any schema change is manual on `data/site.db` (SQLite, not phpMyAdmin). Plan the changes from domains 1–19 as one batch.

## Blast radius
Every domain: header, footer, main.js, db.php and the service worker are on every page. Specifically: domain 10 (`reset_admin.php`), 12 (OG images), 16 (footer newsletter, homepage forms), 2 (cookie banner overlap, Bible DBs), 11/19 (polling and SQLite contention).

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 weight/JS/banner | S | Re-export two images; delete dead JS; restyle banner. |
| J2 mobile menu | S–M | Header layout at small widths. |
| J3 homepage | S–M | Guard/create testimonials; approval screen M. |
| J4 sharing | M | Static OG images on save. |
| J5 service worker | S | Version or remove (plus one forced update). |
| J6 SQLite | M | Move DDL out of requests; set busy timeout; reduce per-view writes. |
| J7 deploy | S | Exclude list plus FTP cleanup. |

## Regression check against AUDIT.md
- `db.php` DDL on every request: **Yes.**
- SQLite locking with no busy timeout → "database is locked": **Yes**, confirmed (4 of 60 concurrent views).
- `serve_og.php:16` `$db` undefined; fallback logo missing: **Yes.**
- `sw.js` stale cache-first: **Yes** (code).
- `favicon.ico` missing: **Yes.**
- `reset_admin.php`, `worker.php`, `allow_url_fopen` deployed: **Yes.**
- `index.php:83` `testimonials` [schema]: **Yes** (Env A).
- `index.php` page cache with no `cache/` dir: **Yes.**
- AUDIT.md didn't report the broken mobile menu, the `main.js:170` site-wide error, the missing testimonial approval, the `generate_og_image.php` path reach, or `docs/` being deployed. New.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `includes/header.php` | COVERED | Journeys 1, 2, 4 |
| `includes/footer.php` | COVERED | Journey 1 (cookie banner, scripts) |
| `includes/config.php` | COVERED | Journey 7 step 4; Root causes (`SITE_URL`, `SITE_NAME`; see also 19 J3) |
| `includes/db.php` | COVERED | Journey 6 |
| `includes/load_env.php` | COVERED | Journey 7 (`.env` excluded from deploy, protected by `.htaccess`); loader itself works, no user-visible journey |
| `assets/css/style.css` | COVERED | Quality (inline CSS, dark mode); detail in AUDIT.md §5 |
| `assets/js/main.js` | COVERED | Journey 1 step 3 |
| `sw.js` | COVERED | Journey 5 |
| `manifest.json` | COVERED | Journey 5 step 3 |
| `.htaccess` | COVERED | Journeys 4 (rewrite), 7 step 3 |
| `data/.htaccess` | COVERED | Journey 7 step 3 |
| `.github/workflows/deploy.yml` | COVERED | Journey 7 |
| `worker.php` | COVERED | Journey 7 step 2 |
| `allow_url_fopen` | COVERED | Journey 7 step 2 |
| `reset_admin.php` | COVERED | Journey 7 step 2 (detail in 10 J9) |
| `serve_og.php` | COVERED | Journey 4 step 2 |
| `generate_og_image.php` | COVERED | Journey 4 step 3 |
| `index.php` | COVERED | Journey 3 |
| `assets/images/logo.png` | COVERED | Journey 1 step 1 (2.1 MB, 1536×1024) |
| `assets/images/hero-logo.png` | COVERED | Journey 1 step 1 (1.3 MB, homepage) |
| `assets/images/angella.jpg` | COVERED | Journey 1 (56 KB; appropriately sized) |
| `composer.json` | COVERED | Journey 7 step 2 (published, readable) |
| `composer.lock` | COVERED | Journey 7 step 2 (published, readable) |
| `.gitignore` | COVERED | Journey 7 step 3 (lists `.htaccess`, yet it's tracked and deployed; lists `*.db`, yet the Bible DBs are tracked) |
| `vendor/**` (281 files) | NOT APPLICABLE, third-party code | Not audited line by line. Its user-visible effects are covered: PHPMailer (16 J8), Hybridauth (10 J6), pdfparser (09 J5). Its published READMEs/licences: Journey 7 step 2 |
| `docs/**` (19 files at time of writing) | COVERED | Journey 7 step 2 (would be deployed publicly once merged to `main`) |

# 03. Book downloads & the purchase gate

> Test method as in `01-reader.md`. Env B had one free book (id 1) and one paid book (id 2, MWK 5,000) with placeholder files on disk. The payment journey is covered from the *download* angle here; the money side (what is recorded, what the admin sees, session payments) is in `08-payments.md`.

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `download.php` | 93 | Gate: free → any member; paid → must have a `purchases` row; streams the file | Y |
| `process_payment.php` | 93 | Writes a `payments` row marked `completed` (no gateway) | Y (download side; money side in 08) |
| `admin/manage_books.php` | 875 | Book CRUD; **where book files are uploaded and stored** (`:97-110`) | Y (download side only) |
| `books.php` | 550 | Catalogue; holds the Download button and the payment modal JS (`:133`, `:223-320`) | Y (download/payment UI only; catalogue quality in 09) |
| Readers of `purchases` | — | Only `download.php:46-50` | Y |
| Readers of `payments` | — | Only `process_payment.php:47` | Y |

`book.php` (detail page) has no buy button. For books **without processed content** it shows a **Download** button that links **straight to the raw file**, bypassing `download.php` (Journey 4.0). Catalogue/detail quality is in 09.

## User journeys

### Journey 1: Member downloads a free book
1. Member opens **Books**; a **Download** button shows on each card (logged-in only, `books.php:132`).
2. Clicks Download on a free book. JS sends them to `download.php?book_id=1`. **[confirmed]** The browser receives `Walking_in_Grace.pdf` with `Content-Disposition: attachment`. **Works.**
   - Env A: works too, since `books` is a repo-defined table.
3. **[quality] [inferred]** If the file is missing on disk, the member gets a bare white page reading *"File not found. Please contact support."* (`download.php:62`), with no site layout and no link back.

### Journey 2: Guest wants a free book
1. **[quality] [confirmed]** Guests see no Download button at all, and nothing tells them signing in unlocks downloads.
2. If they reach `download.php?book_id=1` directly, they're redirected to `login.php?redirect=%2Fdownload.php%3Fbook_id%3D1` (`download.php:19`).
3. **[wrong] [inferred]** After logging in, `login.php:117` only honours a redirect that starts with `https://angelwrites.gt.tc`. This one is a relative path, so it's ignored and the member lands on the dashboard. The download they asked for is forgotten.

### Journey 3: Member buys and downloads a paid book
1. Clicks **Download** on the paid book. A payment modal opens with three options labelled Airtel Money, "Mpamba" and "NBM Mo626" **[confirmed]**.
2. Chooses Airtel, enters a phone number, presses Pay. The status shows "Processing payment…".
3. `process_payment.php` inserts a `payments` row with status **`completed`** (`:58-63`). No money moves; there is no payment provider and no prompt on the phone.
4. `process_payment.php:77` emails the admin. SMTP fails, `die()` prints HTML, and the modal's JSON parse fails.
   - **[wrong] [confirmed]** The member sees **"Payment failed. Please try again."** while the database says `completed`.
5. Member presses Pay again. **[wrong] [confirmed]** *"You have already purchased this book."*
6. Member tries the download anyway (`download.php?book_id=2`). **[silent] [confirmed]**
   - The gate checks the **`purchases`** table (`download.php:46-50`), but the payment was written to **`payments`**.
   - Result: 302 to `books.php?error=not_purchased`. `books.php` never reads `$_GET['error']`, so the member is dropped back on the catalogue with no message.
7. **Dead end.** The member "paid", is told it failed, is told they already bought it, and can never download it.
   - Even if SMTP worked, step 6 would still fail: the two tables are never reconciled anywhere in the code.

### Journey 4: Anyone gets a paid book without paying
0. **Through the book's own detail page [wrong] [confirmed]:**
   - As a guest, `book.php?id=2` (paid, MWK 5,000, no processed content) shows the price **and a "Download (PDF)" button**.
   - The button is `<a href="https://angelwrites.gt.tc/<file_path>" download>` (`book.php:224-226`), and the request returns `200 application/pdf`.
   - No login, no payment, no `download.php`. **This is the easiest leak:** one click on the public product page.
1. **Through the reader [wrong] [confirmed mechanism]:**
   - `reader/reader.php?id=2` returns 200 for a guest.
   - `reader/book_data.php` has **no price or purchase check**, and the server renders every page of the book into the HTML (6 of 6 pages delivered to a guest for book 1, confirmed).
   - So the full text of any paid book that has processed content is readable for free. This still happens despite the reader's fatal error, because the pages are emitted before line 384.
2. **Through the file URL [wrong] [confirmed]:**
   - Uploaded book files are stored under `assets/uploads/books/<unix-time>_<original-name>` (`admin/manage_books.php:99-103`). The directory is web-readable; only `data/` has a deny rule.
   - A direct request to the file returns 200 `application/pdf` with no login (confirmed with a placeholder file).
   - The name is predictable: an upload timestamp plus the original filename.
3. **Through the payment endpoint [wrong] [inferred]:**
   - `process_payment.php` takes `amount` from the browser (`:23`) and marks it completed.
   - Once the purchases/payments mismatch is fixed, anyone could "pay" MWK 0.01 by editing the request.

### Journey 5: Admin uploads a book file
1. Upload on `admin/manage_books.php` accepts **any file extension** (`:101-108`). Unknown types are stored with `file_type = 'unknown'` **[inferred]**.
2. The file lands in a public folder (Journey 4.2).
3. **[quality] [inferred]** There's no size check or limit message. InfinityFree's upload limit (typically about 10 MB) can reject large PDFs with no friendly error; `move_uploaded_file` just fails, giving "Failed to upload book file."

## Quality problems independent of the bugs above
- **No purchase state anywhere in the UI.** A member who owns a book still sees the same "Download → payment modal" on the card. There's no "Owned" badge and no "My purchases" page.
- **The detail page (`book.php`) has no buy button.** For unprocessed books its only action is a raw-file download that ignores the price (Journey 4.0). For processed books its only action is "Read Book", into the ungated reader.
- **Payment modal on phones:** no horizontal overflow at 768/375 in the catalogue crawl (see 09). The modal itself uses fixed padding and `display:flex` centring and fits **[inferred from styles]**. Its 3 payment options are image-less text buttons.
- **The modal shows "Processing payment…" for something that never contacts a provider.** It's a mock flow presented to real users as real.
- **Errors are raw.** `download.php` uses `die('…')` for every failure (`:26`, `:35`, `:62`), giving white pages without the site layout.
- **No download count for paid books.** `books.download_count` exists in the schema but `download.php` never increments it.

## Root causes
- **[code]**
  - Payments written to `payments`, gate reads `purchases`.
  - Client-supplied amount.
  - No access check in the reader.
  - `?error=` never displayed.
  - Relative redirect rejected by login.
  - `die()` error pages.
- **[schema]** Neither `payments` nor `purchases` is created by the repo. `download.php`'s header comment (`:9-10`) guesses at the `purchases` columns ("payment_status (or is_paid)"), which suggests the table may not exist in production either.
- **[ux]** No owned/purchased state, no buy button on the detail page (and a raw-file download link there instead), mock payment presented as real.
- **[infra]**
  - SMTP failure turns every payment into a "failure".
  - Public upload directory.
  - Host upload limit not surfaced.

## What a professional version looks like
- **J1:** The Download button streams the file. On failure, show a styled page in the site layout: "We couldn't find this file. We've been notified." with a Back link.
- **J2:** Guests see "Sign in to download". After login they return to the exact download (relative redirects accepted after validating that they start with `/` and not `//`).
- **J3:**
  - No "Pay" button until a real payment method exists. Until then, the honest flow is "Request this book": the member submits name and phone, the admin confirms receipt of mobile money manually in an admin screen, and that confirmation creates the ownership record.
  - Once ownership exists, the card shows **Owned · Download** and the detail page shows the same.
  - One table is the source of truth for ownership.
- **J4:**
  - Paid book files live outside the public web path (or behind a deny rule) and are only streamed by `download.php` after the ownership check.
  - The reader shows a preview (e.g. the first chapter) for paid books unless the viewer owns the book.
  - Amounts always come from the database, never from the browser.
- **J5:** Upload accepts only PDF/EPUB/DOCX, shows the host's size limit next to the field, and reports "File too large (max X MB)" precisely.

## Dependencies before this can be fixed
1. **Business decision:** is there going to be a real payment provider (Airtel Money / Mpamba APIs need outbound HTTPS calls, which your plan forbids), or a manual "pay then admin confirms" flow? Everything in J3 depends on this.
2. Decide which table holds ownership (`payments` or `purchases`) and whether the other is dropped. If production lacks it, **create it manually** in `data/site.db`. That's SQLite, not phpMyAdmin; see the note in 01.
3. Move existing uploaded book files out of `assets/uploads/books/`, or add a deny rule, **before** paid downloads go live.
4. Email strategy (domain 16) so a notification can never fail a payment.
5. Reader access control (domain 1) depends on step 2.

## Blast radius
- **Domain 1 (reader):** the free-read leak lives there; gating needs the ownership table.
- **Domain 8 (payments):** same `process_payment.php`, seen from the money side. Fixing one fixes the other.
- **Domain 9 (catalogue/detail):** the Download button and modal live in `books.php`; the detail page has no buy path, and links raw files directly for unprocessed books.
- **Domain 10 (auth):** the post-login redirect rule breaks "download after sign-in".
- **Domain 16 (email):** the admin payment email is what turns success into "failed".
- **Domain 20 (infra):** public `assets/uploads/` directory and no deny rules.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 free download | S | Already works; only the error page needs styling. |
| J2 guest → download | S | Accept safe relative redirects in login; add a sign-in prompt. |
| J3 buy → download | L | Needs a payment/ownership model decision, a manual-confirmation admin screen or a gateway, one source of truth, and UI states. |
| J4 leaks | M | Move files plus a deny rule (S), reader preview gating (M), server-side price (S). |
| J5 admin upload | S | Extension allow-list and limit message. |

## Regression check against AUDIT.md
- `payments` vs `purchases` mismatch: **Yes**, reproduced end to end (Journey 3).
- `process_payment.php` trusts the client amount and marks it completed: **Yes.**
- `process_payment.php:77` `sendEmail()` → `die()`: **Yes.** It is what shows "Payment failed".
- Upload with no extension check (`manage_books.php:101`): **Yes.**

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `download.php` | COVERED | Journeys 1, 2, 3 (step 6), 4 |
| `book.php` (download link only) | COVERED | Journey 4.0 (page reviewed in full in 09) |
| `process_payment.php` | COVERED | Journey 3; Journey 4.3 (money side also in 08) |
| `admin/manage_books.php` | COVERED | Journey 4.2; Journey 5 (download side only; rest in 09) |
| `books.php` | COVERED | Journeys 1–3 (Download button and payment modal; catalogue in 09) |
| Readers of `purchases` (`download.php` only) | COVERED | Journey 3 step 6 |
| Readers of `payments` (`process_payment.php` only) | COVERED | Journey 3 step 5 |

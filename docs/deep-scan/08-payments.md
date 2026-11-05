# 08. Payments, end to end

> Companion to `03-downloads-purchase-gate.md`. That file walks the buyer's journey up to the download. This file covers the money: what gets recorded, who can see it, what the admin can do, and the payments/purchases mismatch. The shared steps aren't repeated; they're referenced as "03 J3".

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `process_payment.php` | 93 | The only payment code: validates the form, inserts a `payments` row as `completed`, emails admin | Y |
| `download.php` | 93 | Reads `purchases` to decide whether a paid book may be downloaded | Y |
| `book_session.php` | 495 | 1-on-1 booking. **Checked for payment: there is none** (no price, fee or MWK anywhere in the file) | Y |
| Admin views of payments | — | **None exist.** No admin page, dashboard stat or export reads `payments` or `purchases` | Y |
| `books.php` (payment modal JS, `:250-320`) | 550 | Client side of the payment form | Y (modal mechanics in 03) |
| `admin/manage_books.php` (price fields, `:253`) | 875 | Where the price and free/sale flags are set | Y |

## User journeys

### Journey 1: Member pays for a book (money side)
1. Member fills the modal (03 J3 steps 1–2).
2. `process_payment.php` validates:
   - `payment_method` ∈ {airtel, mpamba, nbm}
   - phone length ≥ 10
   - `amount > 0`, where **the amount is taken from the browser** (`:23`)
3. It checks for an earlier `completed` row in `payments` (`:47`), generates `TXN_<16 hex>_<time>`, and inserts **status `completed`** (`:58-63`).
   - **[wrong] [confirmed]** No provider is contacted. No USSD/push prompt reaches the phone, and nothing confirms money arrived.
4. **[wrong] [confirmed by code]** The phone number the member typed is **not stored**. The INSERT has no phone column (`:60`). It exists only in the admin email body (`:76`).
5. The admin email (`:77`) is the only way the admin learns of the payment. On SMTP failure the member sees "Payment failed" (03 J3 step 4) and the admin gets nothing. **[confirmed]**
6. The recorded row: `(user 2, book 2, amount 5000, airtel, completed)` **[confirmed]**, with no phone and nothing to reconcile against.

### Journey 2: Member checks what they've bought
1. **[missing] [confirmed by grep]** There's no "My purchases", order history or receipt page. `dashboard.php`, `profile.php` and `library.php` never read `payments` or `purchases`.
2. The member's only evidence is the "Payment successful!" message, which they don't see when SMTP fails.

### Journey 3: Admin reviews payments and confirms money arrived
1. **[missing] [confirmed by grep]** No admin screen lists payments, and there's no way to mark one confirmed or refunded. `admin/dashboard.php` and `ajax_admin.php` show no revenue figure.
2. The only record is the `payments` table inside `data/site.db`. Reading it means downloading the SQLite file over FTP (domain 20).
3. So the admin can't answer "has this person paid me?", and the phone number needed to check a mobile-money statement was thrown away (Journey 1 step 4).

### Journey 4: Paid member downloads (the mismatch)
1. `download.php` grants paid downloads only if a row exists in **`purchases`** with `payment_status = 'completed'` (`:46-50`).
2. **Nothing in the codebase ever writes to `purchases`** (grep). `process_payment.php` writes **`payments`**.
3. **[wrong] [confirmed]** Every paid download is refused (03 J3 step 6), forever, for every member.

### Journey 5: Admin sets up a paid, free or "sale" book
1. `admin/manage_books.php` has Price (MWK), Free and Sale flags.
2. **Sale [wrong] [inferred]:** there's no sale-price field. `books.php` shows a sale book's normal price in a "sale" colour (`books.php:121-122`), so a sale shows no discount.
3. **Price 0 and not free [wrong] [inferred]:** the card shows "MWK 0.00" and opens the payment modal. `process_payment.php:41` rejects `amount <= 0` with "Invalid amount", so the book can never be obtained.
4. **Changing a price after someone "paid"** has no effect on their existing row. That's fine, but there's also no record of what price was shown to them beyond the client-supplied amount.

### Journey 6: Member books a 1-on-1 session: is it paid?
1. `book_session.php` has no price, fee or payment step (searched for `price|pay|fee|MWK|cost`) **[confirmed by grep]**. Sessions are free.
2. Recorded here so it's clear the payments system has exactly one consumer: books. Session-booking problems are in domain 18.

## Quality problems independent of the bugs above
- **A simulated payment shown as real.** "Processing payment… Payment successful!" with no provider is the most serious trust issue on the site, more than a crash.
- **No receipt, no transaction reference shown, no confirmation email that works.**
- **Currency formatting** uses `number_format($price, 2)` ("MWK 5,000.00"). MWK prices aren't normally shown with tambala decimals.
- **Modal UX:** three text-only provider buttons with no logos and no instructions for what happens next; covered in 03.

## Root causes
- **[code]**
  - Status hard-coded `completed`.
  - Amount from client.
  - Phone not stored.
  - Writes `payments`, reads `purchases`.
  - No sale price.
  - Price-0 dead end.
- **[schema]** Neither `payments` nor `purchases` is created by the repo, and their columns are only implied by the queries.
- **[ux]** No receipt, history or admin ledger.
- **[infra]** A real mobile-money integration needs outbound HTTPS calls to the provider (forbidden on your plan) and an inbound callback URL. The only payment flow this host can support is a manual one.

## What a professional version looks like
For this host (no outbound calls), the honest version is **manual confirmation**:
- **J1:**
  - Member presses "Buy" and sees: "Send MWK 5,000 to Airtel Money 099… with reference *AW-1042*, then enter your phone number."
  - A `pending` order is stored **with the phone number and the server-side price**.
  - The member sees "Order AW-1042 received. We'll unlock the book once we see your payment (usually within 24 h)."
- **J2:** "My purchases" lists orders: *AW-1042 · Seasons of the Heart · MWK 5,000 · Pending/Confirmed*, with a Download button once confirmed.
- **J3:** Admin "Orders" screen: filter pending, see phone and reference, press **Confirm** (unlocks download) or **Reject** (with a reason shown to the member).
- **J4:** Download checks the same table the orders screen writes. One source of truth.
- **J5:** Sale has an explicit sale price and end date. Price 0 forces "Free".

## Dependencies before this can be fixed
1. **Business decision:** manual confirmation (fits the host) vs a real gateway (needs a different host or a third-party payment page the user is redirected to).
2. Pick one ownership table. **Create or alter it manually** in `data/site.db` (SQLite, not phpMyAdmin; see 01) with at least: user, book, amount (server-side), phone, reference, status, created/confirmed timestamps.
3. Rows already in `payments` were marked `completed` without any money check. **Decide manually** which are genuine before migrating them.
4. Email must not be in the critical path (domain 16).

## Blast radius
- **Domain 3 (downloads):** same files; the buyer-side journey.
- **Domain 1 (reader):** paid books are fully readable there for free.
- **Domain 9 (catalogue):** the Buy entry point lives on the catalogue card. The detail page has none, and for unprocessed books it links the raw file directly, so the paid file is free (03 J4.0).
- **Domain 19 (admin):** a new orders screen belongs with the admin tools; `ajax_admin.php` stats could show pending orders.
- **Domain 16 (email):** admin notification of new orders.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 pay (manual flow) | M | New pending-order flow with server-side price, reference and phone. |
| J2 my purchases | S | One list page over the orders table. |
| J3 admin orders | M | List, filter, confirm/reject actions, member-visible status. |
| J4 mismatch | S | Read the same table that is written. |
| J5 price rules | S | Sale-price field and price-0 validation. |
| J6 sessions | — | No payment involved. |

## Regression check against AUDIT.md
- `process_payment.php:58-63` client amount, status `completed`: **Yes.**
- `payments` vs `purchases` table mismatch: **Yes**, confirmed end to end.
- `process_payment.php:77` `sendEmail()` → `die()`: **Yes**, it makes successful inserts look failed.

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `process_payment.php` | COVERED | Journey 1; Journey 5 step 3 |
| `download.php` | COVERED | Journey 4 |
| `book_session.php` | COVERED | Journey 6 (no payment; booking itself in domain 18) |
| Admin views of payments | COVERED | Journey 3 (none exist) |
| `books.php` (payment modal) | COVERED | Journey 1 (mechanics in 03 J3) |
| `admin/manage_books.php` (price fields) | COVERED | Journey 5 |

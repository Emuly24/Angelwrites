# 11. Profile, member dashboard & library

> Test method as in `01-reader.md`. Logged in as member user 2 ("Test Reader") in Env B; `users.name` nullable (see 10).

## Files in scope

| File | Lines | Role | Covered below? |
|---|---|---|---|
| `dashboard.php` | 463 | Member home: welcome, stat tiles, recent activity, module shortcuts, charts, 60 s polling | Y |
| `profile.php` | 963 | Edit profile (name, username, email, gender, country, phone, bio, avatar crop/camera), change password, stats | Y |
| `library.php` | 692 | "My Library": currently reading / want to read / finished, filters, status change, remove | Y |
| `notifications.php` | 373 | Notification centre, mark read / mark all read | Y |
| `notification_read.php` | 18 | Marks one notification read, redirects to dashboard | Y |
| `ajax_user.php` | 224 | JSON stats and charts for the dashboard | Y |

## User journeys

### Journey 1: Member lands on their dashboard
1. Env A: renders with PHP warnings (*Undefined array key "current_streak" / "level" / "points"*, crawl) **[confirmed]**.
2. Env B: "Welcome back, Test Reader", stat tiles (Currently reading, Books finished, Poems read, Videos watched…), Recent activity, module sections, Quick Actions **[confirmed]**. **Phone layout is clean** (no overflow at 768/375, screenshot reviewed) **[confirmed]**.
3. **"🔥 Day Streak" shows no number** when the member has no streak row **[quality] [confirmed]**. Same `fetchColumn() ?? 0` pattern as `book.php`.
4. **Several tiles can never change [wrong] [inferred, code-verified]:**
   - *Videos watched:* `video_watches` is written by **no** file.
   - *Reading minutes / streak / level & points:* `reading_sessions`, `reading_streaks` and `user_reputations` are written only by reader code that never runs (domain 1).
   - *Currently reading / Finished:* see Journey 3, status vocabulary.
   - Only "Poems read" (written by `poem_view.php`) moves in practice.
   - So a member's dashboard shows a row of zeros forever.
5. **Reader Tools / member modules** link to 8 raw reader partials and endpoints (JSON, blank pages, fatals). Detailed in domain 1 Journey 5 **[confirmed]**.
6. **Polling [infra] [inferred, code-verified]:**
   - `dashboard.php:378` `setInterval(refreshStats, 60000)` and `:399` `setInterval(updateCharts, 60000)`. Both call `ajax_user.php` every minute and never stop when the tab is hidden.
   - **One dashboard tab left open costs 2 hits/minute ≈ 2,880 hits/day**, about 6% of your 50k daily allowance from one idle member.
   - (My fake-clock run only registered 1 extra request, because the chart library is blocked in the sandbox and `updateCharts` throws. In production both intervals run.)

### Journey 2: Member edits their profile
1. `profile.php`: Env A **[fatal] [confirmed]** *no such table: poem_reads* (`profile.php:23`). Env B renders a profile form and a password form **[confirmed]**.
2. Fields: name, username, email, gender, country, contact number, bio, avatar (upload with crop, or live camera). These overlap with registration's 13 fields but **not exactly**: registration stores `first_name`/`last_name`, while profile edits a single `name`. Neither form shows what the other collected **[inferred]**.
3. Change username to one that's taken: *"This username is already taken."* **[confirmed]** Good.
4. **Change email [wrong] [confirmed]:**
   - The new address is saved immediately and the account **stays verified** (`is_verified = 1`). There's no confirmation to the new address and no notice to the old one, so anyone with a few minutes at an unlocked session can take the account over by changing the email and resetting the password.
   - Then `profile.php:117` emails the admin → **SMTP dump**. The member sees a crash after a successful save.
5. Avatar: base64 from the cropper is written as `assets/uploads/profiles/user_<id>_<time>.jpg` (`:53-66`) without checking it is an image **[inferred]**. Old avatars are never deleted.

### Journey 3: Member changes their password
1. Wrong current password: *"Current password is incorrect."* **[confirmed]**
2. Right current password: *"Password changed successfully!"* **[confirmed]**. **Works well.** Other sessions aren't signed out and remember-me tokens aren't revoked (they're never read anyway, domain 10).

### Journey 4: Member opens "My Library"
1. `library.php` renders counters (0 Reading / 0 Want to Read / 0 Finished), filters, and three sections with **good empty states** ("No books in progress — Browse books to get started") **[confirmed]**.
2. **[wrong] [inferred, code-verified] The library can never fill up.** Only two places write `reading_status`:
   - `reader/reader_ajax.php:616-619`, the reader's status dropdown, which never runs (domain 1).
   - `library.php:47-50`, which only changes books *already* in the library.

   The "Browse books" link leads to a catalogue with no "Want to read" or "Add to library" button.
3. **[wrong] [inferred, code-verified] Status vocabularies don't match:**
   - The reader saves `currently_reading` / `want_to_read` (underscores, `reader_ajax.php:607`; `reader.php:297-301`).
   - The library and dashboard count and filter `currently reading` / `want to read` (spaces, `library.php:19`, `:40`; `dashboard.php:59`; `ajax_user.php:58`).
   - Once the reader works, books it marks still won't appear in the library sections or the dashboard counters.

### Journey 5: Member checks notifications
1. Header bell shows "No notifications yet" **[confirmed]**. `notifications.php`: "0 total • 0 unread … All clear!" **[confirmed]**. Nice empty state.
2. **[missing] [inferred, code-verified] Nothing in the codebase ever creates a notification.** There's no `INSERT INTO notifications` anywhere. The bell, the page, mark-read (`notifications.php:16-30`) and `notification_read.php` are all UI with nothing feeding them.
3. Env A: **[fatal] [confirmed]** *no such table: notifications* (`notifications.php:50`). The table is only created by `update_schema.php`.

### Journey 6: Dashboard stats modal / charts
1. Tiles open a stats modal that fetches `ajax_user.php?action=…` **[confirmed: stats JSON returned]**. The JSON includes `my_total_views` (counts the member's own page views from `view_logs`).
2. Charts depend on the unpinned `https://cdn.jsdelivr.net/npm/chart.js` (latest major version). A future Chart.js major release can break them without any change here **[inferred]**.
3. Most series are zero for the reasons in Journey 1 step 4.

## Quality problems independent of the bugs above
- **The dashboard promises more than the site does:** streaks, levels, reading minutes, videos watched, reader tools. All inert, so the most personal page feels empty and broken.
- **Profile data model confusion:** first/last name at signup, single name in profile, a NULL `name` for every self-registered member (the greeting bug in 10).
- **No account deletion or data export** for members (the reader export is broken, domain 1). The privacy page should be checked against this (domain 21).
- **Dashboard and library are the best-looking pages on phone** **[confirmed]**. Layout isn't the problem here; empty data is.
- **Polling never pauses** for hidden tabs.

## Root causes
- **[code]**
  - Status vocabulary mismatch.
  - No "add to library" path.
  - No notification producers.
  - Email change without verification.
  - Email inside the profile save.
  - Streak `?? 0` bug.
  - Dashboard links to partials.
  - Unpinned Chart.js.
- **[schema]** `poem_reads`, `video_watches`, `reading_sessions`, `reading_streaks`, `user_reputations`, `notifications` aren't created by the repo (`notifications` only by the one-off script).
- **[ux]** Inert gamification shown prominently; mismatched signup/profile fields.
- **[infra]** 2 × 60 s polling per open dashboard.

## What a professional version looks like
- **J1:** Dashboard shows only things that move:
  - "Continue reading *Walking in Grace* · page 12 of 40"
  - "Poems you've read: 3"
  - "Your recent comments"
  - A "Start reading" card when empty.

  Stats load once with the page (server-rendered); no polling. If live updates are wanted, refresh only on focus and at most every 5 minutes.
- **J2:** Changing email sends a code to the new address and only switches after confirmation; the old address gets a "your email was changed" notice. Saves show "Profile updated" and never crash.
- **J4:** Every book card and detail page has "Add to library / Want to read". The reader marks books "Currently reading" automatically on open and "Finished" on the last page. One status vocabulary everywhere.
- **J5:** Either remove the bell, or create notifications for real events (reply to your comment or question, admin reply, session confirmed). Each notification links to the thing it is about and is marked read when opened.

## Dependencies before this can be fixed
1. Confirm the stat tables exist in production `data/site.db` (and `notifications`). **Create manually** if missing (SQLite file, not phpMyAdmin; see 01).
2. **Pick one status vocabulary, and update existing `reading_status` rows manually** to match.
3. Reader fixes (domain 1) before reading-based stats can move.
4. Email strategy (domain 16) for profile saves and any notification emails.

## Blast radius
- **Domain 1 (reader):** source of reading data; dashboard links into reader partials.
- **Domain 10 (auth):** `name` field, remember-me, email verification on change.
- **Domain 9 (books):** needs "Add to library".
- **Domains 13/14/15/18:** natural producers of notifications (replies, answers, session confirmations).
- **Domain 19 (admin analytics):** `my_total_views` reads `view_logs`, shared with admin stats.

## Effort
| Journey | Effort | Why |
|---|---|---|
| J1 dashboard | M | Remove inert tiles and polling; real "continue reading". |
| J2 profile | M | Email change verification; non-fatal save; name model. |
| J3 password | — | Works. |
| J4 library | M | Add-to-library entry points plus vocabulary unification and data fix. |
| J5 notifications | M | Producers at 3–4 events, or remove. |
| J6 charts | S | Pin library; drop zero series. |

## Regression check against AUDIT.md
- `profile.php:23` `poem_reads` missing [schema]: **Yes** (Env A).
- `notifications.php:50` table missing [schema]: **Yes** (Env A).
- `dashboard.php:378/399` 60 s polling ≈ 2,880 hits/day per tab: **Yes** (code unchanged; see note in Journey 1 step 6).
- `profile.php:117` `sendEmail()` → `die()`: **Yes** (Journey 2 step 4).

## Coverage matrix
| File | Status | Where |
|---|---|---|
| `dashboard.php` | COVERED | Journeys 1, 6 |
| `profile.php` | COVERED | Journeys 2, 3 |
| `library.php` | COVERED | Journey 4 |
| `notifications.php` | COVERED | Journey 5 |
| `notification_read.php` | COVERED | Journey 5 step 2 |
| `ajax_user.php` | COVERED | Journey 1 step 6; Journey 6 |

# Repairing the AngelWrites database

**What this fixes:** the database problems in [`docs/PRODUCTION_SCHEMA.md`](docs/PRODUCTION_SCHEMA.md):
- 19 tables whose foreign keys point at the empty `users_old` table, which makes every save to them fail
- 30 missing columns and 4 missing tables
- two `NOT NULL` traps (`reading_groups.book_id`, `book_comments`)
- dropping the empty `users_old`

**What it doesn't fix:** code bugs. Those are in the `fix/core-fatals` branch, which is merged **after** this repair (see [`MERGE_ORDER.md`](MERGE_ORDER.md)).

**Nothing is deleted:** every existing row in every table is kept and checked byte for byte. Only `users_old` is removed, and it is empty.

## Use Option B: run the script on a fresh download

An earlier draft offered an "Option A", uploading a pre-repaired copy of the database. **Don't use it.** That copy is a snapshot of the day you downloaded the database. Uploading it would erase every signup, subscriber, password change and saved item since then. It isn't in this repository, deliberately. Always run `repair.sql` on a database you've **just** downloaded.

## Files

| File | What it is |
|---|---|
| `repair.sql` | The repair. Plain SQL, about 450 lines (mostly table definitions). It has no `BEGIN`/`COMMIT` of its own: DB Browser supplies the transaction, so nothing is saved until you press *Write Changes*, and *close without saving* undoes everything. |
| `generate.py` | Regenerates `repair.sql` from a database file. Use it if the script stops with an error because the live database has changed shape since the snapshot. |
| `verify.py` | The test harness used to prove the repair on copies of the database. |
| `docs/PRODUCTION_SCHEMA.md` | What was wrong with the production database and why. |
| `MERGE_ORDER.md` | The order to merge branches and apply this repair, with stop/rollback signals. |

## How it was tested (on copies of the production database, never the live site)
1. **Safety guard:** with foreign keys left ON, the script stops at its first lines (*CHECK constraint failed: fk = 0*) and changes nothing. ✅
2. **Normal run:**
   - `integrity_check` returns `ok`; `foreign_key_check` returns no rows.
   - No table references `users_old`.
   - All 215 rows in all original tables are identical before and after.
   - All 76 indexes and every autoincrement counter are preserved. ✅
3. **Accidental second run:** stops at the first duplicate column; nothing changes. ✅
4. **Compatibility:** works on SQLite 3.53 and on PHP's SQLite 3.45, and still works if a tool ignores `legacy_alter_table`. ✅
   *Not tested here:* DB Browser for SQLite itself. The steps below are written for it, and the guard stops the script if its settings are wrong.
5. **The site against the repaired database:**
   - asking a question, answering, poem comments, book reviews (and they show on the book page), blog comments, library status, booking a session and creating a group all **save**
   - blog posts open for guests and members
   - the reflections page, search and the subscriber export load without errors ✅

## Before you start
- Pick a quiet time; the whole thing takes about 10 minutes, and changes made on the live site during those minutes would be lost.
- You need **DB Browser for SQLite** (free): https://sqlitebrowser.org/. It runs on Windows, Mac and Linux, **not on an iPad**, so this step needs a computer. On an iPad, a SQLite app that can run a multi-statement script with foreign keys switched off would also work, but none was tested.
- In your FTP client or the InfinityFree file manager, go to `htdocs/data/`. You'll see `site.db`, and possibly `site.db-wal` and `site.db-shm`. **The `-wal` file can hold your most recent changes**, so always download all three together.

## Steps
1. **Download** `site.db` plus `site.db-wal` and `site.db-shm` (if present) from `htdocs/data/` into one folder. Then **copy that whole folder** as your untouched backup. This fresh copy, not any older download, is your rollback.
2. Open DB Browser for SQLite → *Edit → Preferences* (Mac: *DB Browser for SQLite → Settings*) → **Database** tab → **untick "Foreign keys"** → OK.
   *This matters: rebuilding tables with foreign keys on would delete linked rows. If you forget, the script's guard stops it before anything changes.*
3. *File → Open Database* → choose the working `site.db` (the `-wal` file, if present, is read automatically).
4. Go to the **Execute SQL** tab, open `repair.sql` (folder icon in that tab) or paste its contents, and press **Execute all** (▶ or F5).
5. The last two results should be an **empty** foreign key check and **`ok`** for the integrity check. **Stop** if you see any error message instead, e.g. *duplicate column name* or *no such table*. That means the live database has changed shape since the snapshot. Close **without saving** (*Close Database → don't save*); your backup is untouched. Then regenerate the script with `generate.py` against the new download (or ask for it to be regenerated).
6. Press **Write Changes** (Ctrl+S / Cmd+S), then *File → Close Database*. Closing folds everything into `site.db`; there should be no `-wal` file left next to it. If there is, open and close the database once more.
7. On the server, **delete** `site.db-wal` and `site.db-shm` from `htdocs/data/` (if present), then immediately **upload** your repaired `site.db`, replacing the old one. Don't touch `htdocs/data/.htaccess`.

## After uploading (2 minutes)
- Open the homepage and a poem: they should load as before.
- Log in as a reader and **change a book's status in My Library**. Before the repair this crashed; now it should save.
- Post a test **comment on a poem**: it should appear. (Until `fix/core-fatals` is merged, an SMTP error page may follow; that's the separate email bug, and the comment is still saved.)
- **If anything looks wrong:** delete the new `site.db` and any new `-wal`/`-shm` on the server, then upload the backup files from step 1. That fully restores the state from minutes earlier.

## Notes
- The repair adds `purchases`, an empty table, so the download page stops crashing. Paid books stay locked until the payment model is decided.
- The 14 tables in production that the code never uses (e.g. `activity_logs`, `media_files`) are left alone.
- `includes/db.php` only creates tables that are *missing*, so it won't undo this repair.
- This folder is excluded from the FTP deploy (`db/**` in `.github/workflows/deploy.yml`), so none of it is ever published on the website.

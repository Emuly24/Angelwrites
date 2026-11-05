# Merge and repair order

Follow these steps in order. Don't start a step until the previous one is verified. Nothing here merges automatically: every merge is done by hand on GitHub. **Every merge to `main` deploys** to the live site via `.github/workflows/deploy.yml`.

| PR | Branch | Merged at step |
|---|---|---|
| — | `db/repair-artifacts` (this folder; `db/**` is never deployed) | Any time; it publishes nothing |
| — | `deploy/exclude-sensitive` | 1 |
| — | `fix/security` | 2 |
| #1 | `fix/core-fatals` | 4 |

## 1. Deploy excludes → deploy → verify → delete one-off scripts → change admin password
1. Merge `deploy/exclude-sensitive` **alone**. Wait for the "Deploy to InfinityFree" action to finish green (GitHub → Actions).
2. Check the URLs in the PR description (docs, db, one-off scripts, `data/site.db`).
3. In the InfinityFree file manager, delete `reset_admin.php`, `worker.php`, `update_schema.php`, `allow_url_fopen` from `htdocs/`. **Don't** delete `data/site.db`, `data/.htaccess` or `vendor/`.
4. Confirm those three `.php` URLs now return 404.
5. Change the admin password: log in as admin, open `/profile.php`, use **Change password** (needs the current password).

**Risk:** low. Excludes only change what is *uploaded*. Excluded files are also skipped by the deploy's delete step, so nothing already on the server is removed by the deploy.
**Stop if:** the Actions run fails, or the homepage stops loading after the deploy.
**Roll back:** revert the PR on GitHub (that redeploys the previous workflow).
**Is the `site.db` backup enough?** Not needed: this step never touches the database (`data/**` has always been excluded).

## 2. Security fixes → deploy → run the seven tests
1. Merge `fix/security`. Wait for the deploy.
2. Run the seven tests listed in that PR.

**Risk:** low–medium. Code only. Note the `book.php` purchase check runs **before** the repair has created the `purchases` table, so the code treats a missing table as "not purchased" (no link) rather than crashing.
**Stop if:** any of the seven tests fails, or a page that loaded before now shows an error.
**Roll back:** revert the PR on GitHub. Deleted one-off scripts would come back in the repo, but `deploy/exclude-sensitive` keeps them from being uploaded.
**Is the `site.db` backup enough?** Not needed: no database change.

## 3. Apply the database repair to the live site (Option B)
Follow `db/repair/README.md` exactly, on a **freshly downloaded** `site.db` + `-wal` + `-shm`. Needs a computer with DB Browser for SQLite, not an iPad.
Then verify: the homepage loads, a poem loads, a reader can change a book's status in My Library, and a poem comment saves.

**Risk: this is the risky step.** It's the only step that rewrites live data, and it involves a manual download, edit and upload with WAL side files.
**Stop if:**
- DB Browser shows *any* error while running the script (the live schema has drifted, so regenerate the script)
- the final `foreign_key_check` is not empty or `integrity_check` is not `ok`
- after upload, the site shows *database disk image is malformed*, *no such table*, or nobody can log in
**Roll back:** delete the live `site.db` (and any new `-wal`/`-shm`), then upload the backup files you downloaded in README step 1.
**Is the `site.db` backup enough?** **Only the fresh one from README step 1, taken minutes before the repair, with its `-wal`/`-shm`.** An older backup (e.g. the copy downloaded weeks ago) is *not* a safe rollback: restoring it would erase every signup, subscriber, password change and saved item since it was taken.

## 4. Core fixes (PR #1)
1. Rebase `fix/core-fatals` on the new `main`. Re-run `php -l` on the changed files and the local 207-page sweep against a repaired database. It must still show zero fatals.
2. Merge PR #1. Wait for the deploy.

**Risk:** medium. It's the largest code change. It depends on step 3: without the repair, saves still fail on the database.
**Stop if:** the homepage, a poem, the reader or login shows an error after the deploy.
**Roll back:** revert PR #1 on GitHub. Rows saved while it was live are ordinary valid data and can stay.
**Is the `site.db` backup enough?** Not needed for a code revert. The step-3 backup would only matter if data were damaged, which this code change doesn't do.

## 5. End-to-end check
Register a new account, post a poem comment, save a highlight in the reader, answer a question. Each should complete without an error page. (Email may not arrive if the host blocks outbound mail; that's expected and no longer breaks the page.)
